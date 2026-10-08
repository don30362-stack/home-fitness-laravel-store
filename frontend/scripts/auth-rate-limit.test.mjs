import assert from 'node:assert/strict'
import { test, after } from 'node:test'
import { readFile } from 'node:fs/promises'
import { createServer } from 'vite'
import { parse, compileScript } from '@vue/compiler-sfc'
import ts from 'typescript'
import axios from 'axios'
import { createRenderer, nextTick, h } from 'vue'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
const storage = new Map()
globalThis.localStorage = { getItem: k => storage.get(k) ?? null, setItem: (k,v) => storage.set(k,v), removeItem: k => storage.delete(k) }
globalThis.ShadowRoot = class {}
globalThis.Document = class {}
const files = { login: '/src/views/LoginView.vue', register: '/src/views/RegisterView.vue', admin: '/src/views/admin/AdminLoginView.vue' }
const server = await createServer({ server: { middlewareMode: true }, appType: 'custom', plugins: [{
  name: 'auth-rate-limit-test', enforce: 'pre',
  resolveId(id) { if (id.startsWith('virtual:')) return '\0' + id + '.ts' },
  async load(id) {
    const key = id.replace(/^\0virtual:/, '').replace(/\.ts$/, '')
    if (!files[key]) return
    const source = await readFile(new URL('..' + files[key], import.meta.url), 'utf8')
    return ts.transpileModule(compileScript(parse(source).descriptor, { id, inlineTemplate: true,
      templateOptions: { compilerOptions: { hoistStatic: false } } }).content,
      { compilerOptions: { target: ts.ScriptTarget.ESNext, module: ts.ModuleKind.ESNext } }).outputText
  }
}] })
const components = {}
for (const key of Object.keys(files)) components[key] = (await server.ssrLoadModule('virtual:' + key)).default
const { default: api } = await server.ssrLoadModule('/src/services/api.ts')
const { default: adminApi } = await server.ssrLoadModule('/src/services/adminApi.ts')
const { useAuthStore } = await server.ssrLoadModule('/src/stores/auth.ts')
const { useAdminAuthStore } = await server.ssrLoadModule('/src/stores/adminAuth.ts')
const { useCartStore } = await server.ssrLoadModule('/src/stores/cart.ts')
after(async () => { await server.close(); delete globalThis.localStorage; delete globalThis.ShadowRoot; delete globalThis.Document })

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

for (const kind of Object.keys(files)) {
  for (const status of [429, 419, 422, 500, 'network']) test(`${kind} HTTP ${status}: single mutation, visible error, identity/cart isolation`, async () => {
    storage.clear(); storage.set('home-fitness-store-guest-cart', 'preserved-marker')
    const pinia = createPinia(); setActivePinia(pinia)
    const auth = useAuthStore(pinia), admin = useAdminAuthStore(pinia), cart = useCartStore(pinia)
    auth.currentUser = { id: 1, name: 'Fixture', email: 'member@example.test', phone: '0912345678', status: 'active' }
    admin.currentAdmin = { id: 2, name: 'Fixture', email: 'admin@example.test', status: 'active', permissions: ['admin_manage'] }
    const beforeMember = JSON.stringify(auth.currentUser), beforeAdmin = JSON.stringify(admin.currentAdmin), beforeCart = JSON.stringify(cart.memberCart)
    let calls = [], release
    const pending = new Promise(resolve => { release = resolve })
    const adapter = async config => {
      if (config.method !== 'post') return { data: {}, status: 204, headers: {}, config }
      calls.push(config)
      await pending
      throw new axios.AxiosError('fixture', 'ERR_BAD_RESPONSE', config, undefined, status === 'network' ? undefined : { config, status, headers: {}, data: { message: status === 429 ? '嘗試次數過多，請稍後再試。' : 'Temporary failure', ...(status === 422 ? { errors: { email: ['Invalid fixture email'] } } : {}) } })
    }
    axios.defaults.adapter = adapter; api.defaults.adapter = adapter; adminApi.defaults.adapter = adapter
    const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/:pathMatch(.*)*', component: { render: () => null } }, ...['home','login','register'].map(name => ({path: '/fixture/'+name, name, component: {render: () => null}}))] })
    await router.push('/fixture'); await router.isReady()
    const root = node('root'), app = renderer.createApp({ render: () => h(components[kind]) })
    app.use(pinia); app.use(router); app.mount(root); await settle()
    for (const input of all(root, e => e.type === 'input')) {
      const id = input.props.id
      const value = id.toLowerCase().includes('password') ? 'dummy-fixture-password' : id.includes('email') ? 'missing@example.test' : id === 'phone' ? '0912345678' : 'Fixture'
      input.props['onUpdate:modelValue'](value)
    }
    const form = all(root, e => e.type === 'form')[0]
    form.props.onSubmit({ preventDefault() {} }); form.props.onSubmit({ preventDefault() {} })
    await settle()
    assert.equal(calls.length, 1)
    assert.equal(all(root, e => e.type === 'button' && e.props.type === 'submit')[0].props.disabled, true)
    release(); await settle(); await settle()
    if (status === 429) assert.match(text(root), /嘗試次數過多，請稍後再試。/)
    else assert.ok(all(root, e => e.props.role === 'alert').length > 0)
    if (status === 422) assert.match(text(root), /Invalid fixture email/)
    assert.equal(calls.length, 1)
    assert.equal(router.currentRoute.value.path, '/fixture')
    assert.equal(JSON.stringify(auth.currentUser), beforeMember)
    assert.equal(JSON.stringify(admin.currentAdmin), beforeAdmin)
    assert.equal(JSON.stringify(cart.memberCart), beforeCart)
    assert.equal(storage.get('home-fitness-store-guest-cart'), 'preserved-marker')
    assert.equal(all(root, e => e.type === 'button' && e.props.type === 'submit')[0].props.disabled, false)
    app.unmount()
  })
}
