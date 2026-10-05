import type { ApiResponse, ApiMessageResponse } from './api'

export type AdminCategoryStatus = 'active' | 'inactive'

export interface AdminCategoryChild {
  id: number
  parent_id: number
  name: string
  status: AdminCategoryStatus
  sort_order: number
  product_count: number
  created_at: string | null
  updated_at: string | null
}

export interface AdminCategory {
  id: number
  name: string
  status: AdminCategoryStatus
  sort_order: number
  children_count: number
  children: AdminCategoryChild[]
  created_at: string | null
  updated_at: string | null
}

export type AdminCategoryListResponse = ApiResponse<AdminCategory[]>

export interface CreateAdminCategoryPayload {
  name: string
  parent_id?: number | null
  sort_order?: number
  status?: AdminCategoryStatus
}

export interface UpdateAdminCategoryPayload {
  name?: string
  parent_id?: number | null
  sort_order?: number
}

export interface AdminCategoryMutation extends Omit<AdminCategory, 'children'> {
  parent_id: number | null
  product_count: number
  children: AdminCategoryChild[]
}

export type AdminCategoryMutationResponse = ApiMessageResponse<AdminCategoryMutation>
