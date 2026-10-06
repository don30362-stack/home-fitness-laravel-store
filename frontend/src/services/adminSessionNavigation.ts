import { getAdminGeneration } from './adminSessionState'

type NavigationCallbacks = {
  loginRequired?: () => void | Promise<void>
  loginSucceeded?: () => void | Promise<void>
  permissionContext?: () => string
  permissionsChanged?: (context: string, refreshFailed: boolean) => void | Promise<void>
}

let callbacks: NavigationCallbacks = {}
let queued = false

// Step 4 由入口注入 Router 操作；傳輸與 store 不 import Router。
export const setAdminNavigationCallbacks = (next: NavigationCallbacks) => { callbacks = next }
export const requestAdminLogin = () => {
  if (queued) return
  queued = true
  const expected = getAdminGeneration()
  queueMicrotask(() => {
    queued = false
    if (expected === getAdminGeneration()) void callbacks.loginRequired?.()
  })
}
export const notifyAdminLoginSuccess = async () => { await callbacks.loginSucceeded?.() }
export const getAdminPermissionContext = () => callbacks.permissionContext?.() ?? ''
export const notifyAdminPermissionsChanged = async (context: string, refreshFailed: boolean) => {
  await callbacks.permissionsChanged?.(context, refreshFailed)
}
