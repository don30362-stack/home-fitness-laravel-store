import assert from 'node:assert/strict'
import { test, after, beforeEach } from 'node:test'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import { createRenderer, nextTick, h } from 'vue'
import { createRouter, createMemoryHistory, RouterView } from 'vue-router'
import axios from 'axios'
import { createPinia } from 'pinia'

const views = {
  'virtual:home': '/src/views/HomeView.vue',
  'virtual:product-card': '/src/components/product/ProductCard.vue',
  'virtual:banner-carousel': '/src/components/home/BannerCarousel.vue',
  'virtual:header': '/src/components/layout/AppHeader.vue',
}
const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', plugins: [{
  name: 'home-content-test-sfc', enforce: 'pre',
  resolveId(id) {
    if (id in views) return '\0' + id + '.ts'
    if (id.endsWith('/ProductCard.vue')) return '\0virtual:product-card.ts'
    if (id.endsWith('/BannerCarousel.vue')) return '\0virtual:banner-carousel.ts'
  },
  async load(id) {
    const file = views[id.replace(/^\0/, '').replace(/\.ts$/, '')]
    if (!file) return
    const source = await readFile(new URL('..' + file, import.meta.url), 'utf8')
    return ts.transpileModule(compileScript(parse(source).descriptor, { id, inlineTemplate: true,
      templateOptions: { compilerOptions: { hoistStatic: false } } }).content,
      { compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.ESNext } }).outputText
  },
}] })
const { default: api } = await server.ssrLoadModule('/src/services/api.ts')
const services = await server.ssrLoadModule('/src/services/homeContentService.ts')
const categoryServices = await server.ssrLoadModule('/src/services/categoryService.ts')
const session = await server.ssrLoadModule('/src/services/sessionState.ts')
const { default: Home } = await server.ssrLoadModule('virtual:home')
const { default: Header } = await server.ssrLoadModule('virtual:header')
const originalError = console.error
const originalStorage = globalThis.localStorage
globalThis.localStorage = { getItem: () => null, setItem() {}, removeItem() {} }
console.error = () => {}
after(async () => { console.error = originalError; if (originalStorage === undefined) delete globalThis.localStorage; else globalThis.localStorage = originalStorage; await server.close() })
beforeEach(() => { session.startSessionVersion(); session.setSessionFailureHandler(() => {}) })

// Same Vue memory-host approach as existing orders/admin tests; this is not browser acceptance.
const node = (type, text = '') => ({ type, text, props: {}, children: [], parent: null })
const renderer = createRenderer({
  createElement: (type) => node(type), createText: (text) => node('text', text), createComment: () => node('comment'),
  setText: (el, text) => { el.text = text }, setElementText: (el, text) => { el.text = text; el.children = [] },
  patchProp: (el, key, _old, value) => { el.props[key] = value },
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
const all = (el, predicate) => [...(predicate(el) ? [el] : []), ...el.children.flatMap((child) => all(child, predicate))]
const settle = async () => { await new Promise((resolve) => setImmediate(resolve)); await nextTick() }
const reply = (config, data) => ({ config, data: { data }, status: 200, statusText: 'OK', headers: {} })
const fail = (config, status) => new axios.AxiosError('request failed', status ? 'ERR_BAD_RESPONSE' : 'ERR_NETWORK', config, undefined,
  status ? { ...reply(config, []), status, data: { message: 'temporary error' } } : undefined)
const banner = (id = 1, overrides = {}) => ({ id, title: '輪播 ' + id, subtitle: '介紹', image_url: '/storage/banners/test.jpg', button_text: '瀏覽商品', link_url: '/products?category_id=5#list', sort_order: id, ...overrides })
const product = (id = 12) => ({ id, product_code: 'PRD-TEST0001', name: '推薦器材 ' + id, price: '1234.00', short_description: null, description: null, stock: 0, status: 'active', category: { id: 5, name: '器材' }, images: [] })
const category = (id = 41) => ({ id, name: '主分類 ' + id, children: [{ id: 91, name: '子分類不展開' }] })
const dataFor = (url) => url === '/banners' ? [banner()] : url === '/categories' ? [category()] : [product()]
const mount = async (component = Home) => {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/', name: 'home', component },
    { path: '/products', name: 'products', component: { render: () => h('div', '商品頁') } },
    { path: '/products/:id', name: 'product-detail', component: { render: () => h('div', '商品詳細') } },
    { path: '/away', component: { render: () => h('div', '其他頁') } },
    ...['about', 'cart', 'member-profile', 'login', 'register'].map((name) => ({ path: '/nav-' + name, name, component: { render: () => h('div', name) } })),
  ] })
  await router.push('/'); await router.isReady()
  const root = node('root')
  const app = renderer.createApp({ render: () => h(RouterView) })
  app.use(createPinia()); app.use(router); app.mount(root)
  return { app, root, router }
}

