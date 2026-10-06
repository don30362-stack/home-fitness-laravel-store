<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, computed } from 'vue'
import ProductCard from '@/components/product/ProductCard.vue'
import { getBanners, getRecommendedProducts } from '@/services/homeContentService'
import type { Banner } from '@/types/homeContent'
import type { ProductListItem } from '@/types/product'

const banners = ref<Banner[]>([])
const products = ref<ProductListItem[]>([])
const bannerLoading = ref(true)
const productLoading = ref(true)
const bannerError = ref('')
const productError = ref('')
const bannerIndex = ref(0)
const currentBanner = computed(() => banners.value[bannerIndex.value])
let bannerSequence = 0
let productSequence = 0
let disposed = false

async function loadBanners() {
    const sequence = ++bannerSequence
    bannerLoading.value = true
    bannerError.value = ''
    try {
        const result = await getBanners()
        if (disposed || sequence !== bannerSequence) return
        banners.value = result
        bannerIndex.value = 0
    } catch {
        if (disposed || sequence !== bannerSequence) return
        bannerError.value = '輪播載入失敗，請稍後再試。'
    } finally {
        if (!disposed && sequence === bannerSequence) bannerLoading.value = false
    }
}

async function loadProducts() {
    const sequence = ++productSequence
    productLoading.value = true
    productError.value = ''
    try {
        const result = await getRecommendedProducts()
        if (disposed || sequence !== productSequence) return
        products.value = result
    } catch {
        if (disposed || sequence !== productSequence) return
        productError.value = '推薦商品載入失敗，請稍後再試。'
    } finally {
        if (!disposed && sequence === productSequence) productLoading.value = false
    }
}

onMounted(() => { void loadBanners(); void loadProducts() })
onBeforeUnmount(() => { disposed = true; ++bannerSequence; ++productSequence })
</script>

<template>
  <div class="container py-5">
    <h1>首頁</h1>
    <section class="my-4" aria-label="輪播">
      <p v-if="bannerLoading" role="status">輪播載入中…</p>
      <div v-else-if="bannerError" role="alert">
        <p>{{ bannerError }}</p>
        <button type="button" class="btn btn-outline-dark" @click="loadBanners">重試輪播</button>
      </div>
      <template v-else-if="currentBanner">
        <img :src="currentBanner.image_url" :alt="currentBanner.title" class="img-fluid w-100">
        <h2 class="h4 mt-3">{{ currentBanner.title }}</h2>
        <p v-if="currentBanner.subtitle">{{ currentBanner.subtitle }}</p>
        <RouterLink v-if="currentBanner.button_text && currentBanner.link_url"
          :to="currentBanner.link_url" class="btn btn-dark">{{ currentBanner.button_text }}</RouterLink>
        <div v-if="banners.length > 1" class="d-flex gap-2 mt-3">
          <button type="button" class="btn btn-outline-dark" :disabled="bannerIndex === 0"
            @click="bannerIndex--">上一張</button>
          <button type="button" class="btn btn-outline-dark" :disabled="bannerIndex === banners.length - 1"
            @click="bannerIndex++">下一張</button>
        </div>
      </template>
      <p v-else class="text-muted">目前沒有輪播內容。</p>
    </section>
    <section aria-labelledby="recommended-title">
      <h2 id="recommended-title" class="h4">推薦商品</h2>
      <p v-if="productLoading" role="status">推薦商品載入中…</p>
      <div v-else-if="productError" role="alert">
        <p>{{ productError }}</p>
        <button type="button" class="btn btn-outline-dark" @click="loadProducts">重試推薦商品</button>
      </div>
      <div v-else-if="products.length" class="row g-3">
        <div v-for="product in products" :key="product.id" class="col-12 col-sm-6 col-lg-4">
          <ProductCard :product="product" />
        </div>
      </div>
      <p v-else class="text-muted">目前沒有推薦商品。</p>
    </section>
  </div>
</template>
