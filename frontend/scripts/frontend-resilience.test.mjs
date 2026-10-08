import assert from 'node:assert/strict'
import { test, after } from 'node:test'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import { createRenderer, nextTick, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'

const originalStorage = globalThis.localStorage
globalThis.localStorage = { getItem: () => null, setItem() {}, removeItem() {} }
const originalShadowRoot = globalThis.ShadowRoot
const originalWindow = globalThis.window
const originalDocument = globalThis.Document
globalThis.Document = class Document {}
globalThis.ShadowRoot = class ShadowRoot {}
const files = {
  'virtual:member': '/src/views/MemberView.vue',
  'virtual:detail': '/src/views/ProductDetailView.vue',
  'virtual:category': '/src/components/product/ProductCategoryNav.vue',
  'virtual:addressform': '/src/components/member/UserAddressForm.vue',
  'virtual:addresses': '/src/views/MemberAddressView.vue',
  'virtual:checkout': '/src/views/CheckoutView.vue',
  'virtual:list': '/src/views/ProductListView.vue',
  'virtual:pagination': '/src/components/common/AppPagination.vue',
}
const server = await createServer({
  server: { middlewareMode: true },
  appType: 'custom',
  plugins: [
    {
      name: 'storefront-ui-test',
      enforce: 'pre',
      resolveId(id) {
        if (id in files) return '\0' + id + '.ts'
        if (id.endsWith('/UserAddressForm.vue')) return '\0virtual:addressform.ts'
        if (id.endsWith('.vue')) return '\0virtual:blank.ts'
      },
      async load(id) {
        if (id === '\0virtual:blank.ts') return 'export default { render() { return null } }'
        const file = files[id.replace(/^\0/, '').replace(/\.ts$/, '')]
        if (!file) return
        const source = await readFile(new URL('..' + file, import.meta.url), 'utf8')
        return ts.transpileModule(
          compileScript(parse(source).descriptor, {
            id,
            inlineTemplate: true,
            templateOptions: { compilerOptions: { hoistStatic: false } },
          }).content,
          { compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.ESNext } },
        ).outputText
      },
    },
  ],
})
const { default: Member } = await server.ssrLoadModule('virtual:member')
const { useAuthStore } = await server.ssrLoadModule('/src/stores/auth.ts')
const { default: ProductList } = await server.ssrLoadModule('virtual:list')
const { default: Pagination } = await server.ssrLoadModule('virtual:pagination')
const { default: api } = await server.ssrLoadModule('/src/services/api.ts')
const { useCartStore } = await server.ssrLoadModule('/src/stores/cart.ts')
const { parseGuestCart } = await server.ssrLoadModule('/src/utils/guestCartStorage.ts')
const components = {}
for (const name of ['detail', 'category', 'addressform', 'addresses', 'checkout'])
  components[name] = (await server.ssrLoadModule('virtual:' + name)).default
