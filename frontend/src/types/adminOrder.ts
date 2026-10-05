import type { ApiResponse, PaginatedApiResponse } from './api'

export type AdminOrderStatus = 'pending' | 'processing' | 'shipped' | 'completed' | 'cancelled'
export type AdminPaymentStatus = 'unpaid' | 'paid'

export interface AdminOrderQuery {
  search?: string
  order_status?: string
  payment_status?: string
  date_from?: string
  date_to?: string
  page?: number | string
}

export interface AdminOrderSummary {
  id: number
  order_no: string
  created_at: string | null
  total_amount: string
  payment_method: string
  payment_status: AdminPaymentStatus
  order_status: AdminOrderStatus
  user: { id: number; name: string; email: string } | null
}

export interface AdminOrderItemSnapshot {
  id: number
  product_id: number
  product_variant_id: number | null
  product_code: string
  product_name: string
  variant: string | null
  unit_price: string
  quantity: number
  subtotal: string
}

export interface AdminOrderDetail extends AdminOrderSummary {
  updated_at: string | null
  user: { id: number; name: string; email: string; status: string } | null
  purchaser: { name: string; phone: string; email: string }
  recipient: { name: string; phone: string; postal_code: string; city: string; district: string; address: string }
  shipping_method: string
  shipping_fee: string
  logistics_company: string | null
  tracking_number: string | null
  subtotal: string
  items: AdminOrderItemSnapshot[]
}

export interface AdminOrderListResponse extends PaginatedApiResponse<AdminOrderSummary> {
  links: { first: string; last: string; prev: string | null; next: string | null }
}
export type AdminOrderDetailResponse = ApiResponse<AdminOrderDetail>
