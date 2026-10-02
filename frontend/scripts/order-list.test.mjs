import assert from 'node:assert/strict'
import { test, after, beforeEach } from 'node:test'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import { createRenderer, nextTick, h } from 'vue'
import { createRouter, createMemoryHistory, RouterView } from 'vue-router'
import { createPinia } from 'pinia'
import axios from 'axios'

// 既有 Vue compiler + Vite 載入真實 SFC 的 client render；不新增測試框架。
globalThis.localStorage = { getItem: () => null, setItem: () => {}, removeItem: () => {} }
const views = {
  'virtual:order-list': '/src/views/MemberOrderListView.vue',
  'virtual:order-pagination': '/src/components/common/AppPagination.vue',
  'virtual:order-detail': '/src/views/MemberOrderDetailView.vue',
  'virtual:checkout': '/src/views/CheckoutView.vue',
}
const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', plugins: [{
  name: 'order-list-test-sfc', enforce: 'pre',
  resolveId(id) {
    if (id in views) return '\0' + id + '.ts'
    if (id.endsWith('/AppPagination.vue')) return '\0virtual:order-pagination.ts'
  },
  async load(id) {
    const file = views[id.replace(/^\0/, '').replace(/\.ts$/, '')]
    if (!file) return
    const source = await readFile(new URL('..' + file, import.meta.url), 'utf8')
    return ts.transpileModule(compileScript(parse(source).descriptor, { id, inlineTemplate: true }).content,
      { compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.ESNext } }).outputText
  },
}] })
const { default: api } = await server.ssrLoadModule('/src/services/api.ts')
const { default: List } = await server.ssrLoadModule('virtual:order-list')
const { default: Detail } = await server.ssrLoadModule('virtual:order-detail')
const { default: Checkout } = await server.ssrLoadModule('virtual:checkout')
const { useAuthStore } = await server.ssrLoadModule('/src/stores/auth.ts')
const { useCartStore } = await server.ssrLoadModule('/src/stores/cart.ts')
const sessionState = await server.ssrLoadModule('/src/services/sessionState.ts')
beforeEach(() => {
  sessionState.startSessionVersion()
  sessionState.setSessionFailureHandler(() => {})
})
const { default: Member } = await server.ssrLoadModule('/src/views/MemberView.vue')
const originalError = console.error
console.error = () => {}
after(async () => { console.error = originalError; await server.close() })

// 表單 v-model 需要事件及 options；仍是記憶體 host，並非瀏覽器 DOM。
const node = (type, text = '') => ({
  type, tagName: type.toUpperCase(), text, props: {}, children: [], parent: null,
  value: '', listeners: {},
  addEventListener(event, handler) { this.listeners[event] = handler },
  removeEventListener(event) { delete this.listeners[event] },
  get options() { return this.children.filter((child) => child.type === 'option') },
})
const renderer = createRenderer({
  createElement: (type) => node(type), createText: (text) => node('text', text), createComment: () => node('comment'),
  setText: (el, text) => { el.text = text }, setElementText: (el, text) => { el.text = text; el.children = [] },
  patchProp: (el, key, _old, value) => {
    el.props[key] = value
    if (key === 'value') { el.value = value; el._value = value }
    if (key === 'multiple') el.multiple = value
  },
  insert(el, parent, anchor = null) {
    if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1)
    el.parent = parent
    const index = anchor ? parent.children.indexOf(anchor) : -1
    if (index < 0) parent.children.push(el); else parent.children.splice(index, 0, el)
  },
  remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1) },
  parentNode: (el) => el.parent, nextSibling: (el) => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
})
const text = (el) => el.text + el.children.map(text).join('')
const find = (el, predicate) => predicate(el) ? el : el.children.map((child) => find(child, predicate)).find(Boolean)
const settle = async () => { await new Promise((resolve) => setImmediate(resolve)); await nextTick() }
const summary = (id) => ({ id, order_no: 'HF-TEST-' + id, created_at: '2026-10-01T00:00:00Z', subtotal: '100.00', shipping_fee: '100.00', total_amount: '200.00', payment_method: 'cod', payment_status: 'unpaid', order_status: 'pending' })
const pageData = (page = 1, items = [summary(page)]) => ({ data: items, links: { first: '', last: '', prev: null, next: null }, meta: { current_page: page, last_page: 2, per_page: 10, total: 11 } })
const reply = (config, data) => ({ config, data, status: 200, statusText: 'OK', headers: {} })
const mount = async (url = '/member/orders', initialize = () => {}) => {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/member/orders', name: 'member-orders', component: List },
    { path: '/member/orders/:id', name: 'member-order-detail', component: Detail },
    { path: '/checkout', name: 'checkout', component: Checkout },
    { path: '/cart', name: 'cart', component: { template: '<div />' } },
    { path: '/products', name: 'products', component: { template: '<div />' } },
    { path: '/products/:id', name: 'product-detail', component: { template: '<div />' } },
    { path: '/', name: 'home', component: { template: '<div />' } },
  ] })
  await router.push(url); await router.isReady()
  const root = node('root')
  const app = renderer.createApp({ render: () => h(RouterView) }); const pinia = createPinia(); app.use(pinia); initialize(pinia); app.use(router); app.mount(root)
  return { root, router, app }
}