const requests = []
let handler
api.defaults.adapter = async (config) => {
  requests.push(config)
  return await handler(config)
}
const reply = (config, data) => ({ data, status: 200, statusText: 'OK', headers: {}, config })
const deferred = () => {
  let resolve, reject
  const promise = new Promise((a, b) => {
    resolve = a
    reject = b
  })
  return { promise, resolve, reject }
}
after(async () => {
  if (originalShadowRoot === undefined) delete globalThis.ShadowRoot
  else globalThis.ShadowRoot = originalShadowRoot
  if (originalWindow === undefined) delete globalThis.window
  else globalThis.window = originalWindow
  if (originalStorage === undefined) delete globalThis.localStorage
  else globalThis.localStorage = originalStorage
  if (originalDocument === undefined) delete globalThis.Document
  else globalThis.Document = originalDocument
  await server.close()
})
const node = (type, text = '') => ({
  type,
  text,
  props: {},
  children: [],
  parent: null,
  tagName: type.toUpperCase(),
  value: '',
  getRootNode: () => ({ activeElement: null }),
  get options() {
    const collect = (el) =>
      el.children.flatMap((child) => (child.type === 'option' ? [child] : collect(child)))
    return collect(this)
  },
  listeners: {},
  addEventListener(event, handler) {
    this.listeners[event] = handler
  },
  removeEventListener(event) {
    delete this.listeners[event]
  },
})
const renderer = createRenderer({
  createElement: (type) => node(type),
  createText: (text) => node('text', text),
  createComment: () => node('comment'),
  setText: (el, text) => {
    el.text = text
  },
  setElementText: (el, text) => {
    el.text = text
    el.children = []
  },
  patchProp: (el, key, _old, value) => {
    el.props[key] = value
  },
  insert(el, parent, anchor = null) {
    if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1)
    el.parent = parent
    const i = anchor ? parent.children.indexOf(anchor) : -1
    if (i < 0) parent.children.push(el)
    else parent.children.splice(i, 0, el)
  },
  remove(el) {
    if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1)
  },
  parentNode: (el) => el.parent,
  nextSibling: (el) => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
})
const text = (el) => el.text + el.children.map(text).join('')
const all = (el, pred) => [
  ...(pred(el) ? [el] : []),
  ...el.children.flatMap((child) => all(child, pred)),
]
const settle = async () => {
  await new Promise((resolve) => setImmediate(resolve))
  await nextTick()
}
const source = (path) => readFile(new URL('../src/' + path, import.meta.url), 'utf8')
const mount = async (component, path = '/products/1', props = {}) => {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/products/:id', component },
      { path: '/:pathMatch(.*)*', component },
      ...['cart', 'products', 'home', 'member-order-detail', 'product-detail'].map((name) => ({
        path:
          '/fixture/' +
          name +
          (['product-detail', 'member-order-detail'].includes(name) ? '/:id' : ''),
        name,
        component: { render: () => null },
      })),
    ],
  })
  await router.push(path)
  await router.isReady()
  const pinia = createPinia()
  setActivePinia(pinia)
  const auth = useAuthStore()
  auth.currentUser = {
    id: 9,
    name: 'Fixture',
    email: 'fixture@example.test',
    phone: '0912345678',
    status: 'active',
  }
  const root = node('root')
  const app = renderer.createApp({ render: () => h(component, props) })
  app.use(pinia)
  app.use(router)
  app.mount(root)
  await settle()
  return { root, app, router }
}
const button = (root, label) => all(root, (e) => e.type === 'button' && text(e).includes(label))[0]
const page = (data = [], current = 1, last = 1) => ({
  data,
  links: {},
  meta: { current_page: current, last_page: last, total: data.length, per_page: 8 },
})
const product = (id) => ({
  id,
  name: 'Product-' + id,
  product_code: 'PRD-' + id,
  price: '10.00',
  stock: 10,
  status: 'active',
  category: { id: 2, name: 'Category' },
  images: [],
  variants: [],
  specifications: [],
})
const address = {
  id: 1,
  label: 'Fixture',
  recipient_name: 'Fixture',
  recipient_phone: '0912345678',
  address: 'Fixture Road',
  is_default: false,
  district: { id: 11, name: 'District A', postal_code: '100', city: { id: 1, name: 'City A' } },
}
const cities = [
  { id: 1, name: 'City A' },
  { id: 2, name: 'City B' },
]
const baseline = (config) =>
  reply(
    config,
    config.url === '/addresses'
      ? { data: [address] }
      : config.url === 'cities'
        ? { data: cities }
        : config.url === '/cart'
          ? {
              data: {
                id: 1,
                items: [
                  {
                    id: 1,
                    product_id: 1,
                    product_variant_id: null,
                    product: product(1),
                    variant: null,
                    quantity: 1,
                    unit_price: '10.00',
                    subtotal: '10.00',
                    is_available: true,
                  },
                ],
                subtotal: '10.00',
                has_unavailable_items: false,
              },
            }
          : { data: [] },
  )

