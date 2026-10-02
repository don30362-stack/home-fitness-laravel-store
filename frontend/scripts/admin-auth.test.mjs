import assert from 'node:assert/strict'
import { test, beforeEach, after } from 'node:test'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import { createRenderer, nextTick, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import axios from 'axios'

// 與既有 orders 測試相同：真 SFC client render／記憶體 host，並非真瀏覽器。
const storage = new Map()
globalThis.localStorage = {
  getItem: (key) => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, value),
  removeItem: (key) => storage.delete(key),
}
const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', plugins: [{
  name: 'admin-test-sfc', enforce: 'pre',
  resolveId(id) { if (id === 'virtual:admin-login') return '\0admin-login.ts' },
  async load(id) {
    if (id !== '\0admin-login.ts') return
    const source = await readFile(new URL('../src/views/admin/AdminLoginView.vue', import.meta.url), 'utf8')
    return ts.transpileModule(compileScript(parse(source).descriptor, { id, inlineTemplate: true }).content,
      { compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.ESNext } }).outputText
  },
}] })
const { default: api } = await server.ssrLoadModule('/src/services/adminApi.ts')
const service = await server.ssrLoadModule('/src/services/adminAuthService.ts')
const state = await server.ssrLoadModule('/src/services/adminSessionState.ts')
const navigation = await server.ssrLoadModule('/src/services/adminSessionNavigation.ts')
const bootstrap = await server.ssrLoadModule('/src/services/adminBootstrap.ts')
const { useAdminAuthStore } = await server.ssrLoadModule('/src/stores/adminAuth.ts')
const { useAuthStore } = await server.ssrLoadModule('/src/stores/auth.ts')
const { useCartStore } = await server.ssrLoadModule('/src/stores/cart.ts')
const memberState = await server.ssrLoadModule('/src/services/sessionState.ts')
const { default: memberApi } = await server.ssrLoadModule('/src/services/api.ts')
const { default: Login } = await server.ssrLoadModule('virtual:admin-login')
const originalError = console.error
console.error = () => {}
after(async () => { console.error = originalError; await server.close() })

const admin = { id: 1, name: '管理員', email: 'admin@example.test', status: 'active' }
const newer = { ...admin, id: 2, email: 'new@example.test' }
const user = { id: 9, name: '會員', email: 'member@example.test', phone: '0912345678', status: 'active' }
const payload = { email: admin.email, password: 'test-only' }
const key = 'home-fitness-store-guest-cart'
const guest = JSON.stringify([{ key: '99:none', product_id: 99, product_variant_id: null, quantity: 1 }])
const response = (config, data, status = 200) => ({ config, data, status, statusText: '', headers: {} })
const failure = (config, status, code) => new axios.AxiosError('test error', status ? 'ERR_BAD_RESPONSE' : 'ERR_NETWORK', config, undefined,
  status ? response(config, { message: '後端停用原因', ...(code ? { code } : {}) }, status) : undefined)
const settle = async () => { await new Promise((resolve) => setImmediate(resolve)); await nextTick() }
let pinia, auth, member, carts, calls, nav, memberEvents
beforeEach(() => {
  storage.clear(); storage.set(key, guest)
  pinia = createPinia(); setActivePinia(pinia)
  state.startAdminGeneration(); memberState.startSessionVersion()
  auth = useAdminAuthStore(pinia); auth.currentAdmin = { ...admin }
  member = useAuthStore(pinia); member.currentUser = { ...user }
  carts = useCartStore(pinia); carts.memberCart = { items: [], item_count: 0, has_unavailable_items: false }
  calls = []; nav = []; memberEvents = []
  navigation.setAdminNavigationCallbacks({ loginRequired: () => { nav.push('required') }, loginSucceeded: () => { nav.push('success') } })
  memberState.setSessionFailureHandler((reason) => { memberEvents.push(reason); member.resetMemberSession(reason) })
  memberApi.defaults.adapter = async (config) => response(config, { data: user })
  api.defaults.adapter = async (config) => {
    calls.push(config)
    return response(config, config.url.endsWith('/csrf-cookie') ? {} : { data: admin, message: '登入成功' })
  }
})
const assertMemberUntouched = () => {
  assert.equal(member.currentUser.id, user.id); assert.ok(carts.memberCart)
  assert.equal(storage.get(key), guest); assert.equal(carts.guestItems.length, 1)
  assert.deepEqual(memberEvents, [])
}

