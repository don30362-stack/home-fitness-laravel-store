import type { PaginatedApiResponse } from './api'

export type InventoryStatus = 'normal' | 'low_stock' | 'out_of_stock'
export interface AdminInventoryItem {
  stock_owner_type: 'product' | 'variant'
  stock_owner_id: number
  product_id: number
  product_code: string
  product_name: string
  product_status: string
  category: { id: number; name: string }
  variant: { id: number; option_name: string; option_value: string; status: string } | null
  stock: number
  low_stock_threshold: number
  inventory_status: InventoryStatus
}
export interface AdminInventoryQuery {
  search?: string
  category_id?: string
  inventory_status?: string
  page?: string
}
export type AdminInventoryListResponse = PaginatedApiResponse<AdminInventoryItem>
export interface AdjustInventoryPayload {
  adjustment: number
}
