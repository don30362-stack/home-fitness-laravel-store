import api from '@/services/api'
import type { ApiResponse } from '@/types/api'
import type { Banner } from '@/types/homeContent'
import type { ProductListItem } from '@/types/product'

export const getBanners = async (): Promise<Banner[]> => {
    const response = await api.get<ApiResponse<Banner[]>>('/banners')
    return response.data.data
}

export const getRecommendedProducts = async (): Promise<ProductListItem[]> => {
    const response = await api.get<ApiResponse<ProductListItem[]>>('/recommended-products')
    return response.data.data
}
