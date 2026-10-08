import type { GuestCartItem } from '@/types/cart'

const record = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
const id = (value: unknown): value is number => Number.isSafeInteger(value) && Number(value) > 0
const stock = (value: unknown): value is number => Number.isSafeInteger(value) && Number(value) >= 0
const money = (value: unknown): value is string =>
  typeof value === 'string' && /^\d+(\.\d+)?$/.test(value) && Number.isFinite(Number(value))

// Storage is untrusted input. These summaries are for guest display only;
// merge sends IDs/quantity and Laravel supplies authoritative price/stock.
export const parseGuestCart = (raw: string | null): GuestCartItem[] => {
  if (raw === null) return []
  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch {
    return []
  }
  if (!Array.isArray(parsed)) return []
  const result: GuestCartItem[] = []
  const keys = new Set<string>()
  for (const row of parsed) {
    if (
      !record(row) ||
      !id(row.product_id) ||
      !(row.product_variant_id === null || id(row.product_variant_id)) ||
      !id(row.quantity) ||
      !record(row.product)
    )
      continue
    const product = row.product,
      variant = row.variant
    if (
      product.id !== row.product_id ||
      typeof product.name !== 'string' ||
      typeof product.product_code !== 'string' ||
      typeof product.status !== 'string' ||
      !money(product.price) ||
      !money(row.unit_price) ||
      !money(row.subtotal) ||
      !(row.available_stock === null || stock(row.available_stock)) ||
      typeof row.is_available !== 'boolean' ||
      !(row.unavailable_reason === null || typeof row.unavailable_reason === 'string')
    )
      continue
    const image = product.primary_image
    if (
      image !== null &&
      (!record(image) ||
        !id(image.id) ||
        typeof image.image_path !== 'string' ||
        typeof image.image_url !== 'string' ||
        typeof image.image_type !== 'string' ||
        typeof image.is_primary !== 'boolean' ||
        !stock(image.sort_order))
    )
      continue
    if (
      row.product_variant_id === null
        ? variant !== null
        : !record(variant) ||
          variant.id !== row.product_variant_id ||
          typeof variant.option_name !== 'string' ||
          typeof variant.option_value !== 'string' ||
          typeof variant.status !== 'string' ||
          !stock(variant.stock)
    )
      continue
    const key = `${row.product_id}:${row.product_variant_id ?? 'none'}`
    if (keys.has(key)) continue
    keys.add(key)
    // All nested fields used by Cart rendering were checked above.
    result.push({
      ...row,
      key,
      subtotal: (Number(row.unit_price) * row.quantity).toFixed(2),
    } as unknown as GuestCartItem)
  }
  return result
}
