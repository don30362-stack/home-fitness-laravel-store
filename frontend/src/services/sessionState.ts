export type AuthFailureReason = 'disabled' | 'expired'

export const ACCOUNT_DISABLED_MESSAGE = '此會員帳號已停用，請聯絡管理員'

export class SessionInvalidatedError extends Error {
  constructor() {
    super('登入狀態已失效，忽略先前請求的結果')
    this.name = 'SessionInvalidatedError'
  }
}

let version = 0
let lastFailure: { version: number; reason: AuthFailureReason } | null = null
let failureHandler: ((reason: AuthFailureReason, redirect: boolean) => void) | undefined

export const getSessionVersion = () => version
export const ensureSessionVersion = (requestVersion: number) => {
  if (requestVersion !== version) throw new SessionInvalidatedError()
}

// 新登入／正常登出建立新的邊界，舊請求不得覆寫新登入的資料。
export const startSessionVersion = () => {
  lastFailure = null
  return ++version
}

export const setSessionFailureHandler = (
  handler: (reason: AuthFailureReason, redirect: boolean) => void,
) => {
  failureHandler = handler
}

export const isLoginRequest = (url = '') => /(?:^|\/)login\/?(?:\?|$)/.test(url)
export const isMemberRequest = (url = '') =>
  /(?:^|\/)(?:me|addresses|cart|checkout|logout|orders)(?:\/|\?|$)/.test(url)

export const reportSessionFailure = (
  requestVersion: number,
  reason: AuthFailureReason,
  redirect: boolean,
) => {
  if (requestVersion !== version) {
    // 同一批請求先回 401、後回停用 403 時，只允許提升為較明確的原因。
    if (lastFailure?.version !== requestVersion || lastFailure.reason !== 'expired' || reason !== 'disabled') {
      return
    }
    lastFailure.reason = 'disabled'
  } else {
    if (lastFailure?.reason === 'disabled' || (lastFailure?.reason === 'expired' && reason === 'expired')) return
    lastFailure = { version: requestVersion, reason }
    ++version
  }

  failureHandler?.(reason, redirect)
}
