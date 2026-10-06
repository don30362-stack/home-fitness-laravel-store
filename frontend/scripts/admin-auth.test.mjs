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
  InventoryManagementView: '/src/views/admin/InventoryManagementView.vue',
  CategoryManagementView: '/src/views/admin/CategoryManagementView.vue',
  HomeContentManagementView: '/src/views/admin/HomeContentManagementView.vue',
  RecommendedProductManager: '/src/components/admin/RecommendedProductManager.vue',
  CategoryForm: '/src/components/admin/CategoryForm.vue',
  OrderManagementView: '/src/views/admin/OrderManagementView.vue',
  AdminOrderDetailView: '/src/views/admin/AdminOrderDetailView.vue',
  MemberManagementView: '/src/views/admin/MemberManagementView.vue',
  AdminUserDetailView: '/src/views/admin/AdminUserDetailView.vue',
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
const inventoryService = await server.ssrLoadModule('/src/services/adminInventoryService.ts')
const categoryService = await server.ssrLoadModule('/src/services/adminCategoryService.ts')
const orderService = await server.ssrLoadModule('/src/services/adminOrderService.ts')
const userService = await server.ssrLoadModule('/src/services/adminUserService.ts')
const bannerService = await server.ssrLoadModule('/src/services/adminHomeContentService.ts')
const originalError = console.error
console.error = () => {}
after(async () => { console.error = originalError; await server.close() })

const admin = { id: 1, name: '管理員', email: 'admin@example.test', status: 'active', permissions: ['product_manage'] }
const newer = { ...admin, id: 2, email: 'new@example.test' }
const user = { id: 9, name: '會員', email: 'member@example.test', phone: '0912345678', status: 'active' }
const payload = { email: admin.email, password: 'test-only' }
const key = 'home-fitness-store-guest-cart'
const guest = JSON.stringify([{ key: '99:none', product_id: 99, product_variant_id: null, quantity: 1 }])
const categoryOptions = [{ id: 1, name: '器材', children: [{ id: 2, name: '啞鈴' }] }]
const adminCategoryTree = [{ id: 1, name: '管理根分類', status: 'inactive', sort_order: 2, children_count: 2,
  created_at: '2026-10-05T00:00:00Z', updated_at: '2026-10-05T00:00:00Z', children: [
    { id: 2, parent_id: 1, name: '啟用子分類', status: 'active', sort_order: 1, product_count: 3, created_at: null, updated_at: null },
    { id: 3, parent_id: 1, name: '停用子分類', status: 'inactive', sort_order: 2, product_count: 0, created_at: null, updated_at: null },
  ] }, { id: 4, name: '空根分類', status: 'active', sort_order: 3, children_count: 0, children: [], created_at: null, updated_at: null }]
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
const adminOrderSummary = { id: 1, order_no: 'HF-READ-001', created_at: '2026-10-04T16:00:00Z',
  total_amount: '1300.00', payment_method: 'cod', payment_status: 'unpaid', order_status: 'pending',
  user: { id: user.id, name: '目前會員姓名', email: 'current@example.test' } }
const adminOrderDetail = { ...adminOrderSummary, updated_at: '2026-10-04T16:00:00Z',
  user: { ...adminOrderSummary.user, status: 'active' },
  purchaser: { name: '下單時訂購人', phone: '0912345678', email: 'snapshot@example.test' },
  recipient: { name: '下單時收件人', phone: '0987654321', postal_code: '100', city: '臺北市', district: '中正區', address: '歷史地址' },
  shipping_method: 'home_delivery', shipping_fee: '100.00', subtotal: '1200.00', logistics_company: null, tracking_number: null,
  items: [{ id: 1, product_id: 88, product_variant_id: 9, product_code: 'SNAP-OLD', product_name: '下單時商品名',
    variant: '顏色：黑色', unit_price: '600.00', quantity: 2, subtotal: '1200.00' }] }
const adminOrderPage = (data = [adminOrderSummary], page = 1, last = 2) => ({ ...productPage(data, page, last) })
const adminUser = { ...user, created_at: '2026-10-04T16:00:00Z', updated_at: '2026-10-04T16:00:00Z' }
const userPage = (data = [adminUser], page = 1, last = 2) => productPage(data, page, last)
const userDetail = (status = 'active', id = user.id, orders = [adminOrderSummary], page = 1) => ({
  user: { ...adminUser, id, status }, orders: productPage(orders.map(({ user: ignored, ...order }) => order), page, 2),
})
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
    if (config.url === '/admin/categories') return response(config, { data: adminCategoryTree })
    if (config.url === '/admin/banners') return response(config, { data: [] })
    if (config.url === '/admin/recommended-products') return response(config, { data: [] })
    if (config.url === '/admin/orders') return response(config, adminOrderPage())
    if (config.url === '/admin/users') return response(config, userPage())
    if (/^\/admin\/users\/\d+$/.test(config.url)) return response(config, { data: userDetail('active', Number(config.url.split('/').at(-1)), [adminOrderSummary], Number(config.params?.order_page ?? 1)) })
    if (/^\/admin\/orders\/\d+$/.test(config.url)) return response(config, { data: adminOrderDetail })
    if (config.url === '/admin/products') return response(config, productPage())
    if (config.url === '/admin/inventory') return response(config, productPage([]))
    if (config.url.startsWith('/admin/products/')) return response(config, { data: productDetail })
    return response(config, config.url.endsWith('/csrf-cookie') ? {} : { data: admin, message: '登入成功' })
  }
})
const assertMemberUntouched = () => {
  assert.equal(member.currentUser.id, user.id); assert.ok(carts.memberCart)
  assert.equal(storage.get(key), guest); assert.equal(carts.guestItems.length, 1)
  assert.deepEqual(memberEvents, [])
}

// Stage22 Banner: real SFC + independent Admin transport, memory host only.
const bannerRow = (id = 1, status = 'active') => ({ id, title: '輪播' + id, subtitle: '副標題',
  image_url: `http://localhost/storage/banners/${id}.jpg`, button_text: '看商品', link_url: '/products',
  sort_order: id - 1, status, created_at: null, updated_at: null })
const bannerAdapter = (rows, handler = c => response(c, { data: bannerRow(), message: '輪播操作成功' })) => async c => {
  calls.push(c)
  if (c.url === '/admin/recommended-products') return response(c, { data: [] })
  return c.method === 'get' ? response(c, { data: typeof rows === 'function' ? rows() : rows }) : handler(c)
}
const bannersView = () => { auth.isAdminInitialized = true; return mountRoute('/admin/home-content') }
const bannerFile = async (view, file = new File(['image bytes'], 'original.png', { type: 'image/png' })) => {
  const input = find(view.root, el => el.props.id === 'banner-image')
  input.files = file ? [file] : []; input.props.onChange({ target: input }); await nextTick()
}
const bannerBoundary = () => {
  assert.ok(calls.every(c => c.method === 'get' && c.url === '/admin/recommended-products' || /^\/admin\/banners(?:\/(?:\d+(?:\/status)?|order))?$/.test(c.url)))
  assertMemberUntouched()
}

test('Banner service six endpoints, FormData create/spoof replace, JSON patch; no multipart boundary override', async () => {
  api.defaults.adapter = bannerAdapter([])
  const file = new File(['fixture'], 'upload.png', { type: 'image/png' })
  await bannerService.getAdminBanners()
  await bannerService.createAdminBanner({ title: '新輪播', subtitle: null, button_text: null, link_url: null, sort_order: 0, image: file })
  await bannerService.updateAdminBanner(2, { title: '編輯', subtitle: null })
  await bannerService.updateAdminBanner(2, { image: file, title: '替換' })
  await bannerService.updateAdminBannerStatus(2, 'inactive')
  await bannerService.reorderAdminBanners({ ids: [2, 1] })
  await bannerService.deleteAdminBanner(2)
  assert.deepEqual(calls.map(c => [c.method, c.url]), [['get', '/admin/banners'], ['post', '/admin/banners'], ['patch', '/admin/banners/2'], ['post', '/admin/banners/2'], ['patch', '/admin/banners/2/status'], ['patch', '/admin/banners/order'], ['delete', '/admin/banners/2']])
  assert.ok(calls[1].data instanceof FormData); assert.equal(calls[1].data.get('image').name, file.name); assert.equal(calls[1].data.get('subtitle'), '')
  assert.deepEqual(JSON.parse(calls[2].data), { title: '編輯', subtitle: null }); assert.equal(calls[3].data.get('_method'), 'PATCH')
  assert.ok(!String(calls[3].headers['Content-Type']).includes('boundary=')); assert.deepEqual(JSON.parse(calls[4].data), { status: 'inactive' })
  assert.deepEqual(JSON.parse(calls[5].data), { ids: [2, 1] }); bannerBoundary()
})

test('Banner route replaces placeholder, renders both states/preview/pair/order; empty and loading', async () => {
  let finish
  api.defaults.adapter = c => { calls.push(c); if (c.url === '/admin/recommended-products') return Promise.resolve(response(c, { data: [] })); return new Promise(resolve => { finish = () => resolve(response(c, { data: [bannerRow(), bannerRow(2, 'inactive')] })) }) }
  const view = await bannersView(); assert.ok(text(view.root).includes('載入輪播中')); assert.ok(button(view, '新增輪播').props.disabled)
  finish(); await settle(); assert.ok(text(view.root).includes('輪播1')); assert.ok(text(view.root).includes('輪播2'))
  assert.ok(text(view.root).includes('/products')); assert.ok(!text(view.root).includes('尚未實作'))
  assert.ok(text(view.root).includes('推薦商品管理')); assert.ok(!text(view.root).includes('推薦商品管理將於後續步驟完成'))
  assert.equal(find(view.root, el => el.type === 'img').props.src, bannerRow().image_url); bannerBoundary(); view.app.unmount()
  api.defaults.adapter = bannerAdapter([]); const empty = await bannersView(); assert.ok(text(empty.root).includes('目前沒有輪播')); empty.app.unmount()
})

test('Banner initial GET error/manual retry and old initial success cannot replace newer retry', async () => {
  api.defaults.adapter = c => { calls.push(c); return Promise.reject(failure(c, 503)) }
  const view = await bannersView(); assert.ok(text(view.root).includes('稍後再試')); assert.ok(button(view, '新增輪播').props.disabled)
  api.defaults.adapter = bannerAdapter([bannerRow()]); await click(view, '重新載入'); assert.ok(text(view.root).includes('輪播1')); assert.equal(mutations().length, 0); view.app.unmount()
  let finishOld, first = true
  api.defaults.adapter = c => { calls.push(c); if (c.url === '/admin/recommended-products') return Promise.resolve(response(c, { data: [] })); if (first) { first = false; return new Promise(resolve => { finishOld = () => resolve(response(c, { data: [bannerRow(9)] })) }) }; return Promise.resolve(response(c, { data: [bannerRow(2)] })) }
  const late = await bannersView()
  await click(late, '重新載入輪播'); assert.ok(text(late.root).includes('輪播2')); finishOld(); await settle()
  assert.ok(!text(late.root).includes('輪播9')); assert.ok(text(late.root).includes('輪播2')); late.app.unmount(); assertMemberUntouched()
})

test('Banner create pair UX, FormData inactive, pending disables all contexts/cancel, success authoritative GET', async () => {
  let finish, rows = [bannerRow()]
  api.defaults.adapter = bannerAdapter(() => rows, c => new Promise(resolve => { finish = () => { rows = [bannerRow(2, 'inactive')]; resolve(response(c, { data: rows[0], message: '新增輪播完成' })) } }))
  const view = await bannersView(); await click(view, '新增輪播'); await inputValue(view, 'banner-title', '草稿')
  await inputValue(view, 'banner-button', '前往'); await bannerFile(view); await submitCore(view)
  assert.equal(mutations().length, 0); assert.ok(text(view.root).includes('同時填寫'))
  await inputValue(view, 'banner-link', '/products/12'); await inputValue(view, 'banner-status', 'inactive'); await submitCore(view); await submitCore(view)
  assert.equal(mutations().length, 1); assert.equal(mutations()[0].data.get('status'), 'inactive'); assert.equal(mutations()[0].data.get('button_text'), '前往')
  for (const label of ['新增輪播', '編輯', '下架', '刪除', '放棄', '提交中…']) assert.ok(button(view, label).props.disabled, label)
  await click(view, '放棄'); await click(view, '編輯'); assert.equal(find(view.root, el => el.props.id === 'banner-title').value, '草稿')
  assert.ok(text(view.root).includes('輪播1')); finish(); await settle(); assert.equal(find(view.root, el => el.type === 'form'), undefined)
  assert.ok(text(view.root).includes('新增輪播完成')); assert.ok(text(view.root).includes('輪播2')); assert.ok(!button(view, '新增輪播').props.disabled)
  assert.equal(calls.filter(c => c.method === 'get' && c.url === '/admin/banners').length, 2); bannerBoundary(); view.app.unmount()
})

test('Banner edit JSON clear pair, omit image/status; image replacement spoof and preview revoked', async () => {
  api.defaults.adapter = bannerAdapter([bannerRow()])
  const view = await bannersView(); await click(view, '編輯')
  assert.equal(find(view.root, el => el.props.id === 'banner-status'), undefined)
  await inputValue(view, 'banner-subtitle', ''); await inputValue(view, 'banner-button', ''); await inputValue(view, 'banner-link', ''); await submitCore(view)
  const data = JSON.parse(mutations()[0].data); assert.equal(data.subtitle, null); assert.equal(data.button_text, null); assert.equal(data.link_url, null)
  assert.ok(!('image' in data)); assert.ok(!('status' in data))
  const createUrl = URL.createObjectURL, revokeUrl = URL.revokeObjectURL, revoked = []; let n = 0
  URL.createObjectURL = () => 'blob:banner-' + ++n; URL.revokeObjectURL = value => revoked.push(value)
  try {
    await click(view, '編輯'); await bannerFile(view); await bannerFile(view); assert.deepEqual(revoked, ['blob:banner-1'])
    await submitCore(view); const replace = mutations()[1]; assert.equal(replace.method, 'post'); assert.equal(replace.data.get('_method'), 'PATCH')
    assert.deepEqual(revoked, ['blob:banner-1', 'blob:banner-2'])
    await click(view, '新增輪播'); await bannerFile(view); view.app.unmount(); assert.ok(revoked.includes('blob:banner-3'))
  } finally { URL.createObjectURL = createUrl; URL.revokeObjectURL = revokeUrl }
  bannerBoundary()
})

for (const [type, size] of [['image/gif', 2], ['image/png', 5 * 1024 * 1024 + 1]]) {
  test(`Banner file UX rejects ${type}/${size} before mutation`, async () => {
    api.defaults.adapter = bannerAdapter([]); const view = await bannersView(); await click(view, '新增輪播')
    await bannerFile(view, new File([new Uint8Array(size)], 'bad', { type })); await inputValue(view, 'banner-title', '草稿')
    assert.ok(text(view.root).includes('5 MiB')); await submitCore(view); assert.equal(mutations().length, 0); view.app.unmount()
  })
}

for (const action of ['status', 'delete', 'order']) {
  test(`Banner ${action} confirmation/working order, one mutation, GET authoritative, no optimistic`, async () => {
    let finish, rows = [bannerRow(), bannerRow(2, 'inactive')]
    api.defaults.adapter = bannerAdapter(() => rows, c => new Promise(resolve => { finish = () => { rows = action === 'delete' ? [bannerRow(2, 'inactive')] : action === 'status' ? [bannerRow(1, 'inactive'), bannerRow(2, 'inactive')] : [bannerRow(2, 'inactive'), bannerRow()]; resolve(response(c, { message: '權威操作完成', data: rows[0] })) } }))
    const view = await bannersView()
    if (action === 'order') { await click(view, '下移'); assert.equal(mutations().length, 0); await click(view, '儲存排序'); await click(view, '儲存排序') }
    else { await click(view, action === 'status' ? '下架' : '刪除'); assert.equal(mutations().length, 0)
      if (action === 'delete') { assert.ok(text(view.root).includes('永久刪除')); assert.ok(text(view.root).includes('不影響商品資料')) }
      await click(view, '確認'); await click(view, '確認') }
    assert.equal(mutations().length, 1); assert.ok(button(view, '新增輪播').props.disabled); assert.ok(text(view.root).includes('輪播1'))
    if (action === 'order') assert.deepEqual(JSON.parse(mutations()[0].data), { ids: [2, 1] })
    if (action === 'status') assert.deepEqual(JSON.parse(mutations()[0].data), { status: 'inactive' })
    finish(); await settle(); assert.ok(text(view.root).includes('權威操作完成')); assert.equal(calls.filter(c => c.method === 'get' && c.url === '/admin/banners').length, 2)
    assert.ok(!button(view, '新增輪播').props.disabled); bannerBoundary(); view.app.unmount()
  })
}

test('Banner inactive status can be activated', async () => {
  api.defaults.adapter = bannerAdapter([bannerRow(1, 'inactive')]); const view = await bannersView()
  await click(view, '上架'); await click(view, '確認'); assert.deepEqual(JSON.parse(mutations()[0].data), { status: 'active' }); bannerBoundary(); view.app.unmount()
})

