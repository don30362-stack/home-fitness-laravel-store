import assert from 'node:assert/strict'
import { test, after } from 'node:test'
import { createServer } from 'vite'
import axios from 'axios'
import { createPinia, setActivePinia } from 'pinia'
import { createSSRApp } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createRouter, createMemoryHistory } from 'vue-router'

// 使用現有 Vite 載入真實 TS／Vue 模組，不安裝額外測試框架。
const storage = new Map()
globalThis.localStorage = {
  getItem: (key) => storage.get(key) ?? null,
  setItem: (key, value) => storage.set(key, value),
  removeItem: (key) => storage.delete(key),
}
const server = await createServer({ server: { middlewareMode: true }, appType: 'custom' })
const { default: api } = await server.ssrLoadModule('/src/services/api.ts')
const state = await server.ssrLoadModule('/src/services/sessionState.ts')
const { createSessionNavigation, shouldRedirectSessionFailure } = await server.ssrLoadModule('/src/services/sessionNavigation.ts')
const { useAuthStore } = await server.ssrLoadModule('/src/stores/auth.ts')
const { useCartStore } = await server.ssrLoadModule('/src/stores/cart.ts')
const { default: LoginView } = await server.ssrLoadModule('/src/views/LoginView.vue')
const originalError = console.error
console.error = () => {}
after(async () => { console.error = originalError; await server.close() })

const user = { id: 1, name: '測試會員', email: 'c06@example.test', phone: '0912345678', status: 'active' }
const cart = { items: [], item_count: 0, has_unavailable_items: false }
const guestKey = 'home-fitness-store-guest-cart'
const guestJson = JSON.stringify([{ key: 'preserved-guest', product_id: 99, quantity: 1 }])
let auth, carts, pinia, notifications, calls
const response = (config, data, status = 200) => ({ config, data, status, statusText: '', headers: {} })
const reject = (config, status, code) => Promise.reject(new axios.AxiosError(
  'test error', 'ERR_BAD_RESPONSE', config, undefined,
  response(config, { message: 'test message', ...(code ? { code } : {}) }, status),
))
const disabled = (config) => reject(config, 403, 'ACCOUNT_DISABLED')
const setup = () => {
  storage.clear(); storage.set(guestKey, guestJson)
  pinia = createPinia(); setActivePinia(pinia)
  auth = useAuthStore(pinia); carts = useCartStore(pinia)
  auth.currentUser = { ...user }; carts.memberCart = { ...cart }
  state.startSessionVersion(); notifications = []; calls = []
  state.setSessionFailureHandler((reason, redirect) => {
    notifications.push({ reason, redirect }); auth.resetMemberSession(reason)
  })
  axios.defaults.adapter = async (config) => response(config, {}, 204)
  api.defaults.adapter = async (config) => { calls.push(config); return response(config, { data: user }) }
}
const assertCleared = () => {
  assert.equal(auth.currentUser, null); assert.equal(carts.memberCart, null)
  assert.equal(storage.get(guestKey), guestJson)
  assert.equal(carts.guestItems.length, 1)
}

test('訪客啟動 /me 的預期 401 不強制離開公開頁；停用與真正過期仍導頁', () => {
  assert.equal(shouldRedirectSessionFailure('expired', false, false), false)
  assert.equal(shouldRedirectSessionFailure('expired', true, false), true)
  assert.equal(shouldRedirectSessionFailure('expired', false, true), true)
  assert.equal(shouldRedirectSessionFailure('disabled', false, false), true)
})

test('本機清理保留訪客車且不送刪除／logout API', () => {
  setup(); auth.resetMemberSession('disabled'); auth.resetMemberSession('expired')
  assertCleared(); assert.equal(auth.authFailureReason, 'disabled'); assert.equal(calls.length, 0)
})

test('並行 ACCOUNT_DISABLED 只處理一次，後到 401／419 不蓋停用原因、不重送', async () => {
  setup()
  api.defaults.adapter = (config) => { calls.push(config); return config.url === '/cart' ? reject(config, 401) : config.url === '/checkout' ? reject(config, 419) : disabled(config) }
  await Promise.allSettled([api.get('/me'), api.get('/addresses'), api.get('/cart'), api.post('/checkout', {})])
  assertCleared(); assert.equal(auth.authFailureReason, 'disabled')
  assert.deepEqual(notifications, [{ reason: 'disabled', redirect: true }]); assert.equal(calls.length, 4)
})

test('同批先 401 後停用 403 可提升原因，後到的錯誤不降級', async () => {
  setup()
  api.defaults.adapter = (config) => config.url === '/cart' ? reject(config, 401) : disabled(config)
  await Promise.allSettled([api.get('/cart'), api.get('/me')])
  assertCleared(); assert.equal(auth.authFailureReason, 'disabled')
  assert.deepEqual(notifications.map((event) => event.reason), ['expired', 'disabled'])
})

for (const status of [403, 419, 500, 503]) {
  test(`${status} 一般錯誤不清理會員也不視為停用`, async () => {
    setup(); api.defaults.adapter = (config) => reject(config, status)
    await assert.rejects(api.get('/me'))
    assert.equal(auth.currentUser.id, user.id); assert.ok(carts.memberCart)
    assert.equal(notifications.length, 0); assert.equal(auth.authFailureReason, null)
  })
}