test('真實列表頁 loading → 資料，service 只呼叫 orders／page，切頁及重建頁面保留 query', async () => {
  let finish
  const calls = []
  api.defaults.adapter = (config) => {
    calls.push(config)
    return new Promise((resolve) => { finish = () => resolve(reply(config, pageData(Number(config.params.page ?? 1)))) })
  }
  const view = await mount('/member/orders?page=2')
  await settle(); assert.ok(text(view.root).includes('訂單載入中'))
  assert.equal(calls[0].url, '/orders'); assert.equal(calls[0].params.page, '2')
  finish(); await settle()
  assert.ok(text(view.root).includes('HF-TEST-2')); assert.ok(text(view.root).includes('貨到付款'))
  assert.equal(find(view.root, (el) => el.type === 'nav').props['aria-label'], '訂單分頁')
  const previous = find(view.root, (el) => el.type === 'button' && text(el).trim() === '上一頁')
  previous.props.onClick(); await settle()
  assert.equal(view.router.currentRoute.value.query.page, '1'); assert.equal(calls[1].params.page, '1')
  finish(); await settle(); assert.ok(text(view.root).includes('HF-TEST-1'))
  const url = view.router.currentRoute.value.fullPath; view.app.unmount()
  const reload = await mount(url); await settle(); assert.equal(calls[2].params.page, '1')
  finish(); await settle(); assert.ok(text(reload.root).includes('HF-TEST-1')); reload.app.unmount()
})

test('空列表呈現', async () => {
  api.defaults.adapter = async (config) => reply(config, { ...pageData(1, []), meta: { current_page: 1, last_page: 1, per_page: 10, total: 0 } })
  const view = await mount(); await settle()
  assert.ok(text(view.root).includes('目前這一頁沒有訂單'))
  view.app.unmount()
})

test('超出末頁時沿用商品列表模式修正 URL 並讀取末頁', async () => {
  const pages = []
  api.defaults.adapter = async (config) => {
    const page = Number(config.params.page ?? 1); pages.push(page)
    return reply(config, pageData(page, page > 2 ? [] : [summary(page)]))
  }
  const view = await mount('/member/orders?page=99'); await settle(); await settle()
  assert.equal(view.router.currentRoute.value.query.page, '2')
  assert.deepEqual(pages, [99, 2]); assert.ok(text(view.root).includes('HF-TEST-2'))
  view.app.unmount()
})

test('錯誤顯示與手動重試，不自動重送', async () => {
  let calls = 0
  api.defaults.adapter = async (config) => { ++calls; throw new axios.AxiosError('network', 'ERR_NETWORK', config) }
  const view = await mount(); await settle()
  assert.equal(calls, 1); assert.ok(text(view.root).includes('訂單載入失敗'))
  assert.equal(find(view.root, (el) => el.props.role === 'alert').type, 'div')
  api.defaults.adapter = async (config) => reply(config, pageData())
  find(view.root, (el) => el.type === 'button' && el.text === '重新載入').props.onClick()
  await settle(); assert.ok(text(view.root).includes('HF-TEST-1')); view.app.unmount()
})

