import type { ApiResponse, ApiMessageResponse } from './api'

export interface Admin {
  id: number
  name: string
  email: string
  status: string
}

export interface AdminLoginPayload {
  email: string
  password: string
}

export type AdminResponse = ApiResponse<Admin>
export type AdminLoginResponse = ApiMessageResponse<Admin>