test('public service uses existing api GET endpoints and returns unwrapped data', async () => {
  const calls = []
  api.defaults.adapter = async (config) => { calls.push(config); return reply(config, dataFor(config.url)) }
  assert.deepEqual(await services.getBanners(), [banner()])
  assert.deepEqual(await services.getRecommendedProducts(), [product()])
  assert.deepEqual(await categoryServices.getCategories(), [category()])
  assert.deepEqual(calls.map((c) => [c.method, c.url]), [['get', '/banners'], ['get', '/recommended-products'], ['get', '/categories']])
})

test('Home loads sections independently and renders actual Banner and ProductCard', async () => {
  const finishes = {}
  api.defaults.adapter = (config) => new Promise((resolve) => { finishes[config.url] = (data) => resolve(reply(config, data)) })
  const view = await mount(); await settle()
  assert.ok(text(view.root).includes('輪播載入中'))
  assert.ok(text(view.root).includes('推薦商品載入中'))
  assert.ok(text(view.root).includes('商品分類載入中'))
  finishes['/banners']([banner()]); await settle()
  assert.ok(text(view.root).includes('輪播 1'))
  assert.ok(text(view.root).includes('推薦商品載入中'))
  assert.equal(find(view.root, (el) => el.type === 'img').props.src, banner().image_url)
  assert.equal(find(view.root, (el) => el.type === 'a').props.href, banner().link_url)
  finishes['/recommended-products']([product()]); await settle()
  finishes['/categories']([category()]); await settle()
  assert.ok(text(view.root).includes('主分類 41'))
  assert.ok(!text(view.root).includes('子分類不展開'))
  assert.ok(text(view.root).includes('推薦器材 12'))
  assert.ok(text(view.root).includes('1,234'))
  assert.ok(text(view.root).includes('商品圖片準備中'))
  assert.ok(find(view.root, (el) => el.type === 'a' && el.props.href === '/products/12'))
  assert.ok(!text(view.root).includes('缺貨'))
  view.app.unmount()
})

test('Banner controls expose all ordered banners without autoplay', async () => {
  api.defaults.adapter = async (config) => reply(config, config.url === '/banners' ? [banner(4), banner(2)] : [])
  const view = await mount(); await settle()
  assert.ok(text(view.root).includes('輪播 4'))
  find(view.root, (el) => el.type === 'button' && text(el) === '下一張').props.onClick()
  await settle(); assert.ok(text(view.root).includes('輪播 2'))
  find(view.root, (el) => el.type === 'button' && text(el) === '下一張').props.onClick()
  await settle(); assert.ok(text(view.root).includes('輪播 4'))
  find(view.root, (el) => el.type === 'button' && text(el) === '上一張').props.onClick()
  await settle(); assert.ok(text(view.root).includes('輪播 2'))
  const firstIndicator = find(view.root, (el) => el.props['aria-label'] === '顯示第 1 張輪播')
  firstIndicator.props.onClick(); await settle()
  assert.equal(firstIndicator.props['aria-pressed'], true)
  assert.ok(text(view.root).includes('輪播 4'))
  view.app.unmount()
})

for (const [label, fields, hasButton] of [
  ['null optional text', { subtitle: null, button_text: null, link_url: null }, false],
  ['text without link', { subtitle: null, link_url: null }, false],
  ['link without text', { subtitle: null, button_text: null }, false],
  ['paired button', {}, true],
]) test('Banner optional fields: ' + label, async () => {
  api.defaults.adapter = async (config) => reply(config, config.url === '/banners' ? [banner(1, fields)] : [])
  const view = await mount(); await settle()
  const hero = find(view.root, (el) => el.type === 'section' && el.props['aria-label'] === '輪播')
  assert.equal(Boolean(find(hero, (el) => el.type === 'a')), hasButton)
  if (fields.subtitle === null) assert.ok(!text(hero).includes('介紹'))
  view.app.unmount()
})

test('empty Banner has formal fallback, empty category keeps CTA, empty recommendations hide section', async () => {
  api.defaults.adapter = async (config) => reply(config, [])
  const view = await mount(); await settle()
  assert.ok(text(view.root).includes('把訓練，帶回自己的生活'))
  const hero = find(view.root, (el) => el.props['aria-label'] === '輪播')
  assert.ok(find(hero, (el) => el.type === 'a' && el.props.href === '/products'))
  const categories = find(view.root, (el) => el.props['aria-labelledby'] === 'categories-title')
  assert.ok(find(categories, (el) => el.type === 'a' && el.props.href === '/products'))
  assert.equal(find(view.root, (el) => el.props['aria-labelledby'] === 'recommended-title'), undefined)
  assert.ok(!text(view.root).includes('目前沒有輪播內容'))
  assert.ok(!text(view.root).includes('目前沒有推薦商品'))
  assert.equal(all(view.root, (el) => el.props.role === 'alert').length, 0)
  view.app.unmount()
})

