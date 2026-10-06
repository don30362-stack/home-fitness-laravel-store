import axios, { type InternalAxiosRequestConfig } from 'axios'
import type { ApiErrorResponse } from '@/types/api'
import { getAdminPermissionContext } from './adminSessionNavigation'
import { reportAdminPermissionDenied } from './adminPermissionState'
import {
  AdminSessionInvalidatedError, getAdminGeneration, reportAdminFailure,
} from './adminSessionState'

type AdminRequestConfig = InternalAxiosRequestConfig & { adminGeneration?: number; adminPermissionContext?: string }
const isAdminLogin = (url = '') => /(?:^|\/)admin\/login\/?(?:\?|$)/.test(url)
const isAdminRequest = (url = '') => /(?:^|\/)admin(?:\/|\?|$)/.test(url)

const adminApi = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL,
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json' },
})

adminApi.interceptors.request.use((config: AdminRequestConfig) => {
  config.adminGeneration = getAdminGeneration()
  config.adminPermissionContext = getAdminPermissionContext()
  return config
})
adminApi.interceptors.response.use((response) => {
  if ((response.config as AdminRequestConfig).adminGeneration !== getAdminGeneration()) {
    throw new AdminSessionInvalidatedError()
  }
  return response
}, async (error: unknown) => {
  if (axios.isAxiosError<ApiErrorResponse>(error)) {
    const config = error.config as AdminRequestConfig | undefined
    const expected = config?.adminGeneration
    const status = error.response?.status
    const disabled = status === 403 && error.response?.data?.code === 'ADMIN_ACCOUNT_DISABLED'
    if (expected !== undefined && isAdminRequest(config?.url) && !isAdminLogin(config?.url)) {
      if (status === 401 || disabled) {
        const stale = expected !== getAdminGeneration()
        reportAdminFailure(expected, disabled ? 'disabled' : 'expired',
          error.response?.data?.message || (disabled ? '此管理員帳號已停用' : '管理員登入已失效，請重新登入'))
        if (stale) return Promise.reject(new AdminSessionInvalidatedError())
      } else if (expected !== getAdminGeneration()) {
        return Promise.reject(new AdminSessionInvalidatedError())
      } else if (status === 403 && error.response?.data?.code === 'ADMIN_PERMISSION_DENIED' && !/(?:^|\/)admin\/me\/?(?:\?|$)/.test(config?.url ?? '')) {
        await reportAdminPermissionDenied(expected, config?.adminPermissionContext ?? '')
      }
      // report 會推進世代；保留本次真正錯誤讓 restore 區分 guest／disabled。
      // 舊錯誤不可再由 store 清理或覆寫新資料。
    } else if (expected !== undefined && expected !== getAdminGeneration()) {
      return Promise.reject(new AdminSessionInvalidatedError())
    }
  }
  return Promise.reject(error)
})

export default adminApi