test('Product list failed GET can be retried once and repeated retry is guarded', async () => {
  requests.length = 0
  handler = async () => {
    throw new Error('network')
  }
  const v = await mount(ProductList, '/products')
  await settle()
  assert.match(text(v.root), /商品載入失敗/)
  const d = deferred()
  handler = (c) => d.promise.then((data) => reply(c, data))
  const retry = button(v.root, '重新載入商品')
  retry.props.onClick()
  retry.props.onClick()
  await settle()
  assert.equal(requests.length, 2)
  d.resolve(page())
  await settle()
  assert.doesNotMatch(text(v.root), /商品載入失敗/)
  v.app.unmount()
})
for (const oldFailure of [false, true])
  test(
    'List latest query wins over old ' + (oldFailure ? 'failure' : 'success') + ' and finally',
    async () => {
      const first = deferred(),
        second = deferred()
      handler = (c) =>
        (c.params.search === 'new' ? second : first).promise.then((data) => reply(c, data))
      const v = await mount(ProductList, '/products?search=old')
      await v.router.push(
        '/products?search=new&category_id=2&sort=price_desc&min_price=1&max_price=50&page=2',
      )
      await settle()
      if (oldFailure) first.reject(new Error('late'))
      else first.resolve(page())
      await settle()
      assert.match(text(v.root), /商品載入中/)
      assert.doesNotMatch(text(v.root), /商品載入失敗/)
      second.resolve(page([], 2, 2))
      await settle()
      assert.doesNotMatch(text(v.root), /載入中|載入失敗/)
      v.app.unmount()
    },
  )
test('Unmounted list late response cannot redirect an out-of-range page', async () => {
  const d = deferred()
  handler = (c) => d.promise.then((data) => reply(c, data))
  const v = await mount(ProductList, '/products?page=100')
  assert.equal(v.router.currentRoute.value.query.page, '100')
  v.app.unmount()
  const afterUnmount = v.router.currentRoute.value.fullPath
  d.resolve(page())
  await settle()
  assert.equal(v.router.currentRoute.value.fullPath, afterUnmount)
})
test('Related failure is isolated, manual retry only requests related', async () => {
  requests.length = 0
  handler = (c) =>
    c.url.endsWith('/related')
      ? Promise.reject(new Error('network'))
      : Promise.resolve(reply(c, { data: product(1) }))
  const v = await mount(components.detail)
  await settle()
  await settle()
  assert.match(text(v.root), /Product-1/)
  assert.match(text(v.root), /相關商品載入失敗/)
  handler = (c) => Promise.resolve(reply(c, { data: [] }))
  button(v.root, '重新載入相關商品').props.onClick()
  await settle()
  assert.equal(requests.at(-1).url, '/products/1/related')
  assert.doesNotMatch(text(v.root), /相關商品載入失敗/)
  v.app.unmount()
})
for (const failure of [false, true])
  test('Detail id switch ignores old main ' + (failure ? 'error' : 'success'), async () => {
    const old = deferred()
    handler = (c) =>
      c.url === '/products/1'
        ? old.promise.then((data) => reply(c, data))
        : Promise.resolve(reply(c, { data: c.url.endsWith('/related') ? [] : product(2) }))
    const v = await mount(components.detail)
    await v.router.push('/products/2')
    await settle()
    await settle()
    failure ? old.reject(new Error('late')) : old.resolve({ data: product(1) })
    await settle()
    assert.match(text(v.root), /Product-2/)
    assert.doesNotMatch(text(v.root), /Product-1|資料載入失敗/)
    v.app.unmount()
  })