for (const [status, code] of [[401], [403, 'ADMIN_ACCOUNT_DISABLED'], [403], [419], [422], [undefined], [500]]) {
  for (const action of ['form', 'status', 'delete', 'order']) {
    test(`Banner ${action} ${status ?? 'network'}/${code ?? ''}: retain draft/tree, coordinator isolation, no retry`, async () => {
      api.defaults.adapter = bannerAdapter([bannerRow(), bannerRow(2)], c => {
        const e = failure(c, status, code)
        if (status === 422) e.response.data = { message: '資料已變更，請重新載入', errors: { title: ['欄位錯誤原因'] } }
        throw e
      })
      const view = await bannersView()
      if (action === 'form') { await click(view, '編輯'); await inputValue(view, 'banner-title', '保留草稿'); await submitCore(view) }
      else if (action === 'order') { await click(view, '下移'); await click(view, '儲存排序') }
      else { await click(view, action === 'status' ? '下架' : '刪除'); await click(view, '確認') }
      await settle(); assert.equal(mutations().length, 1)
      if (status === 401 || code) { assert.equal(auth.currentAdmin, null); assert.equal(view.router.currentRoute.value.name, 'admin-login') }
      else {
        assert.equal(auth.currentAdmin.id, admin.id); assert.ok(text(view.root).includes('輪播1')); assert.equal(button(view, '新增輪播').props.disabled, action === 'order' && status === 422)
        if (status === 422) { assert.ok(text(view.root).includes('欄位錯誤原因')); assert.ok(text(view.root).includes('請重新載入')) }
        if (action === 'form') assert.equal(find(view.root, el => el.props.id === 'banner-title').value, '保留草稿')
      }
      bannerBoundary(); view.app.unmount()
    })
  }
}

test('Banner mutation success + refresh failure preserves success/stale list, disables mutation, retry GET only', async () => {
  let get = 0
  api.defaults.adapter = async c => {
    calls.push(c)
    if (c.url === '/admin/recommended-products') return response(c, { data: [] })
    if (c.method !== 'get') return response(c, { message: 'DB 已成功更新' })
    if (++get === 2) throw failure(c, 503)
    return response(c, { data: [bannerRow(1, get > 2 ? 'inactive' : 'active')] })
  }
  const view = await bannersView(); await click(view, '下架'); await click(view, '確認')
  assert.ok(text(view.root).includes('DB 已成功更新')); assert.ok(text(view.root).includes('最新輪播資料重新載入失敗'))
  assert.ok(text(view.root).includes('舊資料')); assert.ok(button(view, '新增輪播').props.disabled); await click(view, '編輯'); assert.equal(find(view.root, el => el.type === 'form'), undefined)
  await click(view, '重新載入'); assert.equal(mutations().length, 1); assert.equal(get, 3); assert.ok(!button(view, '新增輪播').props.disabled)
  assert.ok(text(view.root).includes('DB 已成功更新')); bannerBoundary(); view.app.unmount()
})

for (const outcome of ['success', 'error', 'refresh-success', 'refresh-error']) {
  test(`Banner route leave pending ${outcome} cannot refresh/navigate/message other module`, async () => {
    let finish, get = 0
    api.defaults.adapter = async c => {
      calls.push(c)
      if (c.url === '/admin/recommended-products') return response(c, { data: [] })
      if (c.url === '/admin/orders') return response(c, adminOrderPage())
      if (c.method === 'get') {
        if (++get === 1) return response(c, { data: [bannerRow()] })
        return new Promise((resolve, reject) => { finish = () => outcome === 'refresh-error' ? reject(failure(c, 503)) : resolve(response(c, { data: [bannerRow(9)] })) })
      }
      if (outcome.startsWith('refresh')) return response(c, { message: '舊輪播成功' })
      return new Promise((resolve, reject) => { finish = () => outcome === 'error' ? reject(failure(c, 422)) : resolve(response(c, { message: '舊輪播成功' })) })
    }
    const view = await bannersView(); await click(view, '下架'); await click(view, '確認')
    await view.router.push('/admin/orders'); await settle(); finish(); await settle()
    assert.equal(view.router.currentRoute.value.fullPath, '/admin/orders'); assert.ok(!text(view.root).includes('舊輪播成功')); assert.ok(!text(view.root).includes('輪播9'))
    assert.equal(mutations().length, 1); assertMemberUntouched(); view.app.unmount()
  })
}

// Stage22 Recommendation: same page, independent state and existing Admin product selector.
const recommendedRow = (id = 31, productId = 1, visible = false) => ({ id, product_id: productId, sort_order: 0,
  is_publicly_visible: visible, unavailable_reason: visible ? null : '商品目前為下架狀態，不會顯示於前台推薦。',
  product: { ...productItem, id: productId, name: `推薦商品${productId}`, status: visible ? 'active' : 'inactive', stock: 0, has_variants: false } })
const recommendationRoot = view => ({ root: find(view.root, el => el.props['aria-labelledby'] === 'recommended-management-title') })
const recommendedAdapter = (rows = [recommendedRow()], handler = c => response(c, { message: '推薦操作成功' }), selector = () => productPage()) => async c => {
  calls.push(c)
  if (c.url === '/admin/banners') return response(c, { data: [] })
  if (c.url === '/admin/orders') return response(c, adminOrderPage())
  if (c.url === '/admin/products') return selector(c)
  assert.ok(/^\/admin\/recommended-products(?:\/(?:order|\d+))?$/.test(c.url), c.url)
  return c.method === 'get' ? response(c, { data: typeof rows === 'function' ? rows() : rows }) : handler(c)
}
const recommendationCalls = () => calls.filter(c => c.url.startsWith('/admin/recommended-products'))
const recommendedBoundary = () => {
  assert.ok(calls.every(c => c.url === '/admin/banners' && c.method === 'get' || c.url === '/admin/products' && c.method === 'get' || /^\/admin\/recommended-products(?:\/(?:order|\d+))?$/.test(c.url)))
  assertMemberUntouched()
}
const prepareRecommendedAction = async (view, action) => {
  const section = recommendationRoot(view)
  if (action === 'add') { await click(section, '選擇推薦商品'); await click(section, '加入推薦') }
  if (action === 'delete') { await click(section, '移除推薦'); await click(section, '確認移除推薦') }
  if (action === 'reorder') { await click(section, '推薦下移'); await click(section, '儲存推薦排序') }
}

