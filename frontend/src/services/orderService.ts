import api from './api'
import type { OrderDetailResponse, OrderListResponse } from '@/types/order'

export const getOrders = async (page?: string): Promise<OrderListResponse> => {
  const response = await api.get<OrderListResponse>('/orders', { params: { page } })
  return response.data
}

export const getOrder = async (id: string): Promise<OrderDetailResponse> => {
  const response = await api.get<OrderDetailResponse>(`/orders/${encodeURIComponent(id)}`)
  return response.data
}
