export interface ApiResponse<T> {
  data: T
}

export interface ApiMessageResponse<T> extends ApiResponse<T> {
  message: string
}

export interface ApiMessageOnlyResponse {
  message: string
}

export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface PaginatedApiResponse<T> {
  data: T[]
  meta: PaginationMeta
}

export interface ApiErrorResponse {
  message: string
  code?: string
  errors?: Record<string, string[]>
}
