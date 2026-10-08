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
const originalDocument = globalThis.Document
globalThis.Document = class Document {}
const files = {
  'virtual:member': '/src/views/MemberView.vue',
  'virtual:list': '/src/views/ProductListView.vue',
  'virtual:pagination': '/src/components/common/AppPagination.vue',
}
const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', plugins: [{
  name: 'storefront-ui-test', enforce: 'pre',
  resolveId(id) { if (id in files) return '\0' + id + '.ts'; if (id.endsWith('.vue')) return '\0virtual:blank.ts' },
  async load(id) {
    if (id === '\0virtual:blank.ts') return 'export default { render() { return null } }'
    const file = files[id.replace(/^\0/, '').replace(/\.ts$/, '')]
    if (!file) return
    const source = await readFile(new URL('..' + file, import.meta.url), 'utf8')
    return ts.transpileModule(compileScript(parse(source).descriptor, { id, inlineTemplate: true,
      templateOptions: { compilerOptions: { hoistStatic: false } } }).content,
      { compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.ESNext } }).outputText
  },
}] })
const { default: Member } = await server.ssrLoadModule('virtual:member')
const { useAuthStore } = await server.ssrLoadModule('/src/stores/auth.ts')
const { default: ProductList } = await server.ssrLoadModule('virtual:list')
const { default: Pagination } = await server.ssrLoadModule('virtual:pagination')
const { default: api } = await server.ssrLoadModule('/src/services/api.ts')
const requests = []
api.defaults.adapter = async config => {
  requests.push(config)
  return { data: { data: [], links: {}, meta: { current_page: 1, last_page: 2, per_page: 8, total: 0 } },
    status: 200, statusText: 'OK', headers: {}, config }
}
after(async () => { if (originalStorage === undefined) delete globalThis.localStorage; else globalThis.localStorage = originalStorage; if (originalDocument === undefined) delete globalThis.Document; else globalThis.Document = originalDocument; await server.close() })
const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null, tagName: type.toUpperCase(), value: '', getRootNode: () => ({ activeElement: null }), get options() { const collect = el => el.children.flatMap(child => child.type === 'option' ? [child] : collect(child)); return collect(this) }, listeners: {}, addEventListener(event, handler) { this.listeners[event] = handler }, removeEventListener(event) { delete this.listeners[event] } })
const renderer = createRenderer({
  createElement: type => node(type), createText: text => node('text', text), createComment: () => node('comment'),
  setText: (el, text) => { el.text = text }, setElementText: (el, text) => { el.text = text; el.children = [] },
  patchProp: (el, key, _old, value) => { el.props[key] = value },
  insert(el, parent, anchor = null) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1); el.parent = parent; const i = anchor ? parent.children.indexOf(anchor) : -1; if (i < 0) parent.children.push(el); else parent.children.splice(i, 0, el) },
  remove(el) { if (el.parent) el.parent.children.splice(el.parent.children.indexOf(el), 1) },
  parentNode: el => el.parent, nextSibling: el => el.parent?.children[el.parent.children.indexOf(el) + 1] ?? null,
})
const text = el => el.text + el.children.map(text).join('')
const all = (el, pred) => [...(pred(el) ? [el] : []), ...el.children.flatMap(child => all(child, pred))]
const settle = async () => { await new Promise(resolve => setImmediate(resolve)); await nextTick() }
const source = path => readFile(new URL('../src/' + path, import.meta.url), 'utf8')
const mountList = async path => {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/products', component: ProductList }] })
  await router.push(path); await router.isReady()
  const root = node('root'); const app = renderer.createApp({ render: () => h(ProductList) }); app.use(router); app.mount(root)
  await settle(); await settle()
  return { root, app, router }
}

