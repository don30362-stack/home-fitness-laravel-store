import type { ApiResponse, ApiMessageResponse, PaginatedApiResponse } from './api'
import type { AdminOrderStatus, AdminPaymentStatus } from './adminOrder'

export type AdminUserStatus = 'active' | 'disabled' | 'inactive'
export interface AdminUser {
  id: number
  name: string
  email: string
  phone: string | null
  status: AdminUserStatus
  created_at: string | null
  updated_at: string | null
}
export interface AdminUserQuery { search?: string; status?: string; page?: string | number }
export interface AdminUserOrderSummary {
  id: number
  order_no: string
  created_at: string | null
  total_amount: string
  payment_method: string
  payment_status: AdminPaymentStatus
  order_status: AdminOrderStatus
}
interface PaginationLinks { first: string; last: string; prev: string | null; next: string | null }
export interface AdminUserListResponse extends PaginatedApiResponse<AdminUser> { links: PaginationLinks }
export interface AdminUserOrderPage extends PaginatedApiResponse<AdminUserOrderSummary> { links: PaginationLinks }
export interface AdminUserDetail { user: AdminUser; orders: AdminUserOrderPage }
export type AdminUserDetailResponse = ApiResponse<AdminUserDetail>
export interface UpdateAdminUserStatusPayload { status: 'active' | 'disabled' }
export type AdminUserMutationResponse = ApiMessageResponse<AdminUser>
