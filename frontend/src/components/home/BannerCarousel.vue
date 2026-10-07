<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { Banner } from '@/types/homeContent'

const props = defineProps<{ banners: Banner[] }>()
const index = ref(0)
const currentBanner = computed(() => props.banners[index.value])
watch(() => props.banners, () => { index.value = 0 })

function move(direction: number) {
  if (props.banners.length < 2) return
  index.value = (index.value + direction + props.banners.length) % props.banners.length
}
</script>

<template>
  <div v-if="currentBanner" class="hero" aria-roledescription="輪播">
    <img :src="currentBanner.image_url" :alt="currentBanner.title" class="hero-image"
      loading="eager" decoding="async" :fetchpriority="index === 0 ? 'high' : 'auto'">
    <div class="hero-content">
      <h1>{{ currentBanner.title }}</h1>
      <p v-if="currentBanner.subtitle">{{ currentBanner.subtitle }}</p>
      <RouterLink v-if="currentBanner.button_text && currentBanner.link_url"
        :to="currentBanner.link_url" class="hf-button hf-button--light">{{ currentBanner.button_text }}</RouterLink>
    </div>
    <div v-if="banners.length > 1" class="hero-controls" aria-label="輪播控制">
      <button type="button" class="hero-direction" aria-label="上一張" @click="move(-1)">上一張</button>
      <div class="hero-indicators">
      <button v-for="(banner, position) in banners" :key="banner.id" type="button"
        class="hero-indicator"
        :aria-label="`顯示第 ${position + 1} 張輪播`" :aria-pressed="position === index"
        @click="index = position"><span class="visually-hidden">{{ position + 1 }}</span></button>
      </div>
      <button type="button" class="hero-direction" aria-label="下一張" @click="move(1)">下一張</button>
    </div>
  </div>
  <div v-else class="hero hero--fallback">
    <div class="hero-content">
      <h1>把訓練，帶回自己的生活</h1>
      <p>從居家重訓器材到訓練配件，找到適合日常練習的選擇。</p>
      <RouterLink to="/products" class="hf-button hf-button--light">開始選購</RouterLink>
    </div>
  </div>
</template>

<style scoped>
.hero { position: relative; isolation: isolate; display: grid; grid-template-rows: 1fr auto; width: 100%; min-width: 0; aspect-ratio: 8 / 3; min-height: 28rem; background: var(--hf-charcoal); color: #fff; }
.hero-image { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; object-position: center; z-index: -2; }
.hero::before { content: ''; position: absolute; inset: 0; background: linear-gradient(90deg, rgb(0 0 0 / 78%) 0%, rgb(0 0 0 / 48%) 36%, transparent 75%); z-index: -1; }
.hero-content { align-self: center; padding: 2.5rem max(1.5rem, calc((100% - 1116px) / 2)); box-sizing: content-box; width: min(39%, 31rem); min-width: 0; overflow-wrap: anywhere; }
.hero h1 { text-wrap: balance; font-size: clamp(2rem, 4vw, 3.5rem); line-height: 1.25; font-weight: 700; margin-bottom: 1.25rem; }
.hero p { font-size: 1.05rem; line-height: 1.8; margin-bottom: 1.5rem; }
.hero-controls { display: flex; align-items: center; justify-content: flex-end; gap: 1rem; padding: .5rem max(1.5rem, calc((100% - 1116px) / 2)) 1.25rem; }
.hero-direction { flex-shrink: 0; white-space: nowrap; min-height: 44px; padding: .5rem .85rem; border: 1px solid rgb(255 255 255 / 50%); background: rgb(0 0 0 / 30%); color: #fff; transition: background 200ms; }
.hero-direction:hover { background: var(--hf-ivory); color: var(--hf-charcoal); }
.hero-indicators { display: flex; flex-wrap: wrap; margin-right: auto; min-width: 0; }
.hero-indicator { flex-shrink: 0; display: grid; place-items: center; width: 1.5rem; height: 44px; padding: 0; border: 0; background: transparent; }
.hero-indicator::before { content: ''; width: 1.2rem; height: 2px; background: rgb(255 255 255 / 55%); }
.hero-indicator[aria-pressed='true']::before { background: var(--hf-gold); height: 4px; }
.hero :focus-visible { outline: 3px solid #fff; outline-offset: 4px; }
.hero--fallback { min-height: 25rem; }
@media (max-width: 991.98px) {
 .hero { aspect-ratio: auto; min-height: 0; }
 .hero-image { position: static; aspect-ratio: 16 / 10; height: auto; z-index: auto; }
 .hero::before { display: none; }
 .hero-content { box-sizing: border-box; width: 100%; padding: 2rem 1.5rem; }
 .hero h1 { text-wrap: balance; font-size: clamp(1.75rem, 4vw, 2rem); }
 .hero-controls { padding: 0 1.5rem 1.5rem; gap: .5rem; }
 .hero--fallback { min-height: 20rem; }
}
@media (prefers-reduced-motion: reduce) { .hero-direction { transition: none; } }
</style>