test('Recommended service four exact endpoints and identity payload, Admin transport only', async () => {
  api.defaults.adapter = recommendedAdapter()
  await bannerService.getAdminRecommendedProducts(); await bannerService.createAdminRecommendedProduct(8)
  await bannerService.deleteAdminRecommendedProduct(31); await bannerService.reorderAdminRecommendedProducts({ ids: [40, 31] })
  assert.deepEqual(calls.map(c => [c.method, c.url]), [['get', '/admin/recommended-products'], ['post', '/admin/recommended-products'], ['delete', '/admin/recommended-products/31'], ['patch', '/admin/recommended-products/order']])
  assert.deepEqual(JSON.parse(calls[1].data), { product_id: 8 }); assert.deepEqual(JSON.parse(calls[3].data), { ids: [40, 31] }); recommendedBoundary()
})
test('Recommended loading, complete visibility/reason including stock zero, empty list', async () => {
  let finish
  api.defaults.adapter = recommendedAdapter(() => [], c => response(c, {}))
  const base = api.defaults.adapter
  api.defaults.adapter = c => c.url === '/admin/recommended-products' ? (calls.push(c), new Promise(resolve => { finish = () => resolve(response(c, { data: [recommendedRow(), recommendedRow(40, 2, true)] })) })) : base(c)
  const view = await bannersView(); const section = recommendationRoot(view)
  assert.ok(text(section.root).includes('載入推薦商品')); assert.ok(button(section, '選擇推薦商品').props.disabled)
  finish(); await settle(); assert.ok(text(section.root).includes('目前不會顯示於前台')); assert.ok(text(section.root).includes('前台顯示中')); assert.ok(text(section.root).includes('下架狀態')); recommendedBoundary(); view.app.unmount()
  api.defaults.adapter = recommendedAdapter([]); const empty = await bannersView(); assert.ok(text(recommendationRoot(empty).root).includes('目前沒有推薦商品')); empty.app.unmount()
})
test('Recommended list error manual GET retry and late old GET cannot replace new rows', async () => {
  let bad = true
  api.defaults.adapter = recommendedAdapter(() => { if (bad) throw failure({}, 503); return [recommendedRow()] })
  const view = await bannersView(); const section = recommendationRoot(view)
  assert.ok(button(section, '選擇推薦商品').props.disabled); bad = false; await click(section, '重新載入推薦商品')
  assert.ok(text(section.root).includes('推薦商品1')); view.app.unmount()
  let old, get = 0
  api.defaults.adapter = recommendedAdapter(); const base = api.defaults.adapter
  api.defaults.adapter = c => c.url === '/admin/recommended-products' && ++get === 1 ? (calls.push(c), new Promise(resolve => { old = () => resolve(response(c, { data: [recommendedRow(99, 99)] })) })) : base(c)
  const late = await bannersView(); await click(recommendationRoot(late), '重新載入推薦商品'); old(); await settle()
  assert.ok(!text(late.root).includes('推薦商品99')); assert.ok(text(late.root).includes('推薦商品1')); recommendedBoundary(); late.app.unmount()
})
test('Recommended selector server search/page, already selected disabled, inactive product can add', async () => {
  let selected = [recommendedRow()]
  api.defaults.adapter = recommendedAdapter(() => selected, c => { selected = [...selected, recommendedRow(40, 2)]; return response(c, { message: '新增推薦完成' }) }, c => response(c, productPage([{ ...productItem }, { ...productItem, id: 2, name: '可新增停用商品', status: 'disabled' }], Number(c.params.page), 2)))
  const view = await bannersView(); const section = recommendationRoot(view); await click(section, '選擇推薦商品')
  assert.ok(button(section, '已推薦').props.disabled); await click(section, '已推薦'); assert.equal(mutations().length, 0)
  await inputValue(section, 'recommended-search', 'PRD-搜尋')
  find(section.root, el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await settle()
  assert.deepEqual(calls.filter(c => c.url === '/admin/products').at(-1).params, { search: 'PRD-搜尋', page: '1' })
  await click(section, '下一頁商品'); assert.equal(calls.at(-1).params.page, '2'); assert.equal(calls.at(-1).params.search, 'PRD-搜尋')
  await click(section, '上一頁商品'); await click(section, '加入推薦'); assert.deepEqual(JSON.parse(mutations()[0].data), { product_id: 2 })
  assert.ok(text(section.root).includes('新增推薦完成')); assert.equal(find(section.root, el => el.type === 'button' && text(el) === '加入推薦'), undefined)
  recommendedBoundary(); view.app.unmount()
})
test('Selector loading/empty/error retry stays independent of recommendation remove', async () => {
  let finish, bad = true
  api.defaults.adapter = recommendedAdapter([recommendedRow()], c => response(c, { message: '已移除' }), c => new Promise((resolve, reject) => { finish = () => bad ? reject(failure(c, 503)) : resolve(response(c, productPage([], 1, 1))) }))
  const view = await bannersView(); const section = recommendationRoot(view); await click(section, '選擇推薦商品')
  assert.ok(text(section.root).includes('載入商品選項')); finish(); await settle(); assert.ok(button(section, '重試商品選項'))
  assert.ok(!button(section, '移除推薦').props.disabled); bad = false; await click(section, '重試商品選項'); finish(); await settle()
  assert.ok(text(section.root).includes('沒有符合條件的商品')); recommendedBoundary(); view.app.unmount()
})
test('Selector old search/page success or error does not replace latest options', async () => {
  const requests = []
  api.defaults.adapter = recommendedAdapter([], undefined, c => new Promise((resolve, reject) => requests.push({ c, resolve, reject })))
  const view = await bannersView(); const section = recommendationRoot(view); await click(section, '選擇推薦商品')
  for (const search of ['older', 'latest']) { await inputValue(section, 'recommended-search', search); find(section.root, el => el.type === 'form').props.onSubmit({ preventDefault() {} }); await settle() }
  requests[2].resolve(response(requests[2].c, productPage([{ ...productItem, name: '最新商品選項' }]))); await settle()
  requests[0].resolve(response(requests[0].c, productPage([{ ...productItem, name: '舊商品選項' }]))); requests[1].reject(failure(requests[1].c, 500)); await settle()
  assert.ok(text(section.root).includes('最新商品選項')); assert.ok(!text(section.root).includes('舊商品選項')); assert.equal(button(section, '重試商品選項'), undefined); recommendedBoundary(); view.app.unmount()
})
for (const action of ['add', 'delete', 'reorder']) {
  test(`Recommended ${action} pending mutual exclusion, no optimistic, success authoritative GET`, async () => {
    let finish, rows = [recommendedRow(31, 1), recommendedRow(40, 2)]
    api.defaults.adapter = recommendedAdapter(() => rows, c => new Promise(resolve => { finish = () => { rows = [recommendedRow(50, 8, true)]; resolve(response(c, { message: '推薦權威成功' })) } }), c => response(c, productPage([{ ...productItem, id: 3 }], 1, 1)))
    const view = await bannersView(); const section = recommendationRoot(view); await prepareRecommendedAction(view, action)
    assert.ok(button(section, '選擇推薦商品').props.disabled); assert.ok(button(section, '重新載入推薦商品').props.disabled)
    assert.ok(button(section, '移除推薦').props.disabled); await prepareRecommendedAction(view, action); await click(section, '重新載入推薦商品')
    assert.equal(mutations().length, 1); assert.ok(text(section.root).includes('推薦商品1')); assert.ok(!text(section.root).includes('推薦商品8'))
    if (action === 'delete') { assert.equal(mutations()[0].url, '/admin/recommended-products/31'); assert.ok(text(section.root).includes('不會刪除商品')); assert.ok(text(section.root).includes('歷史訂單')) }
    if (action === 'reorder') assert.deepEqual(JSON.parse(mutations()[0].data), { ids: [40, 31] })
    finish(); await settle(); assert.ok(text(section.root).includes('推薦權威成功')); assert.ok(text(section.root).includes('推薦商品8')); assert.equal(recommendationCalls().filter(c => c.method === 'get').length, 2)
    assert.ok(!button(section, '選擇推薦商品').props.disabled); recommendedBoundary(); view.app.unmount()
  })
  for (const status of [403, 419, 422, undefined, 500]) {
    test(`Recommended ${action} ${status ?? 'network'} no retry/no optimistic/member isolation`, async () => {
      api.defaults.adapter = recommendedAdapter([recommendedRow(), recommendedRow(40, 2)], c => { const error = failure(c, status); if (status === 422) error.response.data.errors = { product_id: ['此商品已是推薦商品。'] }; throw error }, c => response(c, productPage([{ ...productItem, id: 3 }], 1, 1)))
      const view = await bannersView(); const section = recommendationRoot(view); await prepareRecommendedAction(view, action)
      assert.equal(mutations().length, 1); assert.ok(text(section.root).includes('推薦商品1')); assert.ok(find(section.root, el => el.props.role === 'alert'))
      if (status === 422) assert.ok(text(section.root).includes('此商品已是推薦商品'))
      assert.equal(auth.currentAdmin.id, admin.id); assert.equal(auth.adminFailureReason, null); assert.equal(view.router.currentRoute.value.path, '/admin/home-content')
      if (action === 'reorder' && status === 422) { assert.ok(button(section, '選擇推薦商品').props.disabled); await click(section, '重新載入推薦商品'); assert.ok(!button(section, '選擇推薦商品').props.disabled); assert.equal(mutations().length, 1) }
      recommendedBoundary(); view.app.unmount()
    })
  }
  for (const [status, code] of [[401, undefined], [403, 'ADMIN_ACCOUNT_DISABLED']]) {
    test(`Recommended ${action} ${status}/${code ?? 'expired'} Admin coordinator only`, async () => {
      api.defaults.adapter = recommendedAdapter([recommendedRow(), recommendedRow(40, 2)], c => { throw failure(c, status, code) }, c => response(c, productPage([{ ...productItem, id: 3 }], 1, 1)))
      const view = await bannersView(); await prepareRecommendedAction(view, action); await settle()
      assert.equal(auth.currentAdmin, null); assert.equal(view.router.currentRoute.value.name, 'admin-login'); assert.equal(mutations().length, 1); assertMemberUntouched(); view.app.unmount()
    })
  }
}
test('Recommended duplicate 422 retains selector/draft/relation, manual refresh only GET', async () => {
  api.defaults.adapter = recommendedAdapter([recommendedRow()], c => { const error = failure(c, 422); error.response.data.errors = { product_id: ['此商品已是推薦商品。'] }; throw error }, c => response(c, productPage([{ ...productItem, id: 3 }], 1, 1)))
  const view = await bannersView(); const section = recommendationRoot(view); await click(section, '選擇推薦商品'); await inputValue(section, 'recommended-search', '保留搜尋')
  await click(section, '加入推薦'); assert.equal(find(section.root, el => el.props.id === 'recommended-search').value, '保留搜尋'); assert.ok(text(section.root).includes('此商品已是推薦商品')); assert.ok(!button(section, '加入推薦').props.disabled)
  await click(section, '重新載入推薦商品'); assert.equal(mutations().length, 1); assert.equal(recommendationCalls().filter(c => c.method === 'get').length, 2); recommendedBoundary(); view.app.unmount()
})
test('Recommended success + refresh failure keeps success/stale list, disables mutation; retry only GET', async () => {
  let get = 0
  api.defaults.adapter = recommendedAdapter(); const base = api.defaults.adapter
  api.defaults.adapter = c => c.url === '/admin/recommended-products' && c.method === 'get' && ++get === 2 ? (calls.push(c), Promise.reject(failure(c, 503))) : base(c)
  const view = await bannersView(); const section = recommendationRoot(view); await prepareRecommendedAction(view, 'delete')
  assert.ok(text(section.root).includes('推薦操作成功')); assert.ok(text(section.root).includes('最新推薦資料重新載入失敗')); assert.ok(text(section.root).includes('推薦商品1')); assert.ok(button(section, '選擇推薦商品').props.disabled)
  assert.ok(!button(view, '新增輪播').props.disabled); await click(section, '重新載入推薦商品'); assert.equal(mutations().length, 1); assert.equal(get, 3); assert.ok(!button(section, '選擇推薦商品').props.disabled); recommendedBoundary(); view.app.unmount()
})
for (const area of ['banner', 'recommended']) {
  test(`Home content ${area} GET failure cannot block the other management section`, async () => {
    api.defaults.adapter = recommendedAdapter(); const base = api.defaults.adapter
    api.defaults.adapter = c => c.url === (area === 'banner' ? '/admin/banners' : '/admin/recommended-products') ? (calls.push(c), Promise.reject(failure(c, 503))) : base(c)
    const view = await bannersView()
    if (area === 'banner') { await prepareRecommendedAction(view, 'delete'); assert.equal(mutations().length, 1) }
    else { await click(view, '新增輪播'); assert.ok(find(view.root, el => el.props.id === 'banner-title')); assert.equal(mutations().length, 0) }
    recommendedBoundary(); view.app.unmount()
  })
}
for (const phase of ['list', 'selector']) {
  for (const [status, code] of [[401, undefined], [403, 'ADMIN_ACCOUNT_DISABLED']]) {
    test(`Recommended ${phase} GET ${status}/${code ?? 'expired'} uses Admin session coordinator`, async () => {
      api.defaults.adapter = recommendedAdapter(); const base = api.defaults.adapter
      api.defaults.adapter = c => c.url === (phase === 'list' ? '/admin/recommended-products' : '/admin/products') ? (calls.push(c), Promise.reject(failure(c, status, code))) : base(c)
      const view = await bannersView(); if (phase === 'selector') await click(recommendationRoot(view), '選擇推薦商品'); await settle()
      assert.equal(auth.currentAdmin, null); assert.equal(view.router.currentRoute.value.name, 'admin-login'); assert.equal(mutations().length, 0); assertMemberUntouched(); view.app.unmount()
    })
  }
}
for (const status of [403, 419, 422, undefined, 500]) {
  test(`Recommended GET ${status ?? 'network'} no session cleanup, manual retry only`, async () => {
    api.defaults.adapter = recommendedAdapter(); const base = api.defaults.adapter
    api.defaults.adapter = c => c.url === '/admin/recommended-products' ? (calls.push(c), Promise.reject(failure(c, status))) : base(c)
    const view = await bannersView(); const section = recommendationRoot(view)
    assert.ok(find(section.root, el => el.props.role === 'alert')); assert.equal(auth.currentAdmin.id, admin.id); assert.equal(auth.adminFailureReason, null)
    assert.equal(recommendationCalls().length, 1); api.defaults.adapter = base; await click(section, '重新載入推薦商品'); assert.ok(text(section.root).includes('推薦商品1'))
    assert.equal(mutations().length, 0); recommendedBoundary(); view.app.unmount()
  })
}
for (const action of ['add', 'delete', 'reorder']) {
 for (const outcome of ['success', 'error', 'refresh-success', 'refresh-error']) {
  test(`Recommended ${action} route leave late ${outcome} cannot navigate/refresh/message another module`, async () => {
    let finish, get = 0
    api.defaults.adapter = recommendedAdapter(undefined, undefined, c => response(c, productPage([{ ...productItem, id: 3 }]))); const base = api.defaults.adapter
    api.defaults.adapter = c => {
      if (!c.url.startsWith('/admin/recommended-products')) return base(c)
      calls.push(c)
      if (c.method === 'get') {
        if (++get === 1) return Promise.resolve(response(c, { data: [recommendedRow(), recommendedRow(40, 2)] }))
        return new Promise((resolve, reject) => { finish = () => outcome === 'refresh-error' ? reject(failure(c, 503)) : resolve(response(c, { data: [recommendedRow(99, 99)] })) })
      }
      if (outcome.startsWith('refresh')) return Promise.resolve(response(c, { message: '舊推薦成功' }))
      return new Promise((resolve, reject) => { finish = () => outcome === 'error' ? reject(failure(c, 422)) : resolve(response(c, { message: '舊推薦成功' })) })
    }
    const view = await bannersView()
    await prepareRecommendedAction(view, action); await view.router.push('/admin/orders'); await settle(); finish(); await settle()
    assert.equal(view.router.currentRoute.value.path, '/admin/orders'); assert.ok(!text(view.root).includes('舊推薦成功')); assert.ok(!text(view.root).includes('推薦商品99')); assert.equal(mutations().length, 1); assertMemberUntouched(); view.app.unmount()
  })
 }
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

test('Stage23 login saves code permissions; restore replaces the latest identity including empty permissions', async () => {
  await auth.login(payload)
  assert.deepEqual(auth.currentAdmin.permissions, ['product_manage'])
  api.defaults.adapter = config => { calls.push(config); return Promise.resolve(response(config, { data: { ...admin, permissions: ['admin_manage', 'order_manage'] } })) }
  assert.equal(await auth.restoreAdmin(), 'authenticated')
  assert.deepEqual(auth.currentAdmin.permissions, ['admin_manage', 'order_manage'])
  api.defaults.adapter = config => { calls.push(config); return Promise.resolve(response(config, { data: { ...admin, permissions: [] } })) }
  assert.equal(await auth.restoreAdmin(), 'authenticated')
  assert.deepEqual(auth.currentAdmin.permissions, [])
  assert.equal(auth.isAdminAuthenticated, true)
  assertMemberUntouched()
})

test('Stage23 foundation permission denied remains ordinary403, retaining identity and member/cart', async () => {
  const before = state.getAdminGeneration()
  api.defaults.adapter = config => Promise.reject(failure(config, 403, 'ADMIN_PERMISSION_DENIED'))
  await assert.rejects(api.get('/admin/permission-test/single'))
  assert.equal(auth.currentAdmin.id, admin.id)
  assert.deepEqual(auth.currentAdmin.permissions, ['product_manage'])
  assert.equal(auth.adminFailureReason, null)
  assert.equal(state.getAdminGeneration(), before)
  assert.deepEqual(nav, [])
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

for (const path of ['/admin/dashboard', '/admin/orders', '/admin/orders/1', '/admin/does-not-exist', '/admin/products', '/admin/products/1', '/admin/products/new', '/admin/products/1/edit', '/admin/inventory', '/admin/categories', '/admin/users', '/admin/users/9']) {
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
test('Admin-only 八模組保持階段對照，products／inventory／categories 已接真實頁面', async () => {
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
    if (['products', 'inventory', 'categories', 'orders', 'users', 'home-content'].includes(item.path)) assert.ok(!text(main).includes('尚未實作'))
    else { assert.ok(text(main).includes('尚未實作')); assert.ok(text(main).includes('Stage ' + item.stage)) }
  }
  assert.deepEqual(routes.adminModules.map((item) => item.stage), [24, 19, 20, 19, 21, 21, 22, 23])
  assert.deepEqual(calls.map(c => c.url).sort(), ['/admin/banners', '/admin/categories', '/admin/inventory', '/admin/orders', '/admin/products', '/admin/recommended-products', '/admin/users', '/categories', '/categories']); assert.equal(member.currentUser, null)
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

// Stage19 Step4 inventory uses the existing memory renderer, not browser acceptance.
const inventoryItem = { stock_owner_type:'product',stock_owner_id:1,product_id:1,product_code:'PRD-INVENTORY',product_name:'庫存啞鈴',product_status:'inactive',category:{id:2,name:'啞鈴'},variant:null,stock:3,low_stock_threshold:5,inventory_status:'low_stock' }
const inventoryVariant = {...inventoryItem,stock_owner_type:'variant',stock_owner_id:2,product_id:2,variant:{id:2,option_name:'顏色',option_value:'黑色',status:'inactive'},stock:0,inventory_status:'out_of_stock'}
const inventoryAdapter = (data=[inventoryItem,inventoryVariant]) => async config => { calls.push(config); if(config.url==='/categories')return response(config,{data:categoryOptions}); assert.equal(config.url,'/admin/inventory');assert.equal(config.method,'get');return response(config,productPage(data,Number(config.params?.page||1),2)) }
const inventoryView = async(url='/admin/inventory')=>{auth.isAdminInitialized=true;return mountRoute(url)}
const inventoryPanel = view=>find(view.root,el=>el.props['aria-label']==='庫存調整')
const submitAdjustment = async view=>{find(inventoryPanel(view),el=>el.type==='form').props.onSubmit({preventDefault(){}});await settle()}
const assertInventoryCalls=()=>{assert.ok(calls.every(c=>c.url==='/categories'||/^\/admin\/inventory(?:\/(?:variants\/)?\d+)?$/.test(c.url)));assert.ok(calls.every(c=>['get','patch'].includes(c.method)));assertMemberUntouched()}

test('Inventory service adminApi：GET與兩種delta PATCH契約',async()=>{
 api.defaults.adapter=async config=>{calls.push(config);return response(config,{data:inventoryItem,message:'庫存調整成功'})}
 await inventoryService.getAdminInventory({search:'PRD',inventory_status:'low_stock',page:'2'});await inventoryService.adjustProductInventory(1,{adjustment:5});await inventoryService.adjustVariantInventory(2,{adjustment:-3})
 assert.deepEqual(calls.map(c=>[c.method,c.url]),[['get','/admin/inventory'],['patch','/admin/inventory/1'],['patch','/admin/inventory/variants/2']]);assert.deepEqual(JSON.parse(calls[1].data),{adjustment:5});assert.deepEqual(JSON.parse(calls[2].data),{adjustment:-3});assertInventoryCalls()
})
test('Inventory真頁面：plain/variant、inactive/disabled、販售與庫存狀態分開',async()=>{
 api.defaults.adapter=inventoryAdapter([inventoryItem,inventoryVariant,{...inventoryItem,stock_owner_id:3,product_status:'disabled',stock:20,inventory_status:'normal'}]);const view=await inventoryView();const main=find(view.root,el=>el.type==='main')
 for(const value of ['庫存管理','庫存啞鈴','顏色：黑色','低庫存','缺貨','正常','管理性停用','停用販售'])assert.ok(text(main).includes(value),value)
 const adjustmentButtons=[];const collectButtons=el=>{if(el.type==='button'&&text(el).trim()==='調整庫存')adjustmentButtons.push(el);el.children.forEach(collectButtons)};collectButtons(main)
 assert.ok(!text(main).includes('尚未實作'));assert.equal(adjustmentButtons.length,3);assertInventoryCalls();view.app.unmount()
})
test('Inventory initial loading與empty',async()=>{
 let finish;api.defaults.adapter=config=>{calls.push(config);return config.url==='/categories'?Promise.resolve(response(config,{data:categoryOptions})):new Promise(resolve=>{finish=()=>resolve(response(config,productPage([],1,1)))})}
 const view=await inventoryView();assert.ok(text(view.root).includes('庫存載入中'));finish();await settle();assert.ok(text(view.root).includes('沒有符合條件的庫存'));assertInventoryCalls();view.app.unmount()
})
test('Inventory URL reload/search/category/status/page/back-forward',async()=>{
 api.defaults.adapter=inventoryAdapter();const view=await inventoryView('/admin/inventory?search=old&category_id=2&inventory_status=low_stock&page=2')
 assert.deepEqual(calls.find(c=>c.url==='/admin/inventory').params,{search:'old',category_id:'2',inventory_status:'low_stock',page:'2'})
 await click(view,'上一頁');assert.equal(view.router.currentRoute.value.query.inventory_status,'low_stock');assert.equal(view.router.currentRoute.value.query.page,'1')
 await submitProductFilters(view,{'inventory-search':'PRD-NEW','inventory-category':'2','inventory-status':'normal'});assert.deepEqual({...view.router.currentRoute.value.query},{search:'PRD-NEW',category_id:'2',inventory_status:'normal',page:'1'})
 view.router.back();await settle();assert.equal(find(view.root,el=>el.props.id==='inventory-search').value,'old');view.router.forward();await settle();assert.equal(find(view.root,el=>el.props.id==='inventory-status').value,'normal');view.app.unmount()
 const reload=await inventoryView('/admin/inventory?search=PRD-NEW&category_id=2&inventory_status=normal&page=1');assert.equal(find(reload.root,el=>el.props.id==='inventory-search').value,'PRD-NEW');assertInventoryCalls();reload.app.unmount()
})
test('Inventory舊GET成功/錯誤不覆蓋新結果',async()=>{
 const pending=[];api.defaults.adapter=config=>{calls.push(config);return config.url==='/categories'?Promise.resolve(response(config,{data:categoryOptions})):new Promise((resolve,reject)=>pending.push({config,resolve,reject}))}
 const view=await inventoryView('/admin/inventory?search=old');await view.router.push('/admin/inventory?search=middle');await settle();await view.router.push('/admin/inventory?search=new');await settle()
 pending[2].resolve(response(pending[2].config,productPage([{...inventoryItem,product_name:'最新庫存'}])));await settle();pending[0].resolve(response(pending[0].config,productPage([{...inventoryItem,product_name:'舊庫存'}])));pending[1].reject(failure(pending[1].config,500));await settle();assert.ok(text(view.root).includes('最新庫存'));assert.ok(!text(view.root).includes('舊庫存'));assertInventoryCalls();view.app.unmount()
})
for(const [item,delta,endpoint] of [[inventoryItem,'+5','/admin/inventory/1'],[inventoryVariant,'-3','/admin/inventory/variants/2']])test(`Inventory ${item.stock_owner_type} ${delta} backend值/filter re-fetch/防重送/無optimistic`,async()=>{
 let finish,adjusted=false;api.defaults.adapter=config=>{calls.push(config);if(config.url==='/categories')return Promise.resolve(response(config,{data:categoryOptions}));if(config.method==='patch')return new Promise(resolve=>{finish=()=>{adjusted=true;resolve(response(config,{data:{...item,stock:19,inventory_status:'normal'},message:'庫存調整成功'}))}});return Promise.resolve(response(config,productPage(adjusted?[]:[item],1,1)))}
 const view=await inventoryView('/admin/inventory?inventory_status=low_stock');await click(view,'調整庫存');await inputValue(view,'inventory-adjustment',delta);await submitAdjustment(view);assert.equal(button(view,'調整中…').props.disabled,true);await submitAdjustment(view);assert.equal(calls.filter(c=>c.method==='patch').length,1);assert.ok(text(inventoryPanel(view)).includes('目前庫存：'+item.stock));assert.equal(calls.find(c=>c.method==='patch').url,endpoint);assert.deepEqual(JSON.parse(calls.find(c=>c.method==='patch').data),{adjustment:Number(delta)})
 finish();await settle();assert.ok(text(view.root).includes('最新庫存：19'));assert.ok(text(view.root).includes('沒有符合條件的庫存'));assert.equal(calls.filter(c=>c.url==='/admin/inventory').length,2);assert.equal(calls.filter(c=>c.url==='/admin/inventory').at(-1).params.inventory_status,'low_stock');assertInventoryCalls();view.app.unmount()
})
test('Inventory 0 UX拒絕、放棄不送PATCH',async()=>{api.defaults.adapter=inventoryAdapter();const view=await inventoryView();await click(view,'調整庫存');await inputValue(view,'inventory-adjustment','0');await submitAdjustment(view);assert.ok(text(view.root).includes('非 0 整數'));assert.equal(calls.filter(c=>c.method==='patch').length,0);await click(view,'放棄');assert.equal(inventoryPanel(view),undefined);assertInventoryCalls();view.app.unmount()})
for(const status of [403,419,undefined,500,422]){
 test(`Inventory GET ${status??'network'}一般錯誤/手動retry`,async()=>{
  api.defaults.adapter=config=>{calls.push(config);return config.url==='/categories'?Promise.resolve(response(config,{data:categoryOptions})):Promise.reject(failure(config,status))};const view=await inventoryView();assert.ok(find(view.root,el=>el.props.role==='alert'));assert.equal(auth.currentAdmin.id,admin.id);assert.equal(view.router.currentRoute.value.name,'admin-inventory');assert.equal(calls.filter(c=>c.url==='/admin/inventory').length,1);api.defaults.adapter=inventoryAdapter();await click(view,'重試');assert.ok(text(view.root).includes('庫存啞鈴'));assertInventoryCalls();view.app.unmount()
 })
 test(`Inventory PATCH ${status??'network'}保留stock、不retry、不清member`,async()=>{
  api.defaults.adapter=async config=>{calls.push(config);if(config.method==='patch'){const e=failure(config,status);if(status===422)e.response.data.errors={adjustment:['調整後庫存不可小於 0']};throw e}return response(config,config.url==='/categories'?{data:categoryOptions}:productPage([inventoryItem]))};const view=await inventoryView();await click(view,'調整庫存');await inputValue(view,'inventory-adjustment','-99');await submitAdjustment(view);assert.ok(text(inventoryPanel(view)).includes('目前庫存：3'));if(status===422)assert.ok(text(view.root).includes('調整後庫存不可小於 0'));assert.ok(find(view.root,el=>el.props.role==='alert'));assert.equal(calls.filter(c=>c.method==='patch').length,1);assert.equal(auth.currentAdmin.id,admin.id);assert.equal(auth.adminFailureReason,null);assert.equal(view.router.currentRoute.value.name,'admin-inventory');assertInventoryCalls();view.app.unmount()
 })
}
for(const [status,code]of [[401,undefined],[403,'ADMIN_ACCOUNT_DISABLED']])for(const phase of ['get','patch'])test(`Inventory ${phase} ${status}/${code??''}Admin coordinator/member隔離`,async()=>{
 api.defaults.adapter=config=>{calls.push(config);if(config.url==='/categories')return Promise.resolve(response(config,{data:categoryOptions}));if(config.method===phase)return Promise.reject(failure(config,status,code));return Promise.resolve(response(config,productPage([inventoryItem])))};const view=await inventoryView();if(phase==='patch'){await click(view,'調整庫存');await inputValue(view,'inventory-adjustment','5');await submitAdjustment(view)}await settle();assert.equal(view.router.currentRoute.value.name,'admin-login');assert.equal(auth.currentAdmin,null);assertInventoryCalls();view.app.unmount()
})
test('Inventory晚到PATCH不覆蓋新query、不重查舊條件',async()=>{
 let finish;api.defaults.adapter=config=>{calls.push(config);if(config.method==='patch')return new Promise(resolve=>{finish=()=>resolve(response(config,{data:{...inventoryItem,stock:999},message:'舊調整'}))});return Promise.resolve(response(config,config.url==='/categories'?{data:categoryOptions}:productPage([{...inventoryItem,product_name:config.params.search==='new'?'新條件':'舊條件'}])))}
 const view=await inventoryView('/admin/inventory?search=old');await click(view,'調整庫存');await inputValue(view,'inventory-adjustment','5');await submitAdjustment(view);await view.router.push('/admin/inventory?search=new');await settle();const count=calls.length;finish();await settle();assert.ok(text(view.root).includes('新條件'));assert.ok(!text(view.root).includes('999'));assert.equal(calls.length,count);assertInventoryCalls();view.app.unmount()
})

test('Inventory調整成功後GET失敗：保留成功證據、手動只重試GET',async()=>{
 let adjusted=false,failRefresh=true
 api.defaults.adapter=async config=>{calls.push(config);if(config.url==='/categories')return response(config,{data:categoryOptions});if(config.method==='patch'){adjusted=true;return response(config,{data:{...inventoryItem,stock:8,inventory_status:'normal'},message:'庫存調整成功'})}if(adjusted&&failRefresh)throw failure(config,500);return response(config,productPage([{...inventoryItem,stock:adjusted?8:3,inventory_status:adjusted?'normal':'low_stock'}]))}
 const view=await inventoryView();await click(view,'調整庫存');await inputValue(view,'inventory-adjustment','5');await submitAdjustment(view)
 assert.ok(text(view.root).includes('最新庫存：8'));assert.ok(find(view.root,el=>el.props.role==='alert'));assert.equal(inventoryPanel(view),undefined)
 failRefresh=false;await click(view,'重試');assert.equal(calls.filter(c=>c.method==='patch').length,1);assert.ok(text(view.root).includes('正常'));assertInventoryCalls();view.app.unmount()
})
test('Inventory分類選項失敗可獨立重試，只用公開categories',async()=>{
 let failCategory=true;api.defaults.adapter=async config=>{calls.push(config);if(config.url==='/categories'){if(failCategory)throw failure(config,500);return response(config,{data:categoryOptions})}return response(config,productPage([inventoryItem]))}
 const view=await inventoryView();assert.ok(text(view.root).includes('分類選項載入失敗'));assert.ok(text(view.root).includes('庫存啞鈴'));failCategory=false;await click(view,'重試分類');assert.ok(!text(view.root).includes('分類選項載入失敗'));assertInventoryCalls();view.app.unmount()
})

const categoriesView = async () => {
  auth.isAdminInitialized = true
  return mountRoute('/admin/categories')
}
const assertCategoryCalls = () => {
  assert.ok(calls.every(c => c.url === '/admin/categories' && c.method === 'get'))
  assertMemberUntouched()
}

test('Admin分類service只透過獨立adminApi讀取完整envelope，不借用公開分類', async () => {
  memberApi.defaults.adapter = () => { throw new Error('Admin不能使用會員transport') }
  const result = await categoryService.getAdminCategories()
  assert.deepEqual(result, { data: adminCategoryTree })
  assert.equal(calls.length, 1); assertCategoryCalls()
})

test('分類頁取代placeholder，顯示兩態、排序、children/product counts與無子分類狀態', async () => {
  const view = await categoriesView()
  const main = find(view.root, el => el.type === 'main')
  for (const expected of ['商品分類管理', '管理根分類', '啟用子分類', '停用子分類', '空根分類',
    '狀態：啟用', '狀態：停用', '排序：2', '子分類：2', '商品：3', '商品：0', '此分類尚無子分類']) {
    assert.ok(text(main).includes(expected), expected)
  }
  assert.ok(!text(main).includes('尚未實作'))
  assert.ok(button(view, '新增主分類')); assert.ok(button(view, '新增子分類'))
  assert.equal(find(main, el => el.type === 'form'), undefined, '未開表單時不提供搜尋或分頁')
  assertCategoryCalls(); view.app.unmount()
})

test('分類頁initial loading與空data狀態，不顯示假分類', async () => {
  let finish
  api.defaults.adapter = config => { calls.push(config); return new Promise(resolve => {
    finish = () => resolve(response(config, { data: [] }))
  }) }
  const view = await categoriesView()
  assert.ok(text(view.root).includes('分類載入中'))
  assert.ok(!text(view.root).includes('目前沒有分類'))
  finish(); await settle()
  assert.ok(text(view.root).includes('目前沒有分類'))
  assertCategoryCalls(); view.app.unmount()
})

for (const status of [403, 419, undefined, 500]) {
  test(`分類GET ${status ?? 'network'}為一般錯誤，手動重試GET，不清Admin或會員`, async () => {
    api.defaults.adapter = config => { calls.push(config); return Promise.reject(failure(config, status)) }
    const view = await categoriesView()
    assert.ok(find(view.root, el => el.props.role === 'alert'))
    assert.equal(auth.currentAdmin.id, admin.id); assert.equal(auth.adminFailureReason, null)
    assert.equal(view.router.currentRoute.value.name, 'admin-categories')
    assert.equal(calls.length, 1, '沒有自動重送')
    api.defaults.adapter = async config => { calls.push(config); return response(config, { data: adminCategoryTree }) }
    await find(view.root, el => el.type === 'button' && text(el) === '重試').props.onClick(); await settle()
    assert.ok(text(view.root).includes('管理根分類'))
    assert.equal(find(view.root, el => el.props.role === 'alert'), undefined)
    assert.equal(calls.length, 2); assertCategoryCalls(); view.app.unmount()
  })
}

for (const [status, code] of [[401, undefined], [403, 'ADMIN_ACCOUNT_DISABLED']]) {
  test(`分類GET ${status}/${code ?? ''}沿用Admin coordinator，會員/cart/localStorage保留`, async () => {
    api.defaults.adapter = config => { calls.push(config); return Promise.reject(failure(config, status, code)) }
    const view = await categoriesView(); await settle()
    assert.equal(auth.currentAdmin, null)
    assert.equal(view.router.currentRoute.value.name, 'admin-login')
    if (code) assert.ok(text(view.root).includes('後端停用原因'))
    assertCategoryCalls(); view.app.unmount()
  })
}

test('分類快速重試：晚到舊成功／失敗均不覆蓋最新管理樹', async () => {
  api.defaults.adapter = config => { calls.push(config); return Promise.reject(failure(config, 500)) }
  const view = await categoriesView()
  const retry = find(view.root, el => el.type === 'button' && text(el) === '重試').props.onClick
  const pending = []
  api.defaults.adapter = config => { calls.push(config); return new Promise((resolve, reject) => pending.push({ config, resolve, reject })) }
  const first = retry(), second = retry(), newest = retry(); await settle()
  assert.equal(pending.length, 3)
  pending[2].resolve(response(pending[2].config, { data: [{ ...adminCategoryTree[0], name: '最新分類' }] }))
  await newest; await settle()
  pending[0].resolve(response(pending[0].config, { data: [{ ...adminCategoryTree[0], name: '過時分類' }] }))
  pending[1].reject(failure(pending[1].config, 500))
  await Promise.all([first, second]); await settle()
  assert.ok(text(view.root).includes('最新分類')); assert.ok(!text(view.root).includes('過時分類'))
  assert.equal(find(view.root, el => el.props.role === 'alert'), undefined)
  assertCategoryCalls(); view.app.unmount()
})

test('離開分類route後晚到GET不覆蓋其他模組，返回時重新讀取', async () => {
  let finish
  api.defaults.adapter = config => { calls.push(config); return new Promise(resolve => {
    finish = () => resolve(response(config, { data: adminCategoryTree }))
  }) }
  const view = await categoriesView()
  await view.router.push('/admin/dashboard'); await settle()
  finish(); await settle()
  const main = find(view.root, el => el.type === 'main')
  assert.ok(text(main).includes('Dashboard')); assert.ok(!text(main).includes('管理根分類'))
  api.defaults.adapter = async config => { calls.push(config); return response(config, { data: adminCategoryTree }) }
  await view.router.push('/admin/categories'); await settle()
  assert.ok(text(view.root).includes('管理根分類')); assert.equal(calls.length, 2)
  assertCategoryCalls(); view.app.unmount()
})

test('分類protected URL重新開啟先restoreAdmin，再GET管理樹', async () => {
  auth.currentAdmin = null; auth.isAdminInitialized = false
  api.defaults.adapter = async config => { calls.push(config); return response(config,
    { data: config.url === '/admin/me' ? admin : adminCategoryTree }) }
  const view = await mountRoute('/admin/categories')
  assert.equal(view.router.currentRoute.value.name, 'admin-categories')
  assert.deepEqual(calls.map(c => c.url), ['/admin/me', '/admin/categories'])
  assert.ok(text(view.root).includes('管理根分類')); assertMemberUntouched(); view.app.unmount()
})

const categoryMutationRow = (payload, id = 20) => ({ id, parent_id: null, name: 'server category', status: 'active',
  sort_order: 0, children_count: 0, product_count: 0, children: [], created_at: null, updated_at: null, ...payload })
const categoryMutationAdapter = handler => async config => {
  calls.push(config)
  if (config.method === 'get') return response(config, { data: adminCategoryTree })
  return handler(config)
}
const submitCategory = async view => {
  const form = find(view.root, el => el.type === 'form' && el.props['aria-label'] === '分類維護表單')
  assert.ok(form); form.props.onSubmit({ preventDefault() {} }); await settle()
}
const assertCategoryMutationBoundary = () => {
  assert.ok(calls.every(c => /^\/admin\/categories(?:\/\d+)?$/.test(c.url) && ['get', 'post', 'patch'].includes(c.method)))
  assertMemberUntouched()
}

test('Category create/update service only uses POST/PATCH data/message', async () => {
  api.defaults.adapter = categoryMutationAdapter(c => response(c,
    { data: categoryMutationRow(JSON.parse(c.data)), message: 'backend success' }, c.method === 'post' ? 201 : 200))
  const created = await categoryService.createAdminCategory({ name: 'root', status: 'inactive' })
  const updated = await categoryService.updateAdminCategory(20, { name: 'edited', parent_id: 4, sort_order: 3 })
  assert.equal(created.data.status, 'inactive'); assert.equal(updated.data.parent_id, 4)
  assert.deepEqual(calls.map(c => [c.method, c.url]), [['post', '/admin/categories'], ['patch', '/admin/categories/20']])
  assert.equal(updated.message, 'backend success'); assertCategoryMutationBoundary()
})

for (const [role, initialStatus] of [['root', 'active'], ['root', 'inactive'], ['child', 'active'], ['child', 'inactive']]) {
  test(`Category create ${role}/${initialStatus}，role固定、parent只active、初始狀態與sort payload`, async () => {
    let finish
    api.defaults.adapter = categoryMutationAdapter(c => new Promise(resolve => {
      finish = () => resolve(response(c, { data: categoryMutationRow(JSON.parse(c.data)), message: '建立成功' }, 201))
    }))
    const view = await categoriesView(); await click(view, role === 'root' ? '新增主分類' : '新增子分類')
    assert.ok(text(view.root).includes('建立後不可變更'))
    assert.equal(find(view.root, el => el.props.id === 'category-role'), undefined)
    if (role === 'child') {
      const parent = find(view.root, el => el.props.id === 'category-parent')
      assert.deepEqual(parent.options.map(option => option.value), ['', 4])
      await inputValue(view, 'category-parent', '4')
    } else assert.equal(find(view.root, el => el.props.id === 'category-parent'), undefined)
    const initialStatusSelect = find(view.root, el => el.props.id === 'category-initial-status')
    assert.equal(initialStatusSelect.options[initialStatusSelect.selectedIndex].value, 'active')
    await inputValue(view, 'category-name', '  新分類  '); await inputValue(view, 'category-sort', '7')
    await inputValue(view, 'category-initial-status', initialStatus); await submitCategory(view)
    assert.equal(button(view, '儲存中…').props.disabled, true)
    await submitCategory(view); assert.equal(mutations().length, 1)
    assert.deepEqual(JSON.parse(mutations()[0].data), { name: '新分類', parent_id: role === 'root' ? null : 4, sort_order: 7, status: initialStatus })
    assert.equal(calls.filter(c => c.method === 'get').length, 1, '成功前不刷新')
    assert.ok(!text(view.root).includes('建立成功'), '沒有optimistic成功')
    finish(); await settle()
    assert.ok(text(view.root).includes('建立成功')); assert.equal(calls.filter(c => c.method === 'get').length, 2)
    assert.equal(find(view.root, el => el.type === 'form'), undefined)
    assertCategoryMutationBoundary(); view.app.unmount()
  })
}

for (const role of ['root', 'child']) {
  test(`Category edit ${role}：role固定、沒有status field，原inactive parent可顯示/保留`, async () => {
    api.defaults.adapter = categoryMutationAdapter(c => response(c,
      { data: categoryMutationRow(JSON.parse(c.data), role === 'root' ? 1 : 2), message: '更新成功' }))
    const view = await categoriesView(); await click(view, role === 'root' ? '編輯主分類' : '編輯子分類')
    assert.equal(find(view.root, el => el.props.id === 'category-initial-status'), undefined)
    assert.ok(text(view.root).includes('建立後不可變更'))
    if (role === 'child') {
      const parent = find(view.root, el => el.props.id === 'category-parent')
      assert.equal(parent.options[parent.selectedIndex].value, 1); assert.deepEqual(parent.options.map(option => option.value), ['', 1, 4])
      assert.ok(text(parent).includes('目前停用，可保留'))
    }
    await inputValue(view, 'category-name', '改名'); await inputValue(view, 'category-sort', '6'); await submitCategory(view)
    assert.deepEqual(JSON.parse(mutations()[0].data), { name: '改名', parent_id: role === 'root' ? null : 1, sort_order: 6 })
    assert.equal(mutations()[0].url, '/admin/categories/' + (role === 'root' ? 1 : 2))
    assertCategoryMutationBoundary(); view.app.unmount()
  })
}

test('Category child reparent active root：不列其他inactive root，送目標parent', async () => {
  api.defaults.adapter = async c => { calls.push(c); return response(c, c.method === 'get'
    ? { data: [...adminCategoryTree, { ...adminCategoryTree[1], id: 8, status: 'inactive', name: '不可新選' }] }
    : { data: categoryMutationRow(JSON.parse(c.data), 2), message: '搬移成功' }) }
  const view = await categoriesView(); await click(view, '編輯子分類')
  const parent = find(view.root, el => el.props.id === 'category-parent')
  assert.deepEqual(parent.options.map(option => option.value), ['', 1, 4])
  await inputValue(view, 'category-parent', '4'); await submitCategory(view)
  assert.equal(JSON.parse(mutations()[0].data).parent_id, 4)
  assertCategoryMutationBoundary(); view.app.unmount()
})

for (const [role, field, reason] of [['root', 'name', '主分類名稱重複'], ['child', 'name', '兄弟分類名稱重複'], ['child', 'parent_id', '層級不合法']]) {
  test(`Category 422 ${reason}：保留表單/draft，不假成功或重送`, async () => {
    api.defaults.adapter = categoryMutationAdapter(c => {
      const error = failure(c, 422); error.response.data.errors = { [field]: [reason] }; throw error
    })
    const view = await categoriesView(); await click(view, role === 'root' ? '新增主分類' : '新增子分類')
    await inputValue(view, 'category-name', '保留草稿'); if (role === 'child') await inputValue(view, 'category-parent', '4')
    await submitCategory(view)
    assert.ok(text(view.root).includes(reason)); assert.equal(find(view.root, el => el.props.id === 'category-name').value, '保留草稿')
    assert.equal(mutations().length, 1); assert.equal(calls.filter(c => c.method === 'get').length, 1)
    assertCategoryMutationBoundary(); view.app.unmount()
  })
}

for (const status of [403, 419, undefined, 500]) {
  test(`Category mutation ${status ?? 'network'}：保留Admin/member/cart/draft，不自動重送`, async () => {
    api.defaults.adapter = categoryMutationAdapter(c => Promise.reject(failure(c, status)))
    const view = await categoriesView(); await click(view, '編輯子分類'); await inputValue(view, 'category-name', '草稿')
    await submitCategory(view)
    assert.ok(find(view.root, el => el.props.role === 'alert')); assert.equal(auth.currentAdmin.id, admin.id)
    assert.equal(auth.adminFailureReason, null); assert.equal(view.router.currentRoute.value.name, 'admin-categories')
    assert.equal(mutations().length, 1); assert.equal(find(view.root, el => el.props.id === 'category-name').value, '草稿')
    assertCategoryMutationBoundary(); view.app.unmount()
  })
}

for (const [status, code] of [[401, undefined], [403, 'ADMIN_ACCOUNT_DISABLED']]) {
  test(`Category mutation ${status}/${code ?? ''}：沿用Admin coordinator，member隔離`, async () => {
    api.defaults.adapter = categoryMutationAdapter(c => Promise.reject(failure(c, status, code)))
    const view = await categoriesView(); await click(view, '新增主分類'); await inputValue(view, 'category-name', 'draft')
    await submitCategory(view); await settle()
    assert.equal(view.router.currentRoute.value.name, 'admin-login'); assert.equal(auth.currentAdmin, null)
    assert.equal(mutations().length, 1); assertCategoryMutationBoundary(); view.app.unmount()
  })
}

for (const method of ['post', 'patch']) for (const outcome of ['success', 'failure']) {
  test(`Category pending ${method}/${outcome}：禁止同頁context切換，完成才刷新或保留draft`, async () => {
    let finish, completed = false
    api.defaults.adapter = c => {
      calls.push(c)
      if (c.method === 'get') return Promise.resolve(response(c, { data: completed
        ? [{ ...adminCategoryTree[0], name: '權威新樹' }, adminCategoryTree[1]] : adminCategoryTree }))
      return new Promise((resolve, reject) => {
        finish = () => {
          completed = outcome === 'success'
          if (completed) resolve(response(c, { data: categoryMutationRow({ name: 'backend name' }), message: '儲存成功' }))
          else { const error = failure(c, 422); error.response.data.errors = { name: ['後端拒絕原因'] }; reject(error) }
        }
      })
    }
    const view = await categoriesView(); await click(view, method === 'post' ? '新增主分類' : '編輯主分類')
    await inputValue(view, 'category-name', '保留草稿')
    const originalForm = find(view.root, el => el.type === 'form')
    await submitCategory(view)
    assert.equal(button(view, '儲存中…').props.disabled, true)
    const controls = []
    const collect = el => {
      if (el.type === 'button' && ['放棄', '新增主分類', '新增子分類', '編輯主分類', '編輯子分類'].includes(text(el).trim())) controls.push(el)
      el.children.forEach(collect)
    }
    collect(view.root)
    assert.equal(controls.length, 7, '含所有root/child edit controls')
    for (const label of ['啟用主分類','停用主分類','啟用子分類','停用子分類','刪除主分類','刪除子分類']) {
      const control = button(view,label)
      assert.equal(control.props.disabled,true)
      control.props.onClick(); await settle()
      assert.equal(find(view.root,el=>el.type==='form'),originalForm)
      assert.equal(find(view.root,el=>el.props['aria-label']==='確認分類狀態'),undefined)
    }
    for (const control of controls) {
      assert.equal(control.props.disabled, true)
      // 直接呼叫handler，另外驗證程式防線，不只依賴瀏覽器disabled。
      control.props.onClick(); await settle()
      assert.equal(find(view.root, el => el.type === 'form'), originalForm)
      assert.equal(find(view.root, el => el.props.id === 'category-name').value, '保留草稿')
    }
    // 直接模擬 child emit(cancel)，驗 parent closeForm 的防線。
    const findComponent = vnode => {
      if (!vnode || typeof vnode !== 'object') return undefined
      if (typeof vnode.component?.props.context === 'number' && ['root', 'child'].includes(vnode.component.props.role)) return vnode.component
      return findComponent(vnode.component?.subTree) || (Array.isArray(vnode.children)
        ? vnode.children.map(findComponent).find(Boolean) : undefined)
    }
    const formComponent = findComponent(view.app._instance.subTree)
    assert.ok(formComponent); formComponent.emit('cancel'); await settle()
    assert.equal(find(view.root, el => el.type === 'form'), originalForm)
    await submitCategory(view)
    assert.equal(mutations().length, 1); assert.equal(mutations()[0].method, method)
    assert.equal(calls.filter(c => c.method === 'get').length, 1)
    assert.ok(!text(view.root).includes('權威新樹')); assert.ok(!text(view.root).includes('儲存成功'))
    finish(); await settle()
    if (outcome === 'success') {
      assert.equal(find(view.root, el => el.type === 'form'), undefined)
      assert.equal(calls.filter(c => c.method === 'get').length, 2)
      assert.ok(text(view.root).includes('權威新樹')); assert.ok(text(view.root).includes('儲存成功'))
    } else {
      assert.equal(find(view.root, el => el.type === 'form'), originalForm)
      assert.equal(find(view.root, el => el.props.id === 'category-name').value, '保留草稿')
      assert.ok(text(view.root).includes('後端拒絕原因')); assert.equal(button(view, '儲存分類').props.disabled, false)
      assert.equal(button(view, '放棄').props.disabled, false)
      assert.equal(calls.filter(c => c.method === 'get').length, 1)
    }
    for (const label of ['新增主分類', '新增子分類', '編輯主分類', '編輯子分類']) assert.equal(button(view, label).props.disabled, false)
    assert.equal(mutations().length, 1); assertCategoryMutationBoundary(); view.app.unmount()
  })
}

test('Category mutation離頁後晚到success不導航、不刷新、不污染另一模組', async () => {
  let finish
  api.defaults.adapter = categoryMutationAdapter(c => new Promise(resolve => {
    finish = () => resolve(response(c, { data: categoryMutationRow({}), message: '舊成功' }))
  }))
  const view = await categoriesView(); await click(view, '新增主分類'); await inputValue(view, 'category-name', 'draft'); await submitCategory(view)
  await view.router.push('/admin/dashboard'); await settle(); const count = calls.length
  finish(); await settle()
  assert.equal(view.router.currentRoute.value.name, 'admin-dashboard'); assert.ok(!text(view.root).includes('舊成功'))
  assert.equal(calls.length, count); assertCategoryMutationBoundary(); view.app.unmount()
})

test('Category表單blank/negative sort/未選parent拒絕，放棄不送mutation', async () => {
  const view = await categoriesView(); await click(view, '新增子分類'); await inputValue(view, 'category-name', ' ')
  await inputValue(view, 'category-sort', '-1'); await submitCategory(view)
  for (const reason of ['請輸入分類名稱', '非負整數', '請選擇主分類']) assert.ok(text(view.root).includes(reason))
  assert.equal(mutations().length, 0); await click(view, '放棄'); assert.equal(find(view.root, el => el.type === 'form'), undefined)
  assertCategoryMutationBoundary(); view.app.unmount()
})

for (const [label, id, desired] of [['啟用主分類',1,'active'], ['停用主分類',4,'inactive'], ['停用子分類',2,'inactive'], ['啟用子分類',3,'active']]) {
  test(`Category status ${label}: confirmation/status-only payload/no optimistic/authoritative GET`, async () => {
    let finish, completed=false
    api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:completed?[{...adminCategoryTree[0],name:'status權威樹'},adminCategoryTree[1]]:adminCategoryTree}));return new Promise(resolve=>{finish=()=>{completed=true;resolve(response(c,{data:categoryMutationRow({id,status:desired}),message:'狀態已更新'}))}})}
    const view=await categoriesView();await click(view,label)
    const message=text(view.root)
    assert.ok(message.includes(desired==='inactive'?'商品暫時不可公開／不可購買':'商品是否可販售仍依主分類'))
    assert.equal(mutations().length,0)
    await click(view,'確認變更狀態')
    const collect=el=>{if(el.type==='button'&&['新增主分類','新增子分類','編輯主分類','編輯子分類','啟用主分類','停用主分類','啟用子分類','停用子分類','確認變更狀態','取消狀態變更','刪除主分類','刪除子分類'].includes(text(el).trim())){assert.equal(el.props.disabled,true);el.props.onClick()}el.children.forEach(collect)}
    collect(view.root);await settle()
    assert.equal(find(view.root,el=>el.type==='form'),undefined)
    assert.equal(mutations().length,1)
    assert.equal(mutations()[0].url,`/admin/categories/${id}/status`)
    assert.deepEqual(JSON.parse(mutations()[0].data),{status:desired})
    assert.ok(!text(view.root).includes('status權威樹'))
    finish();await settle();await settle()
    assert.ok(text(view.root).includes('status權威樹'));assert.ok(text(view.root).includes('狀態已更新'))
    assert.equal(calls.filter(c=>c.method==='get').length,2);assert.equal(button(view,'新增主分類').props.disabled,false)
    assertMemberUntouched();view.app.unmount()
  })
}
for(const [status,code] of [[422],[403],[419],[undefined],[500],[401],[403,'ADMIN_ACCOUNT_DISABLED']]) {
 test(`Category status failure ${status}/${code??''}: no retry/old tree/member isolation`,async()=>{
  api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:adminCategoryTree}));const e=failure(c,status,code);if(status===422)e.response.data.errors={status:['分類拒絕原因']};return Promise.reject(e)}
  const view=await categoriesView();await click(view,'停用子分類');await click(view,'確認變更狀態');await settle()
  assert.equal(mutations().length,1);assert.equal(mutations()[0].method,'patch');assertMemberUntouched()
  if(status===401||code){assert.equal(auth.currentAdmin,null);assert.equal(view.router.currentRoute.value.name,'admin-login')}
  else {assert.ok(auth.currentAdmin);assert.ok(text(view.root).includes(status===422?'分類拒絕原因':status?'後端停用原因':'分類狀態更新失敗'));assert.ok(text(view.root).includes('啟用子分類'));assert.equal(button(view,'確認變更狀態').props.disabled,false);assert.equal(calls.filter(c=>c.method==='get').length,1)}
  view.app.unmount()
 })
}
test('Category status route leave keeps stale protection and never refreshes another module',async()=>{
 let finish;api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:adminCategoryTree}));return new Promise(resolve=>{finish=()=>resolve(response(c,{data:categoryMutationRow(),message:'late status success'}))})}
 const view=await categoriesView();await click(view,'啟用主分類');await click(view,'確認變更狀態');await view.router.push('/admin/dashboard');await settle();finish();await settle()
 assert.equal(view.router.currentRoute.value.path,'/admin/dashboard');assert.ok(!text(view.root).includes('late status success'));assert.equal(calls.filter(c=>c.method==='get').length,1);assertMemberUntouched();view.app.unmount()
})

