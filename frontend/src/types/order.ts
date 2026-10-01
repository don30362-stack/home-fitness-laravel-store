import type { PaginatedApiResponse } from './api'

export interface OrderSummary {
  id: number
  order_no: string
  created_at: string | null
  subtotal: string
  shipping_fee: string
  total_amount: string
  payment_method: string
  payment_status: string
  order_status: string
}

export interface OrderListResponse extends PaginatedApiResponse<OrderSummary> {
  links: {
    first: string
    last: string
    prev: string | null
    next: string | null
  }
}
