import adminApi from './adminApi'
import { ensureAdminGeneration, getAdminGeneration } from './adminSessionState'
import type { AdminLoginPayload, AdminLoginResponse, AdminResponse } from '@/types/adminAuth'
import type { ApiMessageOnlyResponse } from '@/types/api'

const backendBaseUrl = import.meta.env.VITE_API_BASE_URL.replace(/\/api\/?$/, '')

export const loginAdmin = async (payload: AdminLoginPayload): Promise<AdminLoginResponse> => {
  const generation = getAdminGeneration()
  await adminApi.get(`${backendBaseUrl}/sanctum/csrf-cookie`)
  ensureAdminGeneration(generation)
  const response = await adminApi.post<AdminLoginResponse>('/admin/login', payload)
  return response.data
}
export const getCurrentAdmin = async (): Promise<AdminResponse> => {
  const response = await adminApi.get<AdminResponse>('/admin/me')
  return response.data
}
export const logoutAdmin = async (): Promise<ApiMessageOnlyResponse> => {
  const response = await adminApi.post<ApiMessageOnlyResponse>('/admin/logout')
  return response.data
}
