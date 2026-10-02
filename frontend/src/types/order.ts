import type { ApiResponse, PaginatedApiResponse } from './api'

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

export interface OrderItemSnapshot {
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

export interface OrderDetail extends OrderSummary {
  purchaser: { name: string; phone: string; email: string }
  recipient: { name: string; phone: string; postal_code: string; city: string; district: string; address: string }
  shipping_method: string
  logistics_company: string | null
  tracking_number: string | null
  items: OrderItemSnapshot[]
}

export type OrderDetailResponse = ApiResponse<OrderDetail>
