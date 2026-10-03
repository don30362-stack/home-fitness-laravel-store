<script setup lang="ts">
defineProps<{ title: string; stage: number }>()
</script>

<template>
  <section><h1 class="h3">{{ title }}</h1><p>尚未實作，預計於 Stage {{ stage }} 建立。</p></section>
</template>
