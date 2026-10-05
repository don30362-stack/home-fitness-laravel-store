import adminApi from './adminApi'
import type { AdminOrderQuery, AdminOrderListResponse, AdminOrderDetailResponse, AdminOrderDetail } from '@/types/adminOrder'
import type { AdminOrderMutationResponse, UpdateAdminOrderStatusPayload, UpdateAdminOrderPaymentPayload, UpdateAdminOrderShipmentPayload } from '@/types/adminOrder'

export const getAdminOrders = async (params: AdminOrderQuery = {}): Promise<AdminOrderListResponse> =>
  (await adminApi.get<AdminOrderListResponse>('/admin/orders', { params })).data

export const getAdminOrder = async (id: string): Promise<AdminOrderDetail> =>
  (await adminApi.get<AdminOrderDetailResponse>(`/admin/orders/${id}`)).data.data

export const updateAdminOrderStatus = async (id: string, payload: UpdateAdminOrderStatusPayload): Promise<AdminOrderMutationResponse> =>
  (await adminApi.patch<AdminOrderMutationResponse>(`/admin/orders/${id}/status`, payload)).data

export const updateAdminOrderPaymentStatus = async (id: string, payload: UpdateAdminOrderPaymentPayload): Promise<AdminOrderMutationResponse> =>
  (await adminApi.patch<AdminOrderMutationResponse>(`/admin/orders/${id}/payment-status`, payload)).data

export const updateAdminOrderShipment = async (id: string, payload: UpdateAdminOrderShipmentPayload): Promise<AdminOrderMutationResponse> =>
  (await adminApi.patch<AdminOrderMutationResponse>(`/admin/orders/${id}/shipment`, payload)).data

export const cancelAdminOrder = async (id: string): Promise<AdminOrderMutationResponse> =>
  (await adminApi.post<AdminOrderMutationResponse>(`/admin/orders/${id}/cancel`)).data
