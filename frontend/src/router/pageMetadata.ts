import type { RouteMeta } from 'vue-router'

declare module 'vue-router' {
  interface RouteMeta {
    title?: string
    description?: string
  }
}

export const DEFAULT_PAGE_METADATA = {
  title: 'Home Fit｜居家訓練器材',
  description: 'Home Fit 提供居家重訓器材與訓練配件的商品瀏覽與選購資訊。',
}

export const ADMIN_PAGE_METADATA = {
  title: 'Home Fit 管理後台',
  description: 'Home Fit 管理後台。',
}

const nonblank = (value: unknown, fallback: string): string =>
  typeof value === 'string' && value.trim() ? value.trim() : fallback

// 每次成功導航都覆蓋兩個欄位，缺值也不沿用前頁資料。
export function applyPageMetadata(
  meta: Pick<RouteMeta, 'title' | 'description'>,
  target: Document | undefined = typeof document === 'undefined' ? undefined : document,
): void {
  if (!target) return
  target.title = nonblank(meta.title, DEFAULT_PAGE_METADATA.title)
  const descriptions = target.querySelectorAll<HTMLMetaElement>('meta[name="description"]')
  const description = descriptions[0] ?? target.createElement('meta')
  description.name = 'description'
  description.content = nonblank(meta.description, DEFAULT_PAGE_METADATA.description)
  if (!descriptions.length) target.head.appendChild(description)
  // 若宿主意外提供重複 tag，保留並更新第一個，避免導航繼續累積。
  for (const duplicate of Array.from(descriptions).slice(1)) duplicate.remove()
}
