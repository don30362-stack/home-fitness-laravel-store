<script setup lang="ts">
import { RouterLink } from 'vue-router'
import AppHeader from '@/components/layout/AppHeader.vue'
import AppFooter from '@/components/layout/AppFooter.vue'
</script>
<template>
  <div class="storefront-shell d-flex flex-column min-vh-100">
    <AppHeader />
    <main class="container py-5 hf-functional-page hf-not-found">
      <p class="hf-eyebrow">404 · PAGE NOT FOUND</p>
      <h1>找不到你要瀏覽的頁面</h1>
      <p class="text-muted">可以返回首頁，或繼續探索訓練器材。</p>
      <div class="d-flex flex-wrap justify-content-center gap-3 mt-4">
        <RouterLink class="btn btn-dark" :to="{ name: 'home' }">返回首頁</RouterLink>
        <RouterLink class="btn btn-outline-dark" :to="{ name: 'products' }">前往商品</RouterLink>
      </div>
    </main>
    <AppFooter />
  </div>
</template>
<style scoped>
.hf-not-found { text-align: center; padding-block: clamp(4rem, 12vw, 8rem) !important; }
.hf-not-found h1 { font-size: clamp(1.7rem, 4vw, 2.6rem); margin-block: 1rem; line-height: 1.5; }
</style>
