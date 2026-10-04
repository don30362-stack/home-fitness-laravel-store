import adminApi from './adminApi'
import type { ApiResponse, ApiMessageResponse, ApiMessageOnlyResponse } from '@/types/api'
import type { Category } from '@/types/category'
import type {
  AdminProductDetail,
  AdminProductListResponse,
  AdminProductQuery,
  AdminProductImage,
  AdminProductImageUpload,
  UpdateAdminProductImagePayload,
} from '@/types/adminProduct'
import type {
  CreateAdminProductPayload,
  UpdateAdminProductPayload,
  UpdateAdminProductStatusPayload,
} from '@/types/adminProduct'

export const getAdminProducts = async (
  params: AdminProductQuery = {},
): Promise<AdminProductListResponse> =>
  (await adminApi.get<AdminProductListResponse>('/admin/products', { params })).data

export const getAdminProduct = async (id: string): Promise<AdminProductDetail> =>
  (await adminApi.get<ApiResponse<AdminProductDetail>>(`/admin/products/${id}`)).data.data

// 公開分類 endpoint，不建立 Admin categories API，也不經會員 transport。
export const getProductCategoryOptions = async (): Promise<Category[]> =>
  (await adminApi.get<ApiResponse<Category[]>>('/categories')).data.data

export const createAdminProduct = async (
  payload: CreateAdminProductPayload,
): Promise<ApiMessageResponse<AdminProductDetail>> =>
  (await adminApi.post<ApiMessageResponse<AdminProductDetail>>('/admin/products', payload)).data
export const updateAdminProduct = async (
  id: string,
  payload: UpdateAdminProductPayload,
): Promise<ApiMessageResponse<AdminProductDetail>> =>
  (await adminApi.patch<ApiMessageResponse<AdminProductDetail>>(`/admin/products/${id}`, payload))
    .data
export const updateAdminProductStatus = async (
  id: string,
  payload: UpdateAdminProductStatusPayload,
): Promise<ApiMessageResponse<AdminProductDetail>> =>
  (
    await adminApi.patch<ApiMessageResponse<AdminProductDetail>>(
      `/admin/products/${id}/status`,
      payload,
    )
  ).data
export const deleteAdminProduct = async (id: string): Promise<ApiMessageOnlyResponse> =>
  (await adminApi.delete<ApiMessageOnlyResponse>(`/admin/products/${id}`)).data

export const uploadAdminProductImage = async (
  productId: number,
  payload: AdminProductImageUpload,
): Promise<ApiMessageResponse<AdminProductImage>> => {
  const body = new FormData()
  body.append('image', payload.image)
  body.append('image_type', payload.image_type)
  body.append('sort_order', String(payload.sort_order))
  if (payload.is_primary !== undefined) body.append('is_primary', payload.is_primary ? '1' : '0')
  // boundary由瀏覽器/Axios產生，不手動指定Content-Type。
  return (
    await adminApi.post<ApiMessageResponse<AdminProductImage>>(
      `/admin/products/${productId}/images`,
      body,
    )
  ).data
}
export const updateAdminProductImage = async (
  imageId: number,
  payload: UpdateAdminProductImagePayload,
): Promise<ApiMessageResponse<AdminProductImage>> =>
  (
    await adminApi.patch<ApiMessageResponse<AdminProductImage>>(
      `/admin/product-images/${imageId}`,
      payload,
    )
  ).data
export const deleteAdminProductImage = async (imageId: number): Promise<ApiMessageOnlyResponse> =>
  (await adminApi.delete<ApiMessageOnlyResponse>(`/admin/product-images/${imageId}`)).data