test('快速切頁晚到的成功回應不可覆蓋目前頁', async () => {
  const finishes = []
  api.defaults.adapter = (config) => new Promise((resolve) => { finishes.push(() => resolve(reply(config, pageData(Number(config.params.page ?? 1))))) })
  const view = await mount(); await settle()
  await view.router.push('/member/orders?page=2'); await settle()
  finishes[1](); await settle(); finishes[0](); await settle()
  assert.ok(text(view.root).includes('HF-TEST-2')); assert.ok(!text(view.root).includes('HF-TEST-1'))
  view.app.unmount()
})

test('真實會員中心提供我的訂單 RouterLink', async () => {
  const { createSSRApp } = await import('vue')
  const { renderToString } = await import('@vue/server-renderer')
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/member/orders', name: 'member-orders', component: List },
    { path: '/member/profile', name: 'member-profile', component: { template: '<div />' } },
    { path: '/member/addresses', name: 'member-addresses', component: { template: '<div />' } },
  ] })
  await router.push('/member/profile'); await router.isReady()
  const app = createSSRApp(Member); app.use(createPinia()); app.use(router)
  const html = await renderToString(app)
  assert.ok(html.includes('我的訂單')); assert.ok(html.includes('href="/member/orders"'))
})

const detail = (id, method = 'cod') => ({
  ...summary(id), payment_method: method, payment_status: method === 'cod' ? 'unpaid' : 'paid',
  purchaser: { name: '原訂購人', phone: '0912345678', email: 'fixture@example.test' },
  recipient: { name: '原收件人', phone: '0987654321', postal_code: '100', city: '臺北市', district: '中正區', address: '原地址' },
  shipping_method: 'home_delivery', logistics_company: null, tracking_number: null,
  items: [{ id: 1, product_id: 1, product_variant_id: 2, product_code: 'SNAP-001', product_name: '原商品名稱', variant: '重量：10kg', unit_price: '100.00', quantity: 1, subtotal: '100.00' }],
})
const click = (el) => el.props.onClick({ button: 0, preventDefault() {} })

test('列表連結 → 詳細快照 → 以同 URL 重建時重新 GET，不依賴列表記憶體', async () => {
  const urls = []
  api.defaults.adapter = async (config) => {
    urls.push(config.url)
    return reply(config, config.url === '/orders' ? pageData() : { data: detail(1) })
  }
  const view = await mount(); await settle()
  const link = find(view.root, (el) => el.type === 'a' && el.props.href === '/member/orders/1')
  assert.ok(link); click(link); await settle(); await settle()
  assert.equal(view.router.currentRoute.value.name, 'member-order-detail')
  for (const value of ['原商品名稱', 'SNAP-001', '重量：10kg', '原訂購人', '原收件人', '原地址', '商品小計', '運費', '總金額', '尚無資料']) {
    assert.ok(text(view.root).includes(value), value)
  }
  assert.ok(find(view.root, (el) => el.type === 'button' && text(el) === '取消訂單'))
  const url = view.router.currentRoute.value.fullPath; view.app.unmount()
  const reopened = await mount(url); await settle()
  assert.deepEqual(urls, ['/orders', '/orders/1', '/orders/1'])
  assert.ok(text(reopened.root).includes('原商品名稱')); reopened.app.unmount()
})

test('詳細 loading／404／網路錯誤與重試', async () => {
  let fail
  api.defaults.adapter = (config) => new Promise((_resolve, reject) => { fail = () => reject(new axios.AxiosError('missing', 'ERR_BAD_RESPONSE', config, undefined, { ...reply(config, {}), status: 404 })) })
  const view = await mount('/member/orders/999'); await settle()
  assert.ok(text(view.root).includes('訂單載入中'))
  fail(); await settle(); assert.ok(text(view.root).includes('找不到此訂單'))
  api.defaults.adapter = async (config) => { throw new axios.AxiosError('network', 'ERR_NETWORK', config) }
  find(view.root, (el) => el.type === 'button' && text(el).trim() === '重新載入').props.onClick()
  await settle(); assert.ok(text(view.root).includes('訂單載入失敗'))
  api.defaults.adapter = async (config) => reply(config, { data: detail(999) })
  find(view.root, (el) => el.type === 'button' && text(el).trim() === '重新載入').props.onClick()
  await settle(); assert.ok(text(view.root).includes('HF-TEST-999')); view.app.unmount()
})

