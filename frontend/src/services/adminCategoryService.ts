import adminApi from './adminApi'
import type { AdminCategoryListResponse, AdminCategoryMutationResponse, CreateAdminCategoryPayload, UpdateAdminCategoryPayload, UpdateAdminCategoryStatusPayload } from '@/types/adminCategory'

export const getAdminCategories = async (): Promise<AdminCategoryListResponse> => {
  const response = await adminApi.get<AdminCategoryListResponse>('/admin/categories')
  return response.data
}

export const createAdminCategory = async (payload: CreateAdminCategoryPayload): Promise<AdminCategoryMutationResponse> => {
  return (await adminApi.post<AdminCategoryMutationResponse>('/admin/categories', payload)).data
}

export const updateAdminCategory = async (id: number, payload: UpdateAdminCategoryPayload): Promise<AdminCategoryMutationResponse> => {
  return (await adminApi.patch<AdminCategoryMutationResponse>(`/admin/categories/${id}`, payload)).data
}

export const updateAdminCategoryStatus = async (id: number, payload: UpdateAdminCategoryStatusPayload): Promise<AdminCategoryMutationResponse> => {
  return (await adminApi.patch<AdminCategoryMutationResponse>(`/admin/categories/${id}/status`, payload)).data
}