test('Detail 404 retains not-found semantics without network retry', async () => {
  handler = () =>
    Promise.reject({
      isAxiosError: true,
      response: { status: 404, data: { message: '找不到此商品' } },
    })
  const v = await mount(components.detail)
  await settle()
  assert.match(text(v.root), /找不到此商品/)
  assert.equal(button(v.root, '重新載入商品'), undefined)
  v.app.unmount()
})
test('Category independent error and manual retry guard', async () => {
  requests.length = 0
  handler = () => Promise.reject(new Error('network'))
  const v = await mount(components.category)
  await settle()
  assert.match(text(v.root), /分類載入失敗/)
  const d = deferred()
  handler = (c) => d.promise.then((data) => reply(c, data))
  const b = button(v.root, '重新載入分類')
  b.props.onClick()
  b.props.onClick()
  await settle()
  assert.equal(requests.length, 2)
  d.resolve({ data: [] })
  await settle()
  assert.match(text(v.root), /目前沒有商品分類/)
  v.app.unmount()
})
for (const current of [1, 2, 50, 99, 100])
  test(
    'Pagination 100 pages current ' + current + ' has finite unique window, aria and ellipsis',
    () => {
      const root = node('root'),
        changes = []
      const app = renderer.createApp({
        render: () =>
          h(Pagination, {
            currentPage: current,
            lastPage: 100,
            onChangePage: (p) => changes.push(p),
          }),
      })
      app.mount(root)
      const buttons = all(root, (e) => e.type === 'button'),
        numbers = buttons.map((e) => Number(text(e))).filter(Number.isFinite)
      assert.equal(new Set(numbers).size, numbers.length)
      assert.ok(numbers.length <= 7)
      assert.ok(numbers.includes(1) && numbers.includes(100) && numbers.includes(current))
      assert.equal(all(root, (e) => e.props['aria-current'] === 'page').length, 1)
      assert.equal(all(root, (e) => e.type === 'button' && text(e) === '…').length, 0)
      buttons.find((e) => text(e) == String(current)).props.onClick()
      assert.deepEqual(changes, [])
      app.unmount()
    },
  )
for (const last of [1, 2, 4])
  test('Pagination small last ' + last, () => {
    const root = node('root')
    const app = renderer.createApp({
      render: () => h(Pagination, { currentPage: 1, lastPage: last }),
    })
    app.mount(root)
    assert.equal(all(root, (e) => e.type === 'button').length, last === 1 ? 0 : last + 2)
    app.unmount()
  })
const guest = {
  key: '1:none',
  product_id: 1,
  product_variant_id: null,
  quantity: 2,
  product: { ...product(1), primary_image: null },
  variant: null,
  unit_price: '10.00',
  subtotal: '20.00',
  available_stock: 10,
  is_available: true,
  unavailable_reason: null,
}
for (const bad of [
  null,
  {},
  [],
  { ...guest, quantity: 0 },
  { ...guest, quantity: -1 },
  { ...guest, quantity: 1.5 },
  { ...guest, quantity: '2' },
  { ...guest, product_id: 0 },
  { ...guest, product_variant_id: -1 },
  { ...guest, product: null },
  { ...guest, variant: {} },
  { ...guest, unit_price: 'NaN' },
])
  test('Guest storage filters malformed entry ' + JSON.stringify(bad), () => {
    assert.deepEqual(parseGuestCart(JSON.stringify([bad, guest])), [guest])
  })
test('Guest malformed JSON/non-array/access errors fail safe and valid variant remains', () => {
  for (const raw of ['{', 'null', '{}', '1']) assert.deepEqual(parseGuestCart(raw), [])
  const variant = { id: 2, option_name: 'Color', option_value: 'Black', stock: 3, status: 'active' }
  const item = { ...guest, key: '1:2', product_variant_id: 2, variant }
  assert.deepEqual(parseGuestCart(JSON.stringify([item])), [item])
  globalThis.localStorage.getItem = () => {
    throw new Error('privacy')
  }
  setActivePinia(createPinia())
  assert.deepEqual(useCartStore().guestItems, [])
  globalThis.localStorage.getItem = () => null
})

