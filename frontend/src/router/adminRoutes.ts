import type { RouteRecordRaw, NavigationGuard, Router } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
import AdminLayout from '@/layouts/AdminLayout.vue'
import AdminLoginView from '@/views/admin/AdminLoginView.vue'
import AdminPlaceholderView from '@/views/admin/AdminPlaceholderView.vue'
import AdminNotFoundView from '@/views/admin/AdminNotFoundView.vue'
import ProductManagementView from '@/views/admin/ProductManagementView.vue'
import InventoryManagementView from '@/views/admin/InventoryManagementView.vue'
import CategoryManagementView from '@/views/admin/CategoryManagementView.vue'
import AdminProductDetailView from '@/views/admin/AdminProductDetailView.vue'
import AdminProductFormView from '@/views/admin/AdminProductFormView.vue'
import { setAdminNavigationCallbacks } from '@/services/adminSessionNavigation'

export const adminModules = [
  { path: 'dashboard', title: 'Dashboard', stage: 24 },
  { path: 'products', title: '商品管理', stage: 19 },
  { path: 'categories', title: '分類管理', stage: 20 },
  { path: 'inventory', title: '庫存管理', stage: 19 },
  { path: 'orders', title: '訂單管理', stage: 21 },
  { path: 'users', title: '會員管理', stage: 21 },
  { path: 'home-content', title: '首頁內容', stage: 22 },
  { path: 'admins', title: '管理員管理', stage: 23 },
]

export const safeAdminRedirect = (value: unknown): string => {
  const fallback = '/admin/dashboard'
  if (typeof value !== 'string' || !value.startsWith('/admin/') || /[\\\x00-\x20]/.test(value)) return fallback
  try {
    const target = new URL(value, 'https://admin.internal')
    if (target.origin !== 'https://admin.internal' || !target.pathname.startsWith('/admin/') ||
      decodeURIComponent(target.pathname).replace(/\/+$/, '') === '/admin/login') return fallback
    return target.pathname + target.search + target.hash
  } catch { return fallback }
}

export const adminRoutes: RouteRecordRaw[] = [
  { path: '/admin/login', name: 'admin-login', component: AdminLoginView },
  {
    path: '/admin', component: AdminLayout, meta: { requiresAdmin: true },
    children: [
      { path: '', redirect: '/admin/dashboard' },
      ...adminModules.map((module) => ({
        path: module.path, name: 'admin-' + module.path,
        component: module.path === 'products' ? ProductManagementView : module.path === 'inventory' ? InventoryManagementView : module.path === 'categories' ? CategoryManagementView : AdminPlaceholderView,
        props: ['products', 'inventory', 'categories'].includes(module.path) ? undefined : { title: module.title, stage: module.stage }, meta: { requiresAdmin: true },
      })),
      { path: 'products/new', name: 'admin-product-create', component: AdminProductFormView, meta: { requiresAdmin: true } },
      { path: 'products/:id/edit', name: 'admin-product-edit', component: AdminProductFormView, meta: { requiresAdmin: true } },
      { path: 'products/:id', name: 'admin-product-detail', component: AdminProductDetailView, meta: { requiresAdmin: true } },
      { path: ':pathMatch(.*)*', name: 'admin-not-found', component: AdminNotFoundView, meta: { requiresAdmin: true } },
    ],
  },
]

export const adminGuard: NavigationGuard = async (to) => {
  if (!to.meta.requiresAdmin && to.name !== 'admin-login') return
  const auth = useAdminAuthStore()
  if (!auth.isAdminInitialized && !auth.restoreError) {
    try { await auth.restoreAdmin() } catch { /* 保留 restoreError，由頁面呈現 */ }
  }
  if (auth.restoreError) return true
  if (to.name === 'admin-login') return auth.isAdminAuthenticated ? '/admin/dashboard' : true
  if (!auth.isAdminAuthenticated) return { name: 'admin-login', query: { redirect: to.fullPath } }
  return true
}

// 入口注入，避免 service/store import router；初始 restore 失效先排隊。
export const connectAdminNavigation = (router: Router) => {
  let ready = false
  let pending = false
  const loginRequired = async () => {
    if (!ready) { pending = true; return }
    if (router.currentRoute.value.name === 'admin-login') return
    await router.replace({ name: 'admin-login', query: { redirect: safeAdminRedirect(router.currentRoute.value.fullPath) } })
  }
  setAdminNavigationCallbacks({
    loginRequired,
    loginSucceeded: async () => { await router.replace(safeAdminRedirect(router.currentRoute.value.query.redirect)) },
  })
  return { ready: async () => { ready = true; if (pending) { pending = false; await loginRequired() } } }
}