const assertCategoryDeleteBoundary = () => {
  assert.ok(calls.every(c => c.method === 'get' ? c.url === '/admin/categories' : c.method === 'delete' && /^\/admin\/categories\/\d+$/.test(c.url)))
  assertMemberUntouched()
}
for (const [role, label, name, id] of [['root','刪除主分類','管理根分類',1], ['child','刪除子分類','啟用子分類',2]]) {
  test(`Category DELETE ${role}: confirmation name/type/warning; cancel sends nothing`, async () => {
    const view=await categoriesView();await click(view,label)
    const dialog=find(view.root,el=>el.props['aria-label']==='確認刪除分類')
    assert.ok(text(dialog).includes(role==='root'?'主分類':'子分類'));assert.ok(text(dialog).includes(name))
    assert.ok(text(dialog).includes('永久刪除此分類，無法復原'));assert.ok(text(dialog).includes('若分類仍有子分類或商品，後端會拒絕刪除'))
    assert.equal(mutations().length,0);await click(view,'取消刪除')
    assert.equal(find(view.root,el=>el.props['aria-label']==='確認刪除分類'),undefined)
    assert.equal(mutations().length,0);assertCategoryDeleteBoundary();view.app.unmount()
  })
  test(`Category DELETE ${role}: pending mutex/no optimistic/no payload/authority GET`,async()=>{
    let finish,completed=false
    api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:completed?[{...adminCategoryTree[0],name:'刪除後權威樹'},adminCategoryTree[1]]:adminCategoryTree}));return new Promise(resolve=>{finish=()=>{completed=true;resolve(response(c,{message:'後端刪除成功'}))}})}
    const view=await categoriesView();await click(view,label);await click(view,'確認刪除分類')
    assert.equal(mutations().length,1);assert.equal(mutations()[0].url,`/admin/categories/${id}`);assert.equal(mutations()[0].data,undefined)
    assert.ok(text(view.root).includes(name));assert.ok(!text(view.root).includes('刪除後權威樹'))
    const blocked=['新增主分類','新增子分類','編輯主分類','編輯子分類','啟用主分類','停用主分類','啟用子分類','停用子分類','刪除主分類','刪除子分類','確認刪除分類','取消刪除']
    const collect=el=>{if(el.type==='button'&&blocked.includes(text(el).trim())){assert.equal(el.props.disabled,true);el.props.onClick()}el.children.forEach(collect)}
    collect(view.root);await settle()
    assert.equal(mutations().length,1);assert.equal(find(view.root,el=>el.type==='form'),undefined);assert.equal(find(view.root,el=>el.props['aria-label']==='確認分類狀態'),undefined)
    finish();await settle();await settle()
    assert.equal(find(view.root,el=>el.props['aria-label']==='確認刪除分類'),undefined)
    assert.ok(text(view.root).includes('後端刪除成功'));assert.ok(text(view.root).includes('刪除後權威樹'))
    assert.equal(calls.filter(c=>c.method==='get').length,2);assert.equal(button(view,'新增主分類').props.disabled,false)
    assertCategoryDeleteBoundary();view.app.unmount()
  })
}
for(const reason of ['此分類仍有子分類，無法刪除。','此分類仍有商品使用，無法刪除。']) {
 test(`Category DELETE 422 business reason: ${reason}`,async()=>{
  api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:adminCategoryTree}));const e=failure(c,422);e.response.data.errors={category:[reason]};return Promise.reject(e)}
  const view=await categoriesView();await click(view,'刪除主分類');await click(view,'確認刪除分類');await settle()
  assert.ok(text(view.root).includes(reason));assert.ok(text(view.root).includes('管理根分類'));assert.ok(text(view.root).includes('啟用子分類'))
  assert.equal(button(view,'確認刪除分類').props.disabled,false);assert.equal(button(view,'新增主分類').props.disabled,false)
  assert.equal(mutations().length,1);assert.equal(calls.filter(c=>c.method==='get').length,1);assertCategoryDeleteBoundary();view.app.unmount()
 })
}
for(const [status,code] of [[403],[419],[undefined],[500],[401],[403,'ADMIN_ACCOUNT_DISABLED']]) {
 test(`Category DELETE error ${status}/${code??''}: member isolation/no auto retry`,async()=>{
  api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:adminCategoryTree}));return Promise.reject(failure(c,status,code))}
  const view=await categoriesView();await click(view,'刪除子分類');await click(view,'確認刪除分類');await settle()
  assert.equal(mutations().length,1);assertCategoryDeleteBoundary()
  if(status===401||code){assert.equal(auth.currentAdmin,null);assert.equal(view.router.currentRoute.value.name,'admin-login')}
  else {assert.ok(auth.currentAdmin);assert.ok(text(view.root).includes(status?'後端停用原因':'分類刪除失敗'));assert.ok(text(view.root).includes('啟用子分類'));assert.equal(button(view,'確認刪除分類').props.disabled,false);assert.equal(button(view,'刪除子分類').props.disabled,false);assert.equal(calls.filter(c=>c.method==='get').length,1)}
  view.app.unmount()
 })
}
for(const outcome of ['success','failure']) {
 test(`Category DELETE route leave late ${outcome}: no navigation/message/GET contamination`,async()=>{
  let finish;api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:adminCategoryTree}));return new Promise((resolve,reject)=>{finish=()=>outcome==='success'?resolve(response(c,{message:'late delete success'})):reject(failure(c,500))})}
  const view=await categoriesView();await click(view,'刪除主分類');await click(view,'確認刪除分類');await view.router.push('/admin/dashboard');await settle();finish();await settle()
  assert.equal(view.router.currentRoute.value.path,'/admin/dashboard');assert.ok(!text(view.root).includes('late delete success'));assert.ok(!text(view.root).includes('後端停用原因'));assert.equal(calls.filter(c=>c.method==='get').length,1);assertCategoryDeleteBoundary();view.app.unmount()
 })
}
test('Category DELETE service reuses message-only type and adminApi without body',async()=>{
 api.defaults.adapter=c=>{calls.push(c);return Promise.resolve(response(c,{message:'刪除回應'}))}
 const result=await categoryService.deleteAdminCategory(7);assert.deepEqual(result,{message:'刪除回應'});assert.equal(calls[0].method,'delete');assert.equal(calls[0].url,'/admin/categories/7');assert.equal(calls[0].data,undefined);assertCategoryDeleteBoundary()
})