test('詳細 route id 改變，舊請求不覆蓋新訂單；未知狀態及物流原樣展示', async () => {
  const finishes = []
  api.defaults.adapter = (config) => new Promise((resolve) => {
    finishes.push(() => resolve(reply(config, { data: { ...detail(Number(config.url.split('/').at(-1))), order_status: 'future-status', logistics_company: '測試物流', tracking_number: 'TRACK-001' } })))
  })
  const view = await mount('/member/orders/1'); await settle()
  await view.router.push('/member/orders/2'); await settle()
  finishes[1](); await settle(); finishes[0](); await settle()
  assert.ok(text(view.root).includes('HF-TEST-2')); assert.ok(!text(view.root).includes('HF-TEST-1'))
  assert.ok(text(view.root).includes('future-status')); assert.ok(text(view.root).includes('TRACK-001'))
  view.app.unmount()
})

for (const method of ['cod', 'mock_credit_card']) {
  test(`${method} 真實 Checkout 元件送出成功 → 查看訂單 → 詳細 URL 重新查詢`, async () => {
    const savedAddress = { id: 1, label: '測試地址', recipient_name: '原收件人', recipient_phone: '0987654321', address: '原地址', is_default: true, district: { id: 1, name: '中正區', postal_code: '100', city: { id: 1, name: '臺北市' } } }
    const urls = []
    api.defaults.adapter = async (config) => {
      urls.push(config.url)
      if (config.url === '/cart') return reply(config, { data: { id: 1, items: [{ id: 1, product_id: 1, quantity: 1, unit_price: '100.00', subtotal: '100.00', variant: null, product: { id: 1, name: '現售商品', primary_image: null } }], item_count: 1, subtotal: '100.00', has_unavailable_items: false } })
      if (config.url === '/addresses') return reply(config, { data: [savedAddress] })
      if (config.url === 'cities') return reply(config, { data: [] })
      if (config.url === '/checkout') {
        assert.equal(JSON.parse(config.data).payment_method, method)
        return reply(config, { data: detail(7, method), message: '訂單建立成功' })
      }
      if (config.url === '/orders/7') return reply(config, { data: detail(7, method) })
      throw new Error('Unexpected endpoint ' + config.url)
    }
    const view = await mount('/checkout', (pinia) => {
      useAuthStore(pinia).currentUser = { id: 1, name: '原訂購人', phone: '0912345678', email: 'fixture@example.test', status: 'active' }
    })
    await settle()
    const radio = find(view.root, (el) => el.props.id === (method === 'cod' ? 'cashOnDelivery' : 'mockCreditCard'))
    assert.ok(radio, text(view.root))
    radio.listeners.change(); await nextTick()
    find(view.root, (el) => el.type === 'form').props.onSubmit({ preventDefault() {} }); await settle()
    const link = find(view.root, (el) => el.type === 'a' && text(el).trim() === '查看訂單')
    assert.ok(link); assert.equal(link.props.href, '/member/orders/7')
    assert.ok(find(view.root, (el) => el.type === 'a' && el.props.href === '/products' && text(el).trim() === '繼續購物'))
    click(link); await settle(); await settle()
    assert.ok(text(view.root).includes('HF-TEST-7')); assert.ok(urls.includes('/orders/7'))
    const url = view.router.currentRoute.value.fullPath; view.app.unmount()
    const reopened = await mount(url); await settle()
    assert.equal(urls.filter((url) => url === '/orders/7').length, 2)
    assert.ok(text(reopened.root).includes(method === 'cod' ? '未付款' : '已付款')); reopened.app.unmount()
  })
}

