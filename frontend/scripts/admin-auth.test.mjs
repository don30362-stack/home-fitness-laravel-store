import assert from 'node:assert/strict'
import { test, beforeEach, after } from 'node:test'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import { createRenderer, nextTick, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory, RouterView } from 'vue-router'
import axios from 'axios'

// 與既有 orders 測試相同：真 SFC client render／記憶體 host，並非真瀏覽器。
const storage = new Map()
// Memory host 提供 v-model 更新時的 DOM 型別邊界；不是瀏覽器驗收。
globalThis.Document = class Document {}
globalThis.ShadowRoot = class ShadowRoot {}
globalThis.localStorage = {
  getItem: (key) => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, value),
  removeItem: (key) => storage.delete(key),
}
const adminViews = {
  AdminLoginView: '/src/views/admin/AdminLoginView.vue', AdminLayout: '/src/layouts/AdminLayout.vue',
  AdminSidebar: '/src/components/admin/AdminSidebar.vue', AdminHeader: '/src/components/admin/AdminHeader.vue',
  AdminPlaceholderView: '/src/views/admin/AdminPlaceholderView.vue', AdminNotFoundView: '/src/views/admin/AdminNotFoundView.vue',
  ProductManagementView: '/src/views/admin/ProductManagementView.vue', AdminProductDetailView: '/src/views/admin/AdminProductDetailView.vue',
  AppPagination: '/src/components/common/AppPagination.vue',
  ProductForm: '/src/components/admin/ProductForm.vue', AdminProductFormView: '/src/views/admin/AdminProductFormView.vue',
  ProductImageManager: '/src/components/admin/ProductImageManager.vue',
}
const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', plugins: [{
  name: 'admin-test-sfc', enforce: 'pre',
  resolveId(id) {
    const name = id === 'virtual:admin-login' ? 'AdminLoginView' : Object.keys(adminViews).find((name) => id.endsWith('/' + name + '.vue'))
    if (name) return '\0admin-sfc:' + name + '.ts'
  },
  async load(id) {
    const file = adminViews[id.replace(/^\0admin-sfc:/, '').replace(/\.ts$/, '')]
    if (!file) return
    const source = await readFile(new URL('..' + file, import.meta.url), 'utf8')
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
const routes = await server.ssrLoadModule('/src/router/adminRoutes.ts')
const productService = await server.ssrLoadModule('/src/services/adminProductService.ts')
const originalError = console.error
console.error = () => {}
after(async () => { console.error = originalError; await server.close() })

const admin = { id: 1, name: '管理員', email: 'admin@example.test', status: 'active' }
const newer = { ...admin, id: 2, email: 'new@example.test' }
const user = { id: 9, name: '會員', email: 'member@example.test', phone: '0912345678', status: 'active' }
const payload = { email: admin.email, password: 'test-only' }
const key = 'home-fitness-store-guest-cart'
const guest = JSON.stringify([{ key: '99:none', product_id: 99, product_variant_id: null, quantity: 1 }])
const categoryOptions = [{ id: 1, name: '器材', children: [{ id: 2, name: '啞鈴' }] }]
const productItem = {
  id: 1, product_code: 'PRD-EXISTING', name: '測試啞鈴', category: { id: 2, name: '啞鈴', status: 'inactive' },
  price: '1234.50', stock: null, has_variants: true, low_stock_threshold: 5, status: 'inactive',
  created_at: '2026-01-01T00:00:00Z', updated_at: '2026-01-02T00:00:00Z',
}
const productPage = (data = [productItem], page = 1, lastPage = 2) => ({ data,
  links: { first: '?page=1', last: `?page=${lastPage}`, prev: null, next: '?page=2' },
  meta: { current_page: page, last_page: lastPage, per_page: 10, total: data.length ? 11 : 0 } })
const productDetail = { ...productItem, category: { ...productItem.category, parent: { id: 1, name: '器材', status: 'active' } },
  short_description: '短介', description: '完整詳細文字', images: [{ id: 1, image_path: 'products/read.jpg',
    image_url: 'http://localhost/storage/products/read.jpg', image_type: 'detail', is_primary: true, sort_order: 2 }],
  specifications: [{ id: 1, spec_name: '材質', spec_value: '鋼材', sort_order: 0 }],
  variants: [{ id: 1, option_name: '顏色', option_value: '黑色', stock: 3, status: 'inactive' }] }
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
    if (config.url === '/categories') return response(config, { data: categoryOptions })
    if (config.url === '/admin/products') return response(config, productPage())
    if (config.url.startsWith('/admin/products/')) return response(config, { data: productDetail })
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
  getRootNode: () => ({ activeElement: null }),
  get options() { const collect = (el) => el.children.flatMap(child => child.type === 'option' ? [child] : collect(child)); return collect(this) },
  value: '', listeners: {}, addEventListener(event, handler) { this.listeners[event] = handler }, removeEventListener(event) { delete this.listeners[event] } })
const renderer = createRenderer({
  createElement: (type) => node(type), createText: (text) => node('text', text), createComment: () => node('comment'),
  setText: (el, text) => { el.text = text }, setElementText: (el, text) => { el.text = text; el.children = [] },
  patchProp: (el, key, _old, value) => { el.props[key] = value; if (key === 'value') el.value = value },
  insert(el, parent, anchor = null) {
    if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1)
    el.parent = parent; const index = anchor ? parent.children.indexOf(anchor) : -1
    if (index < 0) parent.children.push(el); else parent.children.splice(index, 0, el)
  },
  remove(el) { if (el.parent) { el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = null } },
  parentNode: (el) => el.parent,
  nextSibling: (el) => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
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

const memoryRouter = () => {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    ...routes.adminRoutes, { path: '/', component: { render: () => h('div', '前台') } },
  ] })
  router.beforeEach(routes.adminGuard)
  const lifecycle = routes.connectAdminNavigation(router)
  return { router, lifecycle }
}
const mountRoute = async (url, instance = memoryRouter()) => {
  await instance.router.push(url); await instance.router.isReady(); await instance.lifecycle.ready()
  const root = node('root'), app = renderer.createApp({ render: () => h(RouterView) })
  app.use(pinia); app.use(instance.router); app.mount(root); await settle()
  return { ...instance, root, app }
}

