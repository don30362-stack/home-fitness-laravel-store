import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import axios from 'axios'
import type { ApiErrorResponse } from '@/types/api'
import type { Admin, AdminLoginPayload, AdminPermissionCode } from '@/types/adminAuth'
import { loginAdmin, enterAdminDemo, getCurrentAdmin, logoutAdmin } from '@/services/adminAuthService'
import {
  AdminSessionInvalidatedError, getAdminGeneration, ensureAdminGeneration,
  startAdminGeneration, setAdminFailureHandler, type AdminFailureReason,
} from '@/services/adminSessionState'
import { requestAdminLogin, notifyAdminPermissionsChanged } from '@/services/adminSessionNavigation'
import { setAdminPermissionDeniedHandler } from '@/services/adminPermissionState'

type RestoreResult = 'authenticated' | 'guest' | 'disabled' | 'stale'

export const useAdminAuthStore = defineStore('adminAuth', () => {
  const currentAdmin = ref<Admin | null>(null)
  const isAdminInitialized = ref(false)
  const isRestoring = ref(false)
  const restoreError = ref<string | null>(null)
  const adminFailureReason = ref<AdminFailureReason | null>(null)
  const adminFailureMessage = ref<string | null>(null)
  const isAdminAuthenticated = computed(() => currentAdmin.value !== null)
  const isReadOnlyDemo = computed(() => currentAdmin.value?.is_demo === true)
  const permissionRefreshError = ref<string | null>(null)
  const isRefreshingPermissions = ref(false)
  const hasPermission = (code: AdminPermissionCode) => currentAdmin.value?.permissions.includes(code) ?? false
  let pendingIdentityRefresh: Promise<RestoreResult> | null = null
  let pendingRestore: Promise<RestoreResult> | null = null

  const reset = (reason: AdminFailureReason | null = null, message: string | null = null) => {
    currentAdmin.value = null
    isAdminInitialized.value = true
    restoreError.value = null
    permissionRefreshError.value = null
    if (adminFailureReason.value !== 'disabled' || reason === 'disabled') {
      adminFailureReason.value = reason
      adminFailureMessage.value = message
    }
  }
  setAdminFailureHandler((reason, message, redirect) => {
    const wasAuthenticated = currentAdmin.value !== null
    if (reason === 'expired' && !wasAuthenticated && !isAdminInitialized.value) {
      reset()
      return
    }
    reset(reason, message)
    if (redirect && (wasAuthenticated || reason === 'disabled')) requestAdminLogin()
  })

  const clearAdminSession = () => {
    startAdminGeneration()
    pendingRestore = null
    pendingIdentityRefresh = null
    isRefreshingPermissions.value = false
    isRestoring.value = false
    adminFailureReason.value = null
    reset()
  }
  const authenticate = async (operation: () => ReturnType<typeof loginAdmin>) => {
    const attempt = startAdminGeneration()
    pendingIdentityRefresh = null
    isRefreshingPermissions.value = false
    permissionRefreshError.value = null
    pendingRestore = null
    isRestoring.value = false
    const response = await operation()
    ensureAdminGeneration(attempt)
    startAdminGeneration()
    currentAdmin.value = response.data
    isAdminInitialized.value = true
    restoreError.value = null
    adminFailureReason.value = null
    adminFailureMessage.value = null
    return response
  }
  const login = (payload: AdminLoginPayload) => authenticate(() => loginAdmin(payload))
  const startDemo = () => authenticate(enterAdminDemo)
  const restoreAdmin = (): Promise<RestoreResult> => {
    if (pendingRestore) return pendingRestore
    const expected = getAdminGeneration()
    isRestoring.value = true
    restoreError.value = null
    const request = (async (): Promise<RestoreResult> => {
      try {
        const response = await getCurrentAdmin()
        ensureAdminGeneration(expected)
        currentAdmin.value = response.data
        isAdminInitialized.value = true
        adminFailureReason.value = null
        adminFailureMessage.value = null
        return 'authenticated'
      } catch (error) {
        if (error instanceof AdminSessionInvalidatedError) return 'stale'
        if (axios.isAxiosError<ApiErrorResponse>(error)) {
          if (error.response?.status === 401) return 'guest'
          if (error.response?.status === 403 && error.response.data?.code === 'ADMIN_ACCOUNT_DISABLED') return 'disabled'
        }
        if (expected !== getAdminGeneration()) return 'stale'
        isAdminInitialized.value = false
        restoreError.value = '無法恢復管理員登入狀態，請重試'
        throw error
      }
    })()
    const operation = request.finally(() => {
      if (pendingRestore === operation) {
        pendingRestore = null
        isRestoring.value = false
      }
    })
    pendingRestore = operation
    return operation
  }
  const logout = async () => {
    const expected = startAdminGeneration()
    pendingIdentityRefresh = null
    isRefreshingPermissions.value = false
    pendingRestore = null
    isRestoring.value = false
    try {
      await logoutAdmin()
      ensureAdminGeneration(expected)
    } catch (error) {
      if (error instanceof AdminSessionInvalidatedError) return false
      if (axios.isAxiosError(error) && error.response?.status === 401) return false
      throw error
    }
    clearAdminSession()
    return true
  }

  // Unlike initial restore, temporary failure must retain the established identity.
  const refreshAdminIdentity = (): Promise<RestoreResult> => {
    if (pendingIdentityRefresh) return pendingIdentityRefresh
    const expected = getAdminGeneration()
    isRefreshingPermissions.value = true
    permissionRefreshError.value = null
    const operation = (async (): Promise<RestoreResult> => {
      try {
        const response = await getCurrentAdmin()
        ensureAdminGeneration(expected)
        currentAdmin.value = response.data
        return 'authenticated'
      } catch (error) {
        if (axios.isAxiosError<ApiErrorResponse>(error)) {
          if (error.response?.status === 401) return 'guest'
          if (error.response?.status === 403 && error.response.data?.code === 'ADMIN_ACCOUNT_DISABLED') return 'disabled'
        }
        if (expected !== getAdminGeneration() || error instanceof AdminSessionInvalidatedError) return 'stale'
        permissionRefreshError.value = '無法更新管理員權限資料，請手動重試。'
        return 'stale'
      }
    })().finally(() => {
      if (pendingIdentityRefresh === operation) {
        pendingIdentityRefresh = null
        isRefreshingPermissions.value = false
      }
    })
    pendingIdentityRefresh = operation
    return operation
  }
  setAdminPermissionDeniedHandler(async (expected, context) => {
    if (expected !== getAdminGeneration()) return
    await refreshAdminIdentity()
    if (expected !== getAdminGeneration()) return
    await notifyAdminPermissionsChanged(context, Boolean(permissionRefreshError.value))
  })

  return { currentAdmin, isAdminAuthenticated, isAdminInitialized, isRestoring, restoreError,
    adminFailureReason, adminFailureMessage, login, startDemo, isReadOnlyDemo, restoreAdmin, logout, clearAdminSession,
    hasPermission, permissionRefreshError, isRefreshingPermissions, refreshAdminIdentity }
})
