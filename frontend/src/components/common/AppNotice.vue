<script setup lang="ts">
withDefaults(defineProps<{ tone?: 'success' | 'error' | 'warning'; title?: string }>(), { tone: 'error' })
const titles = { success: '操作完成', error: '需要處理', warning: '請留意' }
const icons = { success: '✓', error: '!', warning: '!' }
</script>

<template>
  <div class="hf-notice" :class="`hf-notice--${tone}`" :role="tone === 'success' ? 'status' : 'alert'" aria-atomic="true">
    <span class="hf-notice-icon" aria-hidden="true">{{ icons[tone] }}</span>
    <div class="hf-notice-content"><p class="hf-notice-title">{{ title ?? titles[tone] }}</p><slot /></div>
  </div>
</template>

<style scoped>
.hf-notice { --notice-ink: #923b35; --notice-bg: #fcf2f0; display: flex; gap: .8rem; padding: 1rem 1.15rem; margin-bottom: 1rem; border: 1px solid var(--hf-stone, #D8D3CA); border-left: 3px solid var(--notice-ink); border-radius: .3rem; background: var(--notice-bg); color: var(--hf-charcoal, #2E2E2D); line-height: 1.7; }
.hf-notice--success { --notice-ink: #39604a; --notice-bg: #f1f6f1; }
.hf-notice--warning { --notice-ink: #795a2f; --notice-bg: #faf5e9; }
.hf-notice-icon { display: grid; place-items: center; flex: 0 0 1.5rem; height: 1.5rem; border: 1px solid currentColor; border-radius: 50%; color: var(--notice-ink); font-weight: 700; }
.hf-notice-content { min-width: 0; overflow-wrap: anywhere; }
.hf-notice-title { color: var(--notice-ink); font-weight: 600; margin: 0 0 .25rem; }
</style>