for (const path of ['/admin/dashboard', '/admin/orders', '/admin/does-not-exist', '/admin/products', '/admin/products/1', '/admin/products/new', '/admin/products/1/edit']) {
  test(`guest/member-only ${path} 經真 guard 導登入；會員身分不等於 Admin`, async () => {
    auth.currentAdmin = null
    api.defaults.adapter = (config) => { calls.push(config); return Promise.reject(failure(config, 401)) }
    const view = await mountRoute(path)
    assert.equal(view.router.currentRoute.value.name, 'admin-login')
    assert.equal(view.router.currentRoute.value.query.redirect, path)
    assert.equal(calls.length, 1); assert.ok(text(view.root).includes('管理員登入'))
    assertMemberUntouched(); view.app.unmount()
  })
}
test('Admin-only 八模組保持階段對照，只有 products 已有唯讀 API', async () => {
  auth.isAdminInitialized = true; member.currentUser = null
  const view = await mountRoute('/admin')
  assert.equal(view.router.currentRoute.value.fullPath, '/admin/dashboard')
  assert.ok(find(view.root, (el) => el.type === 'header'))
  assert.ok(find(view.root, (el) => el.type === 'aside'))
  assert.ok(text(view.root).includes(admin.email))
  for (const item of routes.adminModules) {
    const anchor = find(view.root, (el) => el.type === 'a' && el.props.href === '/admin/' + item.path)
    assert.ok(anchor, item.path)
    await view.router.push('/admin/' + item.path); await settle()
    const main = find(view.root, (el) => el.type === 'main')
    assert.ok(text(main).includes(item.title))
    if (item.path === 'products') assert.ok(!text(main).includes('尚未實作'))
    else { assert.ok(text(main).includes('尚未實作')); assert.ok(text(main).includes('Stage ' + item.stage)) }
  }
  assert.deepEqual(routes.adminModules.map((item) => item.stage), [24, 19, 20, 19, 21, 21, 22, 23])
  assert.deepEqual(calls.map(c => c.url).sort(), ['/admin/products', '/categories']); assert.equal(member.currentUser, null)
  const frontLink = find(view.root, (el) => el.type === 'a' && el.props.href === '/')
  assert.ok(frontLink); assert.equal(frontLink.props.onClick, undefined)
  const menu = find(view.root, (el) => el.type === 'button' && text(el) === '選單')
  assert.equal(menu.props['aria-expanded'], false); menu.props.onClick(); await nextTick()
  assert.equal(menu.props['aria-expanded'], true)
  view.app.unmount()
})
test('reload protected child 先等待 restore，再放行而非先導 login', async () => {
  auth.currentAdmin = null
  let finish
  api.defaults.adapter = (config) => new Promise((resolve) => { calls.push(config); finish = () => resolve(response(config, { data: admin })) })
  const instance = memoryRouter(), pending = instance.router.push('/admin/orders')
  await settle(); assert.equal(calls.length, 1); assert.equal(instance.router.currentRoute.value.name, undefined)
  finish(); await pending
  assert.equal(instance.router.currentRoute.value.name, 'admin-orders'); assert.equal(auth.isAdminAuthenticated, true)
})
test('disabled protected child 導 login 並保留後端原因', async () => {
  auth.currentAdmin = null
  api.defaults.adapter = (config) => Promise.reject(failure(config, 403, 'ADMIN_ACCOUNT_DISABLED'))
  const view = await mountRoute('/admin/orders')
  assert.equal(view.router.currentRoute.value.name, 'admin-login')
  assert.ok(text(view.root).includes('後端停用原因')); assert.equal(auth.adminFailureReason, 'disabled')
  view.app.unmount()
})
for (const status of [undefined, 419, 500]) {
  test(`protected restore ${status ?? 'network'} 保留 URL、不呈現業務 child；可重試`, async () => {
    auth.currentAdmin = null
    api.defaults.adapter = (config) => Promise.reject(failure(config, status))
    const view = await mountRoute('/admin/products')
    assert.equal(view.router.currentRoute.value.fullPath, '/admin/products')
    assert.ok(text(view.root).includes('無法恢復管理員登入狀態'))
    assert.equal(find(view.root, (el) => el.type === 'header'), undefined)
    assert.equal(find(view.root, (el) => el.type === 'main'), undefined)
    api.defaults.adapter = async (config) => response(config, config.url === '/admin/products' ? productPage()
      : { data: config.url === '/categories' ? categoryOptions : admin })
    await find(view.root, (el) => el.type === 'button' && text(el) === '重試').props.onClick(); await settle()
    assert.equal(view.router.currentRoute.value.name, 'admin-products')
    assert.ok(text(view.root).includes(productItem.name)); view.app.unmount()
  })
}
test('已初始化有效 Admin 開 login 導 dashboard', async () => {
  auth.isAdminInitialized = true
  const view = await mountRoute('/admin/login')
  assert.equal(view.router.currentRoute.value.name, 'admin-dashboard'); assert.equal(calls.length, 0)
  view.app.unmount()
})
for (const outcome of ['authenticated', 'guest', 'disabled', 'temporary']) {
  test(`login guard 未初始化 restore ${outcome} 的合理結果`, async () => {
    auth.currentAdmin = null
    api.defaults.adapter = (config) => outcome === 'authenticated' ? Promise.resolve(response(config, { data: admin }))
      : Promise.reject(failure(config, outcome === 'guest' ? 401 : outcome === 'disabled' ? 403 : 500,
        outcome === 'disabled' ? 'ADMIN_ACCOUNT_DISABLED' : undefined))
    const view = await mountRoute('/admin/login')
    assert.equal(view.router.currentRoute.value.name, outcome === 'authenticated' ? 'admin-dashboard' : 'admin-login')
    if (outcome === 'disabled') assert.ok(text(view.root).includes('後端停用原因'))
    if (outcome === 'temporary') {
      assert.ok(text(view.root).includes('無法恢復管理員登入狀態'))
      api.defaults.adapter = async (config) => response(config, { data: admin })
      await find(view.root, (el) => el.type === 'button' && text(el) === '重試').props.onClick(); await settle()
      assert.equal(view.router.currentRoute.value.name, 'admin-dashboard')
    }
    view.app.unmount()
  })
}
test('safe redirect 拒絕外站、會員、login、相對穿越等；合法保留 query', () => {
  assert.equal(routes.safeAdminRedirect('/admin/orders?page=2'), '/admin/orders?page=2')
  for (const value of ['https://evil.com', '//evil.com', '/member', '/admin/login', '/admin/login?redirect=x', '/admin/login/',
    '/admin/../member', '/admin/%6cogin', '/admin/\\evil.com', undefined, ['/admin/orders']]) {
    assert.equal(routes.safeAdminRedirect(value), '/admin/dashboard')
  }
})
for (const redirect of ['/admin/orders', 'https://evil.com', '//evil.com', '/member', '/admin/login']) {
  test(`真 Router loginSucceeded callback 使用安全 redirect：${redirect}`, async () => {
    auth.currentAdmin = null; auth.isAdminInitialized = true
    const view = await mountRoute('/admin/login?redirect=' + encodeURIComponent(redirect))
    await auth.login(payload); await navigation.notifyAdminLoginSuccess(); await settle()
    assert.equal(view.router.currentRoute.value.fullPath, redirect === '/admin/orders' ? redirect : '/admin/dashboard')
    view.app.unmount()
  })
}
for (const status of [200, 401, 500, undefined]) {
  test(`Header logout ${status ?? 'network'} 真操作與 Router 導頁／錯誤`, async () => {
    auth.isAdminInitialized = true
    const view = await mountRoute('/admin/dashboard')
    api.defaults.adapter = (config) => { calls.push(config); return status === 200 ? Promise.resolve(response(config, { message: '成功' }))
      : Promise.reject(failure(config, status)) }
    await find(view.root, (el) => el.type === 'button' && text(el) === '登出').props.onClick(); await settle()
    if (status === 200 || status === 401) {
      assert.equal(view.router.currentRoute.value.name, 'admin-login'); assert.equal(auth.currentAdmin, null)
    } else {
      assert.equal(view.router.currentRoute.value.name, 'admin-dashboard'); assert.ok(text(view.root).includes('登出失敗'))
      assert.equal(auth.currentAdmin.id, admin.id)
    }
    assert.equal(calls.length, 1); assertMemberUntouched(); view.app.unmount()
  })
}
test('未知 Admin URL 使用 Admin 專用 NotFound，保留後台框架', async () => {
  auth.isAdminInitialized = true
  const view = await mountRoute('/admin/does-not-exist')
  assert.equal(view.router.currentRoute.value.name, 'admin-not-found')
  assert.ok(text(view.root).includes('找不到後台頁面')); assert.ok(find(view.root, (el) => el.type === 'aside'))
  assert.equal(calls.length, 0); view.app.unmount()
})
test('正式 bootstrap helper：admin 不 restore 會員；前台仍走真會員 restore 流程', async () => {
  let memberRestoreCalls = 0
  const memberCalls = []
  memberApi.defaults.adapter = async (config) => { memberCalls.push(config.url); return response(config,
    { data: config.url === '/cart' ? { items: [], item_count: 0, has_unavailable_items: false } : user }) }
  const restoreMember = async () => { memberRestoreCalls++; await member.restoreAuth() }
  await bootstrap.restoreInitialIdentity('/admin/orders', pinia, restoreMember)
  assert.equal(memberRestoreCalls, 0); assert.equal(calls.length, 1); assert.deepEqual(memberCalls, [])
  calls.length = 0
  await bootstrap.restoreInitialIdentity('/member/orders', pinia, restoreMember)
  assert.equal(memberRestoreCalls, 1); assert.equal(calls.length, 0); assert.deepEqual(memberCalls, ['/me', '/cart'])
  const mainSource = await readFile(new URL('../src/main.ts', import.meta.url), 'utf8')
  assert.ok(mainSource.includes('restoreInitialIdentity(window.location.pathname'))
})

