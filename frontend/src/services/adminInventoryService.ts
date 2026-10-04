import adminApi from './adminApi'
import type { ApiMessageResponse } from '@/types/api'
import type {
  AdminInventoryItem,
  AdminInventoryQuery,
  AdminInventoryListResponse,
  AdjustInventoryPayload,
} from '@/types/adminInventory'

export const getAdminInventory = async (
  params: AdminInventoryQuery = {},
): Promise<AdminInventoryListResponse> =>
  (await adminApi.get<AdminInventoryListResponse>('/admin/inventory', { params })).data
export const adjustProductInventory = async (
  id: number,
  payload: AdjustInventoryPayload,
): Promise<ApiMessageResponse<AdminInventoryItem>> =>
  (await adminApi.patch<ApiMessageResponse<AdminInventoryItem>>(`/admin/inventory/${id}`, payload))
    .data
export const adjustVariantInventory = async (
  id: number,
  payload: AdjustInventoryPayload,
): Promise<ApiMessageResponse<AdminInventoryItem>> =>
  (
    await adminApi.patch<ApiMessageResponse<AdminInventoryItem>>(
      `/admin/inventory/variants/${id}`,
      payload,
    )
  ).data
