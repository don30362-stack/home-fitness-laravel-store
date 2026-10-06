import adminApi from './adminApi'
import type { ApiMessageOnlyResponse } from '@/types/api'
import type { AdminBannerListResponse, AdminBannerMutationResponse, BannerStatus, CreateAdminBannerPayload, UpdateAdminBannerPayload, ReorderBannerPayload } from '@/types/adminHomeContent'

const multipart = (payload: CreateAdminBannerPayload | UpdateAdminBannerPayload): FormData => {
  const form = new FormData()
  for (const [key, value] of Object.entries(payload)) {
    if (value !== undefined) form.append(key, value instanceof File ? value : value === null ? '' : String(value))
  }
  return form
}
export const getAdminBanners = async (): Promise<AdminBannerListResponse> => (await adminApi.get<AdminBannerListResponse>('/admin/banners')).data
export const createAdminBanner = async (payload: CreateAdminBannerPayload): Promise<AdminBannerMutationResponse> =>
  (await adminApi.post<AdminBannerMutationResponse>('/admin/banners', multipart(payload))).data
export const updateAdminBanner = async (id: number, payload: UpdateAdminBannerPayload): Promise<AdminBannerMutationResponse> => {
  if (payload.image) {
    const form = multipart(payload)
    form.append('_method', 'PATCH')
    return (await adminApi.post<AdminBannerMutationResponse>(`/admin/banners/${id}`, form)).data
  }
  return (await adminApi.patch<AdminBannerMutationResponse>(`/admin/banners/${id}`, payload)).data
}
export const updateAdminBannerStatus = async (id: number, status: BannerStatus): Promise<AdminBannerMutationResponse> =>
  (await adminApi.patch<AdminBannerMutationResponse>(`/admin/banners/${id}/status`, { status })).data
export const reorderAdminBanners = async (payload: ReorderBannerPayload): Promise<ApiMessageOnlyResponse> =>
  (await adminApi.patch<ApiMessageOnlyResponse>('/admin/banners/order', payload)).data
export const deleteAdminBanner = async (id: number): Promise<ApiMessageOnlyResponse> =>
  (await adminApi.delete<ApiMessageOnlyResponse>(`/admin/banners/${id}`)).data
