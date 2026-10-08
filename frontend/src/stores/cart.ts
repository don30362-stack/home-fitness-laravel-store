import { parseGuestCart } from '@/utils/guestCartStorage'
import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { ensureSessionVersion, getSessionVersion } from '@/services/sessionState'

import type {
    AddGuestCartItemPayload,
    Cart,
    CartItemProduct,
    GuestCartItem,
    StoreCartItemPayload,
    UpdateCartItemPayload,
} from '@/types/cart'
import type {
    Product,
    ProductVariant,
} from '@/types/product'

import {
    addCartItem as addCartItemApi,
    clearCart as clearCartApi,
    getCart,
    mergeCart as mergeCartApi,
    removeCartItem as removeCartItemApi,
    updateCartItem as updateCartItemApi,
} from '@/services/cartService'

const GUEST_CART_STORAGE_KEY =
    'home-fitness-store-guest-cart'

const loadGuestItems = (): GuestCartItem[] => {
    try { return parseGuestCart(localStorage.getItem(GUEST_CART_STORAGE_KEY)) }
    catch { return [] } // Browser storage can be unavailable (privacy/quota policies).
}

const createItemKey = (
    productId: number,
    variantId: number | null,
): string => {
    return `${productId}:${variantId ?? 'none'}`
}

const getAvailableStock = (
    product: Product,
    variant: ProductVariant | null,
): number | null => {
    return variant?.stock ?? product.stock
}

const getUnavailableReason = (
    product: Product,
    variant: ProductVariant | null,
): string | null => {
    if (product.status !== 'active') {
        return '商品已下架'
    }

    if (variant !== null && variant.status !== 'active') {
        return '商品規格已停用'
    }

    return null
}

const createProductSummary = (
    product: Product,
): CartItemProduct => {
    const primaryImage =
        product.images.find((image) => image.is_primary) ??
        product.images[0] ??
        null

    return {
        id: product.id,
        product_code: product.product_code,
        name: product.name,
        price: product.price,
        status: product.status,
        primary_image: primaryImage,
    }
}