// Stage 21 Step 1: real SFCs / memory router / Axios adapter; not browser acceptance.
const ordersView = async (url = '/admin/orders') => {
  auth.isAdminInitialized = true
  return mountRoute(url)
}
const assertReadOnlyOrderCalls = () => {
  assert.ok(calls.every(c => c.method === 'get' && /^\/admin\/orders(?:\/\d+)?$/.test(c.url)))
  assertMemberUntouched()
}
test('Admin Order service only GETs list/detail through independent adminApi, passes query/envelopes', async () => {
  const params = { search: 'HF-', order_status: 'pending', payment_status: 'paid', date_from: '2026-10-05', date_to: '2026-10-05', page: 2 }
  assert.deepEqual(await orderService.getAdminOrders(params), adminOrderPage())
  assert.deepEqual(await orderService.getAdminOrder('1'), adminOrderDetail)
  assert.deepEqual(calls.map(c => [c.method, c.url]), [['get', '/admin/orders'], ['get', '/admin/orders/1']])
  assert.deepEqual(calls[0].params, params); assertReadOnlyOrderCalls()
})
test('Admin orders replace placeholder, current identity/list fields/detail link, no mutation controls', async () => {
  const view = await ordersView()
  const main = find(view.root, el => el.type === 'main')
  for (const value of ['訂單管理', adminOrderSummary.order_no, '目前會員姓名', 'current@example.test', '待處理', '未付款']) assert.ok(text(main).includes(value))
  for (const value of ['尚未實作', '下單時收件人', '下單時商品名', '取消訂單', '更新付款']) assert.ok(!text(main).includes(value))
  assert.equal(find(main, el => el.type === 'button' && ['出貨', '處理中', '完成', '取消', '更新付款'].includes(text(el).trim())), undefined)
  assert.ok(find(main, el => el.type === 'a' && el.props.href === '/admin/orders/1'))
  assertReadOnlyOrderCalls(); view.app.unmount()
})
test('Admin orders initial loading waits for GET and then renders', async () => {
  let finish
  api.defaults.adapter = c => { calls.push(c); return new Promise(resolve => { finish = () => resolve(response(c, adminOrderPage())) }) }
  const view = await ordersView(); assert.ok(text(view.root).includes('訂單載入中'))
  finish(); await settle(); assert.ok(text(view.root).includes(adminOrderSummary.order_no)); assertReadOnlyOrderCalls(); view.app.unmount()
})
test('Admin orders empty/reload query reconstruction and pagination preserve every filter', async () => {
  api.defaults.adapter = async c => { calls.push(c); return response(c, adminOrderPage([], Number(c.params.page), 2)) }
  const query = { search: 'HF-', order_status: 'pending', payment_status: 'unpaid', date_from: '2026-10-01', date_to: '2026-10-05', page: '2' }
  const view = await ordersView('/admin/orders?' + new URLSearchParams(query))
  assert.ok(text(view.root).includes('沒有符合條件的訂單')); assert.deepEqual(calls[0].params, query)
  for (const [id, field] of [['order-search','search'], ['order-status','order_status'], ['order-payment-status','payment_status'], ['order-date-from','date_from'], ['order-date-to','date_to']]) {
    const input = find(view.root, el => el.props.id === id)
    assert.equal(input.type === 'select' ? input.options[input.selectedIndex]?.value : input.value, query[field])
  }
  await click(view, '上一頁')
  assert.deepEqual({ ...view.router.currentRoute.value.query }, { ...query, page: '1' })
  assertReadOnlyOrderCalls(); view.app.unmount()
})
test('Admin orders submit search/status/payment/dates resets page; back-forward restores authoritative URL', async () => {
  const view = await ordersView('/admin/orders?search=old&page=2')
  await inputValue(view, 'order-search', 'typed only'); assert.equal(calls.length, 1, 'typing does not issue requests')
  await submitProductFilters(view, { 'order-search': ' HF-READ ', 'order-status': 'processing', 'order-payment-status': 'paid', 'order-date-from': '2026-10-01', 'order-date-to': '2026-10-05' })
  const query = { search: 'HF-READ', order_status: 'processing', payment_status: 'paid', date_from: '2026-10-01', date_to: '2026-10-05', page: '1' }
  assert.deepEqual({ ...view.router.currentRoute.value.query }, query); assert.deepEqual(calls.at(-1).params, query)
  view.router.back(); await settle(); assert.equal(find(view.root, el => el.props.id === 'order-search').value, 'old')
  assert.equal(view.router.currentRoute.value.query.page, '2')
  view.router.forward(); await settle(); assert.deepEqual({ ...view.router.currentRoute.value.query }, query)
  assertReadOnlyOrderCalls(); view.app.unmount()
})
test('Admin orders repeated query values are local format errors, not malformed backend requests', async () => {
  const view = await ordersView('/admin/orders?search=a&search=b')
  assert.ok(text(view.root).includes('查詢條件格式不正確')); assert.equal(calls.length, 0)
  assertMemberUntouched(); view.app.unmount()
})
for (const phase of ['list', 'detail']) {
  for (const status of [403, 419, undefined, 500, 422]) {
    test(`Admin Order ${phase} ${status ?? 'network'} keeps identity/URL, manual retry only`, async () => {
      api.defaults.adapter = c => { calls.push(c); return Promise.reject(failure(c, status)) }
      const url = phase === 'list' ? '/admin/orders?search=keep' : '/admin/orders/1'
      const view = await ordersView(url)
      assert.ok(find(view.root, el => el.props.role === 'alert')); assert.equal(calls.length, 1)
      assert.equal(view.router.currentRoute.value.fullPath, url); assert.equal(auth.currentAdmin.id, admin.id); assert.equal(auth.adminFailureReason, null)
      api.defaults.adapter = async c => { calls.push(c); return response(c, phase === 'list' ? adminOrderPage() : { data: adminOrderDetail }) }
      await click(view, '重試'); assert.ok(text(view.root).includes(adminOrderSummary.order_no))
      assert.equal(calls.length, 2); assertReadOnlyOrderCalls(); view.app.unmount()
    })
  }
  for (const [status, code] of [[401, undefined], [403, 'ADMIN_ACCOUNT_DISABLED']]) {
    test(`Admin Order ${phase} ${status}/${code ?? ''} uses Admin coordinator without member side effects`, async () => {
      api.defaults.adapter = c => { calls.push(c); return Promise.reject(failure(c, status, code)) }
      const view = await ordersView(phase === 'list' ? '/admin/orders' : '/admin/orders/1'); await settle()
      assert.equal(view.router.currentRoute.value.name, 'admin-login'); assert.equal(auth.currentAdmin, null)
      assertReadOnlyOrderCalls(); view.app.unmount()
    })
  }
}
test('Admin Order detail GET snapshot/null logistics, link back keeps filters', async () => {
  const view = await ordersView('/admin/orders/1?search=keep&page=2')
  const main = find(view.root, el => el.type === 'main')
  for (const value of ['目前會員姓名','下單時訂購人','下單時收件人','歷史地址','下單時商品名','SNAP-OLD','顏色：黑色','600.00','1200.00','1300.00','尚未提供']) assert.ok(text(main).includes(value))
  assert.ok(find(main, el => el.type === 'a' && el.props.href === '/admin/orders?search=keep&page=2'))
  assert.ok(button(view, '設為處理中'))
  assertReadOnlyOrderCalls(); view.app.unmount()
})
test('Admin Order detail 404/loading/logistics update and fresh GET on re-entry', async () => {
  let finish
  api.defaults.adapter = c => { calls.push(c); return new Promise((resolve, reject) => { finish = () => reject(failure(c, 404)) }) }
  const view = await ordersView('/admin/orders/1'); assert.ok(text(view.root).includes('訂單詳細載入中'))
  finish(); await settle(); assert.ok(text(view.root).includes('訂單不存在'))
  api.defaults.adapter = async c => { calls.push(c); return response(c, { data: { ...adminOrderDetail, logistics_company: '物流測試', tracking_number: 'TRACK-123' } }) }
  await click(view, '重試'); assert.ok(text(view.root).includes('物流測試')); assert.ok(text(view.root).includes('TRACK-123'))
  await view.router.push('/admin/dashboard'); await settle(); await view.router.push('/admin/orders/1'); await settle()
  assert.equal(calls.length, 3); assertReadOnlyOrderCalls(); view.app.unmount()
})
for (const outcome of ['success', 'error']) {
  for (const condition of ['search', 'page']) {
    test(`Admin Order old list ${condition} ${outcome} cannot override newer URL/result`, async () => {
      let finish
      api.defaults.adapter = c => {
        calls.push(c)
        if (calls.length === 1) return new Promise((resolve, reject) => { finish = () => outcome === 'success'
          ? resolve(response(c, adminOrderPage([{ ...adminOrderSummary, order_no: 'OLD-ORDER' }]))) : reject(failure(c, 500)) })
        return Promise.resolve(response(c, adminOrderPage([{ ...adminOrderSummary, order_no: 'NEW-ORDER' }], 2)))
      }
      const view = await ordersView('/admin/orders?' + (condition === 'search' ? 'search=old' : 'page=1'))
      await view.router.push('/admin/orders?' + (condition === 'search' ? 'search=new&page=2' : 'page=2')); await settle()
      finish(); await settle(); assert.ok(text(view.root).includes('NEW-ORDER')); assert.ok(!text(view.root).includes('OLD-ORDER'))
      assert.equal(find(view.root, el => el.props.role === 'alert'), undefined); assertReadOnlyOrderCalls(); view.app.unmount()
    })
  }
  test(`Admin Order detail id switch ignores old ${outcome}`, async () => {
    let finish
    api.defaults.adapter = c => { calls.push(c); if (c.url.endsWith('/1')) return new Promise((resolve, reject) => { finish = () => outcome === 'success'
      ? resolve(response(c, { data: adminOrderDetail })) : reject(failure(c, 500)) })
      return Promise.resolve(response(c, { data: { ...adminOrderDetail, id: 2, order_no: 'SECOND-ORDER' } })) }
    const view = await ordersView('/admin/orders/1'); await view.router.push('/admin/orders/2'); await settle()
    finish(); await settle(); assert.ok(text(view.root).includes('SECOND-ORDER')); assert.ok(!text(view.root).includes('HF-READ-001'))
    assert.equal(find(view.root, el => el.props.role === 'alert'), undefined); assertReadOnlyOrderCalls(); view.app.unmount()
  })
  for (const phase of ['list', 'detail']) {
    test(`Admin Order ${phase} route leave ignores late ${outcome} without navigation/error contamination`, async () => {
      let finish
      api.defaults.adapter = c => { calls.push(c); return new Promise((resolve, reject) => { finish = () => outcome === 'success'
        ? resolve(response(c, phase === 'list' ? adminOrderPage() : { data: adminOrderDetail })) : reject(failure(c, 500)) }) }
      const view = await ordersView(phase === 'list' ? '/admin/orders' : '/admin/orders/1')
      await view.router.push('/admin/dashboard'); await settle(); finish(); await settle()
      assert.equal(view.router.currentRoute.value.name, 'admin-dashboard'); assert.ok(!text(view.root).includes('HF-READ-001'))
      assert.ok(!text(view.root).includes('後端停用原因')); assert.equal(calls.length, 1); assertReadOnlyOrderCalls(); view.app.unmount()
    })
  }
}

