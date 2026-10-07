import assert from 'node:assert/strict'
import { test, after } from 'node:test'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import { createRenderer, nextTick, h } from 'vue'
import { createRouter, createMemoryHistory, RouterView } from 'vue-router'

const files = {
  'virtual:about': '/src/views/AboutView.vue',
  'virtual:faq': '/src/views/FAQView.vue',
}
const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', plugins: [{
  name: 'content-pages-test-sfc', enforce: 'pre',
  resolveId(id) { if (id in files) return '\0' + id + '.ts' },
  async load(id) {
    const file = files[id.replace(/^\0/, '').replace(/\.ts$/, '')]
    if (!file) return
    const source = await readFile(new URL('..' + file, import.meta.url), 'utf8')
    return ts.transpileModule(compileScript(parse(source).descriptor, { id, inlineTemplate: true,
      templateOptions: { compilerOptions: { hoistStatic: false } } }).content,
      { compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.ESNext } }).outputText
  },
}] })
const { default: About } = await server.ssrLoadModule('virtual:about')
const { default: FAQ } = await server.ssrLoadModule('virtual:faq')
const { faqGroups } = await server.ssrLoadModule('/src/content/faq.ts')
const aboutSource = await readFile(new URL('../src/views/AboutView.vue', import.meta.url), 'utf8')
const faqSource = await readFile(new URL('../src/views/FAQView.vue', import.meta.url), 'utf8')
const routerSource = await readFile(new URL('../src/router/index.ts', import.meta.url), 'utf8')
after(async () => { await server.close() })

// Vue memory renderer verifies rendered contracts; native browser interaction is checked separately.
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

const mount = async (path) => {
  const router = createRouter({ history: createMemoryHistory(), routes: [
    { path: '/about', name: 'about', component: About },
    { path: '/faq', name: 'faq', component: FAQ },
    { path: '/products', name: 'products', component: { render: () => h('div', '商品頁') } },
  ] })
  await router.push(path); await router.isReady()
  const root = node('root')
  const app = renderer.createApp({ render: () => h(RouterView) })
  app.use(router); app.mount(root)
  return { app, root, router }
}

test('About presents the confirmed story in order and all three values', async () => {
  const v = await mount('/about')
  assert.equal(text(find(v.root, e => e.type === 'h1')), '把訓練，帶回自己的生活')
  for (const copy of ['ABOUT HOME FIT', '為日常而設計的訓練選擇',
    '居家訓練不需要一次擁有所有設備。', 'Home Fit 聚焦居家重訓器材與訓練配件，',
    '讓器材資訊與選擇更清楚', 'Home Fit 將商品分類、圖片、規格與購買選項']) {
    assert.ok(text(v.root).includes(copy), copy)
  }
  const sections = all(v.root, e => e.type === 'section')
  assert.deepEqual(sections.map(e => e.props['aria-labelledby'] || e.props['aria-label']),
    ['philosophy-title', 'context-title', '三項品牌價值', 'positioning-title', '探索訓練器材'])
  const values = all(find(v.root, e => e.type === 'ol'), e => e.type === 'li')
  assert.deepEqual(values.map(text), ['01居家訓練導向', '02依訓練需求選購', '03清楚查看器材規格'])
  v.app.unmount()
})

test('About uses the supplied asset, meaningful alt and intrinsic dimensions without homepage image reuse', async () => {
  const v = await mount('/about')
  const images = all(v.root, e => e.type === 'img')
  assert.equal(images.length, 1)
  assert.match(images[0].props.src, /about\/about-home-training\.webp/)
  assert.equal(images[0].props.alt, '自然光住宅空間中的健身椅、啞鈴與居家重訓器材')
  assert.equal(images[0].props.width, '1536'); assert.equal(images[0].props.height, '1024')
  assert.equal(images[0].props.loading, 'eager'); assert.equal(images[0].props.decoding, 'async')
  assert.ok(!aboutSource.includes('cta_01'))
  v.app.unmount()
})

test('About CTA navigates to the existing product list', async () => {
  const v = await mount('/about')
  const link = find(v.root, e => e.type === 'a' && e.props.href === '/products')
  assert.ok(text(link).includes('探索訓練器材'))
  link.props.onClick({ button: 0, preventDefault() {} })
  await settle(); await settle()
  assert.equal(v.router.currentRoute.value.name, 'products')
  v.app.unmount()
})

test('About has no invented commercial promises or developer background', async () => {
  const v = await mount('/about')
  assert.doesNotMatch(text(v.root), /專家|專業顧問|最高品質|嚴選|保證|售後服務|工廠|門市|教練|保固|職訓|作品集|Vue|Laravel|MySQL|技術棧|面試|開發過程/)
  v.app.unmount()
})

