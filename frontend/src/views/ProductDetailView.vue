<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { isAxiosError } from 'axios'

import { getProductById, getRelatedProducts } from '@/services/productService'
import type { Product, ProductListItem, ProductVariant } from '@/types/product'
import type { ApiErrorResponse } from '@/types/api'
import ProductGallery from '@/components/product/ProductGallery.vue'
import ProductCard from '@/components/product/ProductCard.vue'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

const route = useRoute()
const authStore = useAuthStore()
const cartStore = useCartStore()

const product = ref<Product | null>(null)
const relatedProducts = ref<ProductListItem[]>([])
const selectedVariant = ref<ProductVariant | null>(null)
const isLoading = ref(false)
const errorMessage = ref('')
const quantity = ref(1)
const notFound = ref(false)
const relatedLoading = ref(false)
const relatedError = ref('')
let sequence = 0, relatedSequence = 0
let disposed = false
onBeforeUnmount(() => { disposed = true; ++sequence; ++relatedSequence })
const fetchRelated = async () => {
    if (!product.value || relatedLoading.value || disposed) return
    const id = product.value.id, current = ++relatedSequence, main = sequence
    const valid = () => !disposed && main === sequence && current === relatedSequence
    relatedLoading.value = true
    relatedError.value = ''
    try {
        const rows = await getRelatedProducts(id)
        if (valid()) relatedProducts.value = rows
    } catch {
        if (valid()) relatedError.value = '相關商品載入失敗'
    } finally {
        if (valid()) relatedLoading.value = false
    }
}
const retryProduct = () => { if (!isLoading.value) void fetchProduct() }

const isAddingToCart = ref(false)
const cartSuccessMessage = ref('')
const cartErrorMessage = ref('')

const hasVariants = computed(() => {
    return (product.value?.variants.length ?? 0) > 0
})

const availableStock = computed(() => {
    if (!product.value) {
        return 0
    }

    if (hasVariants.value) {
        return selectedVariant.value?.stock ?? 0
    }

    return product.value.stock ?? 0
})

const isOutOfStock = computed(() => {
    return availableStock.value === 0
})

const isAddToCartDisabled = computed(() => {
    return (
        isAddingToCart.value ||
        product.value?.status !== 'active' ||
        isOutOfStock.value ||
        (hasVariants.value && selectedVariant.value === null)
    )
})

const addToCartButtonText = computed(() => {
    if (isAddingToCart.value) {
        return '加入中...'
    }

    if (product.value?.status !== 'active') {
        return '商品已下架'
    }

    if (hasVariants.value && selectedVariant.value === null) {
        return '請先選擇規格'
    }

    if (isOutOfStock.value) {
        return '商品缺貨'
    }

    return '加入購物車'
})

const fetchProduct = async () => {
    const current = ++sequence
    ++relatedSequence
    const valid = () => !disposed && current === sequence
    relatedLoading.value = false
    relatedError.value = ''
    notFound.value = false
    isLoading.value = true
    errorMessage.value = ''
    product.value = null
    relatedProducts.value = []
    selectedVariant.value = null
    quantity.value = 1
    cartSuccessMessage.value = ''
    cartErrorMessage.value = ''

    try {
        const id = route.params.id

        if (typeof id !== 'string') {
            errorMessage.value = '無效的商品 ID'
            return
        }

        const productId = Number(id)

        if (!Number.isInteger(productId) || productId <= 0) {
            errorMessage.value = '無效的商品 ID'
            return
        }

        const result = await getProductById(productId)
        if (!valid()) return
        product.value = result
        void fetchRelated()
    } catch (error) {
        if (!valid()) return

        if (isAxiosError<ApiErrorResponse>(error) && error.response?.status === 404) {
            notFound.value = true
            errorMessage.value = error.response.data?.message || '找不到此商品'
        } else {
            errorMessage.value = '商品資料載入失敗'
        }
    } finally {
        if (valid()) isLoading.value = false
    }
}

const decreaseQuantity = () => {
    if (quantity.value > 1) {
        quantity.value--
    }
}

const increaseQuantity = () => {
    if (quantity.value < availableStock.value) {
        quantity.value++
    }
}

const handleAddToCart = async () => {
    if (
        product.value === null ||
        isAddToCartDisabled.value
    ) {
        return
    }

    cartSuccessMessage.value = ''
    cartErrorMessage.value = ''
    isAddingToCart.value = true

    try {
        if (authStore.isAuthenticated) {
            const response = await cartStore.addMemberItem({
                product_id: product.value.id,
                product_variant_id:
                    selectedVariant.value?.id ?? null,
                quantity: quantity.value,
            })

            cartSuccessMessage.value =
                response.message ?? '商品已加入購物車。'
        } else {
            cartStore.addGuestItem({
                product: product.value,
                variant: selectedVariant.value,
                quantity: quantity.value,
            })

            cartSuccessMessage.value =
                '商品已加入購物車。'
        }

        quantity.value = 1
    } catch (error) {
        if (isAxiosError<ApiErrorResponse>(error)) {
            const responseData = error.response?.data

            const firstFieldError =
                Object.values(
                    responseData?.errors ?? {},
                )[0]?.[0]

            cartErrorMessage.value =
                firstFieldError ??
                responseData?.message ??
                '加入購物車失敗，請稍後再試。'

            return
        }

        if (error instanceof Error) {
            cartErrorMessage.value = error.message

            return
        }

        cartErrorMessage.value =
            '加入購物車失敗，請稍後再試。'
    } finally {
        isAddingToCart.value = false
    }
}

watch(
    () => route.params.id,
    () => {
        fetchProduct()
    },
    {
        immediate: true
    }
)

