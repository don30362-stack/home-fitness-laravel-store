import api from './api'
import type { OrderCancellationResponse, OrderDetailResponse, OrderListResponse } from '@/types/order'

export const getOrders = async (page?: string): Promise<OrderListResponse> => {
  const response = await api.get<OrderListResponse>('/orders', { params: { page } })
  return response.data
}

export const getOrder = async (id: string): Promise<OrderDetailResponse> => {
  const response = await api.get<OrderDetailResponse>(`/orders/${encodeURIComponent(id)}`)
  return response.data
}

export const cancelOrder = async (id: string): Promise<OrderCancellationResponse> => {
  const response = await api.post<OrderCancellationResponse>(`/orders/${encodeURIComponent(id)}/cancel`)
  return response.data
}