test('FAQ renders exactly five ordered categories and thirteen native question/answer pairs', async () => {
  const v = await mount('/faq')
  assert.equal(text(find(v.root, e => e.type === 'h1')), '常見問題與購物須知')
  assert.deepEqual(faqGroups.map(g => g.title), ['購物與商品', '會員與購物車', '付款', '配送', '訂單與取消'])
  assert.deepEqual(faqGroups.map(g => g.items.length), [3, 3, 2, 2, 3])
  const expectedQuestions = ['如何選擇商品？', '有購買規格的商品要怎麼選？', '缺貨或商品目前無法購買怎麼辦？',
    '一定要登入會員才能購物嗎？', '登入後原本的購物車會保留嗎？', '收件地址可以怎麼填寫？',
    '目前有哪些付款方式？', '付款流程失敗時會建立訂單嗎？', '目前提供哪些配送方式？運費是多少？',
    '可以查看物流資訊嗎？', '要在哪裡查看訂單？', '哪些訂單可以取消？', '取消訂單後會怎麼處理？']
  assert.deepEqual(all(v.root, e => e.type === 'summary').map(text), expectedQuestions)
  const details = all(v.root, e => e.type === 'details')
  assert.equal(details.length, 13)
  for (const detail of details) {
    assert.equal(detail.children[0].type, 'summary')
    assert.equal(all(detail, e => e.type === 'p').length, 1)
    assert.equal(detail.props.name, undefined) // no exclusive details group
    assert.equal(detail.props.onToggle, undefined) // native state, no Vue accordion state
  }
  v.app.unmount()
})

test('FAQ clearly describes mock payment and rollback, not real card processing', async () => {
  const v = await mount('/faq')
  const copy = text(v.root)
  for (const expected of ['信用卡流程僅供專題展示', '不會進行真實扣款', '不需要輸入真實信用卡資料',
    '系統不會建立訂單或扣除商品庫存', '購物車內容會保留']) assert.ok(copy.includes(expected))
  assert.doesNotMatch(copy, /安全第三方刷卡|正式信用卡刷卡/)
  v.app.unmount()
})

test('FAQ preserves delivery, member and cancellation domain boundaries', async () => {
  const v = await mount('/faq'); const copy = text(v.root)
  for (const expected of ['每筆訂單固定運費為 NT$100', '沒有免運門檻', '目前沒有提供即時物流追蹤頁面或到貨時間承諾',
    '進入結帳流程前需要登入有效會員帳號', '系統會嘗試合併', '「待處理」或「處理中」',
    '「已出貨」及「已完成」訂單無法取消', '保留原訂單紀錄與付款狀態', '目前沒有實作正式退款流程']) assert.ok(copy.includes(expected))
  assert.doesNotMatch(copy, /全台配送|[13]～[35]天|快速到貨|出貨前都可以取消|自動存入地址簿|沒有物流功能/)
  v.app.unmount()
})

test('Demonstration notice follows FAQ groups outside all details', async () => {
  const v = await mount('/faq')
  const notice = find(v.root, e => e.type === 'aside')
  assert.equal(text(find(notice, e => e.type === 'h2')), '展示環境說明')
  assert.ok(text(notice).includes('Home Fit 為專題展示網站。'))
  assert.ok(text(notice).includes('尚未串接正式第三方金流、電子發票，以及退貨、換貨與退款服務。'))
  assert.equal(all(notice, e => e.type === 'details').length, 0)
  v.app.unmount()
})

test('FAQ is static native HTML with no API/store/Collapse state or new service', () => {
  assert.doesNotMatch(faqSource, /from ['"][^'"]*(?:services|stores)|axios|fetch\(|onMounted|ref\(|reactive\(|data-bs-toggle|Collapse|v-html/)
  assert.match(faqSource, /summary:focus-visible/)
  assert.match(faqSource, /details\[open\]/)
})

test('Actual public router adds only the FAQ page and retains existing named routes', () => {
  const ast = ts.createSourceFile('router.ts', routerSource, ts.ScriptTarget.Latest, true)
  const records = []
  const visit = node => {
    if (ts.isObjectLiteralExpression(node)) {
      const fields = Object.fromEntries(node.properties.filter(ts.isPropertyAssignment)
        .filter(p => ts.isIdentifier(p.name) && ts.isStringLiteral(p.initializer))
        .map(p => [p.name.text, p.initializer.text]))
      if (fields.path !== undefined && fields.name) records.push(fields)
    }
    ts.forEachChild(node, visit)
  }
  visit(ast)
  const expected = { home: '', about: 'about', faq: 'faq', products: 'products', 'product-detail': 'products/:id',
    cart: 'cart', checkout: 'checkout', login: 'login', register: 'register', member: 'member',
    'member-orders': 'orders', 'member-order-detail': 'orders/:id', 'member-profile': 'profile',
    'member-addresses': 'addresses', 'not-found': '/:pathMatch(.*)*' }
  for (const [name, path] of Object.entries(expected)) assert.equal(records.find(r => r.name === name)?.path, path)
  assert.match(routerSource, /component: FAQView/)
  assert.match(routerSource, /\.\.\.adminRoutes/)
  assert.match(routerSource, /router\.beforeEach\(adminGuard\)/)
  assert.ok(!routerSource.includes('afterEach')) // SEO belongs to Step2
})
