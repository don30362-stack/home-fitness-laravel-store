<script setup lang="ts">
import { computed, ref } from 'vue'
import { isAxiosError } from 'axios'
import { RouterLink, useRoute, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

import type {
    CartItem,
    GuestCartItem,
} from '@/types/cart'
import type { ApiErrorResponse } from '@/types/api'

type DisplayCartItem = CartItem | GuestCartItem

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const cartStore = useCartStore()

const processingItemKey = ref<string | null>(null)
const isClearingCart = ref(false)
const isRetryingSync = ref(false)

const actionSuccessMessage = ref('')
const actionErrorMessage = ref('')

const cartItems = computed<DisplayCartItem[]>(() => {
    if (authStore.isAuthenticated) {
        return cartStore.memberItems
    }

    return cartStore.guestItems
})

const itemCount = computed(() => {
    if (authStore.isAuthenticated) {
        return cartStore.memberItemCount
    }

    return cartStore.guestItemCount
})

const subtotal = computed(() => {
    if (authStore.isAuthenticated) {
        return cartStore.memberSubtotal
    }

    return cartStore.guestSubtotal
})

const hasUnavailableItems = computed(() => {
    if (authStore.isAuthenticated) {
        return cartStore.hasUnavailableMemberItems
    }

    return cartStore.hasUnavailableGuestItems
})

const isCartLoading = computed(() => {
    return (
        authStore.isAuthenticated &&
        cartStore.isMemberCartLoading
    )
})

const isCartEmpty = computed(() => {
    return cartItems.value.length === 0
})

const canCheckout = computed(() => {
    return (
        !isCartEmpty.value &&
        !hasUnavailableItems.value
    )
})

const hasCartSyncFailed = computed(() => {
    return route.query.cart_sync === 'failed'
})

const getItemKey = (
    item: DisplayCartItem,
): string => {
    if ('key' in item) {
        return `guest-${item.key}`
    }

    return `member-${item.id}`
}

const formatCurrency = (value: string): string => {
    return Number(value).toLocaleString('zh-TW')
}

const getActionErrorMessage = (
    error: unknown,
): string => {
    if (isAxiosError<ApiErrorResponse>(error)) {
        const responseData = error.response?.data

        const firstFieldError =
            Object.values(
                responseData?.errors ?? {},
            )[0]?.[0]

        return (
            firstFieldError ??
            responseData?.message ??
            '購物車操作失敗，請稍後再試。'
        )
    }

    if (error instanceof Error) {
        return error.message
    }

    return '購物車操作失敗，請稍後再試。'
}

const clearActionMessage = () => {
    actionSuccessMessage.value = ''
    actionErrorMessage.value = ''
}

const updateItemQuantity = async (
    item: DisplayCartItem,
    quantity: number,
) => {
    if (quantity < 1) {
        return
    }

    const key = getItemKey(item)

    processingItemKey.value = key
    clearActionMessage()

    try {
        if ('id' in item) {
            await cartStore.updateMemberItem(
                item.id,
                { quantity },
            )
        } else {
            cartStore.updateGuestItem(
                item.key,
                quantity,
            )
        }
    } catch (error) {
        actionErrorMessage.value =
            getActionErrorMessage(error)
    } finally {
        processingItemKey.value = null
    }
}

const canDecreaseItem = (
    item: DisplayCartItem,
): boolean => {
    if (item.quantity <= 1) {
        return false
    }

    if (item.is_available) {
        return true
    }

    return (
        item.unavailable_reason === '商品庫存不足' &&
        (item.available_stock ?? 0) > 0
    )
}

const decreaseItem = (item: DisplayCartItem) => {
    let newQuantity = item.quantity - 1

    // 若原本數量遠高於目前庫存，直接調整到可用庫存。
    if (
        !item.is_available &&
        item.unavailable_reason === '商品庫存不足' &&
        item.available_stock !== null
    ) {
        newQuantity = Math.min(
            newQuantity,
            item.available_stock,
        )
    }

    void updateItemQuantity(item, newQuantity)
}

const increaseItem = (item: DisplayCartItem) => {
    void updateItemQuantity(
        item,
        item.quantity + 1,
    )
}

const removeItem = async (
    item: DisplayCartItem,
) => {
    const shouldRemove = window.confirm(
        `確定要移除「${item.product.name}」嗎？`,
    )

    if (!shouldRemove) {
        return
    }

    const key = getItemKey(item)

    processingItemKey.value = key
    clearActionMessage()

    try {
        if ('id' in item) {
            await cartStore.removeMemberItem(item.id)
        } else {
            cartStore.removeGuestItem(item.key)
        }

        actionSuccessMessage.value =
            '商品已從購物車移除。'
    } catch (error) {
        actionErrorMessage.value =
            getActionErrorMessage(error)
    } finally {
        processingItemKey.value = null
    }
}

const clearCart = async () => {
    const shouldClear = window.confirm(
        '確定要清空購物車嗎？',
    )

    if (!shouldClear) {
        return
    }

    isClearingCart.value = true
    clearActionMessage()

    try {
        if (authStore.isAuthenticated) {
            await cartStore.clearMemberCart()
        } else {
            cartStore.clearGuestCart()
        }

        actionSuccessMessage.value =
            '購物車已清空。'
    } catch (error) {
        actionErrorMessage.value =
            getActionErrorMessage(error)
    } finally {
        isClearingCart.value = false
    }
}

const removeCartSyncQuery = async () => {
    const query = {
        ...route.query,
    }

    delete query.cart_sync

    await router.replace({
        query,
    })
}

const retryCartSync = async () => {
    isRetryingSync.value = true
    clearActionMessage()

    try {
        await cartStore.mergeGuestCart()
        await removeCartSyncQuery()

        actionSuccessMessage.value =
            '訪客購物車已成功合併。'
    } catch (error) {
        actionErrorMessage.value =
            getActionErrorMessage(error)
    } finally {
        isRetryingSync.value = false
    }
}

const discardGuestCart = async () => {
    const shouldDiscard = window.confirm(
        '確定要放棄尚未合併的訪客購物車嗎？',
    )

    if (!shouldDiscard) {
        return
    }

    cartStore.clearGuestCart()
    await removeCartSyncQuery()

    actionSuccessMessage.value =
        '未合併的訪客購物車已清除。'
}
</script>

<template>
    <div class="container py-5 hf-functional-page hf-cart-page">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
            <div class="hf-page-heading mb-0"><p class="hf-eyebrow">YOUR TRAINING KIT</p><h1 class="h2 mb-0">購物車</h1></div>

            <div class="d-flex align-items-center gap-3">
                <span class="text-muted">
                    共 {{ itemCount }} 件
                </span>

                <button v-if="!isCartEmpty" type="button" class="btn btn-outline-danger btn-sm"
                    :disabled="isClearingCart" @click="clearCart">
                    {{ isClearingCart ? '清空中...' : '清空購物車' }}
                </button>
            </div>
        </div>

        <div v-if="hasCartSyncFailed" class="alert alert-warning" role="alert">
            <p class="mb-3">
                登入成功，但購物車同步失敗。訪客購物車資料仍然保留。
            </p>

            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-warning btn-sm" :disabled="isRetryingSync" @click="retryCartSync">
                    {{
                        isRetryingSync
                            ? '重新同步中...'
                            : '重新同步'
                    }}
                </button>

                <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="isRetryingSync"
                    @click="discardGuestCart">
                    放棄訪客購物車
                </button>
            </div>
        </div>

        <div v-if="actionSuccessMessage" class="alert alert-success" role="alert">
            {{ actionSuccessMessage }}
        </div>

        <div v-if="actionErrorMessage" class="alert alert-danger" role="alert">
            {{ actionErrorMessage }}
        </div>

        <div v-if="isCartLoading" class="py-5 text-center text-muted">
            購物車載入中...
        </div>

        <div v-else-if="isCartEmpty" class="border rounded p-5 text-center">
            <h2 class="h5 mb-3">
                購物車目前是空的
            </h2>

            <p class="text-muted mb-4">
                前往商品專區挑選適合你的訓練器材。
            </p>

            <RouterLink :to="{ name: 'products' }" class="btn btn-dark">
                前往商品專區
            </RouterLink>
        </div>

        <div v-else class="row g-4">
            <div class="col-12 col-lg-8">
                <div class="border rounded">
                    <article v-for="item in cartItems" :key="getItemKey(item)" class="p-3 border-bottom cart-item">
                        <div class="d-flex flex-column flex-sm-row gap-3">
                            <RouterLink :to="{
                                name: 'product-detail',
                                params: {
                                    id: item.product_id,
                                },
                            }" class="flex-shrink-0">
                                <img v-if="item.product.primary_image" :src="item.product.primary_image
                                    .image_url
                                    " :alt="item.product.name" class="cart-item-image rounded border" />

                                <div v-else
                                    class="cart-item-image rounded border bg-light d-flex align-items-center justify-content-center text-muted">
                                    無圖片
                                </div>
                            </RouterLink>

                            <div class="flex-grow-1 cart-item-details">
                                <RouterLink :to="{
                                    name: 'product-detail',
                                    params: {
                                        id: item.product_id,
                                    },
                                }" class="text-dark text-decoration-none">
                                    <h2 class="h5 mb-2">
                                        {{ item.product.name }}
                                    </h2>
                                </RouterLink>

                                <p v-if="item.variant" class="text-muted small mb-2">
                                    {{ item.variant.option_name }}：
                                    {{ item.variant.option_value }}
                                </p>

                                <p class="mb-2">
                                    單價：NT$
                                    {{
                                        formatCurrency(
                                            item.unit_price,
                                        )
                                    }}
                                </p>

                                <div class="d-flex align-items-center gap-2 mb-3">
                                    <span class="me-1">數量：</span>

                                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!canDecreaseItem(item) ||
                                        processingItemKey === getItemKey(item)
                                        " @click="decreaseItem(item)">
                                        −
                                    </button>

                                    <span class="text-center quantity-value">
                                        {{ item.quantity }}
                                    </span>

                                    <button type="button" class="btn btn-outline-secondary btn-sm" :disabled="!item.is_available ||
                                        item.available_stock === null ||
                                        item.quantity >= item.available_stock ||
                                        processingItemKey === getItemKey(item)
                                        " @click="increaseItem(item)">
                                        ＋
                                    </button>
                                </div>

                                <p class="fw-bold mb-0">
                                    小計：NT$
                                    {{
                                        formatCurrency(
                                            item.subtotal,
                                        )
                                    }}
                                </p>

                                <button type="button" class="btn btn-link text-danger p-0 mt-3" :disabled="processingItemKey === getItemKey(item)
                                    " @click="removeItem(item)">
                                    {{
                                        processingItemKey === getItemKey(item)
                                            ? '處理中...'
                                            : '移除商品'
                                    }}
                                </button>

                                <p v-if="!item.is_available" class="text-danger small mt-2 mb-0">
                                    {{
                                        item.unavailable_reason ??
                                        '此商品目前無法購買'
                                    }}
                                </p>
                            </div>
                        </div>
                    </article>
                </div>
            </div>

            <aside class="col-12 col-lg-4">
                <div class="card">
                    <div class="card-body">
                        <h2 class="h5 mb-4">
                            訂單摘要
                        </h2>

                        <div class="d-flex justify-content-between mb-3">
                            <span>商品數量</span>
                            <span>{{ itemCount }} 件</span>
                        </div>

                        <div class="d-flex justify-content-between border-top pt-3 mb-4">
                            <span class="fw-semibold">
                                商品小計
                            </span>

                            <span class="fs-5 fw-bold">
                                NT$
                                {{
                                    formatCurrency(
                                        subtotal,
                                    )
                                }}
                            </span>
                        </div>

                        <div v-if="hasUnavailableItems" class="alert alert-warning small" role="alert">
                            購物車內有目前無法購買的商品，請調整後再結帳。
                        </div>

                        <RouterLink v-if="canCheckout" :to="{ name: 'checkout' }" class="btn btn-dark w-100">
                            前往結帳
                        </RouterLink>

                        <button v-else type="button" class="btn btn-dark w-100" disabled>
                            暫時無法結帳
                        </button>
                    </div>
                </div>
            </aside>
        </div>
    </div>
</template>

<style scoped>
.cart-item:last-child {
    border-bottom: 0 !important;
}

.cart-item-image {
    width: 110px;
    height: 110px;
    object-fit: cover;
}

.cart-item-details {
    min-width: 0;
    overflow-wrap: anywhere;
}

.quantity-value {
    min-width: 32px;
}
</style>