import type { PaginatedApiResponse } from './api'

export interface AdminProductCategory {
  id: number
  name: string
  status: string
}
export interface AdminProductListItem {
  id: number
  product_code: string
  name: string
  category: AdminProductCategory | null
  price: string
  stock: number | null
  has_variants: boolean
  low_stock_threshold: number
  status: string
  created_at: string | null
  updated_at: string | null
}
export interface AdminProductImage {
  id: number
  image_path: string
  image_url: string
  image_type: string
  is_primary: boolean
  sort_order: number
}
export interface AdminProductSpecification {
  id: number
  spec_name: string
  spec_value: string
  sort_order: number
}
export interface AdminProductVariant {
  id: number
  option_name: string
  option_value: string
  stock: number
  status: string
}
export interface AdminProductDetail extends AdminProductListItem {
  category: (AdminProductCategory & { parent: AdminProductCategory | null }) | null
  short_description: string | null
  description: string | null
  images: AdminProductImage[]
  specifications: AdminProductSpecification[]
  variants: AdminProductVariant[]
}
export interface AdminProductQuery {
  search?: string
  category_id?: string
  status?: string
  page?: string
}
export interface AdminProductListResponse extends PaginatedApiResponse<AdminProductListItem> {
  links: { first: string; last: string; prev: string | null; next: string | null }
}

export type ProductStatus = 'active' | 'inactive' | 'disabled'
export interface AdminSpecificationPayload {
  spec_name: string
  spec_value: string
  sort_order: number
}
export interface AdminVariantCreatePayload {
  option_name: string
  option_value: string
  stock: number
  status: 'active' | 'inactive'
}
export interface AdminVariantUpdatePayload {
  id: number
  option_name: string
  option_value: string
  status: 'active' | 'inactive'
}
export interface AdminProductFields {
  category_id: number
  name: string
  price: number
  short_description: string | null
  description: string | null
  low_stock_threshold: number
}
export interface CreateAdminProductPayload extends AdminProductFields {
  status: ProductStatus
  stock: number | null
  specifications?: AdminSpecificationPayload[]
  variants?: AdminVariantCreatePayload[]
}
export interface UpdateAdminProductPayload extends Partial<AdminProductFields> {
  specifications?: AdminSpecificationPayload[]
  variants?: (AdminVariantCreatePayload | AdminVariantUpdatePayload)[]
}
export interface UpdateAdminProductStatusPayload {
  status: ProductStatus
}

export interface AdminProductImageUpload {
  image: File
  image_type: 'gallery' | 'detail'
  sort_order: number
  is_primary?: boolean
}
export interface UpdateAdminProductImagePayload {
  image_type?: 'gallery' | 'detail'
  sort_order?: number
  is_primary?: boolean
}
