<script setup lang="ts">
import { computed, ref } from 'vue';

import type { ProductListItem } from '@/types/product';

const props = defineProps<{ product: ProductListItem }>()

const imageLoadFailed = ref(false)

const primaryImage = computed(() => {
    return props.product.images.find((image) => image.is_primary)
})
</script>

<template>
 <article class="product-card">
  <div class="product-image-stage">
   <img v-if="primaryImage && !imageLoadFailed" :src="primaryImage.image_url" :alt="product.name" loading="lazy" decoding="async" @error="imageLoadFailed = true">
   <span v-else class="product-placeholder">商品圖片準備中</span>
  </div>
  <div class="product-copy">
   <h2 class="product-name">{{ product.name }}</h2>
   <p class="product-price">NT$ {{ Number(product.price).toLocaleString('zh-TW') }}</p>
   <RouterLink class="product-detail-link" :to="{ name: 'product-detail', params: { id: product.id } }">查看商品 <span aria-hidden="true">→</span></RouterLink>
  </div>
 </article>
</template>
<style scoped>
.product-card { height: 100%; display: flex; flex-direction: column; color: var(--hf-charcoal, #2E2E2D); }
.product-image-stage { aspect-ratio: 1; display: grid; place-items: center; background: var(--hf-ivory, #F5F2EC); overflow: hidden; }
.product-image-stage img { width: 100%; height: 100%; object-fit: contain; padding: .5rem; transition: transform 200ms; }
.product-card:hover img { transform: scale(1.02); }
.product-placeholder { padding: 1rem; color: #666; }
.product-copy { padding: 1.25rem 0; display: flex; flex: 1; flex-direction: column; }
.product-name { font-size: 1.1rem; font-weight: 600; line-height: 1.5; overflow-wrap: anywhere; margin: 0 0 .5rem; }
.product-price { font-size: 1rem; margin-bottom: 1rem; }
.product-detail-link { color: inherit; margin-top: auto; align-self: flex-start; padding-bottom: .25rem; border-bottom: 1px solid var(--hf-stone, #D8D3CA); font-size: .9rem; }
.product-detail-link span { display: inline-block; color: #8a6638; margin-left: .5rem; transition: transform 200ms; }
.product-detail-link:hover span { transform: translateX(3px); }
.product-detail-link:focus-visible { outline: 3px solid var(--hf-gold, #BD935A); outline-offset: 4px; }
@media (prefers-reduced-motion: reduce) { .product-image-stage img, .product-detail-link span { transition: none; transform: none; } .product-card:hover img, .product-detail-link:hover span { transform: none; } }
</style>
