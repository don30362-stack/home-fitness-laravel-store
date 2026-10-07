import type { RouteRecordRaw, NavigationGuard, Router } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
import AdminLayout from '@/layouts/AdminLayout.vue'
import AdminLoginView from '@/views/admin/AdminLoginView.vue'
import AdminDashboardView from '@/views/admin/AdminDashboardView.vue'
import AdminNotFoundView from '@/views/admin/AdminNotFoundView.vue'
import ProductManagementView from '@/views/admin/ProductManagementView.vue'
import InventoryManagementView from '@/views/admin/InventoryManagementView.vue'
import CategoryManagementView from '@/views/admin/CategoryManagementView.vue'
import HomeContentManagementView from '@/views/admin/HomeContentManagementView.vue'
import OrderManagementView from '@/views/admin/OrderManagementView.vue'
import AdminOrderDetailView from '@/views/admin/AdminOrderDetailView.vue'
import MemberManagementView from '@/views/admin/MemberManagementView.vue'
import AdminUserDetailView from '@/views/admin/AdminUserDetailView.vue'
import AdminProductDetailView from '@/views/admin/AdminProductDetailView.vue'
import AdminProductFormView from '@/views/admin/AdminProductFormView.vue'
import { setAdminNavigationCallbacks } from '@/services/adminSessionNavigation'

import { adminModules } from './adminModules'
import { ADMIN_PAGE_METADATA } from './pageMetadata'
export { adminModules } from './adminModules'
import AdminManagementView from '@/views/admin/AdminManagementView.vue'
import AdminForbiddenView from '@/views/admin/AdminForbiddenView.vue'
import type { AdminPermissionCode } from '@/types/adminAuth'

declare module 'vue-router' {
  interface RouteMeta { adminPermission?: AdminPermissionCode }
}

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
  { path: '/admin/login', name: 'admin-login', component: AdminLoginView, meta: { ...ADMIN_PAGE_METADATA } },
  {
    path: '/admin', component: AdminLayout, meta: { requiresAdmin: true, ...ADMIN_PAGE_METADATA },
    children: [
      { path: '', redirect: '/admin/dashboard' },
      ...adminModules.map((module) => ({
        path: module.path, name: 'admin-' + module.path,
        component: module.path === 'products' ? ProductManagementView : module.path === 'inventory' ? InventoryManagementView : module.path === 'categories' ? CategoryManagementView : module.path === 'orders' ? OrderManagementView : module.path === 'users' ? MemberManagementView : module.path === 'home-content' ? HomeContentManagementView : module.path === 'admins' ? AdminManagementView : AdminDashboardView,
        meta: { requiresAdmin: true, ...(module.permission ? { adminPermission: module.permission } : {}) },
      })),
      { path: 'products/new', name: 'admin-product-create', component: AdminProductFormView, meta: { requiresAdmin: true, adminPermission: 'product_manage' } },
      { path: 'products/:id/edit', name: 'admin-product-edit', component: AdminProductFormView, meta: { requiresAdmin: true, adminPermission: 'product_manage' } },
      { path: 'products/:id', name: 'admin-product-detail', component: AdminProductDetailView, meta: { requiresAdmin: true, adminPermission: 'product_manage' } },
      { path: 'orders/:id', name: 'admin-order-detail', component: AdminOrderDetailView, meta: { requiresAdmin: true, adminPermission: 'order_manage' } },
      { path: 'users/:id', name: 'admin-user-detail', component: AdminUserDetailView, meta: { requiresAdmin: true, adminPermission: 'member_manage' } },
      { path: 'forbidden', name: 'admin-forbidden', component: AdminForbiddenView, meta: { requiresAdmin: true } },
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
  if (to.meta.adminPermission && !auth.hasPermission(to.meta.adminPermission)) return { name: 'admin-forbidden' }
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
    permissionContext: () => router.currentRoute.value.fullPath,
    permissionsChanged: async (context, refreshFailed) => {
      const current = router.currentRoute.value
      if (current.fullPath !== context || !current.meta.requiresAdmin || current.name === 'admin-forbidden') return
      const auth = useAdminAuthStore()
      if (auth.isAdminAuthenticated && (refreshFailed || (current.meta.adminPermission && !auth.hasPermission(current.meta.adminPermission)))) {
        await router.replace({ name: 'admin-forbidden' })
      }
    },
    loginRequired,
    loginSucceeded: async () => { await router.replace(safeAdminRedirect(router.currentRoute.value.query.redirect)) },
  })
  return { ready: async () => { ready = true; if (pending) { pending = false; await loginRequired() } } }
}
