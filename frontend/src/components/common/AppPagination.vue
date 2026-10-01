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
  return Array.from({ length: props.lastPage }, (_, index) => index + 1)
})

const changePage = (page: number) => {
  if (page < 1 || page > props.lastPage || page === props.currentPage) {
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
        <button class="page-link" type="button" @click="changePage(page)">
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
