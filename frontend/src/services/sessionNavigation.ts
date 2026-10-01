import type { Router } from 'vue-router'
import type { AuthFailureReason } from './sessionState'

export const shouldRedirectSessionFailure = (
  reason: AuthFailureReason,
  wasAuthenticated: boolean,
  requiresAuth: boolean,
) => reason === 'disabled' || wasAuthenticated || requiresAuth

// 啟動時由 main 注入 router；api 與 stores 都不需要 import router。
export const createSessionNavigation = (router: Router) => {
  let ready = false
  let pending = false
  let navigating = false

  const flush = async () => {
    if (!ready || !pending || navigating) return
    pending = false
    if (router.currentRoute.value.name === 'login') return
    navigating = true
    try {
      await router.replace({ name: 'login' })
    } finally {
      navigating = false
      if (pending) void flush()
    }
  }

  return {
    request: () => {
      pending = true
      // 同一輪的並行失敗共用一次導頁。
      queueMicrotask(() => { void flush() })
    },
    ready: async () => {
      ready = true
      await flush()
    },
  }
}