export const useCartStore = defineStore('cart', () => {
    const guestItems = ref<GuestCartItem[]>(
        loadGuestItems(),
    )
    const memberCart = ref<Cart | null>(null)
    const isMemberCartLoading = ref(false)

    const memberItems = computed(() => {
        return memberCart.value?.items ?? []
    })

    const memberItemCount = computed(() => {
        return memberCart.value?.item_count ?? 0
    })

    const memberSubtotal = computed(() => {
        return memberCart.value?.subtotal ?? '0.00'
    })

    const hasUnavailableMemberItems = computed(() => {
        return (
            memberCart.value?.has_unavailable_items ??
            false
        )
    })

    const guestItemCount = computed(() => {
        return guestItems.value.reduce(
            (total, item) => total + item.quantity,
            0,
        )
    })

    const guestSubtotal = computed(() => {
        const subtotal = guestItems.value.reduce(
            (total, item) => {
                return (
                    total +
                    Number(item.unit_price) * item.quantity
                )
            },
            0,
        )

        return subtotal.toFixed(2)
    })

    const hasUnavailableGuestItems = computed(() => {
        return guestItems.value.some(
            (item) => !item.is_available,
        )
    })

    const saveGuestItems = () => {
        localStorage.setItem(
            GUEST_CART_STORAGE_KEY,
            JSON.stringify(guestItems.value),
        )
    }

    const addGuestItem = (
        payload: AddGuestCartItemPayload,
    ) => {
        const {
            product,
            variant,
            quantity,
        } = payload

        if (!Number.isInteger(quantity) || quantity < 1) {
            throw new Error('購買數量至少為 1。')
        }

        if (
            product.variants.length > 0 &&
            variant === null
        ) {
            throw new Error('請選擇商品規格。')
        }

        const unavailableReason =
            getUnavailableReason(product, variant)

        if (unavailableReason !== null) {
            throw new Error(unavailableReason)
        }

        const availableStock =
            getAvailableStock(product, variant)

        const key = createItemKey(
            product.id,
            variant?.id ?? null,
        )

        const existingItem = guestItems.value.find(
            (item) => item.key === key,
        )

        const newQuantity =
            (existingItem?.quantity ?? 0) + quantity

        if (
            availableStock === null ||
            newQuantity > availableStock
        ) {
            throw new Error(
                `商品庫存不足，目前可購買數量為 ${availableStock ?? 0}。`,
            )
        }

        if (existingItem !== undefined) {
            existingItem.quantity = newQuantity
            existingItem.subtotal = (
                Number(existingItem.unit_price) *
                newQuantity
            ).toFixed(2)

            saveGuestItems()

            return
        }

        guestItems.value.push({
            key,
            product_id: product.id,
            product_variant_id: variant?.id ?? null,
            product: createProductSummary(product),
            variant,
            quantity,
            unit_price: product.price,
            subtotal: (
                Number(product.price) * quantity
            ).toFixed(2),
            available_stock: availableStock,
            is_available: true,
            unavailable_reason: null,
        })

        saveGuestItems()
    }

    const updateGuestItem = (
        key: string,
        quantity: number,
    ) => {
        const item = guestItems.value.find(
            (guestItem) => guestItem.key === key,
        )

        if (item === undefined) {
            return
        }

        if (!Number.isInteger(quantity) || quantity < 1) {
            throw new Error('購買數量至少為 1。')
        }

        if (
            item.available_stock === null ||
            quantity > item.available_stock
        ) {
            throw new Error(
                `商品庫存不足，目前可購買數量為 ${item.available_stock ?? 0}。`,
            )
        }

        item.quantity = quantity
        item.subtotal = (
            Number(item.unit_price) * quantity
        ).toFixed(2)

        saveGuestItems()
    }

    const removeGuestItem = (key: string) => {
        guestItems.value = guestItems.value.filter(
            (item) => item.key !== key,
        )

        saveGuestItems()
    }

    const clearGuestCart = () => {
        guestItems.value = []

        localStorage.removeItem(
            GUEST_CART_STORAGE_KEY,
        )
    }

    const fetchMemberCart = async () => {
        const requestVersion = getSessionVersion()
        isMemberCartLoading.value = true

        try {
            const response = await getCart()
            ensureSessionVersion(requestVersion)
            memberCart.value = response.data

            return response
        } finally {
            isMemberCartLoading.value = false
        }
    }

    const addMemberItem = async (
        payload: StoreCartItemPayload,
    ) => {
        const requestVersion = getSessionVersion()
        const response = await addCartItemApi(payload)
        ensureSessionVersion(requestVersion)
        memberCart.value = response.data

        return response
    }

    const updateMemberItem = async (
        itemId: number,
        payload: UpdateCartItemPayload,
    ) => {
        const requestVersion = getSessionVersion()
        const response = await updateCartItemApi(
            itemId,
            payload,
        )

        ensureSessionVersion(requestVersion)
        memberCart.value = response.data

        return response
    }

    const removeMemberItem = async (itemId: number) => {
        const requestVersion = getSessionVersion()
        const response = await removeCartItemApi(itemId)
        ensureSessionVersion(requestVersion)
        memberCart.value = response.data

        return response
    }

    const clearMemberCart = async () => {
        const requestVersion = getSessionVersion()
        const response = await clearCartApi()
        ensureSessionVersion(requestVersion)
        memberCart.value = response.data

        return response
    }

    const mergeGuestCart = async () => {
        const requestVersion = getSessionVersion()
        if (guestItems.value.length === 0) {
            return await fetchMemberCart()
        }
        const response = await mergeCartApi({
            items: guestItems.value.map((item) => {
                return {
                    product_id: item.product_id,
                    product_variant_id:
                        item.product_variant_id,
                    quantity: item.quantity,
                }
            }),
        })

        ensureSessionVersion(requestVersion)
        memberCart.value = response.data

        // 必須等後端合併成功且登入仍有效，才能清除訪客購物車。
        clearGuestCart()

        return response
    }

    const resetMemberCart = () => {
        memberCart.value = null
    }

    return {
        // 訪客購物車狀態
        guestItems,
        guestItemCount,
        guestSubtotal,
        hasUnavailableGuestItems,

        // 訪客購物車操作
        addGuestItem,
        updateGuestItem,
        removeGuestItem,
        clearGuestCart,

        // 會員購物車狀態
        memberCart,
        memberItems,
        memberItemCount,
        memberSubtotal,
        hasUnavailableMemberItems,
        isMemberCartLoading,

        // 會員購物車操作
        fetchMemberCart,
        addMemberItem,
        updateMemberItem,
        removeMemberItem,
        clearMemberCart,
        mergeGuestCart,
        resetMemberCart,
    }
})
