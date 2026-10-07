<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue'
import BannerCarousel from '@/components/home/BannerCarousel.vue'
import ProductCard from '@/components/product/ProductCard.vue'
import { getBanners, getRecommendedProducts } from '@/services/homeContentService'
import { getCategories } from '@/services/categoryService'
import type { Category } from '@/types/category'
import type { Banner } from '@/types/homeContent'
import type { ProductListItem } from '@/types/product'

const banners = ref<Banner[]>([])
const products = ref<ProductListItem[]>([])
const categories = ref<Category[]>([])
const bannerLoading = ref(true)
const productLoading = ref(true)
const bannerError = ref('')
const productError = ref('')
const categoryLoading = ref(true)
const categoryError = ref('')
let bannerSequence = 0
let productSequence = 0
let categorySequence = 0
let disposed = false

async function loadBanners() {
    const sequence = ++bannerSequence
    bannerLoading.value = true
    bannerError.value = ''
    try {
        const result = await getBanners()
        if (disposed || sequence !== bannerSequence) return
        banners.value = result
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

async function loadCategories() {
    const sequence = ++categorySequence
    categoryLoading.value = true
    categoryError.value = ''
    try {
        const result = await getCategories()
        if (disposed || sequence !== categorySequence) return
        categories.value = result
    } catch {
        if (disposed || sequence !== categorySequence) return
        categoryError.value = '商品分類載入失敗，請稍後再試。'
    } finally {
        if (!disposed && sequence === categorySequence) categoryLoading.value = false
    }
}

onMounted(() => { void loadBanners(); void loadCategories(); void loadProducts() })
onBeforeUnmount(() => { disposed = true; ++bannerSequence; ++categorySequence; ++productSequence })
</script>

<template>
  <div class="container py-5">
    <section class="my-4" aria-label="輪播">
      <p v-if="bannerLoading" role="status">輪播載入中…</p>
      <div v-else-if="bannerError" role="alert">
        <p>{{ bannerError }}</p>
        <button type="button" class="btn btn-outline-dark" @click="loadBanners">重試輪播</button>
      </div>
      <BannerCarousel v-else :banners="banners" />
    </section>
    <section class="my-4" aria-labelledby="brand-title">
      <h2 id="brand-title" class="h3">把訓練，帶回自己的生活</h2>
      <p>從居家重訓器材到訓練配件，找到適合日常練習的選擇。</p>
      <ul class="row list-unstyled g-3">
        <li class="col-12 col-md-4">居家訓練導向</li>
        <li class="col-12 col-md-4">依訓練需求選購</li>
        <li class="col-12 col-md-4">清楚查看器材規格</li>
      </ul>
      <RouterLink to="/products" class="btn btn-dark">開始選購</RouterLink>
    </section>
    <section class="my-4" aria-labelledby="categories-title">
      <h2 id="categories-title" class="h4">商品分類</h2>
      <p v-if="categoryLoading" role="status">商品分類載入中…</p>
      <div v-else-if="categoryError" role="alert">
        <p>{{ categoryError }}</p>
        <button type="button" class="btn btn-outline-dark" @click="loadCategories">重試商品分類</button>
      </div>
      <div v-else-if="categories.length" class="row g-3">
        <div v-for="category in categories" :key="category.id" class="col-12 col-md-6">
          <RouterLink :to="{ path: '/products', query: { parent_category_id: category.id } }"
            class="card h-100 text-decoration-none text-dark">
            <div class="card-body">
              <h3 class="h5">{{ category.name }}</h3>
              <span>瀏覽商品</span>
            </div>
          </RouterLink>
        </div>
      </div>
      <div v-else>
        <p>歡迎先瀏覽全部商品，尋找適合的訓練器材。</p>
        <RouterLink to="/products" class="btn btn-dark">開始選購</RouterLink>
      </div>
    </section>
    <section v-if="productLoading || productError || products.length" class="my-4" aria-labelledby="recommended-title">
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
    </section>
    <section class="my-4" aria-label="開始選購">
      <RouterLink to="/products" class="btn btn-dark">開始選購</RouterLink>
    </section>
  </div>
</template>