const input = (root, id) => all(root, (e) => e.props.id === id)[0]
const choose = async (root, id, value) => {
  const el = input(root, id)
  el.props['onUpdate:modelValue'](value)
  if (el.props.onChange) el.props.onChange()
  await settle()
}
for (const failure of [false, true])
  test(
    'Address districts latest city wins over late ' + (failure ? 'error' : 'success'),
    async () => {
      const a = deferred(),
        b = deferred()
      handler = (c) =>
        c.url === '/cities/1/districts'
          ? a.promise.then((data) => reply(c, data))
          : c.url === '/cities/2/districts'
            ? b.promise.then((data) => reply(c, data))
            : Promise.resolve(baseline(c))
      const v = await mount(components.addressform)
      await settle()
      await choose(v.root, 'city', 1)
      await choose(v.root, 'city', 2)
      b.resolve({ data: [{ id: 22, name: 'District B', postal_code: '200' }] })
      await settle()
      assert.match(text(v.root), /District B/)
      failure
        ? a.reject(new Error('late'))
        : a.resolve({ data: [{ id: 11, name: 'District A', postal_code: '100' }] })
      await settle()
      assert.match(text(v.root), /District B/)
      assert.doesNotMatch(text(v.root), /District A|行政區資料載入失敗/)
      await choose(v.root, 'district', 999)
      let saved = 0
      handler = (c) => {
        saved++
        return Promise.resolve(baseline(c))
      }
      all(v.root, (e) => e.type === 'form')[0].props.onSubmit({ preventDefault() {} })
      await settle()
      assert.equal(saved, 0)
      assert.match(text(v.root), /請選擇行政區/)
      v.app.unmount()
    },
  )
test('Address district response after unmount cannot emit or render', async () => {
  const d = deferred()
  handler = (c) =>
    c.url.includes('districts')
      ? d.promise.then((data) => reply(c, data))
      : Promise.resolve(baseline(c))
  const v = await mount(components.addressform)
  await choose(v.root, 'city', 1)
  v.app.unmount()
  d.resolve({ data: [{ id: 11, name: 'Late', postal_code: '100' }] })
  await settle()
  assert.doesNotMatch(text(v.root), /Late/)
})
test('Checkout initial failure retry is guarded GET only and fills authoritative data', async () => {
  requests.length = 0
  handler = (c) =>
    c.url === '/addresses' ? Promise.reject(new Error('503')) : Promise.resolve(baseline(c))
  const v = await mount(components.checkout, '/checkout')
  await settle()
  assert.match(text(v.root), /结帳資料載入失敗|結帳資料載入失敗/)
  const d = deferred()
  handler = (c) => d.promise.then(() => baseline(c))
  const b = button(v.root, '重新載入結帳資料')
  b.props.onClick()
  b.props.onClick()
  await settle()
  assert.equal(requests.length, 6)
  assert.ok(requests.every((c) => c.method === 'get'))
  d.resolve()
  await settle()
  assert.doesNotMatch(text(v.root), /結帳資料載入失敗/)
  assert.match(text(v.root), /付款方式/)
  v.app.unmount()
})
for (const action of ['設為預設', '刪除'])
  test(
    'Address ' + action + ' success plus refresh failure keeps success and only retries GET',
    async () => {
      globalThis.window = { confirm: () => true }
      requests.length = 0
      handler = (c) => Promise.resolve(baseline(c))
      const v = await mount(components.addresses, '/member/addresses')
      await settle()
      handler = (c) =>
        c.method === 'get'
          ? Promise.reject(new Error('503'))
          : Promise.resolve(reply(c, { message: '操作已成功' }))
      button(v.root, action).props.onClick()
      await settle()
      await settle()
      assert.match(text(v.root), /操作已成功/)
      assert.match(text(v.root), /清單可能過時/)
      assert.equal(requests.filter((c) => c.method !== 'get').length, 1)
      assert.equal(button(v.root, '新增地址').props.disabled, true)
      handler = (c) => Promise.resolve(baseline(c))
      button(v.root, '重新載入').props.onClick()
      await settle()
      assert.equal(requests.at(-1).method, 'get')
      assert.equal(requests.filter((c) => c.method !== 'get').length, 1)
      assert.equal(button(v.root, '新增地址').props.disabled, false)
      v.app.unmount()
    },
  )