// Stage 21 Step 2: SFC memory rendering + real services, not browser or row-lock acceptance.
const lifecycleBoundary = () => {
  assert.ok(calls.every(c => c.method === 'get' ? /^\/admin\/orders\/\d+$/.test(c.url)
    : c.method === 'patch' && /^\/admin\/orders\/\d+\/(status|payment-status|shipment)$/.test(c.url)))
  assertMemberUntouched()
}
const submitShipment = async view => {
  const form=find(view.root,el=>el.props['aria-label']==='物流維護表單')
  assert.ok(form);form.props.onSubmit({preventDefault(){}});await settle()
}
const lifecycleCases = [
  ['status','pending','unpaid','設為處理中',{order_status:'processing'}],
  ['payment-status','processing','unpaid','標記為已付款',{payment_status:'paid'}],
  ['shipment','processing','unpaid','儲存物流並出貨',{logistics_company:'物流公司',tracking_number:'TRACK-NEW'}],
  ['status','shipped','paid','設為已完成',{order_status:'completed'}],
]
const invokeLifecycle = async (view,endpoint,label) => {
  if(endpoint==='shipment') {
    await inputValue(view,'order-logistics-company',' 物流公司 ')
    await inputValue(view,'order-tracking-number',' TRACK-NEW ')
    await submitShipment(view)
  } else await click(view,label)
}

test('Admin Order lifecycle services use three strict PATCH endpoints/adminApi',async()=>{
  api.defaults.adapter=async c=>{calls.push(c);return response(c,{data:adminOrderDetail,message:'backend message'})}
  const payloads=[{order_status:'processing'},{payment_status:'paid'},{logistics_company:'company',tracking_number:'tracking'}]
  const services=[orderService.updateAdminOrderStatus,orderService.updateAdminOrderPaymentStatus,orderService.updateAdminOrderShipment]
  for(let i=0;i<services.length;i++)assert.deepEqual(await services[i](1,payloads[i]),{data:adminOrderDetail,message:'backend message'})
  assert.deepEqual(calls.map(c=>[c.method,c.url,JSON.parse(c.data)]),payloads.map((p,i)=>['patch',`/admin/orders/1/${['status','payment-status','shipment'][i]}`,p]))
  lifecycleBoundary()
})
for(const [state,paid,statusAction,shipment,payment] of [
  ['pending','unpaid','設為處理中',false,true],['processing','unpaid',null,true,true],
  ['shipped','paid','設為已完成',true,false],['shipped','unpaid',null,true,true],
  ['completed','paid',null,false,false],['completed','unpaid',null,false,true],['cancelled','unpaid',null,false,false],
]) {
 test(`Order lifecycle UI ${state}/${paid}: only legal actions, terminal logistics readonly`,async()=>{
  api.defaults.adapter=async c=>{calls.push(c);return response(c,{data:{...adminOrderDetail,order_status:state,payment_status:paid}})}
  const view=await ordersView('/admin/orders/1')
  assert.equal(Boolean(button(view,'設為處理中')),statusAction==='設為處理中')
  assert.equal(Boolean(button(view,'設為已完成')),statusAction==='設為已完成')
  assert.equal(Boolean(button(view,'標記為已付款')),payment)
  assert.equal(Boolean(find(view.root,el=>el.props['aria-label']==='物流維護表單')),shipment)
  assert.equal(find(view.root,el=>el.type==='select'),undefined)
  if(state==='shipped'&&paid==='unpaid')assert.ok(text(view.root).includes('請先標記為已付款後再完成'))
  assert.equal(mutations().length,0);lifecycleBoundary();view.app.unmount()
 })
}
for(const [endpoint,state,paid,label,payload] of lifecycleCases) {
 test(`Order ${endpoint}/${label}: mutex/direct guards/no optimistic/authoritative GET`,async()=>{
  let finishPatch,finishGet
  const original={...adminOrderDetail,order_status:state,payment_status:paid}
  api.defaults.adapter=c=>{calls.push(c);if(c.method==='patch')return new Promise(resolve=>{finishPatch=()=>resolve(response(c,{data:{...original,order_no:'PATCH-NOT-AUTHORITY'},message:'操作正式成功'}))})
    if(calls.filter(c=>c.method==='get').length===1)return Promise.resolve(response(c,{data:original}))
    return new Promise(resolve=>{finishGet=()=>resolve(response(c,{data:{...original,...payload,order_no:'GET-AUTHORITY'}}))})}
  const view=await ordersView('/admin/orders/1');await invokeLifecycle(view,endpoint,label)
  assert.equal(mutations().length,1);assert.deepEqual(JSON.parse(mutations()[0].data),payload)
  const collect=el=>{if(el.type==='button'){assert.equal(el.props.disabled,true);if(el.props.onClick)el.props.onClick()}el.children.forEach(collect)}
  collect(find(view.root,el=>el.props['aria-label']==='訂單處理操作'))
  const form=find(view.root,el=>el.props['aria-label']==='物流維護表單');if(form)form.props.onSubmit({preventDefault(){}})
  await settle();assert.equal(mutations().length,1);assert.equal(calls.length,2)
  assert.ok(text(view.root).includes('HF-READ-001'));assert.ok(!text(view.root).includes('PATCH-NOT-AUTHORITY'))
  finishPatch();await settle();assert.equal(calls.length,3);assert.ok(text(view.root).includes('操作正式成功'))
  assert.ok(text(view.root).includes('HF-READ-001'));assert.ok(!text(view.root).includes('PATCH-NOT-AUTHORITY'))
  finishGet();await settle();assert.ok(text(view.root).includes('GET-AUTHORITY'))
  assert.equal(button(view,'重新讀取訂單').props.disabled,false);lifecycleBoundary();view.app.unmount()
 })
}
for(const endpoint of ['status','payment-status','shipment']) {
 for(const [status,code] of [[422],[403],[419],[undefined],[500],[401],[403,'ADMIN_ACCOUNT_DISABLED']]) {
  test(`Order ${endpoint} ${status}/${code??''}: backend reason/draft retained/no retry/isolation`,async()=>{
   const original={...adminOrderDetail,order_status:endpoint==='status'?'pending':'processing'}
   api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:original}));const e=failure(c,status,code)
    if(status===422)e.response.data.errors={[endpoint==='status'?'order_status':endpoint==='shipment'?'logistics_company':'payment_status']:['明確業務拒絕原因']}
    return Promise.reject(e)}
   const view=await ordersView('/admin/orders/1')
   await invokeLifecycle(view,endpoint,endpoint==='status'?'設為處理中':'標記為已付款');await settle()
   assert.equal(mutations().length,1);assert.equal(calls.filter(c=>c.method==='get').length,1)
   if(status===401||code){assert.equal(view.router.currentRoute.value.name,'admin-login');assert.equal(auth.currentAdmin,null)}
   else {
    assert.ok(text(view.root).includes('HF-READ-001'));assert.ok(text(view.root).includes(status===422?'明確業務拒絕原因':status?'後端停用原因':'訂單操作失敗'))
    assert.equal(button(view,'重新讀取訂單').props.disabled,false);assert.ok(auth.currentAdmin);assert.equal(auth.adminFailureReason,null)
    if(endpoint==='shipment')assert.equal(find(view.root,el=>el.props.id==='order-tracking-number').value,' TRACK-NEW ')
   }
   lifecycleBoundary();view.app.unmount()
  })
 }
}

