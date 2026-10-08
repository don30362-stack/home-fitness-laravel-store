<script setup lang="ts">
import { computed } from 'vue'

const props = defineProps<{
  currentPage: number
  lastPage: number
  label?: string
}>()

const emit = defineEmits<{
  changePage: [page: number]
}>()

const pages = computed(() => {
  const last = Math.max(1, Math.floor(props.lastPage))
  const current = Math.min(last, Math.max(1, Math.floor(props.currentPage)))
  const selected = [...new Set([1, current - 1, current, current + 1, last])]
    .filter(page => page >= 1 && page <= last).sort((a, b) => a - b)
  const result: (number | string)[] = []
  for (const page of selected) {
    const previous = result[result.length - 1]
    if (typeof previous === 'number' && page - previous === 2) result.push(previous + 1)
    else if (typeof previous === 'number' && page - previous > 2) result.push(`gap-${page}`)
    result.push(page)
  }
  return result
})

const changePage = (page: number) => {
  if (!Number.isInteger(page) || page < 1 || page > props.lastPage || page === props.currentPage) {
    return
  }

  emit('changePage', page)
}
</script>

<template>
  <nav v-if="lastPage > 1" :aria-label="label ?? '商品分頁'">
    <ul class="pagination justify-content-center mt-5">
      <li class="page-item" :class="{ disabled: currentPage === 1 }">
        <button
          class="page-link"
          type="button"
          :disabled="currentPage === 1"
          @click="changePage(currentPage - 1)"
        >
          上一頁
        </button>
      </li>

      <li
        v-for="page in pages"
        :key="page"
        class="page-item"
        :class="{ active: page === currentPage }"
      >
        <span v-if="typeof page !== 'number'" class="page-link" aria-hidden="true">…</span>
        <button v-else class="page-link" type="button" :aria-current="page === currentPage ? 'page' : undefined" @click="changePage(page)">
          {{ page }}
        </button>
      </li>

      <li class="page-item" :class="{ disabled: currentPage === lastPage }">
        <button
          class="page-link"
          type="button"
          :disabled="currentPage === lastPage"
          @click="changePage(currentPage + 1)"
        >
          下一頁
        </button>
      </li>
    </ul>
  </nav>
</template>

<style scoped>
/* Keep the finite window contained on narrow screens. */
.pagination { flex-wrap: wrap; gap: .4rem; }
.page-link { min-width: 44px; min-height: 44px; display: grid; place-items: center; border-radius: .25rem !important; }
.page-item + .page-item .page-link { margin-left: 0; }
.page-link:focus-visible { outline: 3px solid var(--hf-gold, #0d6efd); outline-offset: 3px; box-shadow: none; }
:global(.storefront-shell) .pagination { --bs-pagination-color: var(--hf-charcoal); --bs-pagination-border-color: var(--hf-stone); --bs-pagination-hover-color: var(--hf-black); --bs-pagination-hover-bg: var(--hf-ivory); --bs-pagination-hover-border-color: var(--hf-charcoal); --bs-pagination-active-bg: var(--hf-charcoal); --bs-pagination-active-border-color: var(--hf-charcoal); --bs-pagination-focus-color: var(--hf-charcoal); --bs-pagination-focus-bg: var(--hf-ivory); }
</style>