const sectionLabels = { '/banners': '輪播', '/categories': '商品分類', '/recommended-products': '推薦商品' }
for (const endpoint of Object.keys(sectionLabels)) {
  for (const status of [undefined, 500]) test(endpoint + ' error keeps other section and manual retry only GET, status=' + status, async () => {
    let broken = true
    const calls = []
    api.defaults.adapter = async (config) => {
      calls.push([config.method, config.url])
      if (broken && config.url === endpoint) throw fail(config, status)
      return reply(config, dataFor(config.url))
    }
    const view = await mount(); await settle()
    assert.equal(calls.length, 3)
    assert.ok(text(view.root).includes(sectionLabels[endpoint] + '載入失敗'))
    for (const [other, content] of [['/banners', '輪播 1'], ['/categories', '主分類 41'], ['/recommended-products', '推薦器材 12']]) {
      if (other !== endpoint) assert.ok(text(view.root).includes(content))
    }
    if (endpoint === '/banners') assert.equal(find(view.root, (el) => el.type === 'h1'), undefined)
    broken = false
    find(view.root, (el) => el.type === 'button' && text(el) === '重試' + sectionLabels[endpoint]).props.onClick()
    await settle()
    assert.equal(calls.length, 4)
    assert.deepEqual(calls.at(-1), ['get', endpoint])
    assert.equal(all(view.root, (el) => el.props.role === 'alert').length, 0)
    assert.ok(text(view.root).includes('推薦器材 12'))
    assert.ok(text(view.root).includes('輪播 1'))
    view.app.unmount()
  })
}

for (const rejects of [false, true]) test('route leave ignores both late responses, rejects=' + rejects, async () => {
  const finish = []
  api.defaults.adapter = (config) => new Promise((resolve, reject) => {
    finish.push(() => rejects ? reject(fail(config, 500)) : resolve(reply(config, dataFor(config.url))))
  })
  const view = await mount(); await settle()
  await view.router.push('/away'); await settle()
  const before = text(view.root)
  finish.forEach((done) => done()); await settle()
  assert.equal(text(view.root), before)
  assert.equal(before, '其他頁')
  assert.equal(view.router.currentRoute.value.path, '/away')
  view.app.unmount()
})

for (const endpoint of Object.keys(sectionLabels)) test(endpoint + ' older retry responses cannot overwrite latest section results', async () => {
  let broken = true
  const finish = []
  api.defaults.adapter = (config) => {
    if (config.url !== endpoint) return Promise.resolve(reply(config, dataFor(config.url)))
    if (broken) return Promise.reject(fail(config, 500))
    return new Promise((resolve) => { finish.push((id) => resolve(reply(config, endpoint === '/banners' ? [banner(id)] : endpoint === '/categories' ? [category(id)] : [product(id)]))) })
  }
  const view = await mount(); await settle(); broken = false
  const retry = find(view.root, (el) => el.type === 'button' && text(el) === '重試' + sectionLabels[endpoint]).props.onClick
  retry(); retry(); await settle()
  finish[1](2); await settle(); finish[0](1); await settle()
  const prefix = endpoint === '/banners' ? '輪播 ' : endpoint === '/categories' ? '主分類 ' : '推薦器材 '
  assert.ok(text(view.root).includes(prefix + '2'))
  assert.ok(!text(view.root).includes(prefix + '1'))
  view.app.unmount()
})