watch(
    availableStock,
    (stock) => {
        if (stock <= 0) {
            quantity.value = 1
            return
        }

        if (quantity.value > stock) {
            quantity.value = stock
        }
    }
)
</script>

<template>
    <div class="container py-5 hf-functional-page hf-product-detail">
        <p v-if="isLoading">
            商品載入中...
        </p>

        <div v-else-if="errorMessage" role="alert" class="text-danger"><p>{{ errorMessage }}</p><button v-if="!notFound" type="button" class="btn btn-outline-dark" :disabled="isLoading" @click="retryProduct">重新載入商品</button></div>

        <div v-else-if="product">
            <div class="product-detail-grid">
                <div class="product-detail-media">
                    <ProductGallery :images="product.images" :product-name="product.name" />
                </div>

                <div class="product-detail-info">
                    <p class="text-muted mb-2"> {{ product.category.name }}</p>

                    <h1 class="mb-3">{{ product.name }}</h1>

                    <p class="text-muted">
                        商品編號：{{ product.product_code }}
                    </p>

                    <p class="fs-3 fw-bold hf-product-price">
                        NT$ {{ Number(product.price).toLocaleString('zh-TW') }}
                    </p>

                    <div v-if="hasVariants" class="mb-4">
                        <p class="fw-semibold mb-2">
                            {{ product.variants[0]?.option_name }}
                        </p>

                        <div class="d-flex flex-wrap gap-2">
                            <button v-for="variant in product.variants" :key="variant.id" type="button" class="btn"
                                :class="selectedVariant?.id === variant.id ? 'btn-dark' : 'btn-outline-dark'"
                                :disabled="variant.status !== 'active'" @click="selectedVariant = variant">
                                {{ variant.option_value }}
                            </button>
                        </div>
                    </div>

                    <div class="mb-4">
                        <span class="me-2">庫存：</span>

                        <span v-if="hasVariants && !selectedVariant" class="text-muted">請先選擇規格</span>

                        <span v-else-if="isOutOfStock" class="badge text-bg-secondary">缺貨</span>

                        <span v-else class="text-success">尚有 {{ availableStock }} 件</span>
                    </div>

                    <p v-if="product.short_description" class="text-muted">
                        {{ product.short_description }}
                    </p>

                    <div v-if="!hasVariants || selectedVariant" class="mb-4">
                        <label class="form-label fw-semibold">購買數量</label>

                        <div class="d-flex align-items-center gap-2">
                            <button type="button" class="btn btn-outline-secondary"
                                :disabled="quantity <= 1 || isOutOfStock" @click="decreaseQuantity">
                                -
                            </button>

                            <span class="text-center" style="min-width: 40px;">
                                {{ quantity }}
                            </span>

                            <button type="button" class="btn btn-outline-secondary"
                                :disabled="quantity >= availableStock || isOutOfStock" @click="increaseQuantity">
                                +
                            </button>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div v-if="cartSuccessMessage" class="alert alert-success" role="alert">
                            {{ cartSuccessMessage }}
                        </div>

                        <div v-if="cartErrorMessage" class="alert alert-danger" role="alert">
                            {{ cartErrorMessage }}
                        </div>

                        <button type="button" class="btn btn-dark btn-lg w-100" :disabled="isAddToCartDisabled"
                            @click="handleAddToCart">
                            {{ addToCartButtonText }}
                        </button>
                    </div>
                </div>
            </div>

            <section class="mt-5 pt-4 border-top">
                <h2 class="h4 fw-bold mb-4">商品詳情</h2>

                <p v-if="product.description" class="lh-lg mb-0" style="white-space: pre-line;">
                    {{ product.description }}
                </p>

                <p v-else class="text-muted mb-0">暫無商品詳細說明。</p>
            </section>

            <section v-if="product.specifications.length > 0" class="mt-5 pt-4 border-top">
                <h2 class="h4 fw-bold mb-4">商品規格</h2>

                <dl class="row mb-0">
                    <template v-for="specification in product.specifications" :key="specification.id">
                        <dt class="col-sm-3 py-2">
                            {{ specification.spec_name }}
                        </dt>

                        <dd class="col-sm-9 py-2 mb-0">
                            {{ specification.spec_value }}
                        </dd>
                    </template>
                </dl>
            </section>

            <section v-if="relatedLoading || relatedError || relatedProducts.length > 0" class="mt-5 pt-4 border-top">
                <h2 class="h4 fw-bold mb-4">相關商品</h2>
                <p v-if="relatedLoading" role="status">相關商品載入中...</p>
                <div v-if="relatedError" role="alert"><p>{{ relatedError }}</p><button type="button" class="btn btn-outline-dark" :disabled="relatedLoading" @click="fetchRelated">重新載入相關商品</button></div>

                <div class="row g-4">
                    <div v-for="relatedProduct in relatedProducts" :key="relatedProduct.id"
                        class="col-12 col-sm-6 col-lg-3">
                        <ProductCard :product="relatedProduct" />
                    </div>
                </div>
            </section>
        </div>
    </div>
</template>
<style scoped>
/* Explicit grid gaps stay inside the container; no Bootstrap negative gutter margins. */
.product-detail-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: 2rem; }
.product-detail-grid > * { min-width: 0; }
.product-detail-info h1 { font-size: clamp(1.7rem, 3vw, 2.5rem); font-weight: 600; line-height: 1.35; overflow-wrap: anywhere; }
.hf-product-price { padding-block: 1rem; border-block: 1px solid var(--hf-stone); }
.hf-product-detail section { padding-bottom: 1rem; }
.hf-product-detail :is(dt, dd, p) { overflow-wrap: anywhere; }
@media (min-width: 992px) { .product-detail-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 3rem; } }
</style>
