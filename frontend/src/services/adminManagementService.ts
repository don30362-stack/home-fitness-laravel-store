import adminApi from './adminApi'
import type { AdminListItem, AdminDetail, AdminPermissionOption, CreateManagedAdminPayload, UpdateManagedAdminPayload, UpdateManagedAdminStatusPayload, ReplaceManagedAdminPermissionsPayload, AdminManagementResponse } from '@/types/adminManagement'

export const getManagedAdmins = async (): Promise<AdminListItem[]> => (await adminApi.get<{ data: AdminListItem[] }>('/admin/admins')).data.data
export const getAdminPermissionCatalog = async (): Promise<AdminPermissionOption[]> => (await adminApi.get<{ data: AdminPermissionOption[] }>('/admin/permissions')).data.data
export const getManagedAdmin = async (id: number): Promise<AdminDetail> => (await adminApi.get<{ data: AdminDetail }>(`/admin/admins/${id}`)).data.data
export const createManagedAdmin = async (payload: CreateManagedAdminPayload): Promise<AdminManagementResponse> => (await adminApi.post<AdminManagementResponse>('/admin/admins', payload)).data
export const updateManagedAdmin = async (id: number, payload: UpdateManagedAdminPayload): Promise<AdminManagementResponse> => (await adminApi.patch<AdminManagementResponse>(`/admin/admins/${id}`, payload)).data
export const updateManagedAdminStatus = async (id: number, payload: UpdateManagedAdminStatusPayload): Promise<AdminManagementResponse> => (await adminApi.patch<AdminManagementResponse>(`/admin/admins/${id}/status`, payload)).data
export const replaceManagedAdminPermissions = async (id: number, payload: ReplaceManagedAdminPermissionsPayload): Promise<AdminManagementResponse> => (await adminApi.put<AdminManagementResponse>(`/admin/admins/${id}/permissions`, payload)).data
