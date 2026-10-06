import type { ApiResponse, ApiMessageResponse } from './api'
import type { AdminProductListItem } from './adminProduct'

export type BannerStatus = 'active' | 'inactive'
export interface AdminBanner {
  id: number
  title: string
  subtitle: string | null
  image_url: string
  button_text: string | null
  link_url: string | null
  sort_order: number
  status: BannerStatus
  created_at: string | null
  updated_at: string | null
}
export interface BannerFields {
  title: string
  subtitle: string | null
  button_text: string | null
  link_url: string | null
  sort_order: number
}
export interface CreateAdminBannerPayload extends BannerFields { image: File; status?: BannerStatus }
export type UpdateAdminBannerPayload = Partial<BannerFields> & { image?: File }
export interface ReorderBannerPayload { ids: number[] }
export interface BannerForm extends BannerFields { image: File | null; status: BannerStatus }
export type AdminBannerListResponse = ApiResponse<AdminBanner[]>
export type AdminBannerMutationResponse = ApiMessageResponse<AdminBanner>

export interface AdminRecommendedProduct {
  id: number
  product_id: number
  sort_order: number
  is_publicly_visible: boolean
  unavailable_reason: string | null
  product: AdminProductListItem | null
}
export type AdminRecommendedProductListResponse = ApiResponse<AdminRecommendedProduct[]>
export type AdminRecommendedProductMutationResponse = ApiMessageResponse<AdminRecommendedProduct>
export interface CreateRecommendedProductPayload { product_id: number }
export interface ReorderRecommendedProductsPayload { ids: number[] }