test('網路錯誤不當成停用', async () => {
  setup(); api.defaults.adapter = (config) => Promise.reject(new axios.AxiosError('network', 'ERR_NETWORK', config))
  await assert.rejects(api.get('/me')); assert.equal(notifications.length, 0); assert.ok(auth.currentUser)
})

test('會員 401 清理且通知導頁，login 401 只由登入頁處理', async () => {
  setup(); api.defaults.adapter = (config) => reject(config, 401)
  await assert.rejects(api.post('/login', {})); assert.equal(notifications.length, 0)
  await assert.rejects(api.get('/me')); assertCleared()
  assert.deepEqual(notifications, [{ reason: 'expired', redirect: true }])
})

test('login ACCOUNT_DISABLED 清理並保留原因，不要求再導頁', async () => {
  setup(); api.defaults.adapter = disabled
  await assert.rejects(auth.login({ email: user.email, password: 'password' }))
  assertCleared(); assert.deepEqual(notifications, [{ reason: 'disabled', redirect: false }])
})

test('停用後晚到的成功資料不可恢復個資／會員車或清除訪客車', async () => {
  setup(); let finish
  api.defaults.adapter = (config) => config.url === '/me' ? disabled(config) : new Promise((resolve) => { finish = () => resolve(response(config, { data: cart })) })
  const merge = carts.mergeGuestCart()
  await assert.rejects(api.get('/me'))
  finish(); await assert.rejects(merge, state.SessionInvalidatedError)
  assertCleared()
})

test('新登入成功後，舊登入世代的失敗不能登出新會員', async () => {
  setup(); let finish
  api.defaults.adapter = (config) => new Promise((_resolve, rejectRequest) => {
    finish = () => rejectRequest(new axios.AxiosError('old error', 'ERR_BAD_RESPONSE', config, undefined, response(config, { code: 'ACCOUNT_DISABLED' }, 403)))
  })
  const old = api.get('/me'); await new Promise((resolve) => setImmediate(resolve))
  api.defaults.adapter = async (config) => response(config, { data: user })
  await auth.login({ email: user.email, password: 'password' })
  finish(); await assert.rejects(old)
  assert.equal(auth.currentUser.id, user.id); assert.equal(auth.authFailureReason, null); assert.equal(notifications.length, 0)
})

for (const action of ['restoreAuth', 'logout']) {
  test(`新登入後晚到的 ${action} 401 不清除新會員`, async () => {
    setup(); let finish
    api.defaults.adapter = (config) => new Promise((_resolve, rejectRequest) => {
      finish = () => rejectRequest(new axios.AxiosError('old 401', 'ERR_BAD_RESPONSE', config, undefined, response(config, {}, 401)))
    })
    const old = auth[action](); await new Promise((resolve) => setImmediate(resolve))
    api.defaults.adapter = async (config) => response(config, { data: user })
    await auth.login({ email: user.email, password: 'password' })
    finish(); await old
    assert.equal(auth.currentUser.id, user.id); assert.ok(carts.memberCart)
    assert.equal(auth.authFailureReason, null); assert.equal(notifications.length, 0)
  })
}

test('restoreAuth 的 /me 或購物車收到停用時保持未登入與停用原因', async () => {
  for (const endpoint of ['/me', '/cart']) {
    setup(); api.defaults.adapter = (config) => config.url === endpoint ? disabled(config) : Promise.resolve(response(config, { data: user }))
    await auth.restoreAuth(); assertCleared(); assert.equal(auth.authFailureReason, 'disabled'); assert.equal(auth.isAuthInitialized, true)
  }
})

test('正常登入／logout 不受影響，已撤銷 logout 401 不覆蓋停用原因', async () => {
  setup(); auth.resetMemberSession('disabled')
  await auth.login({ email: user.email, password: 'password' }); assert.ok(auth.currentUser); assert.equal(auth.authFailureReason, null)
  assert.equal(await auth.logout(), true); assertCleared()
  auth.resetMemberSession('disabled'); api.defaults.adapter = (config) => reject(config, 401)
  assert.equal(await auth.logout(), false); assert.equal(auth.authFailureReason, 'disabled'); assertCleared()
})

test('啟動前先保存導頁，ready 後只導一次；登入頁 SSR 顯示停用原因', async () => {
  setup()
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/member', name: 'member', component: { template: '<div>member</div>' } },
    { path: '/login', name: 'login', component: LoginView },
    { path: '/register', name: 'register', component: { template: '<div>register</div>' } },
  ] })
  await router.push('/member'); await router.isReady()
  const navigation = createSessionNavigation(router)
  let replacements = 0
  const replace = router.replace.bind(router)
  router.replace = (...args) => { ++replacements; return replace(...args) }
  state.setSessionFailureHandler((reason, redirect) => { auth.resetMemberSession(reason); if (redirect) navigation.request() })
  api.defaults.adapter = disabled
  await Promise.allSettled([api.get('/me'), api.get('/cart')])
  assert.equal(router.currentRoute.value.name, 'member')
  await navigation.ready(); assert.equal(router.currentRoute.value.name, 'login'); assert.equal(replacements, 1)
  navigation.request(); await new Promise((resolve) => setImmediate(resolve)); assert.equal(replacements, 1)
  const app = createSSRApp(LoginView); app.use(pinia); app.use(router)
  const html = await renderToString(app)
  assert.ok(html.includes(state.ACCOUNT_DISABLED_MESSAGE)); assert.ok(html.includes('role="alert"'))
})