test('獨立 transport／service：CSRF→login、me、logout 及四欄 envelope', async () => {
  assert.notEqual(api, memberApi)
  assert.equal(api.defaults.baseURL, memberApi.defaults.baseURL)
  assert.equal(api.defaults.withCredentials, true); assert.equal(api.defaults.withXSRFToken, true)
  assert.equal(api.defaults.headers.Accept, 'application/json')
  assert.deepEqual((await service.loginAdmin(payload)).data, admin)
  assert.deepEqual((await service.getCurrentAdmin()).data, admin)
  await service.logoutAdmin()
  assert.ok(calls[0].url.endsWith('/sanctum/csrf-cookie'))
  assert.deepEqual(calls.slice(1).map((c) => [c.method, c.url]), [['post', '/admin/login'], ['get', '/admin/me'], ['post', '/admin/logout']])
  assert.deepEqual(JSON.parse(calls[1].data), payload)
  assertMemberUntouched()
})
test('Admin login 不 merge cart、不改會員世代或狀態', async () => {
  const memberGeneration = memberState.getSessionVersion()
  carts.mergeGuestCart = () => { throw Error('must not merge') }
  await auth.login(payload)
  assert.equal(auth.isAdminAuthenticated, true); assert.equal(auth.isAdminInitialized, true)
  assert.equal(memberState.getSessionVersion(), memberGeneration)
  assertMemberUntouched()
})
test('restore 200 成功、並行 Promise 去重', async () => {
  let finish
  api.defaults.adapter = (config) => new Promise((resolve) => { calls.push(config); finish = () => resolve(response(config, { data: admin })) })
  const first = auth.restoreAdmin(), second = auth.restoreAdmin()
  // Pinia action 各自包裝 Promise；去重應驗證底層只有一個 request。
  await settle(); assert.equal(calls.length, 1)
  assert.equal(auth.isRestoring, true); finish()
  assert.deepEqual(await Promise.all([first, second]), ['authenticated', 'authenticated']); assert.equal(auth.isRestoring, false)
  assert.equal(auth.isAdminInitialized, true)
})
test('初次 restore 401 是正常 guest，沒有停用／導頁通知', async () => {
  auth.currentAdmin = null
  api.defaults.adapter = (config) => Promise.reject(failure(config, 401))
  assert.equal(await auth.restoreAdmin(), 'guest'); await settle()
  assert.equal(auth.currentAdmin, null); assert.equal(auth.isAdminInitialized, true)
  assert.equal(auth.adminFailureReason, null); assert.deepEqual(nav, [])
})
for (const [status, code, reason] of [[401, undefined, 'expired'], [403, 'ADMIN_ACCOUNT_DISABLED', 'disabled']]) {
  test(`Admin ${status}/${code ?? ''} 只清 Admin，不碰會員`, async () => {
    const memberGeneration = memberState.getSessionVersion()
    api.defaults.adapter = (config) => Promise.reject(failure(config, status, code))
    await auth.restoreAdmin(); await settle()
    assert.equal(auth.currentAdmin, null); assert.equal(auth.adminFailureReason, reason)
    assert.deepEqual(nav, ['required']); assert.equal(memberState.getSessionVersion(), memberGeneration)
    assertMemberUntouched()
  })
}
for (const status of [undefined, 403, 419, 500, 503]) {
  test(`restore ${status ?? 'network'} 保留恢復失敗語意，不是假 guest，可手動重試`, async () => {
    let count = 0
    api.defaults.adapter = (config) => { count++; return Promise.reject(failure(config, status)) }
    await assert.rejects(auth.restoreAdmin())
    assert.equal(auth.currentAdmin.id, admin.id); assert.equal(auth.isAdminInitialized, false)
    assert.ok(auth.restoreError); assert.equal(auth.adminFailureReason, null)
    assert.equal(count, 1); await settle(); assert.deepEqual(nav, []); assertMemberUntouched()
    api.defaults.adapter = async (config) => response(config, { data: admin })
    await auth.restoreAdmin(); assert.equal(auth.restoreError, null)
  })
}
test('並行失效只導頁一次，停用原因不被後到 401／419 覆寫', async () => {
  api.defaults.adapter = (config) => { calls.push(config); return Promise.reject(failure(config,
    config.url.endsWith('/logout') ? 401 : config.url.endsWith('/other') ? 419 : 403,
    config.url.endsWith('/me') ? 'ADMIN_ACCOUNT_DISABLED' : undefined)) }
  await Promise.allSettled([api.get('/admin/me'), api.post('/admin/logout'), api.post('/admin/other')]); await settle()
  assert.deepEqual(nav, ['required']); assert.equal(auth.adminFailureReason, 'disabled'); assert.equal(calls.length, 3)
  assertMemberUntouched()
})
test('同批先過期後停用只提升原因、不再導頁', async () => {
  api.defaults.adapter = (config) => Promise.reject(failure(config, config.url.endsWith('/me') ? 401 : 403,
    config.url.endsWith('/me') ? undefined : 'ADMIN_ACCOUNT_DISABLED'))
  await Promise.allSettled([api.get('/admin/me'), api.get('/admin/other')]); await settle()
  assert.equal(auth.adminFailureReason, 'disabled'); assert.deepEqual(nav, ['required'])
})
test('一般 403 空 body／會員 ACCOUNT_DISABLED code 不誤判 Admin 停用', async () => {
  for (const body of [null, { code: 'ACCOUNT_DISABLED' }]) {
    api.defaults.adapter = (config) => Promise.reject(new axios.AxiosError('forbidden', 'ERR_BAD_RESPONSE', config, undefined, response(config, body, 403)))
    await assert.rejects(api.get('/admin/me')); assert.equal(auth.currentAdmin.id, admin.id)
    assert.equal(auth.adminFailureReason, null); assertMemberUntouched()
  }
})
for (const outcome of ['success', '401', 'disabled']) {
  test(`舊 restore ${outcome} 晚於新 login，不覆寫或清新 Admin`, async () => {
    let finish
    api.defaults.adapter = (config) => new Promise((resolve, reject) => { finish = () => outcome === 'success'
      ? resolve(response(config, { data: admin })) : reject(failure(config, outcome === '401' ? 401 : 403, outcome === 'disabled' ? 'ADMIN_ACCOUNT_DISABLED' : undefined)) })
    const old = auth.restoreAdmin(); await settle()
    api.defaults.adapter = async (config) => response(config, config.url.endsWith('/csrf-cookie') ? {} : { data: newer, message: '成功' })
    await auth.login(payload); finish()
    assert.equal(await old, 'stale'); assert.equal(auth.currentAdmin.id, newer.id)
    assert.equal(auth.adminFailureReason, null); assert.deepEqual(nav, [])
  })
}
for (const status of [200, 401]) {
  test(`舊 logout ${status} 晚於重新登入，不清新 Admin`, async () => {
    let finish
    api.defaults.adapter = (config) => new Promise((resolve, reject) => { finish = () => status === 200
      ? resolve(response(config, { message: '成功' })) : reject(failure(config, 401)) })
    const old = auth.logout(); await settle()
    api.defaults.adapter = async (config) => response(config, config.url.endsWith('/csrf-cookie') ? {} : { data: newer, message: '成功' })
    await auth.login(payload); finish(); await old
    assert.equal(auth.currentAdmin.id, newer.id); assert.deepEqual(nav, [])
  })
}
for (const status of [200, 401, 500, undefined]) {
  test(`logout ${status ?? 'network'}：成功／401 清 Admin，一般錯誤保留`, async () => {
    api.defaults.adapter = (config) => { calls.push(config); return status === 200
      ? Promise.resolve(response(config, { message: '登出成功' })) : Promise.reject(failure(config, status)) }
    if (status === 200 || status === 401) {
      await auth.logout(); assert.equal(auth.currentAdmin, null)
    } else { await assert.rejects(auth.logout()); assert.equal(auth.currentAdmin.id, admin.id) }
    assert.equal(calls.length, 1); assertMemberUntouched()
  })
}
test('會員 C06 不直接清 Admin，下一次 Admin 401 才同步清理', async () => {
  const before = state.getAdminGeneration()
  memberApi.defaults.adapter = (config) => Promise.reject(failure(config, 403, 'ACCOUNT_DISABLED'))
  await assert.rejects(memberApi.get('/me'))
  assert.equal(member.currentUser, null); assert.equal(carts.memberCart, null)
  assert.equal(auth.currentAdmin.id, admin.id); assert.equal(state.getAdminGeneration(), before)
  api.defaults.adapter = (config) => Promise.reject(failure(config, 401))
  await auth.restoreAdmin(); assert.equal(auth.currentAdmin, null); assert.equal(storage.get(key), guest)
})
test('登出完成後舊 restore success 不得復活 Admin', async () => {
  let finish
  api.defaults.adapter = (config) => new Promise((resolve) => { finish = () => resolve(response(config, { data: admin })) })
  const old = auth.restoreAdmin(); await settle()
  api.defaults.adapter = async (config) => response(config, { message: '登出成功' })
  await auth.logout(); finish()
  assert.equal(await old, 'stale'); assert.equal(auth.currentAdmin, null)
})
test('舊 CSRF 回應晚到時，不繼續送出舊 login POST', async () => {
  let finish
  api.defaults.adapter = (config) => new Promise((resolve) => { calls.push(config); finish = () => resolve(response(config, {})) })
  const old = auth.login(payload); const rejection = assert.rejects(old, state.AdminSessionInvalidatedError)
  await settle(); auth.clearAdminSession(); finish(); await rejection
  assert.equal(calls.length, 1); assert.ok(calls[0].url.endsWith('/csrf-cookie')); assert.equal(auth.currentAdmin, null)
})
test('新世代取消排隊中的舊失效導頁', async () => {
  navigation.requestAdminLogin(); state.startAdminGeneration(); await settle()
  assert.deepEqual(nav, [])
})
test('bootstrap helper 僅 admin namespace restore；前台不觸發 Admin API', async () => {
  assert.equal(await bootstrap.restoreInitialAdmin('/member/orders', pinia), false)
  assert.equal(await bootstrap.restoreInitialAdmin('/administrator', pinia), false)
  assert.equal(calls.length, 0)
  for (const path of ['/admin', '/admin/login', '/admin/products']) {
    assert.equal(await bootstrap.restoreInitialAdmin(path, pinia), true)
  }
  assert.equal(calls.length, 3); assertMemberUntouched()
  api.defaults.adapter = (config) => Promise.reject(failure(config, 500))
  assert.equal(await bootstrap.restoreInitialAdmin('/admin', pinia), true)
  assert.ok(auth.restoreError); assert.equal(auth.isAdminInitialized, false)
})