const button = (root, label) => find(root, (el) => el.type === 'button' && text(el).trim() === label)
const openConfirmation = async (view) => {
  button(view.root, '取消訂單').props.onClick(); await nextTick()
}
const confirm = async (view) => {
  await openConfirmation(view)
  button(view.root, '確認取消').props.onClick(); await settle()
}
const apiFailure = (config, status, message = '測試錯誤', code) => {
  throw new axios.AxiosError('test', 'ERR_BAD_RESPONSE', config, undefined, {
    ...reply(config, { message, ...(code ? { code } : {}) }), status,
  })
}

for (const status of ['pending', 'processing', 'shipped', 'completed', 'cancelled', 'unknown']) {
  test(status + ' 取消按鈕資格', async () => {
    api.defaults.adapter = async (config) => reply(config, { data: { ...detail(1), order_status: status } })
    const view = await mount('/member/orders/1'); await settle()
    assert.equal(Boolean(button(view.root, '取消訂單')), ['pending', 'processing'].includes(status))
    view.app.unmount()
  })
}

test('放棄確認不送 POST，也不改訂單狀態', async () => {
  const calls = []
  api.defaults.adapter = async (config) => { calls.push(config); return reply(config, { data: detail(1) }) }
  const view = await mount('/member/orders/1'); await settle()
  await openConfirmation(view)
  assert.ok(text(view.root).includes('確定要取消'))
  button(view.root, '保留訂單').props.onClick(); await nextTick()
  assert.ok(!button(view.root, '確認取消'))
  assert.equal(calls.filter((call) => call.method === 'post').length, 0)
  assert.ok(text(view.root).includes('待處理'))
  view.app.unmount()
})

test('確認 POST／送出防重複／無 optimistic／paid cancelled／列表及詳細重新 GET 一致', async () => {
  let current = detail(1, 'mock_credit_card')
  let finish
  const calls = []
  api.defaults.adapter = (config) => {
    calls.push(config)
    if (config.method === 'post') return new Promise((resolve) => {
      finish = () => {
        current = { ...current, order_status: 'cancelled' }
        resolve(reply(config, { data: current, message: '訂單已取消。' }))
      }
    })
    return Promise.resolve(reply(config, config.url === '/orders' ? pageData(1, [current]) : { data: current }))
  }
  const view = await mount('/member/orders/1'); await settle()
  await confirm(view)
  const submit = button(view.root, '確認取消')
  assert.equal(submit.props.disabled, true)
  assert.equal(button(view.root, '取消訂單').props.disabled, true)
  assert.equal(button(view.root, '保留訂單').props.disabled, true)
  assert.ok(text(view.root).includes('正在取消訂單'))
  assert.ok(text(view.root).includes('待處理'))
  submit.props.onClick(); await settle()
  const posts = calls.filter((call) => call.method === 'post')
  assert.equal(posts.length, 1); assert.equal(posts[0].url, '/orders/1/cancel')
  assert.equal(posts[0].data, undefined)
  finish(); await settle()
  assert.ok(text(view.root).includes('已取消')); assert.ok(text(view.root).includes('已付款'))
  assert.ok(!text(view.root).includes('已退款')); assert.ok(!button(view.root, '取消訂單'))
  click(find(view.root, (el) => el.type === 'a' && el.props.href === '/member/orders')); await settle(); await settle()
  assert.ok(text(view.root).includes('已取消'))
  click(find(view.root, (el) => el.type === 'a' && el.props.href === '/member/orders/1')); await settle(); await settle()
  assert.ok(text(view.root).includes('已取消')); assert.ok(!button(view.root, '取消訂單'))
  view.app.unmount()
  const reopened = await mount('/member/orders/1'); await settle()
  assert.ok(text(reopened.root).includes('已取消')); assert.ok(!button(reopened.root, '取消訂單'))
  assert.equal(calls.filter((call) => call.url === '/orders/1').length, 3)
  reopened.app.unmount()
})

