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
  <div v-if="currentBanner" aria-roledescription="輪播">
    <img :src="currentBanner.image_url" :alt="currentBanner.title" class="img-fluid w-100">
    <h1 class="h2 mt-3">{{ currentBanner.title }}</h1>
    <p v-if="currentBanner.subtitle">{{ currentBanner.subtitle }}</p>
    <RouterLink v-if="currentBanner.button_text && currentBanner.link_url"
      :to="currentBanner.link_url" class="btn btn-dark">{{ currentBanner.button_text }}</RouterLink>
    <div v-if="banners.length > 1" class="d-flex flex-wrap gap-2 mt-3" aria-label="輪播控制">
      <button type="button" class="btn btn-outline-dark" @click="move(-1)">上一張</button>
      <button v-for="(banner, position) in banners" :key="banner.id" type="button"
        class="btn" :class="position === index ? 'btn-dark' : 'btn-outline-dark'"
        :aria-label="`顯示第 ${position + 1} 張輪播`" :aria-pressed="position === index"
        @click="index = position">{{ position + 1 }}</button>
      <button type="button" class="btn btn-outline-dark" @click="move(1)">下一張</button>
    </div>
  </div>
  <div v-else>
    <h1 class="h2">把訓練，帶回自己的生活</h1>
    <p>從居家重訓器材到訓練配件，找到適合日常練習的選擇。</p>
    <RouterLink to="/products" class="btn btn-dark">開始選購</RouterLink>
  </div>
</template>