test('Order shipment correction keeps shipped and sends both trimmed fields, payment unchanged',async()=>{
 let current={...adminOrderDetail,order_status:'shipped',logistics_company:'old',tracking_number:'old-no'}
 api.defaults.adapter=async c=>{calls.push(c);if(c.method==='patch'){current={...current,...JSON.parse(c.data)};return response(c,{data:current,message:'物流已修正'})}return response(c,{data:current})}
 const view=await ordersView('/admin/orders/1');await invokeLifecycle(view,'shipment','更新物流資料')
 assert.ok(text(view.root).includes('物流已修正'));assert.ok(button(view,'更新物流資料'));assert.ok(text(view.root).includes('未付款'))
 assert.equal(mutations().length,1);assert.equal(mutations()[0].url,'/admin/orders/1/shipment');lifecycleBoundary();view.app.unmount()
})

test('Order PATCH succeeds but GET fails: retain success, block stale mutation, manual retry only GET',async()=>{
 let reads=0
 api.defaults.adapter=c=>{calls.push(c);if(c.method==='patch')return Promise.resolve(response(c,{data:adminOrderDetail,message:'已成功變更'}))
  reads++;if(reads===2)return Promise.reject(failure(c,503));return Promise.resolve(response(c,{data:{...adminOrderDetail,order_status:reads===1?'pending':'processing'}}))}
 const view=await ordersView('/admin/orders/1');await click(view,'設為處理中');await settle()
 assert.ok(text(view.root).includes('已成功變更'));assert.ok(text(view.root).includes('最新資料重新載入失敗'))
 assert.equal(button(view,'設為處理中').props.disabled,true);button(view,'設為處理中').props.onClick();await settle()
 assert.equal(mutations().length,1);await click(view,'重試讀取')
 assert.equal(mutations().length,1);assert.equal(reads,3);assert.ok(text(view.root).includes('已成功變更'))
 assert.ok(!text(view.root).includes('最新資料重新載入失敗'));assert.equal(button(view,'設為處理中'),undefined)
 lifecycleBoundary();view.app.unmount()
})
for(const phase of ['mutation','refresh']) {
 for(const outcome of ['success','error']) {
  for(const destination of ['/admin/orders/2','/admin/dashboard']) {
   test(`Order late ${phase} ${outcome} after ${destination}: no message/error/state/navigation contamination`,async()=>{
    let finish,oldReads=0
    api.defaults.adapter=c=>{calls.push(c)
     const deferred=()=>new Promise((resolve,reject)=>{finish=()=>outcome==='success'?resolve(response(c,{data:{...adminOrderDetail,order_no:'LATE-OLD'},message:'LATE-MESSAGE'})):reject(failure(c,500))})
     if(c.method==='patch')return phase==='mutation'?deferred():Promise.resolve(response(c,{data:adminOrderDetail,message:'LATE-MESSAGE'}))
     if(c.url.endsWith('/2'))return Promise.resolve(response(c,{data:{...adminOrderDetail,id:2,order_no:'SECOND-ORDER'}}))
     if(++oldReads>1)return deferred()
     return Promise.resolve(response(c,{data:adminOrderDetail}))}
    const view=await ordersView('/admin/orders/1');await click(view,'設為處理中');await settle()
    await view.router.push(destination);await settle();finish();await settle();await settle()
    assert.equal(view.router.currentRoute.value.path,destination)
    if(destination.endsWith('/2'))assert.ok(text(view.root).includes('SECOND-ORDER'))
    for(const forbidden of ['LATE-OLD','LATE-MESSAGE','後端停用原因','最新資料重新載入失敗'])assert.ok(!text(view.root).includes(forbidden))
    assert.equal(calls.filter(c=>c.method==='get'&&c.url.endsWith('/1')).length,phase==='mutation'?1:2)
    lifecycleBoundary();view.app.unmount()
   })
  }
 }
}

test('Old mutation finally cannot unlock a newer order mutation',async()=>{
 const finishes={}
 api.defaults.adapter=c=>{calls.push(c);if(c.method==='patch')return new Promise(resolve=>{finishes[c.url]=()=>resolve(response(c,{data:adminOrderDetail,message:'done'}))})
  const id=c.url.endsWith('/2')?2:1;return Promise.resolve(response(c,{data:{...adminOrderDetail,id,order_no:`ORDER-${id}`}}))}
 const view=await ordersView('/admin/orders/1');await click(view,'設為處理中');await view.router.push('/admin/orders/2');await settle();await click(view,'標記為已付款')
 finishes['/admin/orders/1/status']();await settle();assert.equal(button(view,'設為處理中').props.disabled,true)
 button(view,'設為處理中').props.onClick();await settle();assert.equal(mutations().length,2)
 finishes['/admin/orders/2/payment-status']();await settle();await settle();assert.equal(button(view,'重新讀取訂單').props.disabled,false)
 lifecycleBoundary();view.app.unmount()
})

// Stage 21 Step 3: shared cancel entry, confirmation and page-local lifecycle.
const cancelBoundary = () => {
 assert.ok(calls.every(c=>c.method==='get'?/^\/admin\/orders\/\d+$/.test(c.url)
  :c.method==='post'?/^\/admin\/orders\/\d+\/cancel$/.test(c.url)&&c.data===undefined
  :c.method==='patch'&&/^\/admin\/orders\/\d+\/(status|payment-status|shipment)$/.test(c.url)))
 assertMemberUntouched()
}
const confirmCancel = async view=>{await click(view,'取消訂單');await click(view,'確認取消訂單')}
test('Admin cancel service POSTs no body through existing adminApi',async()=>{
 api.defaults.adapter=async c=>{calls.push(c);return response(c,{data:adminOrderDetail,message:'訂單已取消。'})}
 assert.deepEqual(await orderService.cancelAdminOrder('7'),{data:adminOrderDetail,message:'訂單已取消。'})
 assert.equal(calls[0].url,'/admin/orders/7/cancel');cancelBoundary()
})
for(const state of ['pending','processing','shipped','completed','cancelled']) {
 test(`Admin cancel ${state}: correct button eligibility/confirmation without request`,async()=>{
  api.defaults.adapter=async c=>{calls.push(c);return response(c,{data:{...adminOrderDetail,order_status:state,payment_status:'paid'}})}
  const view=await ordersView('/admin/orders/1');assert.equal(Boolean(button(view,'取消訂單')),['pending','processing'].includes(state))
  if(['pending','processing'].includes(state)) {
   await click(view,'取消訂單');const dialog=find(view.root,el=>el.props['aria-label']==='取消訂單確認');assert.ok(dialog)
   for(const value of ['HF-READ-001','cancelled','恢復','不會自動退款','付款狀態會保持原值'])assert.ok(text(dialog).includes(value))
   assert.equal(mutations().length,0);await click(view,'返回，不取消');assert.equal(find(view.root,el=>el.props['aria-label']==='取消訂單確認'),undefined)
  }
  assert.equal(mutations().length,0);cancelBoundary();view.app.unmount()
 })
}
for(const state of ['pending','processing']) {
 test(`Admin cancel ${state}: direct handler mutex/no optimistic/success closes/GET authority`,async()=>{
  let finish,done=false
  api.defaults.adapter=c=>{calls.push(c);if(c.method==='post')return new Promise(resolve=>{finish=()=>{done=true;resolve(response(c,{data:{...adminOrderDetail,order_no:'PATCH-NOT-AUTHORITY'},message:'後端取消成功'}))}})
   return Promise.resolve(response(c,{data:{...adminOrderDetail,order_no:done?'GET-CANCEL-AUTHORITY':'HF-READ-001',order_status:done?'cancelled':state,payment_status:'paid'}}))}
  const view=await ordersView('/admin/orders/1');await confirmCancel(view)
  const collect=el=>{if(el.type==='button'){assert.equal(el.props.disabled,true);if(el.props.onClick)el.props.onClick()}el.children.forEach(collect)}
  collect(find(view.root,el=>el.props['aria-label']==='訂單處理操作'))
  const form=find(view.root,el=>el.props['aria-label']==='物流維護表單');if(form)form.props.onSubmit({preventDefault(){}})
  await settle();assert.equal(mutations().length,1);assert.equal(calls.length,2)
  assert.ok(text(view.root).includes('HF-READ-001'));assert.ok(find(view.root,el=>el.props['aria-label']==='取消訂單確認'))
  finish();await settle();await settle()
  assert.ok(text(view.root).includes('後端取消成功'));assert.ok(text(view.root).includes('GET-CANCEL-AUTHORITY'));assert.ok(!text(view.root).includes('PATCH-NOT-AUTHORITY'))
  assert.equal(find(view.root,el=>el.props['aria-label']==='取消訂單確認'),undefined);assert.equal(button(view,'取消訂單'),undefined)
  assert.equal(button(view,'重新讀取訂單').props.disabled,false);assert.equal(calls.filter(c=>c.method==='get').length,2)
  cancelBoundary();view.app.unmount()
 })
}
for(const [status,code] of [[422],[403],[419],[undefined],[500],[401],[403,'ADMIN_ACCOUNT_DISABLED']]) {
 test(`Admin cancel ${status}/${code??''}: original detail/confirmation/no retry/member isolation`,async()=>{
  api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:adminOrderDetail}))
   const e=failure(c,status,code);if(status===422)e.response.data.errors={order:['此訂單目前的狀態不允許取消。']};return Promise.reject(e)}
  const view=await ordersView('/admin/orders/1');await confirmCancel(view);await settle()
  assert.equal(mutations().length,1);assert.equal(calls.filter(c=>c.method==='get').length,1)
  if(status===401||code){assert.equal(auth.currentAdmin,null);assert.equal(view.router.currentRoute.value.name,'admin-login')}
  else {
   assert.ok(auth.currentAdmin);assert.equal(auth.adminFailureReason,null);assert.ok(text(view.root).includes('HF-READ-001'))
   assert.ok(text(view.root).includes(status===422?'此訂單目前的狀態不允許取消。':status?'後端停用原因':'訂單操作失敗'))
   assert.equal(button(view,'確認取消訂單').props.disabled,false);assert.equal(button(view,'設為處理中').props.disabled,false)
  }
  cancelBoundary();view.app.unmount()
 })
}

test('Admin cancel success + refresh failure retains success; stale operations blocked/manual retry GET only',async()=>{
 let reads=0
 api.defaults.adapter=c=>{calls.push(c);if(c.method==='post')return Promise.resolve(response(c,{data:adminOrderDetail,message:'訂單已取消。'}))
  if(++reads===2)return Promise.reject(failure(c,500));return Promise.resolve(response(c,{data:{...adminOrderDetail,order_status:reads===1?'pending':'cancelled'}}))}
 const view=await ordersView('/admin/orders/1');await confirmCancel(view);await settle()
 assert.ok(text(view.root).includes('訂單已取消。'));assert.ok(text(view.root).includes('最新資料重新載入失敗'))
 assert.equal(find(view.root,el=>el.props['aria-label']==='取消訂單確認'),undefined)
 for(const label of ['設為處理中','標記為已付款','取消訂單']){assert.equal(button(view,label).props.disabled,true);button(view,label).props.onClick()}
 await settle();assert.equal(mutations().length,1);await click(view,'重試讀取')
 assert.equal(mutations().length,1);assert.equal(reads,3);assert.ok(text(view.root).includes('訂單已取消。'));assert.equal(button(view,'取消訂單'),undefined)
 assert.ok(!text(view.root).includes('最新資料重新載入失敗'));cancelBoundary();view.app.unmount()
})
for(const phase of ['cancel','refresh'])for(const outcome of ['success','error'])for(const destination of ['/admin/orders/2','/admin/dashboard']) {
 test(`Admin cancel late ${phase} ${outcome} after ${destination}: context isolation`,async()=>{
  let finish,reads=0
  api.defaults.adapter=c=>{calls.push(c)
   const defer=()=>new Promise((resolve,reject)=>{finish=()=>outcome==='success'?resolve(response(c,{data:{...adminOrderDetail,order_no:'LATE-CANCEL'},message:'LATE-CANCEL-MESSAGE'})):reject(failure(c,500))})
   if(c.method==='post')return phase==='cancel'?defer():Promise.resolve(response(c,{data:adminOrderDetail,message:'LATE-CANCEL-MESSAGE'}))
   if(c.url.endsWith('/2'))return Promise.resolve(response(c,{data:{...adminOrderDetail,id:2,order_no:'SECOND-ORDER'}}))
   return ++reads===1?Promise.resolve(response(c,{data:adminOrderDetail})):defer()}
  const view=await ordersView('/admin/orders/1');await confirmCancel(view);await settle();await view.router.push(destination);await settle();finish();await settle();await settle()
  assert.equal(view.router.currentRoute.value.path,destination);if(destination.endsWith('/2'))assert.ok(text(view.root).includes('SECOND-ORDER'))
  for(const forbidden of ['LATE-CANCEL','後端停用原因','最新資料重新載入失敗'])assert.ok(!text(view.root).includes(forbidden))
  assert.equal(calls.filter(c=>c.method==='get'&&c.url.endsWith('/1')).length,phase==='cancel'?1:2)
  cancelBoundary();view.app.unmount()
 })
}
test('Old cancel finally cannot unlock newer order mutation or restore its confirmation',async()=>{
 const finishes={}
 api.defaults.adapter=c=>{calls.push(c);if(c.method!=='get')return new Promise(resolve=>{finishes[c.url]=()=>resolve(response(c,{data:adminOrderDetail,message:'old cancel message'}))})
  const id=c.url.endsWith('/2')?2:1;return Promise.resolve(response(c,{data:{...adminOrderDetail,id,order_no:`ORDER-${id}`}}))}
 const view=await ordersView('/admin/orders/1');await confirmCancel(view);await view.router.push('/admin/orders/2');await settle();await click(view,'標記為已付款')
 finishes['/admin/orders/1/cancel']();await settle();assert.equal(button(view,'取消訂單').props.disabled,true)
 button(view,'取消訂單').props.onClick();await settle();assert.equal(mutations().length,2);assert.equal(find(view.root,el=>el.props['aria-label']==='取消訂單確認'),undefined)
 assert.ok(!text(view.root).includes('old cancel message'));finishes['/admin/orders/2/payment-status']();await settle();await settle()
 assert.equal(button(view,'重新讀取訂單').props.disabled,false);cancelBoundary();view.app.unmount()
})

