import type { AdminPermissionCode } from '@/types/adminAuth'

export const adminModules: { path: string; title: string; stage: number; permission?: AdminPermissionCode }[] = [
  { path: 'dashboard', title: 'Dashboard', stage: 24 },
  { path: 'products', title: '商品管理', stage: 19, permission: 'product_manage' },
  { path: 'categories', title: '分類管理', stage: 20, permission: 'category_manage' },
  { path: 'inventory', title: '庫存管理', stage: 19, permission: 'inventory_manage' },
  { path: 'orders', title: '訂單管理', stage: 21, permission: 'order_manage' },
  { path: 'users', title: '會員管理', stage: 21, permission: 'member_manage' },
  { path: 'home-content', title: '首頁內容', stage: 22, permission: 'home_content_manage' },
  { path: 'admins', title: '管理員管理', stage: 23, permission: 'admin_manage' },
]