const productsView = async (url = '/admin/products') => {
  auth.isAdminInitialized = true
  return mountRoute(url)
}
const submitProductFilters = async (view, values) => {
  for (const [id, value] of Object.entries(values)) {
    const input = find(view.root, el => el.props.id === id)
    input.value = value
    if (input.type === 'select') {
      for (const option of input.options) option.selected = option.value === value
      input.listeners.change({ target: input })
    } else input.listeners.input({ target: input })
  }
  await nextTick()
  find(view.root, el => el.type === 'form').props.onSubmit({ preventDefault() {} })
  await settle()
}
const assertReadOnlyProductCalls = () => {
  assert.ok(calls.every(c => c.method === 'get'))
  assert.ok(calls.every(c => c.url === '/categories' || /^\/admin\/products(?:\/\d+)?$/.test(c.url)))
  assert.ok(!calls.some(c => c.url === '/admin/categories'))
  assertMemberUntouched()
}

test('商品頁替換 placeholder，列表唯讀、detail入口、分類只用公開endpoint', async () => {
  const view = await productsView()
  const main = find(view.root, el => el.type === 'main')
  assert.ok(text(main).includes(productItem.name)); assert.ok(text(main).includes('依購買規格管理'))
  assert.ok(!text(main).includes('尚未實作')); assert.ok(text(main).includes('新增商品'))
  const link = find(main, el => el.type === 'a' && el.props.href === '/admin/products/1')
  assert.ok(link); assertReadOnlyProductCalls(); view.app.unmount()
})
test('商品 initial loading，完成後顯示結果', async () => {
  let finish
  api.defaults.adapter = config => { calls.push(config); return config.url === '/categories'
    ? Promise.resolve(response(config, { data: categoryOptions }))
    : new Promise(resolve => { finish = () => resolve(response(config, productPage())) }) }
  const view = await productsView(); assert.ok(text(view.root).includes('商品載入中'))
  finish(); await settle(); assert.ok(text(view.root).includes(productItem.name)); view.app.unmount()
})
test('商品 empty 與 URL query reload／重建，切頁保留條件', async () => {
  api.defaults.adapter = async config => { calls.push(config); return response(config, config.url === '/categories'
    ? { data: categoryOptions } : productPage([], Number(config.params.page), 2)) }
  const view = await productsView('/admin/products?search=啞鈴&category_id=2&status=inactive&page=2')
  assert.ok(text(view.root).includes('沒有符合條件'))
  assert.deepEqual(calls.find(c => c.url === '/admin/products').params, { search: '啞鈴', category_id: '2', status: 'inactive', page: '2' })
  assert.equal(find(view.root, el => el.props.id === 'product-search').value, '啞鈴')
  const previous = find(view.root, el => el.type === 'button' && text(el).trim() === '上一頁')
  previous.props.onClick(); await settle()
  assert.deepEqual({ ...view.router.currentRoute.value.query }, { search: '啞鈴', category_id: '2', status: 'inactive', page: '1' })
  assertReadOnlyProductCalls(); view.app.unmount()
})
test('搜尋／分類／status 提交回 page=1，back-forward 還原條件並重新GET', async () => {
  const view = await productsView('/admin/products?search=舊條件&page=2')
  await submitProductFilters(view, { 'product-search': 'PRD-EXISTING', 'product-category': '2', 'product-status': 'inactive' })
  assert.deepEqual({ ...view.router.currentRoute.value.query }, { search: 'PRD-EXISTING', category_id: '2', status: 'inactive', page: '1' })
  assert.deepEqual(calls.filter(c => c.url === '/admin/products').at(-1).params, { search: 'PRD-EXISTING', category_id: '2', status: 'inactive', page: '1' })
  view.router.back(); await settle()
  assert.equal(view.router.currentRoute.value.query.search, '舊條件')
  assert.equal(find(view.root, el => el.props.id === 'product-search').value, '舊條件')
  view.router.forward(); await settle(); assert.equal(view.router.currentRoute.value.query.status, 'inactive')
  assertReadOnlyProductCalls(); view.app.unmount()
})
for (const status of [403, 419, undefined, 500, 422]) {
  test(`商品 ${status ?? 'network'} 不誤判停用、不自動重送，可手動 retry`, async () => {
    api.defaults.adapter = config => { calls.push(config); return config.url === '/categories'
      ? Promise.resolve(response(config, { data: categoryOptions })) : Promise.reject(failure(config, status)) }
    const view = await productsView()
    assert.ok(find(view.root, el => el.props.role === 'alert'))
    assert.equal(auth.currentAdmin.id, admin.id); assert.equal(auth.adminFailureReason, null)
    assert.equal(view.router.currentRoute.value.name, 'admin-products')
    assert.equal(calls.filter(c => c.url === '/admin/products').length, 1)
    api.defaults.adapter = async config => { calls.push(config); return response(config, productPage()) }
    await find(view.root, el => el.type === 'button' && text(el) === '重試').props.onClick(); await settle()
    assert.ok(text(view.root).includes(productItem.name))
    assert.equal(calls.filter(c => c.url === '/admin/products').length, 2)
    assertReadOnlyProductCalls(); view.app.unmount()
  })
}
for (const [status, code] of [[401, undefined], [403, 'ADMIN_ACCOUNT_DISABLED']]) {
  test(`商品 ${status}/${code ?? ''} 沿用Admin失效流程，會員不受影響`, async () => {
    api.defaults.adapter = config => { calls.push(config); return config.url === '/categories'
      ? Promise.resolve(response(config, { data: categoryOptions })) : Promise.reject(failure(config, status, code)) }
    const view = await productsView(); await settle()
    assert.equal(view.router.currentRoute.value.name, 'admin-login'); assert.equal(auth.currentAdmin, null)
    if (code) assert.ok(text(view.root).includes('後端停用原因'))
    assertReadOnlyProductCalls(); view.app.unmount()
  })
}
test('分類選項失敗可獨立重試，不請求Admin categories或清會員', async () => {
  api.defaults.adapter = config => { calls.push(config); return config.url === '/categories'
    ? Promise.reject(failure(config, 500)) : Promise.resolve(response(config, productPage())) }
  const view = await productsView(); assert.ok(text(view.root).includes('分類選項載入失敗'))
  api.defaults.adapter = async config => { calls.push(config); return response(config, { data: categoryOptions }) }
  await find(view.root, el => el.type === 'button' && text(el).trim() === '重試分類').props.onClick(); await settle()
  assert.ok(!text(view.root).includes('分類選項載入失敗')); assertReadOnlyProductCalls(); view.app.unmount()
})
test('商品查詢舊成功與舊失敗均不可覆蓋新条件結果', async () => {
  const pending = []
  api.defaults.adapter = config => { calls.push(config); return config.url === '/categories'
    ? Promise.resolve(response(config, { data: categoryOptions }))
    : new Promise((resolve, reject) => pending.push({ config, resolve, reject })) }
  const view = await productsView('/admin/products?search=old')
  await view.router.push('/admin/products?search=middle'); await settle()
  await view.router.push('/admin/products?search=new'); await settle()
  pending[2].resolve(response(pending[2].config, productPage([{ ...productItem, name: '最新結果' }])))
  await settle()
  pending[0].resolve(response(pending[0].config, productPage([{ ...productItem, name: '舊結果' }])))
  pending[1].reject(failure(pending[1].config, 500)); await settle()
  assert.ok(text(view.root).includes('最新結果')); assert.ok(!text(view.root).includes('舊結果'))
  assert.equal(find(view.root, el => el.props.role === 'alert'), undefined)
  assertReadOnlyProductCalls(); view.app.unmount()
})
test('商品 detail route唯讀顯示完整內容，inactive category仍可讀，列表query可返回', async () => {
  const view = await productsView('/admin/products/1?status=inactive&page=2')
  const main = find(view.root, el => el.type === 'main')
  for (const label of ['完整詳細文字', '短介', '器材', '啞鈴', 'inactive', '鋼材', '黑色', '庫存 3', '主圖', 'detail', '排序 2']) assert.ok(text(main).includes(label), label)
  assert.equal(find(main, el => el.type === 'img').props.src, productDetail.images[0].image_url)
  assert.ok(find(main, el => el.type === 'a' && el.props.href === '/admin/products?status=inactive&page=2'))
  assert.ok(!text(main).includes('編輯儲存')); assertReadOnlyProductCalls(); view.app.unmount()
})
test('商品詳細404呈現不可取得，reload URL仍重新GET', async () => {
  api.defaults.adapter = config => { calls.push(config); return Promise.reject(failure(config, 404)) }
  const view = await productsView('/admin/products/999'); assert.ok(text(view.root).includes('商品不存在或無法取得'))
  assert.equal(calls[0].url, '/admin/products/999'); view.app.unmount()
  api.defaults.adapter = async config => { calls.push(config); return response(config, { data: productDetail }) }
  const reopened = await productsView('/admin/products/1'); assert.ok(text(reopened.root).includes('完整詳細文字'))
  assert.equal(calls.at(-1).url, '/admin/products/1'); reopened.app.unmount(); assertReadOnlyProductCalls()
})
test('快速切換詳細id，晚到舊商品不可覆蓋目前商品', async () => {
  const pending = []
  api.defaults.adapter = config => new Promise(resolve => pending.push({ config, resolve }))
  const view = await productsView('/admin/products/1')
  await view.router.push('/admin/products/2'); await settle()
  pending[1].resolve(response(pending[1].config, { data: { ...productDetail, id: 2, name: '第二商品' } })); await settle()
  pending[0].resolve(response(pending[0].config, { data: productDetail })); await settle()
  assert.ok(text(view.root).includes('第二商品')); assert.ok(!text(view.root).includes(productItem.name))
  assertMemberUntouched(); view.app.unmount()
})