test('types reuse ProductListItem and no Admin/mutation transport is added', async () => {
  const service = await readFile(new URL('../src/services/homeContentService.ts', import.meta.url), 'utf8')
  const types = await readFile(new URL('../src/types/homeContent.ts', import.meta.url), 'utf8')
  assert.match(service, /Promise<ProductListItem\[\]>/)
  assert.match(service, /ApiResponse<Banner\[\]>/)
  assert.doesNotMatch(service, /adminApi|authService|pinia|\.post\(|\.patch\(|\.delete\(/)
  assert.doesNotMatch(types, /\bany\b|AdminBanner|CreateBanner|Reorder/)
})

test('formal information architecture, brand copy and final CTA use actual product links', async () => {
  api.defaults.adapter = async (config) => reply(config, dataFor(config.url))
  const view = await mount(); await settle()
  const sections = all(view.root, (el) => el.type === 'section')
  assert.deepEqual(sections.map((el) => el.props['aria-labelledby'] || el.props['aria-label']),
    ['輪播', 'brand-title', 'categories-title', 'recommended-title', '開始選購'])
  const brand = sections[1]
  for (const content of ['把訓練，帶回自己的生活', '從居家重訓器材到訓練配件，找到適合日常練習的選擇。',
    '居家訓練導向', '依訓練需求選購', '清楚查看器材規格']) assert.ok(text(brand).includes(content))
  assert.equal(all(brand, (el) => el.type === 'li').length, 3)
  assert.equal(find(sections.at(-1), (el) => el.type === 'a').props.href, '/products')
  assert.equal(all(view.root, (el) => el.type === 'a' && el.props.href === '/about').length, 0)
  assert.equal(all(view.root, (el) => el.type === 'h1' && text(el) === '首頁').length, 0)
  view.app.unmount()
})

test('single Banner omits all controls and indicators', async () => {
  api.defaults.adapter = async (config) => reply(config, dataFor(config.url))
  const view = await mount(); await settle()
  const hero = find(view.root, (el) => el.props['aria-label'] === '輪播')
  assert.equal(all(hero, (el) => el.type === 'button').length, 0)
  view.app.unmount()
})

test('categories preserve API order, arbitrary root IDs and query navigation without child links', async () => {
  api.defaults.adapter = async (config) => reply(config, config.url === '/categories' ? [category(78), category(43), category(27)] : dataFor(config.url))
  const view = await mount(); await settle()
  const section = find(view.root, (el) => el.props['aria-labelledby'] === 'categories-title')
  const links = all(section, (el) => el.type === 'a')
  assert.deepEqual(links.map((el) => el.props.href), ['/products?parent_category_id=78', '/products?parent_category_id=43', '/products?parent_category_id=27'])
  assert.ok(!text(section).includes('子分類不展開'))
  const event = { button: 0, preventDefault() {} }
  await links[0].props.onClick(event); await settle()
  assert.equal(view.router.currentRoute.value.path, '/products')
  assert.equal(view.router.currentRoute.value.query.parent_category_id, '78')
  view.app.unmount()
})

test('all nine recommendations including stock zero use ProductCard details in API order', async () => {
  const products = Array.from({ length: 9 }, (_, i) => product(30 - i))
  api.defaults.adapter = async (config) => reply(config, config.url === '/recommended-products' ? products : dataFor(config.url))
  const view = await mount(); await settle()
  const section = find(view.root, (el) => el.props['aria-labelledby'] === 'recommended-title')
  assert.deepEqual(all(section, (el) => el.type === 'a').map((el) => el.props.href), products.map((p) => '/products/' + p.id))
  assert.equal(all(section, (el) => el.type === 'h2' && el.props.class === 'h5 card-title').length, 9)
  assert.ok(!text(section).includes('缺貨'))
  view.app.unmount()
})

for (const endpoint of Object.keys(sectionLabels)) test(endpoint + ' older failure/finally cannot end newest loading or overwrite success', async () => {
  let broken = true
  const pending = []
  api.defaults.adapter = (config) => {
    if (config.url !== endpoint) return Promise.resolve(reply(config, dataFor(config.url)))
    if (broken) return Promise.reject(fail(config, 500))
    return new Promise((resolve, reject) => pending.push({ resolve: () => resolve(reply(config, dataFor(config.url))), reject: () => reject(fail(config, 500)) }))
  }
  const view = await mount(); await settle(); broken = false
  const retry = find(view.root, (el) => el.type === 'button' && text(el) === '重試' + sectionLabels[endpoint]).props.onClick
  retry(); retry(); await settle()
  pending[0].reject(); await settle()
  assert.ok(text(view.root).includes(sectionLabels[endpoint] + '載入中'))
  assert.equal(all(view.root, (el) => el.props.role === 'alert').length, 0)
  pending[1].resolve(); await settle()
  assert.ok(!text(view.root).includes(sectionLabels[endpoint] + '載入中'))
  assert.equal(all(view.root, (el) => el.props.role === 'alert').length, 0)
  view.app.unmount()
})

test('Header uses submitted Logo with Home Fitness alt and home brand link, without API requests', async () => {
  const calls = []
  api.defaults.adapter = async (config) => { calls.push(config.url); return reply(config, []) }
  const view = await mount(Header); await settle()
  const brand = find(view.root, (el) => el.type === 'a' && el.props.class.includes('navbar-brand'))
  const logo = find(brand, (el) => el.type === 'img')
  assert.equal(brand.props.href, '/')
  assert.equal(logo.props.alt, 'Home Fitness')
  assert.match(logo.props.src, /home-fit-logo\.png/)
  assert.equal(logo.props.width, '1032')
  assert.equal(logo.props.height, '713')
  assert.deepEqual(calls, [])
  view.app.unmount()
})
