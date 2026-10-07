<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref } from 'vue'
import editorialImage from '@/assets/images/cta_01.webp'
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
  <div class="home-page">
    <section class="home-hero" aria-label="輪播">
      <p v-if="bannerLoading" role="status">輪播載入中…</p>
      <div v-else-if="bannerError" role="alert">
        <p>{{ bannerError }}</p>
        <button type="button" class="hf-button" @click="loadBanners">重試輪播</button>
      </div>
      <BannerCarousel v-else :banners="banners" />
    </section>
    <section class="home-brand" aria-labelledby="brand-title">
      <div class="container editorial-grid">
        <img :src="editorialImage" alt="居家訓練空間中的健身椅與啞鈴器材" width="1376" height="768" loading="lazy" decoding="async" class="editorial-image">
        <div class="editorial-copy">
          <p class="eyebrow">HOME FIT / HOME TRAINING</p>
          <h2 id="brand-title">把訓練，帶回自己的生活</h2>
          <p>從居家重訓器材到訓練配件，找到適合日常練習的選擇。</p>
          <ul class="home-benefits">
            <li><span>01</span>居家訓練導向</li>
            <li><span>02</span>依訓練需求選購</li>
            <li><span>03</span>清楚查看器材規格</li>
          </ul>
        </div>
      </div>
    </section>
    <section class="container home-section" aria-labelledby="categories-title">
      <p class="eyebrow">EXPLORE YOUR TRAINING</p><h2 id="categories-title">商品分類</h2>
      <p v-if="categoryLoading" role="status">商品分類載入中…</p>
      <div v-else-if="categoryError" role="alert">
        <p>{{ categoryError }}</p>
        <button type="button" class="hf-button" @click="loadCategories">重試商品分類</button>
      </div>
      <div v-else-if="categories.length" class="row g-3">
        <div v-for="(category, position) in categories" :key="category.id" class="col-12 col-md-6 col-lg-3">
          <RouterLink :to="{ path: '/products', query: { parent_category_id: category.id } }"
            class="category-card">
            <div class="category-copy">
              <span class="category-index">{{ String(position + 1).padStart(2, '0') }}</span>
              <h3>{{ category.name }}</h3>
              <span class="category-browse">瀏覽商品 <span aria-hidden="true">→</span></span>
            </div>
          </RouterLink>
        </div>
      </div>
      <div v-else>
        <p>歡迎先瀏覽全部商品，尋找適合的訓練器材。</p>
        <RouterLink to="/products" class="hf-button">開始選購</RouterLink>
      </div>
    </section>
    <section v-if="productLoading || productError || products.length" class="container home-section" aria-labelledby="recommended-title">
      <p class="eyebrow">SELECTED EQUIPMENT</p><h2 id="recommended-title">推薦商品</h2>
      <p v-if="productLoading" role="status">推薦商品載入中…</p>
      <div v-else-if="productError" role="alert">
        <p>{{ productError }}</p>
        <button type="button" class="hf-button" @click="loadProducts">重試推薦商品</button>
      </div>
      <div v-else-if="products.length" class="row g-3">
        <div v-for="product in products" :key="product.id" class="col-12 col-md-6 col-lg-3">
          <ProductCard :product="product" />
        </div>
      </div>
    </section>
    <section class="home-cta" aria-label="開始選購">
      <div class="container cta-inner"><h2>把訓練，帶回自己的生活</h2>
      <RouterLink to="/products" class="hf-button hf-button--light">開始選購</RouterLink></div>
    </section>
  </div>
</template>

<style scoped>
.home-page { min-width: 0; }
.home-hero > [role] { padding: 3rem 1.5rem; background: var(--hf-ivory); }
.home-brand { background: var(--hf-ivory); padding-block: var(--hf-section-space); }
.editorial-grid { display: grid; grid-template-columns: 1.2fr 1fr; align-items: center; gap: clamp(2rem, 5vw, 4rem); }
.editorial-image { width: 100%; height: auto; }
.editorial-copy { min-width: 0; }
.eyebrow { font-size: .75rem; letter-spacing: .13em; color: #856239; font-weight: 600; margin-bottom: 1rem; }
h2 { font-size: clamp(1.75rem, 2.6vw, 2.25rem); font-weight: 600; line-height: 1.4; margin-bottom: 1.5rem; overflow-wrap: anywhere; }
.editorial-copy > p:not(.eyebrow) { line-height: 1.9; }
.home-benefits { list-style: none; padding: 0; margin: 1.5rem 0 0; }
.home-benefits li { border-top: 1px solid var(--hf-stone); padding: .8rem 0; display: flex; gap: 1rem; font-size: .95rem; }
.home-benefits span { color: #856239; font-size: .8rem; }
.home-section { padding-block: var(--hf-section-space); }
.home-section + .home-section { padding-top: 0; }
.category-card { display: block; height: 100%; color: var(--hf-charcoal); background: var(--hf-ivory); border-top: 2px solid var(--hf-gold); transition: background 200ms, color 200ms; overflow-wrap: anywhere; }
.category-copy { padding: 1.75rem; }
.category-index { font-size: .8rem; color: #856239; }
.category-card h3 { font-size: 1.6rem; font-weight: 600; margin: 2rem 0; }
.category-browse { display: flex; align-items: center; justify-content: space-between; font-size: .9rem; }
.category-browse span { color: var(--hf-gold); transition: transform 200ms; }
.category-card:hover { background: var(--hf-charcoal); color: var(--hf-white); }
.category-card:hover .category-index { color: var(--hf-gold); }
.category-card:hover .category-browse span { transform: translateX(3px); }
.home-cta { padding-block: var(--hf-section-space); background: var(--hf-charcoal); color: var(--hf-white); }
.cta-inner { display: flex; align-items: center; justify-content: space-between; gap: 2rem; }
.cta-inner h2 { max-width: 30rem; margin: 0; border-left: 2px solid var(--hf-gold); padding-left: 1.5rem; }
@media (max-width: 991.98px) { .editorial-grid { grid-template-columns: 1fr; } }
@media (max-width: 767.98px) { .cta-inner { flex-direction: column; align-items: flex-start; } .category-card h3 { margin-block: 1.5rem; } }
@media (prefers-reduced-motion: reduce) { .category-card, .category-browse span { transition: none; } .category-card:hover .category-browse span { transform: none; } }
</style>
