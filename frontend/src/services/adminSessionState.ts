export type AdminFailureReason = 'expired' | 'disabled'

export class AdminSessionInvalidatedError extends Error {
  constructor() {
    super('管理員登入世代已改變，忽略先前請求')
    this.name = 'AdminSessionInvalidatedError'
  }
}

let generation = 0
let lastFailure: { generation: number; reason: AdminFailureReason } | null = null
let handler: ((reason: AdminFailureReason, message: string, redirect: boolean) => void) | undefined

export const getAdminGeneration = () => generation
export const startAdminGeneration = () => {
  lastFailure = null
  return ++generation
}
export const ensureAdminGeneration = (expected: number) => {
  if (expected !== generation) throw new AdminSessionInvalidatedError()
}
export const setAdminFailureHandler = (callback: NonNullable<typeof handler>) => { handler = callback }

export const reportAdminFailure = (expected: number, reason: AdminFailureReason, message: string) => {
  if (expected !== generation) {
    // 同批晚到停用可提升原因；新登入已清 lastFailure，絕不影響新身分。
    if (lastFailure?.generation === expected && lastFailure.reason === 'expired' && reason === 'disabled') {
      lastFailure.reason = reason
      handler?.(reason, message, false)
    }
    return
  }
  lastFailure = { generation: expected, reason }
  ++generation
  handler?.(reason, message, true)
}
