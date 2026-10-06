import assert from 'node:assert/strict'
import { test, after, beforeEach } from 'node:test'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import { createRenderer, nextTick, h } from 'vue'
import { createRouter, createMemoryHistory, RouterView } from 'vue-router'
import axios from 'axios'

const views = {
  'virtual:home': '/src/views/HomeView.vue',
  'virtual:product-card': '/src/components/product/ProductCard.vue',
}
const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', plugins: [{
  name: 'home-content-test-sfc', enforce: 'pre',
  resolveId(id) {
    if (id in views) return '\0' + id + '.ts'
    if (id.endsWith('/ProductCard.vue')) return '\0virtual:product-card.ts'
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
const services = await server.ssrLoadModule('/src/services/homeContentService.ts')
const session = await server.ssrLoadModule('/src/services/sessionState.ts')
const { default: Home } = await server.ssrLoadModule('virtual:home')
const originalError = console.error
console.error = () => {}
after(async () => { console.error = originalError; await server.close() })
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
const mount = async () => {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/', component: Home },
    { path: '/products', component: { render: () => h('div', '商品頁') } },
    { path: '/products/:id', name: 'product-detail', component: { render: () => h('div', '商品詳細') } },
    { path: '/away', component: { render: () => h('div', '其他頁') } },
  ] })
  await router.push('/'); await router.isReady()
  const root = node('root')
  const app = renderer.createApp({ render: () => h(RouterView) })
  app.use(router); app.mount(root)
  return { app, root, router }
}

test('public service uses existing api GET endpoints and returns unwrapped data', async () => {
  const calls = []
  api.defaults.adapter = async (config) => { calls.push(config); return reply(config, config.url === '/banners' ? [banner()] : [product()]) }
  assert.deepEqual(await services.getBanners(), [banner()])
  assert.deepEqual(await services.getRecommendedProducts(), [product()])
  assert.deepEqual(calls.map((c) => [c.method, c.url]), [['get', '/banners'], ['get', '/recommended-products']])
})

test('Home loads sections independently and renders actual Banner and ProductCard', async () => {
  const finishes = {}
  api.defaults.adapter = (config) => new Promise((resolve) => { finishes[config.url] = (data) => resolve(reply(config, data)) })
  const view = await mount(); await settle()
  assert.ok(text(view.root).includes('輪播載入中'))
  assert.ok(text(view.root).includes('推薦商品載入中'))
  finishes['/banners']([banner()]); await settle()
  assert.ok(text(view.root).includes('輪播 1'))
  assert.ok(text(view.root).includes('推薦商品載入中'))
  assert.equal(find(view.root, (el) => el.type === 'img').props.src, banner().image_url)
  assert.equal(find(view.root, (el) => el.type === 'a').props.href, banner().link_url)
  finishes['/recommended-products']([product()]); await settle()
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
  assert.equal(find(view.root, (el) => el.type === 'button' && text(el) === '下一張').props.disabled, true)
  find(view.root, (el) => el.type === 'button' && text(el) === '上一張').props.onClick()
  await settle(); assert.ok(text(view.root).includes('輪播 4'))
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
  assert.equal(Boolean(find(view.root, (el) => el.type === 'a')), hasButton)
  if (fields.subtitle === null) assert.ok(!text(view.root).includes('介紹'))
  view.app.unmount()
})

test('both empty collections have explicit empty states, not errors', async () => {
  api.defaults.adapter = async (config) => reply(config, [])
  const view = await mount(); await settle()
  assert.ok(text(view.root).includes('目前沒有輪播內容'))
  assert.ok(text(view.root).includes('目前沒有推薦商品'))
  assert.equal(all(view.root, (el) => el.props.role === 'alert').length, 0)
  view.app.unmount()
})

for (const endpoint of ['/banners', '/recommended-products']) {
  for (const status of [undefined, 500]) test(endpoint + ' error keeps other section and manual retry only GET, status=' + status, async () => {
    let broken = true
    const calls = []
    api.defaults.adapter = async (config) => {
      calls.push([config.method, config.url])
      if (broken && config.url === endpoint) throw fail(config, status)
      return reply(config, config.url === '/banners' ? [banner()] : [product()])
    }
    const view = await mount(); await settle()
    assert.equal(calls.length, 2)
    assert.ok(text(view.root).includes(endpoint === '/banners' ? '輪播載入失敗' : '推薦商品載入失敗'))
    assert.ok(text(view.root).includes(endpoint === '/banners' ? '推薦器材 12' : '輪播 1'))
    assert.ok(!text(view.root).includes(endpoint === '/banners' ? '目前沒有輪播內容' : '目前沒有推薦商品'))
    broken = false
    find(view.root, (el) => el.type === 'button' && text(el) === (endpoint === '/banners' ? '重試輪播' : '重試推薦商品')).props.onClick()
    await settle()
    assert.equal(calls.length, 3)
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
    finish.push(() => rejects ? reject(fail(config, 500)) : resolve(reply(config, config.url === '/banners' ? [banner()] : [product()])))
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

test('older retry responses cannot overwrite latest section results', async () => {
  let broken = true
  const finish = []
  api.defaults.adapter = (config) => {
    if (config.url !== '/banners') return Promise.resolve(reply(config, []))
    if (broken) return Promise.reject(fail(config, 500))
    return new Promise((resolve) => { finish.push((id) => resolve(reply(config, [banner(id)]))) })
  }
  const view = await mount(); await settle(); broken = false
  const retry = find(view.root, (el) => el.type === 'button' && text(el) === '重試輪播').props.onClick
  retry(); retry(); await settle()
  finish[1](2); await settle(); finish[0](1); await settle()
  assert.ok(text(view.root).includes('輪播 2'))
  assert.ok(!text(view.root).includes('輪播 1'))
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