const node = (type, text = '') => ({ type, tagName: type.toUpperCase(), text, props: {}, children: [], parent: null,
  value: '', listeners: {}, addEventListener(event, handler) { this.listeners[event] = handler }, removeEventListener(event) { delete this.listeners[event] } })
const renderer = createRenderer({
  createElement: (type) => node(type), createText: (text) => node('text', text), createComment: () => node('comment'),
  setText: (el, text) => { el.text = text }, setElementText: (el, text) => { el.text = text; el.children = [] },
  patchProp: (el, key, _old, value) => { el.props[key] = value; if (key === 'value') el.value = value },
  insert(el, parent, anchor = null) { el.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1; if (index < 0) parent.children.push(el); else parent.children.splice(index, 0, el) },
  remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1) },
  parentNode: (el) => el.parent, nextSibling: () => null,
})
const text = (el) => el.text + el.children.map(text).join('')
const find = (el, predicate) => predicate(el) ? el : el.children.map((child) => find(child, predicate)).find(Boolean)
const mount = async () => {
  const root = node('root'), app = renderer.createApp({ render: () => h(Login) }); app.use(pinia); app.mount(root); await settle()
  for (const [id, value] of [['admin-email', payload.email], ['admin-password', payload.password]]) {
    const input = find(root, (el) => el.props.id === id); input.value = value; input.listeners.input({ target: input })
  }
  await nextTick()
  return { root, app, submit: () => find(root, (el) => el.type === 'form').props.onSubmit({ preventDefault() {} }) }
}
test('登入元件正常輸入、submitting 防重送、成功 callback，不建立正式 route', async () => {
  let finish
  api.defaults.adapter = (config) => { calls.push(config); return config.url.endsWith('/csrf-cookie') ? Promise.resolve(response(config, {}))
    : new Promise((resolve) => { finish = () => resolve(response(config, { data: admin, message: '成功' })) }) }
  const view = await mount(); const first = view.submit(); await settle(); view.submit(); await settle()
  assert.equal(calls.length, 2); assert.deepEqual(JSON.parse(calls[1].data), payload)
  assert.equal(find(view.root, (el) => el.props.type === 'submit').props.disabled, true)
  assert.ok(text(view.root).includes('登入中'))
  finish(); await first; await settle(); assert.deepEqual(nav, ['success']); assertMemberUntouched(); view.app.unmount()
})
test('登入元件呈現 restoreError 並提供手動 GET 重試', async () => {
  api.defaults.adapter = (config) => Promise.reject(failure(config, 500))
  await assert.rejects(auth.restoreAdmin())
  const view = await mount(); assert.ok(text(view.root).includes('無法恢復管理員登入狀態'))
  api.defaults.adapter = async (config) => { calls.push(config); return response(config, { data: admin }) }
  await find(view.root, (el) => el.type === 'button' && text(el) === '重試').props.onClick(); await settle()
  assert.equal(auth.restoreError, null); assert.equal(calls.length, 1); assert.equal(calls[0].method, 'get')
  view.app.unmount()
})
for (const [status, code, expected] of [[401, undefined, '帳號或密碼錯誤'], [403, 'ADMIN_ACCOUNT_DISABLED', '後端停用原因'],
  [422, undefined, 'Email 格式錯誤'], [419, undefined, 'CSRF'], [undefined, undefined, '稍後再試'], [500, undefined, '稍後再試'], [403, undefined, '稍後再試']]) {
  test(`登入元件 ${status ?? 'network'}／${code ?? ''} 錯誤提示，不全域導頁、不重送`, async () => {
    api.defaults.adapter = (config) => {
      calls.push(config); if (config.url.endsWith('/csrf-cookie')) return Promise.resolve(response(config, {}))
      const error = failure(config, status, code)
      if (status === 422) error.response.data.errors = { email: ['Email 格式錯誤'], password: ['密碼欄位錯誤'] }
      return Promise.reject(error)
    }
    const view = await mount(); await view.submit(); await settle()
    assert.ok(text(view.root).includes(expected)); if (status === 422) assert.ok(text(view.root).includes('密碼欄位錯誤'))
    assert.deepEqual(nav, []); assert.equal(auth.currentAdmin.id, admin.id); assert.equal(calls.length, 2)
    assertMemberUntouched(); view.app.unmount()
  })
}
