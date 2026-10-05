import adminApi from './adminApi'
import type { AdminOrderQuery, AdminOrderListResponse, AdminOrderDetailResponse, AdminOrderDetail } from '@/types/adminOrder'

export const getAdminOrders = async (params: AdminOrderQuery = {}): Promise<AdminOrderListResponse> =>
  (await adminApi.get<AdminOrderListResponse>('/admin/orders', { params })).data

export const getAdminOrder = async (id: string): Promise<AdminOrderDetail> =>
  (await adminApi.get<AdminOrderDetailResponse>(`/admin/orders/${id}`)).data.data