// Stage 21 Step 4: SFC/memory-router tests; not real-browser acceptance.
const usersView = async (url = '/admin/users') => { auth.isAdminInitialized = true; return mountRoute(url) }
const userBoundary = () => {
  assert.ok(calls.every(c => c.method === 'get' && /^\/admin\/users(?:\/\d+)?$/.test(c.url) || c.method === 'patch' && /^\/admin\/users\/\d+\/status$/.test(c.url)))
  for (const c of mutations()) assert.ok(['active','disabled'].includes(JSON.parse(c.data).status))
  assertMemberUntouched()
}
const confirmUserStatus = async (view, status = 'active') => {
  await click(view, status === 'active' ? '停用會員' : status === 'inactive' ? '恢復啟用（舊狀態）' : '恢復啟用')
  await click(view, '確認變更會員狀態')
}

test('Admin User service exact three endpoints/query/history/envelopes/adminApi only', async () => {
  const params = { search: 'member', status: 'inactive', page: 2 }
  assert.deepEqual(await userService.getAdminUsers(params), userPage())
  assert.deepEqual(await userService.getAdminUser('9', 2), userDetail('active', 9, [adminOrderSummary], 2))
  api.defaults.adapter = async c => { calls.push(c); return response(c, { data: { ...adminUser, status: 'disabled' }, message: '成功' }) }
  assert.equal((await userService.updateAdminUserStatus('9', { status: 'disabled' })).message, '成功')
  assert.deepEqual(calls.map(c => [c.method,c.url]), [['get','/admin/users'],['get','/admin/users/9'],['patch','/admin/users/9/status']])
  assert.deepEqual(calls[0].params, params); assert.deepEqual(calls[1].params, { order_page: 2 })
  assert.deepEqual(JSON.parse(calls[2].data), { status: 'disabled' }); userBoundary()
})

test('User list is read-only, includes safe fields/detail link, no placeholder or delete', async () => {
  const view = await usersView()
  for (const value of ['會員管理', adminUser.name, adminUser.email, adminUser.phone, '啟用']) assert.ok(text(view.root).includes(value))
  assert.ok(!text(view.root).includes('尚未實作')); assert.ok(!button(view,'停用會員'))
  assert.ok(find(view.root, el => el.type === 'a' && el.props.href === '/admin/users/9'))
  assert.equal(mutations().length,0); userBoundary(); view.app.unmount()
})

for (const status of ['active','disabled','inactive']) {
  test(`User ${status} list filter and detail/history/status-control`, async () => {
    api.defaults.adapter = async c => { calls.push(c); return response(c, c.url === '/admin/users' ? userPage([{ ...adminUser,status }]) : { data: userDetail(status) }) }
    const view = await usersView('/admin/users?status='+status)
    assert.equal(calls[0].params.status, status)
    assert.ok(text(view.root).includes(status === 'inactive' ? '舊停用狀態' : status === 'active' ? '啟用' : '停用'))
    await view.router.push('/admin/users/9'); await settle()
    assert.ok(text(view.root).includes(adminUser.email)); assert.ok(text(view.root).includes(adminOrderSummary.order_no))
    assert.ok(find(view.root,el=>el.type==='a'&&el.props.href==='/admin/orders/1'))
    const action=status==='active'?'停用會員':status==='inactive'?'恢復啟用（舊狀態）':'恢復啟用'
    await click(view,action)
    assert.ok(text(view.root).includes(status==='active'?'下一次受保護請求':'舊 session 不會復活'))
    if(status==='active')for(const phrase of ['地址','購物車','歷史訂單','不是立即全裝置登出','C06'])assert.ok(text(view.root).includes(phrase))
    assert.equal(mutations().length,0); userBoundary(); view.app.unmount()
  })
}

test('User list loading then empty, URL reload and pagination retain filters', async () => {
  let finish
  api.defaults.adapter = c => { calls.push(c); return new Promise(resolve=>{finish=()=>resolve(response(c,userPage([],2,2)))}) }
  const view=await usersView('/admin/users?search=keep&status=inactive&page=2')
  assert.ok(text(view.root).includes('會員載入中')); assert.deepEqual(calls[0].params,{search:'keep',status:'inactive',page:'2'})
  finish();await settle();assert.ok(text(view.root).includes('沒有符合條件的會員'))
  api.defaults.adapter=async c=>{calls.push(c);return response(c,userPage([],Number(c.params.page),2))}
  await click(view,'上一頁');assert.deepEqual({...view.router.currentRoute.value.query},{search:'keep',status:'inactive',page:'1'})
  userBoundary();view.app.unmount()
})

test('User list submit resets page; browser history core restores query and inputs', async () => {
  const view=await usersView('/admin/users?search=old&status=active&page=2')
  await inputValue(view,'user-search','draft');assert.equal(calls.length,1)
  await submitProductFilters(view,{'user-search':' new search ','user-status':'disabled'})
  assert.deepEqual({...view.router.currentRoute.value.query},{search:'new search',status:'disabled',page:'1'})
  assert.deepEqual(calls.at(-1).params,{search:'new search',status:'disabled',page:'1'})
  view.router.back();await settle();assert.equal(find(view.root,el=>el.props.id==='user-search').value,'old');assert.equal(view.router.currentRoute.value.query.page,'2')
  view.router.forward();await settle();assert.equal(find(view.root,el=>el.props.id==='user-search').value,'new search')
  userBoundary();view.app.unmount()
})

test('User repeated list/history query fail locally, no malformed GET', async () => {
  let view=await usersView('/admin/users?status=active&status=disabled');assert.ok(text(view.root).includes('格式不正確'));assert.equal(calls.length,0);view.app.unmount()
  view=await usersView('/admin/users/9?order_page=1&order_page=2');assert.ok(text(view.root).includes('分頁格式不正確'));assert.equal(calls.length,0);view.app.unmount()
})

test('User history order_page drives GET, preserves list query and user identity/back-forward', async () => {
  const view=await usersView('/admin/users/9?search=keep&status=disabled&page=2&order_page=1')
  await click(view,'下一頁');assert.equal(calls.at(-1).params.order_page,'2');assert.equal(view.router.currentRoute.value.params.id,'9')
  assert.equal(view.router.currentRoute.value.query.search,'keep');assert.equal(view.router.currentRoute.value.query.page,'2')
  assert.ok(find(view.root,el=>el.type==='a'&&el.props.href==='/admin/users?search=keep&status=disabled&page=2'))
  view.router.back();await settle();assert.equal(calls.at(-1).params.order_page,'1')
  view.router.forward();await settle();assert.equal(calls.at(-1).params.order_page,'2')
  userBoundary();view.app.unmount()
})

test('User detail loading then empty history and 404 retry', async () => {
  let finish
  api.defaults.adapter=c=>{calls.push(c);return new Promise(resolve=>{finish=()=>resolve(response(c,{data:userDetail('disabled',9,[])}))})}
  const view=await usersView('/admin/users/9');assert.ok(text(view.root).includes('會員詳細載入中'));finish();await settle();assert.ok(text(view.root).includes('尚無歷史訂單'))
  api.defaults.adapter=c=>{calls.push(c);return Promise.reject(failure(c,404))}
  await view.router.push('/admin/users/10');await settle();assert.ok(text(view.root).includes('會員不存在'))
  api.defaults.adapter=async c=>{calls.push(c);return response(c,{data:userDetail('active',10,[])})}
  await click(view,'重試');assert.ok(text(view.root).includes(adminUser.email));userBoundary();view.app.unmount()
})

for(const phase of ['list','detail'])for(const [status,code] of [[403],[419],[422],[undefined],[500],[401],[403,'ADMIN_ACCOUNT_DISABLED']]) {
  test(`User ${phase} GET ${status}/${code??''}: error/retry/coordinator/isolation`,async()=>{
    api.defaults.adapter=c=>{calls.push(c);return Promise.reject(failure(c,status,code))}
    const url=phase==='list'?'/admin/users?search=keep':'/admin/users/9?order_page=2'
    const view=await usersView(url);await settle()
    if(status===401||code){assert.equal(view.router.currentRoute.value.name,'admin-login');assert.equal(auth.currentAdmin,null)}
    else {
      assert.ok(find(view.root,el=>el.props.role==='alert'));assert.equal(calls.length,1);assert.equal(view.router.currentRoute.value.fullPath,url);assert.ok(auth.currentAdmin);assert.equal(auth.adminFailureReason,null)
      api.defaults.adapter=async c=>{calls.push(c);return response(c,phase==='list'?userPage():{data:userDetail()})}
      await click(view,'重試');assert.ok(text(view.root).includes(adminUser.email));assert.equal(calls.length,2)
    }
    userBoundary();view.app.unmount()
  })
}

test('User stale list response cannot overwrite new filter',async()=>{
  let finish
  api.defaults.adapter=c=>{calls.push(c);if(c.params.search==='old')return new Promise(resolve=>{finish=()=>resolve(response(c,userPage([{...adminUser,name:'OLD-USER'}])))})
    return Promise.resolve(response(c,userPage([{...adminUser,name:'NEW-USER'}])))}
  const view=await usersView('/admin/users?search=old');await view.router.push('/admin/users?search=new');await settle();finish();await settle()
  assert.ok(text(view.root).includes('NEW-USER'));assert.ok(!text(view.root).includes('OLD-USER'));userBoundary();view.app.unmount()
})

for(const destination of ['/admin/users/10','/admin/users/9?order_page=2'])for(const outcome of ['success','error']) {
  test(`User stale detail ${destination}/${outcome} cannot overwrite id/history context`,async()=>{
    let finish
    api.defaults.adapter=c=>{calls.push(c);if(calls.length===1)return new Promise((resolve,reject)=>{finish=()=>outcome==='error'?reject(failure(c,500)):resolve(response(c,{data:{...userDetail(),user:{...adminUser,name:'OLD-DETAIL'}}}))})
      return Promise.resolve(response(c,{data:{...userDetail(),user:{...adminUser,name:'NEW-DETAIL'}}}))}
    const view=await usersView('/admin/users/9');await view.router.push(destination);await settle();finish();await settle()
    assert.ok(text(view.root).includes('NEW-DETAIL'));assert.ok(!text(view.root).includes('OLD-DETAIL'));assert.ok(!text(view.root).includes('後端停用原因'));userBoundary();view.app.unmount()
  })
}

for(const status of ['active','disabled','inactive']) {
  test(`User ${status} mutation guard/no optimistic/authoritative GET/backend message`,async()=>{
    let finishPatch,finishGet;let reads=0
    const target=status==='active'?'disabled':'active'
    api.defaults.adapter=c=>{calls.push(c);if(c.method==='patch')return new Promise(resolve=>{finishPatch=()=>resolve(response(c,{data:{...adminUser,name:'PATCH-NOT-AUTHORITY',status:target},message:'會員操作正式成功'}))})
      if(++reads===1)return Promise.resolve(response(c,{data:userDetail(status)}))
      return new Promise(resolve=>{finishGet=()=>resolve(response(c,{data:{...userDetail(target),user:{...adminUser,name:'GET-AUTHORITY',status:target}}}))})}
    const view=await usersView('/admin/users/9?order_page=2');await confirmUserStatus(view,status)
    assert.deepEqual(JSON.parse(mutations()[0].data),{status:target})
    for(const label of ['確認變更會員狀態','返回，不變更','重新讀取會員',status==='active'?'停用會員':status==='inactive'?'恢復啟用（舊狀態）':'恢復啟用']) {
      assert.equal(button(view,label).props.disabled,true);button(view,label).props.onClick()
    }
    await settle();assert.equal(mutations().length,1);assert.equal(calls.length,2);assert.ok(!text(view.root).includes('PATCH-NOT-AUTHORITY'))
    finishPatch();await settle();assert.ok(text(view.root).includes('會員操作正式成功'));assert.equal(calls.at(-1).params.order_page,'2');assert.ok(!text(view.root).includes('PATCH-NOT-AUTHORITY'))
    finishGet();await settle();assert.ok(text(view.root).includes('GET-AUTHORITY'));assert.equal(button(view,'重新讀取會員').props.disabled,false)
    userBoundary();view.app.unmount()
  })
}

for(const [status,code] of [[422],[403],[419],[undefined],[500],[401],[403,'ADMIN_ACCOUNT_DISABLED']]) {
  test(`User status ${status}/${code??''}: reason/no retry/detail preservation/isolation`,async()=>{
    api.defaults.adapter=c=>{calls.push(c);if(c.method==='get')return Promise.resolve(response(c,{data:userDetail()}));const error=failure(c,status,code)
      if(status===422)error.response.data.errors={status:['會員業務拒絕原因']};return Promise.reject(error)}
    const view=await usersView('/admin/users/9');await confirmUserStatus(view);await settle()
    assert.equal(mutations().length,1);assert.equal(calls.filter(c=>c.method==='get').length,1)
    if(status===401||code){assert.equal(view.router.currentRoute.value.name,'admin-login');assert.equal(auth.currentAdmin,null)}
    else {assert.ok(text(view.root).includes(adminUser.email));assert.ok(text(view.root).includes(status===422?'會員業務拒絕原因':status?'後端停用原因':'會員狀態操作失敗'));assert.ok(button(view,'停用會員'));assert.equal(button(view,'重新讀取會員').props.disabled,false);assert.ok(auth.currentAdmin);assert.equal(auth.adminFailureReason,null)}
    userBoundary();view.app.unmount()
  })
}

test('User mutation success + GET failure keeps success/detail, manual retry only GET',async()=>{
  let reads=0
  api.defaults.adapter=c=>{calls.push(c);if(c.method==='patch')return Promise.resolve(response(c,{data:{...adminUser,status:'disabled'},message:'確定已成功'}))
    if(++reads===2)return Promise.reject(failure(c,500));return Promise.resolve(response(c,{data:userDetail(reads===1?'active':'disabled')}))}
  const view=await usersView('/admin/users/9?order_page=2');await confirmUserStatus(view);await settle()
  assert.ok(text(view.root).includes('確定已成功'));assert.ok(text(view.root).includes('最新資料重新載入失敗'));assert.ok(button(view,'停用會員'));assert.equal(button(view,'停用會員').props.disabled,true)
  button(view,'停用會員').props.onClick();await settle();assert.equal(mutations().length,1)
  await click(view,'重試讀取');assert.equal(mutations().length,1);assert.equal(calls.filter(c=>c.method==='get').length,3);assert.ok(button(view,'恢復啟用'));assert.ok(!text(view.root).includes('最新資料重新載入失敗'));userBoundary();view.app.unmount()
})

for(const phase of ['mutation','refresh'])for(const destination of ['/admin/users/10','/admin/users/9?order_page=2','/admin/dashboard'])for(const outcome of ['success','error']) {
  test(`User stale ${phase}/${destination}/${outcome} has no late refresh/message/navigation`,async()=>{
    let finish,reads=0
    const defer=c=>new Promise((resolve,reject)=>{finish=()=>outcome==='error'?reject(failure(c,500)):resolve(response(c,phase==='mutation'?{data:adminUser,message:'LATE-USER-MESSAGE'}:{data:{...userDetail(),user:{...adminUser,name:'LATE-USER-DATA'}}}))})
    api.defaults.adapter=c=>{calls.push(c);if(c.method==='patch')return phase==='mutation'?defer(c):Promise.resolve(response(c,{data:adminUser,message:'LATE-USER-MESSAGE'}))
      if(c.url.endsWith('/10')||c.params?.order_page==='2')return Promise.resolve(response(c,{data:{...userDetail(),user:{...adminUser,name:'NEW-USER-CONTEXT'}}}))
      return ++reads===1?Promise.resolve(response(c,{data:userDetail()})):defer(c)}
    const view=await usersView('/admin/users/9');await confirmUserStatus(view);await settle();await view.router.push(destination);await settle();finish();await settle();await settle()
    assert.equal(view.router.currentRoute.value.fullPath,destination)
    if(destination!=='/admin/dashboard')assert.ok(text(view.root).includes('NEW-USER-CONTEXT'))
    for(const forbidden of ['LATE-USER-MESSAGE','LATE-USER-DATA','後端停用原因','最新資料重新載入失敗'])assert.ok(!text(view.root).includes(forbidden))
    assert.equal(calls.filter(c=>c.method==='get'&&c.url.endsWith('/9')&&!c.params?.order_page).length,phase==='mutation'?1:2)
    userBoundary();view.app.unmount()
  })
}

test('User old finally cannot unlock mutation in newer user context',async()=>{
  const finish={}
  api.defaults.adapter=c=>{calls.push(c);if(c.method==='patch')return new Promise(resolve=>{finish[c.url]=()=>resolve(response(c,{data:adminUser,message:'OLD-FINALLY'}))})
    return Promise.resolve(response(c,{data:userDetail('active',Number(c.url.split('/').at(-1)))}))}
  const view=await usersView('/admin/users/9');await confirmUserStatus(view);await view.router.push('/admin/users/10');await settle();await confirmUserStatus(view)
  finish['/admin/users/9/status']();await settle();assert.equal(button(view,'確認變更會員狀態').props.disabled,true)
  button(view,'確認變更會員狀態').props.onClick();await settle();assert.equal(mutations().length,2);assert.ok(!text(view.root).includes('OLD-FINALLY'))
  finish['/admin/users/10/status']();await settle();userBoundary();view.app.unmount()
})
