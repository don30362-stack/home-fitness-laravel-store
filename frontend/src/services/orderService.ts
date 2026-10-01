import api from './api'
import type { OrderListResponse } from '@/types/order'

export const getOrders = async (page?: string): Promise<OrderListResponse> => {
  const response = await api.get<OrderListResponse>('/orders', { params: { page } })
  return response.data
}
