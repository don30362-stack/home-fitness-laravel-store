// Transport only reports denial; the store and entry point own identity/navigation.
let handler: ((expected: number, context: string) => Promise<void>) | undefined
export const setAdminPermissionDeniedHandler = (next: NonNullable<typeof handler>) => { handler = next }
export const reportAdminPermissionDenied = async (expected: number, context: string) => { await handler?.(expected, context) }