test('Mobile category disclosure changes local state without fetching or losing URL filters', async () => {
  const v = await mountList('/products?search=啞鈴&category_id=11&sort=price_asc&min_price=100&page=2')
  const toggle = all(v.root, e => e.type === 'button' && e.props['aria-controls'] === 'product-filters')[0]
  assert.ok(toggle); assert.equal(toggle.props.type, 'button'); assert.equal(toggle.props['aria-expanded'], false)
  const before = requests.length; const query = { ...v.router.currentRoute.value.query }
  toggle.props.onClick(); await settle(); assert.equal(toggle.props['aria-expanded'], true)
  toggle.props.onClick(); await settle(); assert.equal(toggle.props['aria-expanded'], false)
  assert.equal(requests.length, before); assert.deepEqual(v.router.currentRoute.value.query, query)
  assert.ok(all(v.root, e => e.props.id === 'product-filters').length === 1)
  v.app.unmount()
})
test('Product query continues to be server-side and preserves search/category/price/sort/page', async () => {
  requests.length = 0
  const v = await mountList('/products?search=啞鈴&category_id=11&sort=price_asc&min_price=100&max_price=500&page=2')
  const req = requests.find(r => r.url === '/products')
  assert.ok(req); assert.equal(req.params.search, '啞鈴'); assert.equal(req.params.category_id, 11)
  assert.equal(req.params.min_price, 100); assert.equal(req.params.max_price, 500); assert.equal(req.params.sort, 'price_asc'); assert.equal(req.params.page, 2)
  assert.ok(text(v.root).includes('商品')); v.app.unmount()
})
test('Pagination short window preserves four pages and current/boundary guards', async () => {
  const changes = []; const root = node('root'); const app = renderer.createApp({ render: () => h(Pagination, { currentPage: 2, lastPage: 4, onChangePage: p => changes.push(p) }) }); app.mount(root)
  const buttons = all(root, e => e.type === 'button')
  assert.deepEqual(buttons.map(e => text(e).trim()), ['上一頁', '1', '2', '3', '4', '下一頁'])
  buttons[2].props.onClick(); buttons[3].props.onClick(); assert.deepEqual(changes, [3]); app.unmount()
})
test('Pagination previous and next disabled states remain correct', () => {
  for (const [current, index] of [[1, 0], [4, 5]]) {
    const root = node('root'); const app = renderer.createApp({ render: () => h(Pagination, { currentPage: current, lastPage: 4 }) }); app.mount(root)
    assert.equal(all(root, e => e.type === 'button')[index].props.disabled, true); app.unmount()
  }
})
test('Detail grid contains narrow columns without concealing overflow', async () => {
  const s = await source('views/ProductDetailView.vue')
  assert.match(s, /product-detail-grid/); assert.match(s, /minmax\(0, 1fr\)/)
  assert.doesNotMatch(s, /row g-5|overflow-x:\s*(hidden|clip)/)
  for (const part of ['ProductGallery', '加入購物車', '規格']) assert.ok(s.includes(part))
})
test('Member navigation uses a scoped active class and retains all links', async () => {
  const s = await source('views/MemberView.vue')
  assert.match(s, /hf-member-active/); assert.match(s, /aria-label=/)
  for (const path of ['member-profile', 'member-addresses', 'member-orders']) assert.ok(s.includes(path))
  assert.doesNotMatch(s, /<main/)
})
test('Front 404 retains storefront navigation with real destinations', async () => {
  const s = await source('views/NotFoundView.vue')
  for (const part of ['AppHeader', 'AppFooter', "name: 'home'", "name: 'products'", '<main']) assert.ok(s.includes(part))
})
test('Shared functional styling stays within the storefront and preserves semantic danger', async () => {
  const s = await source('assets/main.css')
  assert.match(s, /\.storefront-shell \.hf-functional-page/)
  assert.doesNotMatch(s, /overflow-x:\s*(hidden|clip)/)
  assert.doesNotMatch(s, /is\([^)]*btn-danger[^)]*\)/)
  const warning = await source('views/admin/CategoryManagementView.vue')
  assert.match(warning, /btn-category-status/); assert.match(warning, /#755300/)
})
test('Member order detail retains the Orders active navigation context', async () => {
  const pinia = createPinia(); setActivePinia(pinia)
  const auth = useAuthStore(pinia); auth.currentUser = { id: 901, name: 'UI fixture', email: 'visual@example.test', status: 'active' }
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/member/orders', name: 'member-orders', component: { render: () => null } },
    { path: '/member/orders/:id', name: 'member-order-detail', component: { render: () => null } },
    { path: '/member/profile', name: 'member-profile', component: { render: () => null } },
    { path: '/member/addresses', name: 'member-addresses', component: { render: () => null } },
    { path: '/login', name: 'login', component: { render: () => null } },
  ] })
  await router.push('/member/orders/901'); await router.isReady()
  const root = node('root'); const app = renderer.createApp({ render: () => h(Member) }); app.use(pinia); app.use(router); app.mount(root)
  const orders = all(root, e => e.type === 'a' && e.props.href === '/member/orders')[0]
  assert.ok(orders); assert.ok(orders.props.class.includes('hf-member-active')); assert.ok(!orders.props.class.split(' ').includes('active'))
  assert.equal(all(root, e => e.type === 'main').length, 0)
  app.unmount()
})
