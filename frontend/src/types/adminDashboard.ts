import type { ApiResponse } from './api'

export interface AdminDashboardRecentOrder {
  id: number
  order_no: string
  created_at: string
  total_amount: string
  order_status: string
  payment_status: string
}

export interface AdminDashboardOrderStats {
  total: number
  pending: number
  awaiting_shipment: number
  completed_order_amount: string
  recent_orders: AdminDashboardRecentOrder[]
}

export interface AdminDashboardStats {
  products: { total: number } | null
  members: { total: number } | null
  orders: AdminDashboardOrderStats | null
}

export type AdminDashboardResponse = ApiResponse<AdminDashboardStats>