for (const status of ['shipped', 'completed']) {
  test('422 重新 GET 真值 ' + status + '，不重送 POST／不假裝成功', async () => {
    const calls = []
    api.defaults.adapter = async (config) => {
      calls.push(config)
      if (config.method === 'post') return apiFailure(config, 422, '此訂單目前的狀態不允許取消。')
      return reply(config, { data: { ...detail(1), order_status: calls.length > 1 ? status : 'pending' } })
    }
    const view = await mount('/member/orders/1'); await settle(); await confirm(view); await settle()
    assert.deepEqual(calls.map((call) => call.method), ['get', 'post', 'get'])
    assert.ok(text(view.root).includes('此訂單目前的狀態不允許取消'))
    assert.ok(text(view.root).includes(status === 'shipped' ? '已出貨' : '已完成'))
    assert.ok(!text(view.root).includes('訂單已取消')); assert.ok(!button(view.root, '取消訂單'))
    view.app.unmount()
  })
}

test('取消 404 使用不可取得呈現，不保留已載入的訂單', async () => {
  api.defaults.adapter = async (config) => config.method === 'post' ? apiFailure(config, 404) : reply(config, { data: detail(1) })
  const view = await mount('/member/orders/1'); await settle(); await confirm(view)
  assert.ok(text(view.root).includes('找不到此訂單'))
  assert.ok(!text(view.root).includes('HF-TEST-1')); assert.ok(!button(view.root, '取消訂單'))
  view.app.unmount()
})

for (const failure of [403, 419, 500, 503, 'network']) {
  test(failure + ' 取消錯誤不當停用／不重送／保留原訂單／可手動重新確認', async () => {
    let posts = 0
    const notifications = []
    sessionState.setSessionFailureHandler((...args) => notifications.push(args))
    api.defaults.adapter = async (config) => {
      if (config.method !== 'post') return reply(config, { data: detail(1) })
      ++posts
      if (failure === 'network') throw new axios.AxiosError('network', 'ERR_NETWORK', config)
      return apiFailure(config, failure)
    }
    const view = await mount('/member/orders/1'); await settle(); await confirm(view)
    assert.equal(posts, 1); assert.deepEqual(notifications, [])
    assert.ok(text(view.root).includes('取消訂單失敗'))
    assert.ok(text(view.root).includes('待處理')); assert.ok(!text(view.root).includes('已取消'))
    assert.equal(button(view.root, '取消訂單').props.disabled, false)
    await confirm(view)
    assert.equal(posts, 2)
    view.app.unmount()
  })
}

for (const failure of [401, 'ACCOUNT_DISABLED']) {
  test(failure + ' cancel POST 經共用 C06 清理與導頁通知，頁面不另寫 session handling', async () => {
    const notifications = []
    let auth, cart, posts = 0
    api.defaults.adapter = async (config) => {
      if (config.method !== 'post') return reply(config, { data: detail(1) })
      ++posts
      return apiFailure(config, failure === 401 ? 401 : 403, '會員拒絕', failure === 401 ? undefined : failure)
    }
    const view = await mount('/member/orders/1', (pinia) => {
      auth = useAuthStore(pinia); cart = useCartStore(pinia)
      auth.currentUser = { id: 1, name: '測試會員', email: 'test@example.test', phone: '0912345678', status: 'active' }
      cart.memberCart = { items: [], item_count: 0, subtotal: '0.00', has_unavailable_items: false }
      sessionState.setSessionFailureHandler((reason, redirect) => {
        notifications.push({ reason, redirect }); auth.resetMemberSession(reason)
      })
    })
    await settle(); await confirm(view)
    assert.equal(posts, 1); assert.equal(auth.currentUser, null); assert.equal(cart.memberCart, null)
    assert.deepEqual(notifications, [{ reason: failure === 401 ? 'expired' : 'disabled', redirect: true }])
    assert.ok(!text(view.root).includes('訂單已取消'))
    view.app.unmount()
  })
}

