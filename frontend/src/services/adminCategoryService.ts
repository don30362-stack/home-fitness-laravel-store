import adminApi from './adminApi'
import type { AdminCategoryListResponse } from '@/types/adminCategory'

export const getAdminCategories = async (): Promise<AdminCategoryListResponse> => {
  const response = await adminApi.get<AdminCategoryListResponse>('/admin/categories')
  return response.data
}
