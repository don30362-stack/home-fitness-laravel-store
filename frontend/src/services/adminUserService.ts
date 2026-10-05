import adminApi from './adminApi'
import type { AdminUserQuery, AdminUserListResponse, AdminUserDetail, AdminUserDetailResponse, UpdateAdminUserStatusPayload, AdminUserMutationResponse } from '@/types/adminUser'

export const getAdminUsers = async (params: AdminUserQuery = {}): Promise<AdminUserListResponse> =>
  (await adminApi.get<AdminUserListResponse>('/admin/users', { params })).data

export const getAdminUser = async (id: string, orderPage?: string | number): Promise<AdminUserDetail> =>
  (await adminApi.get<AdminUserDetailResponse>(`/admin/users/${id}`, { params: { order_page: orderPage } })).data.data

export const updateAdminUserStatus = async (id: string, payload: UpdateAdminUserStatusPayload): Promise<AdminUserMutationResponse> =>
  (await adminApi.patch<AdminUserMutationResponse>(`/admin/users/${id}/status`, payload)).data
