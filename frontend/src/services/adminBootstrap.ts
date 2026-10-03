import type { Pinia } from 'pinia'
import { useAdminAuthStore } from '@/stores/adminAuth'

export const isAdminPath = (path: string) => path === '/admin' || path.startsWith('/admin/')

// Step 4 在 main 的會員 restore 前接線；暫時錯誤留 store，不能冒充 guest。
export const restoreInitialAdmin = async (path: string, pinia: Pinia): Promise<boolean> => {
  if (!isAdminPath(path)) return false
  const store = useAdminAuthStore(pinia)
  try {
    await store.restoreAdmin()
  } catch {
    // restoreError 保留，後續頁面／guard 提供手動重試。
  }
  return true
}

export const restoreInitialIdentity = async (pathname: string, pinia: Pinia, restoreMember: () => Promise<void>) => {
  if (!await restoreInitialAdmin(pathname, pinia)) await restoreMember()
}