test('Address mutation failure preserves draft and unlocks context without retry', async () => {
  requests.length = 0
  handler = (c) => Promise.resolve(baseline(c))
  const v = await mount(components.addresses, '/member/addresses')
  await settle()
  button(v.root, '編輯').props.onClick()
  await settle()
  await choose(v.root, 'city', 1)
  // Load a valid district, then fail the actual PATCH.
  handler = (c) =>
    c.method === 'get'
      ? Promise.resolve(reply(c, { data: [{ id: 11, name: 'District A', postal_code: '100' }] }))
      : Promise.reject(new Error('500'))
  await choose(v.root, 'city', 1)
  await choose(v.root, 'district', 11)
  input(v.root, 'addressLabel').props['onUpdate:modelValue']('Draft retained')
  all(v.root, (e) => e.type === 'form')[0].props.onSubmit({ preventDefault() {} })
  await settle()
  assert.match(text(v.root), /地址儲存失敗/)
  assert.equal(input(v.root, 'addressLabel').value, 'Draft retained')
  assert.equal(requests.filter((c) => c.method === 'patch').length, 1)
  assert.equal(button(v.root, '取消').props.disabled, false)
  v.app.unmount()
})

for (const failure of [false, true])
  test(
    'Related old id ' + (failure ? 'error' : 'success') + ' cannot affect new product',
    async () => {
      const d = deferred()
      handler = (c) =>
        c.url === '/products/1/related'
          ? d.promise.then((data) => reply(c, data))
          : Promise.resolve(
              reply(c, {
                data: c.url.endsWith('/related') ? [] : product(c.url === '/products/1' ? 1 : 2),
              }),
            )
      const v = await mount(components.detail)
      await settle()
      await v.router.push('/products/2')
      await settle()
      failure ? d.reject(new Error('late')) : d.resolve({ data: [product(99)] })
      await settle()
      assert.match(text(v.root), /Product-2/)
      assert.doesNotMatch(text(v.root), /Product-99|相關商品載入失敗/)
      v.app.unmount()
    },
  )
for (const name of ['detail', 'category', 'checkout'])
  test(name + ' late failure after unmount cannot render or refetch', async () => {
    const d = deferred()
    requests.length = 0
    handler = (c) => d.promise.then((data) => reply(c, data))
    const v = await mount(components[name])
    v.app.unmount()
    const count = requests.length
    d.reject(new Error('late'))
    await settle()
    assert.equal(requests.length, count)
    assert.equal(text(v.root), '')
  })
for (const mode of ['create', 'edit'])
  test('Address ' + mode + ' success, refresh failure and pending context guards', async () => {
    requests.length = 0
    handler = (c) =>
      c.url.includes('districts')
        ? Promise.resolve(reply(c, { data: [{ id: 11, name: 'District A', postal_code: '100' }] }))
        : Promise.resolve(baseline(c))
    const v = await mount(components.addresses, '/member/addresses')
    await settle()
    button(v.root, mode === 'create' ? '新增地址' : '編輯').props.onClick()
    await settle()
    await choose(v.root, 'city', 1)
    await choose(v.root, 'district', 11)
    const d = deferred()
    handler = (c) =>
      c.method === 'get'
        ? Promise.reject(new Error('503'))
        : d.promise.then(() => reply(c, { message: 'Saved successfully' }))
    const form = all(v.root, (e) => e.type === 'form')[0]
    form.props.onSubmit({ preventDefault() {} })
    form.props.onSubmit({ preventDefault() {} })
    await settle()
    assert.equal(button(v.root, '取消').props.disabled, true)
    assert.equal(button(v.root, '編輯').props.disabled, true)
    button(v.root, '編輯').props.onClick()
    await settle()
    assert.equal(all(v.root, (e) => e.type === 'form').length, 1)
    assert.equal(requests.filter((c) => c.method !== 'get').length, 1)
    d.resolve()
    await settle()
    await settle()
    assert.match(text(v.root), /Saved successfully/)
    assert.match(text(v.root), /清單可能過時/)
    assert.equal(all(v.root, (e) => e.type === 'form').length, 0)
    handler = (c) => Promise.resolve(baseline(c))
    button(v.root, '重新載入').props.onClick()
    await settle()
    assert.equal(requests.filter((c) => c.method !== 'get').length, 1)
    v.app.unmount()
  })