// Stage19 Step2：真實SFC + Service + Axios adapter，並非瀏覽器/DB驗收。
const inputValue = async (view, id, value) => {
  const input = find(view.root, el => el.props.id === id)
  assert.ok(input, id); input.value = String(value)
  if (input.type === 'select') {
    for (const option of input.options) option.selected = String(option.value) === String(value)
    input.listeners.change({ target: input })
  } else input.listeners.input({ target: input })
  await nextTick()
}
const button = (view, label) => find(view.root, el => el.type === 'button' && text(el).trim() === label)
const click = async (view, label) => { assert.ok(button(view, label), label); button(view, label).props.onClick(); await settle() }
const submitCore = async (view) => { find(view.root, el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await settle() }
const mutations = () => calls.filter(c => c.method !== 'get')
const coreAdapter = (handler) => async config => {
  calls.push(config)
  if (config.method !== 'get') return handler(config)
  if (config.url === '/categories') return response(config, { data: categoryOptions })
  return response(config, config.url === '/admin/products' ? productPage() : { data: productDetail })
}
const assertCoreBoundary = () => {
  assert.ok(calls.every(c => c.url === '/categories' || /^\/admin\/products(?:\/\d+(?:\/status)?)?$/.test(c.url)))
  assertMemberUntouched()
}

test('Admin mutation Service 正確四種endpoint/payload/envelope，沒有重送或會員transport', async () => {
  api.defaults.adapter = coreAdapter(c => response(c, c.method === 'delete' ? { message: '已刪' } : { data: productDetail, message: '已存' }))
  const create = { category_id: 2, name: '新增', price: 100, stock: 0, status: 'active', low_stock_threshold: 5 }
  assert.equal((await productService.createAdminProduct(create)).data.id, 1)
  await productService.updateAdminProduct('1', { name: '改名', specifications: [] })
  await productService.updateAdminProductStatus('1', { status: 'disabled' })
  assert.equal((await productService.deleteAdminProduct('1')).message, '已刪')
  assert.deepEqual(mutations().map(c => [c.method, c.url]), [['post', '/admin/products'], ['patch', '/admin/products/1'], ['patch', '/admin/products/1/status'], ['delete', '/admin/products/1']])
  assert.deepEqual(JSON.parse(calls[0].data), create); assertCoreBoundary()
})

test('create plain route：系統編號不可輸入、初始庫存/specs、submitting不重送、成功才導航', async () => {
  let finish
  api.defaults.adapter = coreAdapter(c => new Promise(resolve => { finish = () => resolve(response(c, { data: { ...productDetail, id: 10 }, message: '已建立' }, 201)) }))
  const view = await productsView('/admin/products/new')
  assert.equal(view.router.currentRoute.value.name, 'admin-product-create')
  assert.ok(text(view.root).includes('系統建立後自動產生')); assert.equal(find(view.root, el => el.props.id === 'core-code'), undefined)
  for (const [id, value] of [['core-name', '新增商品'], ['core-category', '2'], ['core-price', '99.50'], ['core-stock', '6']]) await inputValue(view, id, value)
  await click(view, '新增固定規格'); await inputValue(view, 'spec-name-0', '材質'); await inputValue(view, 'spec-value-0', '鋼')
  await submitCore(view); await submitCore(view)
  assert.equal(mutations().length, 1); assert.ok(find(view.root, el => el.type === 'fieldset').props.disabled)
  assert.equal(view.router.currentRoute.value.name, 'admin-product-create')
  const data = JSON.parse(mutations()[0].data)
  assert.equal(data.stock, 6); assert.equal(data.price, 99.5); assert.equal(data.product_code, undefined); assert.equal(data.variants, undefined)
  assert.deepEqual(data.specifications, [{ spec_name: '材質', spec_value: '鋼', sort_order: 0 }])
  finish(); await settle(); assert.equal(view.router.currentRoute.value.fullPath, '/admin/products/10')
  assertCoreBoundary(); view.app.unmount()
})

test('create variant：單軸欄位、新variant初始庫存、product stock null、spec add/remove', async () => {
  api.defaults.adapter = coreAdapter(c => response(c, { data: productDetail, message: '成功' }, 201))
  const view = await productsView('/admin/products/new')
  await inputValue(view, 'core-mode', 'variant'); await click(view, '新增購買規格')
  await inputValue(view, 'variant-name-0', '顏色'); await inputValue(view, 'variant-value-0', '黑'); await inputValue(view, 'variant-stock-0', 3)
  assert.equal(find(view.root, el => el.props.id === 'variant-stock-0').props.readonly, false)
  await click(view, '新增固定規格'); await click(view, '移除固定規格')
  await submitCore(view)
  const data = JSON.parse(mutations()[0].data)
  assert.equal(data.stock, null); assert.deepEqual(data.specifications, [])
  assert.deepEqual(data.variants, [{ option_name: '顏色', option_value: '黑', stock: 3, status: 'active' }])
  assertCoreBoundary(); view.app.unmount()
})

test('edit：舊編號/mode/既有stock唯讀，保留id、不送existing stock，新variant可初始庫存', async () => {
  let finish
  api.defaults.adapter = coreAdapter(c => new Promise(resolve => { finish = () => resolve(response(c, { data: productDetail, message: '已存' })) }))
  const view = await productsView('/admin/products/1/edit')
  assert.equal(find(view.root, el => el.props.id === 'core-code').props.readonly, '')
  assert.equal(find(view.root, el => el.props.id === 'core-code').value, 'PRD-EXISTING')
  assert.equal(find(view.root, el => el.props.id === 'core-mode'), undefined)
  assert.equal(find(view.root, el => el.props.id === 'variant-stock-0').props.readonly, true)
  assert.ok(text(view.root).includes('庫存調整請至庫存管理'))
  await click(view, '新增購買規格'); await inputValue(view, 'variant-value-1', '白色'); await inputValue(view, 'variant-stock-1', '7')
  await submitCore(view); await submitCore(view); assert.equal(mutations().length, 1)
  const data = JSON.parse(mutations()[0].data)
  for (const field of ['product_code', 'stock', 'status', 'images']) assert.equal(data[field], undefined)
  assert.equal(data.variants[0].id, 1); assert.equal(data.variants[0].stock, undefined)
  assert.equal(data.variants[1].id, undefined); assert.equal(data.variants[1].stock, 7)
  assert.equal(view.router.currentRoute.value.name, 'admin-product-edit'); finish(); await settle()
  assert.equal(view.router.currentRoute.value.name, 'admin-product-detail'); assertCoreBoundary(); view.app.unmount()
})

test('plain edit：商品stock唯讀、不送庫存；inactive目前分類不在公開選項仍可保留', async () => {
  api.defaults.adapter = async c => { calls.push(c); return response(c, c.url === '/categories' ? { data: [] }
    : { data: { ...productDetail, stock: 12, has_variants: false, variants: [] } }) }
  const view = await productsView('/admin/products/1/edit')
  assert.equal(find(view.root, el => el.props.id === 'core-stock').props.readonly, true)
  assert.ok(text(view.root).includes('目前分類，可保留'))
  api.defaults.adapter = coreAdapter(c => response(c, { data: productDetail, message: '成功' }))
  await submitCore(view)
  const data = JSON.parse(mutations()[0].data); assert.equal(data.stock, undefined); assert.equal(data.variants, undefined); assert.equal(data.category_id, 2)
  assertCoreBoundary(); view.app.unmount()
})

test('referenced variant移除422：保留draft與待刪項、後端nested原因、可復原，無假成功', async () => {
  api.defaults.adapter = coreAdapter(c => { const e = failure(c, 422); e.response.data = { message: '歷史規格不可刪除，請改為inactive', errors: { 'variants.0.option_value': ['購物車規格不可改名'] } }; throw e })
  const view = await productsView('/admin/products/1/edit')
  await inputValue(view, 'core-name', '保留輸入'); await click(view, '移除購買規格'); await submitCore(view)
  assert.equal(view.router.currentRoute.value.name, 'admin-product-edit'); assert.ok(text(view.root).includes('購物車規格不可改名'))
  assert.equal(find(view.root, el => el.props.id === 'core-name').value, '保留輸入')
  assert.ok(text(view.root).includes('待刪除：顏色／黑色')); await click(view, '保留此規格')
  assert.equal(find(view.root, el => el.props.id === 'variant-value-0').value, '黑色')
  assert.equal(mutations().length, 1); assertCoreBoundary(); view.app.unmount()
})

for (const status of ['active', 'inactive', 'disabled']) {
  test(`status ${status}：使用後端Resource、提交前不optimistic、重送防護`, async () => {
    let finish
    const original = status === 'inactive' ? 'active' : 'inactive'
    api.defaults.adapter = async c => { calls.push(c); if (c.method === 'get') return response(c, { data: { ...productDetail, status: original } })
      return new Promise(resolve => { finish = () => resolve(response(c, { data: { ...productDetail, status }, message: '狀態已更新' })) }) }
    const view = await productsView('/admin/products/1')
    const label = { active: '設為上架', inactive: '設為下架', disabled: '設為停用' }[status]
    await click(view, label); await click(view, label)
    assert.equal(mutations().length, 1); assert.equal(find(view.root, el => el.type === 'dd' && text(el) === ({ active: '上架', inactive: '下架' }[original])).text, ({ active: '上架', inactive: '下架' }[original]))
    assert.deepEqual(JSON.parse(mutations()[0].data), { status }); finish(); await settle()
    assert.ok(button(view, label).props.disabled); assert.ok(text(view.root).includes('狀態已更新'))
    assertCoreBoundary(); view.app.unmount()
  })
}

test('DELETE：明確cart警告，放棄不送，確認成功才回列表', async () => {
  let finish
  api.defaults.adapter = coreAdapter(c => new Promise(resolve => { finish = () => resolve(response(c, { message: '已刪' })) }))
  const view = await productsView('/admin/products/1?status=inactive&page=2')
  await click(view, '刪除商品'); assert.ok(text(view.root).includes('會員購物車項目'))
  await click(view, '放棄'); assert.equal(mutations().length, 0)
  await click(view, '刪除商品'); await click(view, '確認刪除'); await click(view, '確認刪除')
  assert.equal(mutations().length, 1); assert.equal(view.router.currentRoute.value.name, 'admin-product-detail')
  finish(); await settle(); assert.equal(view.router.currentRoute.value.fullPath, '/admin/products?status=inactive&page=2')
  assertCoreBoundary(); view.app.unmount()
})

test('歷史order DELETE 422：顯示原因、保留商品/URL，不自動下架', async () => {
  api.defaults.adapter = coreAdapter(c => { const e = failure(c, 422); e.response.data.message = '商品已有歷史訂單，不能實體刪除'; throw e })
  const view = await productsView('/admin/products/1')
  await click(view, '刪除商品'); await click(view, '確認刪除')
  assert.equal(view.router.currentRoute.value.name, 'admin-product-detail'); assert.ok(text(view.root).includes('商品已有歷史訂單'))
  assert.ok(text(view.root).includes(productItem.name)); assert.equal(mutations().length, 1); assertCoreBoundary(); view.app.unmount()
})

for (const [status, code] of [[401], [403, 'ADMIN_ACCOUNT_DISABLED'], [403], [419], [undefined], [500]]) {
  for (const action of ['form', 'status', 'delete']) {
    test(`${action} mutation ${status ?? 'network'}/${code ?? ''}：coordinator邊界、保留原值、不retry/不清member`, async () => {
      api.defaults.adapter = coreAdapter(c => Promise.reject(failure(c, status, code)))
      const view = await productsView(action === 'form' ? '/admin/products/1/edit' : '/admin/products/1')
      if (action === 'form') await submitCore(view)
      else if (action === 'status') await click(view, '設為停用')
      else { await click(view, '刪除商品'); await click(view, '確認刪除') }
      await settle(); assert.equal(mutations().length, 1)
      if (status === 401 || code) { assert.equal(auth.currentAdmin, null); assert.equal(view.router.currentRoute.value.name, 'admin-login') }
      else {
        assert.equal(auth.currentAdmin.id, admin.id); assert.notEqual(view.router.currentRoute.value.name, 'admin-login')
        assert.ok(text(view.root).includes(status ? '後端停用原因' : '稍後再試'))
        if (action === 'status') assert.equal(button(view, '設為下架').props.disabled, true)
        if (action === 'form') assert.equal(find(view.root, el => el.props.id === 'core-name').value, productItem.name)
      }
      assertCoreBoundary(); view.app.unmount()
    })
  }
}

test('status舊回應晚到，不覆蓋另一張詳細商品', async () => {
  let finish
  api.defaults.adapter = async c => { calls.push(c); return c.method !== 'get'
    ? new Promise(resolve => { finish = () => resolve(response(c, { data: { ...productDetail, status: 'disabled' }, message: '舊結果' })) })
    : response(c, { data: c.url.endsWith('/2') ? { ...productDetail, id: 2, name: '第二商品' } : productDetail }) }
  const view = await productsView('/admin/products/1'); await click(view, '設為停用')
  await view.router.push('/admin/products/2'); await settle(); finish(); await settle()
  assert.ok(text(view.root).includes('第二商品')); assert.ok(!text(view.root).includes('舊結果'))
  assertCoreBoundary(); view.app.unmount()
})

test('edit舊save回應晚到，不導航或覆蓋另一張form', async () => {
  let finish
  api.defaults.adapter = async c => { calls.push(c); if (c.method !== 'get') return new Promise(resolve => { finish = () => resolve(response(c, { data: productDetail, message: '舊儲存' })) })
    return response(c, c.url === '/categories' ? { data: categoryOptions } : { data: { ...productDetail, id: c.url.endsWith('/2') ? 2 : 1 } }) }
  const view = await productsView('/admin/products/1/edit'); await submitCore(view)
  await view.router.push('/admin/products/2/edit'); await settle(); finish(); await settle()
  assert.equal(view.router.currentRoute.value.fullPath, '/admin/products/2/edit'); assertCoreBoundary(); view.app.unmount()
})

test('create nested422：保留新增欄位、顯示variant原因、不導航或重送', async () => {
  api.defaults.adapter = coreAdapter(c => { const e = failure(c, 422); e.response.data = { message: '規格驗證失敗', errors: { 'variants.0.option_value': ['選項值不可重複'] } }; throw e })
  const view = await productsView('/admin/products/new')
  await inputValue(view, 'core-name', '草稿商品'); await inputValue(view, 'core-mode', 'variant'); await click(view, '新增購買規格')
  await inputValue(view, 'variant-name-0', '顏色'); await inputValue(view, 'variant-value-0', '黑')
  await submitCore(view); assert.ok(text(view.root).includes('選項值不可重複'))
  assert.equal(find(view.root, el => el.props.id === 'core-name').value, '草稿商品')
  assert.equal(find(view.root, el => el.props.id === 'variant-value-0').value, '黑')
  assert.equal(view.router.currentRoute.value.name, 'admin-product-create'); assert.equal(mutations().length, 1)
  assertCoreBoundary(); view.app.unmount()
})

test('表單load失敗手動GET重試，不送mutation', async () => {
  api.defaults.adapter = c => { calls.push(c); return Promise.reject(failure(c, 500)) }
  const view = await productsView('/admin/products/1/edit')
  assert.ok(text(view.root).includes('後端停用原因')); assert.equal(find(view.root, el => el.type === 'form'), undefined)
  api.defaults.adapter = coreAdapter(c => { throw Error('unexpected mutation') })
  await click(view, '重試'); assert.equal(find(view.root, el => el.props.id === 'core-name').value, productItem.name)
  assert.equal(mutations().length, 0); assertCoreBoundary(); view.app.unmount()
})

const imageRow = (id, primary = false, order = 0, type = 'gallery') => ({ id, image_path: `products/1/test-${id}.jpg`,
  image_url: `http://localhost/storage/products/1/test-${id}.jpg`, image_type: type, is_primary: primary, sort_order: order })
const imageSection = view => find(view.root, el => el.props['aria-labelledby'] === 'product-images-title')
const imageButton = (view, label) => find(imageSection(view), el => el.type === 'button' && text(el).trim() === label)
const imageClick = async (view, label) => { const b = imageButton(view, label); assert.ok(b, label); b.props.onClick(); await settle() }
const selectImageFile = async (view, file = new File(['fixture bytes'], 'browser-original.png', { type: 'image/png' })) => {
  const input = find(view.root, el => el.props.id === 'image-file'); input.files = [file]; input.props.onChange({ target: input }); await nextTick()
}
const submitImage = async view => { find(imageSection(view), el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await settle() }
const imageAdapter = (rows, handler) => async c => {
  calls.push(c)
  if (c.method !== 'get') return handler(c)
  return response(c, c.url === '/categories' ? { data: categoryOptions } : { data: { ...productDetail, images: rows() } })
}
const assertImageBoundary = () => {
  assert.ok(calls.every(c => c.url === '/categories' || /^\/admin\/products(?:\/\d+(?:\/images)?)?$/.test(c.url) || /^\/admin\/product-images\/\d+$/.test(c.url)))
  assertMemberUntouched()
}

test('create說明建立後管理圖片，核心payload無binary；edit顯示thumbnail/metadata/primary', async () => {
  const create = await productsView('/admin/products/new')
  assert.ok(text(create.root).includes('請先建立商品，再於編輯頁管理圖片')); assert.equal(find(create.root, el => el.props.id === 'image-file'), undefined)
  create.app.unmount()
  const edit = await productsView('/admin/products/1/edit')
  const section = imageSection(edit); assert.ok(section); assert.ok(text(section).includes('主圖／detail／排序 2'))
  assert.equal(find(section, el => el.type === 'img').props.src, productDetail.images[0].image_url)
  assert.equal(find(section, el => el.props.id === 'image-file').props.accept, 'image/jpeg,image/png,image/webp')
  assert.equal(mutations().length, 0); assertImageBoundary(); edit.app.unmount()
})

test('image Service：native FormData/POST、PATCH/DELETE端點，無手動multipart boundary', async () => {
  api.defaults.adapter = async c => { calls.push(c); return response(c, { data: imageRow(1, true), message: '完成' }) }
  const file = new File(['bytes'], 'image.webp', { type: 'image/webp' })
  await productService.uploadAdminProductImage(1, { image: file, image_type: 'detail', sort_order: 7, is_primary: true })
  assert.ok(calls[0].data instanceof FormData); assert.equal(calls[0].data.get('image').name, 'image.webp')
  assert.equal(calls[0].data.get('image_type'), 'detail'); assert.equal(calls[0].data.get('sort_order'), '7'); assert.equal(calls[0].data.get('is_primary'), '1')
  assert.ok(!String(calls[0].headers.get('Content-Type')).includes('boundary='))
  await productService.updateAdminProductImage(1, { sort_order: 2, image_type: 'gallery' })
  await productService.deleteAdminProductImage(1)
  assert.deepEqual(calls.map(c => [c.method, c.url]), [['post', '/admin/products/1/images'], ['patch', '/admin/product-images/1'], ['delete', '/admin/product-images/1']])
  assertImageBoundary()
})

test('upload防重送/无optimistic：成功後GET权威图片集合，新图可为唯一primary', async () => {
  let rows = [imageRow(1, true)], finish
  api.defaults.adapter = imageAdapter(() => rows, c => new Promise(resolve => { finish = () => { rows = [imageRow(1), imageRow(2, true, 6, 'detail')]; resolve(response(c, { data: rows[1], message: '圖片上傳成功' }, 201)) } }))
  const view = await productsView('/admin/products/1/edit'); await selectImageFile(view)
  await inputValue(view, 'image-upload-type', 'detail'); await inputValue(view, 'image-upload-order', 6); await inputValue(view, 'image-upload-primary', '1')
  await submitImage(view); await submitImage(view)
  assert.equal(mutations().length, 1); assert.equal(find(imageSection(view), el => el.type === 'img').props.src, rows[0].image_url)
  assert.ok(find(imageSection(view), el => el.type === 'fieldset').props.disabled)
  assert.equal(mutations()[0].data.get('sort_order'), '6')
  finish(); await settle(); assert.ok(text(imageSection(view)).includes('主圖／detail／排序 6'))
  assert.ok(text(imageSection(view)).includes('非主圖／gallery／排序 0'))
  assert.equal(calls.filter(c => c.url === '/admin/products/1' && c.method === 'get').length, 2)
  assert.equal(find(view.root, el => el.props.id === 'image-file').value, '')
  assertImageBoundary(); view.app.unmount()
})

test('metadata防重送、draft未當正式值；成功GET後更新類型/排序', async () => {
  let rows = [imageRow(1, true)], finish
  api.defaults.adapter = imageAdapter(() => rows, c => new Promise(resolve => { finish = () => { rows = [imageRow(1, true, 8, 'detail')]; resolve(response(c, { data: rows[0], message: '更新成功' })) } }))
  const view = await productsView('/admin/products/1/edit')
  await inputValue(view, 'image-type-1', 'detail'); await inputValue(view, 'image-order-1', 8)
  await imageClick(view, '儲存圖片資料'); await imageClick(view, '儲存圖片資料')
  assert.equal(mutations().length, 1); assert.deepEqual(JSON.parse(mutations()[0].data), { image_type: 'detail', sort_order: 8 })
  assert.ok(text(imageSection(view)).includes('主圖／gallery／排序 0')); finish(); await settle()
  assert.ok(text(imageSection(view)).includes('主圖／detail／排序 8')); assertImageBoundary(); view.app.unmount()
})

test('設主圖成功才呈現唯一主圖，不由client猜測其他圖狀態', async () => {
  let rows = [imageRow(1, true), imageRow(2)], finish
  api.defaults.adapter = imageAdapter(() => rows, c => new Promise(resolve => { finish = () => { rows = [imageRow(1), imageRow(2, true)]; resolve(response(c, { data: rows[1], message: '主圖更新成功' })) } }))
  const view = await productsView('/admin/products/1/edit')
  const buttons = []
  const collect = el => { if (el.type === 'button' && text(el).trim() === '設為主圖') buttons.push(el); el.children.forEach(collect) }; collect(imageSection(view))
  assert.equal(buttons[0].props.disabled, true); buttons[1].props.onClick(); await settle()
  assert.deepEqual(JSON.parse(mutations()[0].data), { is_primary: true }); assert.equal(buttons[0].props.disabled, true)
  finish(); await settle(); assert.equal(buttons[0].props.disabled, false); assert.equal(buttons[1].props.disabled, true)
  assertImageBoundary(); view.app.unmount()
})

test('delete確認/放棄；primary刪除後GET遞補，last刪除合法empty', async () => {
  let rows = [imageRow(1, true), imageRow(2)]
  api.defaults.adapter = imageAdapter(() => rows, c => { rows = c.url.endsWith('/1') ? [imageRow(2, true)] : []; return response(c, { message: '圖片已刪除' }) })
  const view = await productsView('/admin/products/1/edit')
  await imageClick(view, '刪除圖片'); assert.ok(text(imageSection(view)).includes('確定刪除此圖片'))
  await imageClick(view, '放棄刪圖'); assert.equal(mutations().length, 0)
  await imageClick(view, '刪除圖片'); await imageClick(view, '確認刪圖')
  assert.equal(mutations()[0].url, '/admin/product-images/1'); assert.ok(imageButton(view, '設為主圖').props.disabled)
  assert.equal(find(imageSection(view), el => el.type === 'img').props.src, rows[0].image_url)
  await imageClick(view, '刪除圖片'); await imageClick(view, '確認刪圖')
  assert.equal(mutations().length, 2); assert.ok(text(imageSection(view)).includes('沒有圖片。'))
  assertImageBoundary(); view.app.unmount()
})

test('upload 422 nested errors保留原圖/檔案選擇，不假成功', async () => {
  api.defaults.adapter = imageAdapter(() => [imageRow(1, true)], c => { const e = failure(c, 422); e.response.data = { message: '圖片驗證失敗', errors: { image: ['圖片格式不符'], sort_order: ['排序必須非負'] } }; throw e })
  const view = await productsView('/admin/products/1/edit'); await selectImageFile(view); await submitImage(view)
  assert.ok(text(imageSection(view)).includes('圖片格式不符')); assert.ok(text(imageSection(view)).includes('排序必須非負'))
  assert.equal(find(imageSection(view), el => el.type === 'img').props.src, imageRow(1).image_url); assert.equal(mutations().length, 1)
  assertImageBoundary(); view.app.unmount()
})

for (const file of [new File(['bad'], 'image.gif', { type: 'image/gif' }), new File(new Uint8Array(5 * 1024 * 1024 + 1), 'large.png', { type: 'image/png' })]) {
  test(`upload frontend UX ${file.name}阻擋，但不宣稱代替後端MIME驗證`, async () => {
    const view = await productsView('/admin/products/1/edit'); await selectImageFile(view, file); await submitImage(view)
    assert.ok(text(imageSection(view)).includes('單張最大 5 MiB')); assert.equal(mutations().length, 0); assertImageBoundary(); view.app.unmount()
  })
}

for (const [status, code] of [[401], [403, 'ADMIN_ACCOUNT_DISABLED'], [403], [419], [undefined], [500]]) {
  for (const action of ['upload', 'metadata', 'delete']) {
    test(`image ${action} ${status ?? 'network'}/${code ?? ''}：失敗保留/coordinator/不retry/會員隔離`, async () => {
      api.defaults.adapter = imageAdapter(() => [imageRow(1, true)], c => Promise.reject(failure(c, status, code)))
      const view = await productsView('/admin/products/1/edit')
      if (action === 'upload') { await selectImageFile(view); await submitImage(view) }
      else if (action === 'metadata') await imageClick(view, '儲存圖片資料')
      else { await imageClick(view, '刪除圖片'); await imageClick(view, '確認刪圖') }
      await settle(); assert.equal(mutations().length, 1)
      if (status === 401 || code) { assert.equal(auth.currentAdmin, null); assert.equal(view.router.currentRoute.value.name, 'admin-login') }
      else { assert.equal(auth.currentAdmin.id, admin.id); assert.equal(view.router.currentRoute.value.name, 'admin-product-edit'); assert.ok(find(imageSection(view), el => el.type === 'img')); assert.ok(text(imageSection(view)).includes(status ? '後端停用原因' : '稍後再試')) }
      assertImageBoundary(); view.app.unmount()
    })
  }
}

test('mutation成功但GET失敗：標示舊資料、只手動重試GET，不重送upload', async () => {
  let rows = [imageRow(1, true)], failRefresh = false
  api.defaults.adapter = async c => {
    calls.push(c)
    if (c.method === 'post') { rows = [imageRow(1, true), imageRow(2)]; failRefresh = true; return response(c, { data: rows[1], message: '圖片上傳成功' }) }
    if (c.url === '/categories') return response(c, { data: categoryOptions })
    if (failRefresh) throw failure(c, 500)
    return response(c, { data: { ...productDetail, images: rows } })
  }
  const view = await productsView('/admin/products/1/edit'); await selectImageFile(view); await submitImage(view)
  assert.ok(text(imageSection(view)).includes('畫面仍是先前圖片資料')); assert.equal(mutations().length, 1)
  await submitImage(view); assert.equal(mutations().length, 1)
  failRefresh = false; await imageClick(view, '重新載入圖片')
  assert.ok(!text(imageSection(view)).includes('畫面仍是先前圖片資料')); assert.equal(mutations().length, 1)
  assertImageBoundary(); view.app.unmount()
})

test('舊upload晚到不覆蓋新商品圖片，不發舊商品refresh GET', async () => {
  let finish
  api.defaults.adapter = async c => { calls.push(c); if (c.method === 'post') return new Promise(resolve => { finish = () => resolve(response(c, { data: imageRow(9), message: '舊上傳' })) })
    return response(c, c.url === '/categories' ? { data: categoryOptions } : { data: { ...productDetail, id: c.url.endsWith('/2') ? 2 : 1, images: [imageRow(c.url.endsWith('/2') ? 20 : 1, true)] } }) }
  const view = await productsView('/admin/products/1/edit'); await selectImageFile(view); await submitImage(view)
  await view.router.push('/admin/products/2/edit'); await settle(); finish(); await settle()
  assert.equal(find(imageSection(view), el => el.type === 'img').props.src, imageRow(20).image_url)
  assert.ok(!text(view.root).includes('舊上傳')); assert.equal(calls.filter(c => c.url === '/admin/products/1' && c.method === 'get').length, 1)
  assertImageBoundary(); view.app.unmount()
})
