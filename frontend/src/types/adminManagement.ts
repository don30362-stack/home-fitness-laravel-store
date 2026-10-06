import type { AdminPermissionCode } from './adminAuth'

export interface AdminListItem { id: number; name: string; email: string; status: 'active' | 'disabled' }
export interface AdminPermissionOption { id: number; code: AdminPermissionCode; name: string }
export interface AdminDetail extends AdminListItem { created_at: string | null; updated_at: string | null; permissions: AdminPermissionOption[] }
export interface CreateManagedAdminPayload { name: string; email: string; password: string; password_confirmation: string; status?: 'active' | 'disabled' }
export interface UpdateManagedAdminPayload { name?: string; email?: string }
export interface UpdateManagedAdminStatusPayload { status: 'active' | 'disabled' }
export interface ReplaceManagedAdminPermissionsPayload { permission_ids: number[] }
export interface AdminManagementResponse { data: AdminDetail; message: string }
