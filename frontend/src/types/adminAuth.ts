import type { ApiResponse, ApiMessageResponse } from './api'

export type AdminPermissionCode =
  | 'product_manage'
  | 'category_manage'
  | 'inventory_manage'
  | 'order_manage'
  | 'member_manage'
  | 'home_content_manage'
  | 'admin_manage'

export interface Admin {
  id: number
  name: string
  email: string
  status: string
  permissions: AdminPermissionCode[]
}

export interface AdminLoginPayload {
  email: string
  password: string
}

export type AdminResponse = ApiResponse<Admin>
export type AdminLoginResponse = ApiMessageResponse<Admin>