for (const failure of [false, true])
  test(
    'Checkout district latest city wins over old ' + (failure ? 'failure' : 'success'),
    async () => {
      const a = deferred(),
        b = deferred()
      handler = (c) =>
        c.url === '/addresses'
          ? Promise.resolve(reply(c, { data: [] }))
          : c.url === '/cities/1/districts'
            ? a.promise.then((data) => reply(c, data))
            : c.url === '/cities/2/districts'
              ? b.promise.then((data) => reply(c, data))
              : Promise.resolve(baseline(c))
      const v = await mount(components.checkout, '/checkout')
      await settle()
      await choose(v.root, 'recipientCity', 1)
      await choose(v.root, 'recipientCity', 2)
      b.resolve({ data: [{ id: 22, name: 'District B', postal_code: '200' }] })
      await settle()
      failure
        ? a.reject(new Error('late'))
        : a.resolve({ data: [{ id: 11, name: 'District A', postal_code: '100' }] })
      await settle()
      assert.match(text(v.root), /District B/)
      assert.doesNotMatch(text(v.root), /District A|行政區資料載入失敗/)
      v.app.unmount()
    },
  )
test('Checkout retry after a late initial GET failure preserves already entered purchaser draft', async () => {
  // Form is not exposed until initial GETs succeed; retry must not replace a
  // prefilled purchaser with a second identity value while recovering.
  let fail = true
  handler = (c) =>
    c.url === '/addresses' && fail ? Promise.reject(new Error('503')) : Promise.resolve(baseline(c))
  const v = await mount(components.checkout, '/checkout')
  await settle()
  const auth = useAuthStore()
  auth.currentUser = { ...auth.currentUser, name: 'New identity name' }
  fail = false
  button(v.root, '重新載入結帳資料').props.onClick()
  await settle()
  assert.equal(input(v.root, 'purchaserName').value, 'Fixture')
  assert.equal(requests.filter((c) => c.method === 'post' && c.url === '/checkout').length, 0)
  v.app.unmount()
})

for (const failure of [false, true]) {
  test(`Address mutation late ${failure ? 'error' : 'success'} after unmount emits nothing and never retries`, async () => {
    requests.length = 0
    handler = c => c.url.includes('districts')
      ? Promise.resolve(reply(c, { data: [{ id: 11, name: 'District A', postal_code: '100' }] }))
      : Promise.resolve(baseline(c))
    let saved = 0
    const v = await mount(components.addressform, '/member/addresses', { onSaved: () => saved++ })
    await choose(v.root, 'city', 1)
    await choose(v.root, 'district', 11)
    const d = deferred()
    handler = c => d.promise.then(() => reply(c, { message: 'Saved' }))
    all(v.root, e => e.type === 'form')[0].props.onSubmit({ preventDefault() {} })
    await settle()
    assert.equal(requests.filter(c => c.method === 'post').length, 1)
    v.app.unmount()
    if (failure) d.reject(new Error('late'))
    else d.resolve()
    await settle()
    assert.equal(saved, 0)
    assert.equal(text(v.root), '')
    assert.equal(requests.filter(c => c.method === 'post').length, 1)
  })
}

test('Main product network failure retries just one main GET before starting independent related', async () => {
  requests.length = 0
  handler = () => Promise.reject(new Error('network'))
  const v = await mount(components.detail)
  await settle()
  const d = deferred()
  handler = c => c.url.endsWith('/related') ? Promise.resolve(reply(c, { data: [] })) : d.promise.then(() => reply(c, { data: product(1) }))
  const retry = button(v.root, '重新載入商品')
  retry.props.onClick()
  retry.props.onClick()
  await settle()
  assert.equal(requests.filter(c => c.url === '/products/1').length, 2)
  d.resolve()
  await settle()
  assert.match(text(v.root), /Product-1/)
  assert.equal(requests.filter(c => c.url.endsWith('/related')).length, 1)
  v.app.unmount()
})
