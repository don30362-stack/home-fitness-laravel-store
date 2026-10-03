import adminApi from './adminApi'
import type { ApiResponse } from '@/types/api'
import type { Category } from '@/types/category'
import type { AdminProductDetail, AdminProductListResponse, AdminProductQuery } from '@/types/adminProduct'

export const getAdminProducts = async (params: AdminProductQuery = {}): Promise<AdminProductListResponse> =>
  (await adminApi.get<AdminProductListResponse>('/admin/products', { params })).data

export const getAdminProduct = async (id: string): Promise<AdminProductDetail> =>
  (await adminApi.get<ApiResponse<AdminProductDetail>>(`/admin/products/${id}`)).data.data

// 公開分類 endpoint，不建立 Admin categories API，也不經會員 transport。
export const getProductCategoryOptions = async (): Promise<Category[]> =>
  (await adminApi.get<ApiResponse<Category[]>>('/categories')).data.data