for (const failure of [false, true]) {
  test('切換訂單後舊 cancel ' + (failure ? '失敗' : '成功') + ' 不覆蓋目前資料及操作狀態', async () => {
    let finish
    api.defaults.adapter = (config) => {
      if (config.method === 'post') return new Promise((resolve, reject) => {
        finish = () => {
          if (failure) {
            try { apiFailure(config, 404) } catch (error) { reject(error) }
          } else resolve(reply(config, { data: { ...detail(1), order_status: 'cancelled' }, message: '訂單已取消。' }))
        }
      })
      return Promise.resolve(reply(config, { data: detail(Number(config.url.split('/').at(-1))) }))
    }
    const view = await mount('/member/orders/1'); await settle(); await confirm(view)
    await view.router.push('/member/orders/2'); await settle()
    // Even returning to the same id must invalidate the original request.
    await view.router.push('/member/orders/1'); await settle()
    await openConfirmation(view)
    finish(); await settle()
    assert.ok(text(view.root).includes('HF-TEST-1')); assert.ok(text(view.root).includes('待處理'))
    assert.ok(!text(view.root).includes('找不到此訂單')); assert.ok(!text(view.root).includes('已取消'))
    assert.equal(button(view.root, '確認取消').props.disabled, false)
    view.app.unmount()
  })
}

test('422 refresh GET 晚到，不覆蓋後來切換的另一張訂單', async () => {
  let gets = 0, finish
  api.defaults.adapter = (config) => {
    if (config.method === 'post') return Promise.reject((() => {
      try { apiFailure(config, 422, '目前無法取消') } catch (error) { return error }
    })())
    if (config.url === '/orders/1' && ++gets > 1) return new Promise((resolve) => {
      finish = () => resolve(reply(config, { data: { ...detail(1), order_status: 'shipped' } }))
    })
    return Promise.resolve(reply(config, { data: detail(Number(config.url.split('/').at(-1))) }))
  }
  const view = await mount('/member/orders/1'); await settle(); await confirm(view)
  await view.router.push('/member/orders/2'); await settle()
  finish(); await settle()
  assert.ok(text(view.root).includes('HF-TEST-2')); assert.ok(!text(view.root).includes('HF-TEST-1'))
  assert.ok(!text(view.root).includes('目前無法取消')); assert.equal(button(view.root, '取消訂單').props.disabled, false)
  view.app.unmount()
})

test('舊取消完成不能解除新訂單送出中的 guard 或覆蓋新訂單', async () => {
  const finishes = []
  api.defaults.adapter = (config) => {
    const id = Number(config.url.split('/')[2])
    if (config.method === 'post') return new Promise((resolve) => {
      finishes.push(() => resolve(reply(config, { data: { ...detail(id), order_status: 'cancelled' }, message: '訂單已取消。' })))
    })
    return Promise.resolve(reply(config, { data: detail(id) }))
  }
  const view = await mount('/member/orders/1'); await settle(); await confirm(view)
  await view.router.push('/member/orders/2'); await settle(); await confirm(view)
  finishes[0](); await settle()
  assert.ok(text(view.root).includes('HF-TEST-2')); assert.ok(text(view.root).includes('待處理'))
  assert.ok(!text(view.root).includes('已取消'))
  assert.equal(button(view.root, '確認取消').props.disabled, true)
  button(view.root, '確認取消').props.onClick(); await settle()
  assert.equal(finishes.length, 2)
  finishes[1](); await settle()
  assert.ok(text(view.root).includes('HF-TEST-2')); assert.ok(text(view.root).includes('已取消'))
  view.app.unmount()
})

test('422 回查失敗使用詳細載入錯誤；手動重試只 GET，不重送取消', async () => {
  let gets = 0, posts = 0
  api.defaults.adapter = async (config) => {
    if (config.method === 'post') { ++posts; return apiFailure(config, 422, '目前無法取消') }
    if (++gets === 2) throw new axios.AxiosError('network', 'ERR_NETWORK', config)
    return reply(config, { data: { ...detail(1), order_status: gets > 2 ? 'completed' : 'pending' } })
  }
  const view = await mount('/member/orders/1'); await settle(); await confirm(view); await settle()
  assert.ok(text(view.root).includes('訂單載入失敗')); assert.ok(!text(view.root).includes('已取消'))
  button(view.root, '重新載入').props.onClick(); await settle()
  assert.ok(text(view.root).includes('已完成')); assert.ok(!button(view.root, '取消訂單'))
  assert.equal(posts, 1); assert.equal(gets, 3)
  view.app.unmount()
})
