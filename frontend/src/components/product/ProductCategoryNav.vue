<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'

import { getCategories } from '@/services/categoryService'
import type { Category } from '@/types/category'

const route = useRoute()

const categories = ref<Category[]>([])
const isLoading = ref(false)
const errorMessage = ref('')

const isAllProductsActive = () => {
  return !route.query.parent_category_id && !route.query.category_id
}

const isParentActive = (id: number) => {
  return Number(route.query.parent_category_id) === id
}

const isSubcategoryActive = (id: number) => {
  return Number(route.query.category_id) === id
}

const fetchCategories = async () => {
  isLoading.value = true
  errorMessage.value = ''

  try {
    categories.value = await getCategories()
  } catch (error) {
    console.error(error)
    errorMessage.value = '分類載入失敗'
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  fetchCategories()
})
</script>

<template>
  <div class="product-category-nav">
    <h2 class="h5 mb-3">商品分類</h2>

    <p v-if="isLoading" class="text-muted">分類載入中...</p>

    <p v-else-if="errorMessage" class="text-danger">
      {{ errorMessage }}
    </p>

    <div v-else-if="categories.length === 0" class="text-muted">目前沒有商品分類。</div>

    <ul v-else class="list-unstyled">
      <li class="mb-3">
        <RouterLink :to="{ name: 'products' }" 
        class="text-decoration-none"
        :class="{'fw-bold text-dark text-decoration-underline': isAllProductsActive()}"
        >
          全部商品
        </RouterLink>
      </li>

      <li v-for="category in categories" :key="category.id" class="mb-3">
        <RouterLink
          :to="{
            name: 'products',
            query: {
              parent_category_id: category.id,
            },
          }"
          class="fw-bold text-decoration-none"
          :class=" {'text-dark text-decoration-underline': isParentActive(category.id)} "
        >
          {{ category.name }}
        </RouterLink>

        <ul class="list-unstyled ps-3 mt-2">
          <li v-for="subcategory in category.children" :key="subcategory.id" class="mb-2">
            <RouterLink
              :to="{
                name: 'products',
                query: {
                  category_id: subcategory.id,
                },
              }"
              class="text-decoration-none"
              :class=" {'fw-bold text-dark text-decoration-underline': isSubcategoryActive(subcategory.id)} "
            >
              {{ subcategory.name }}
            </RouterLink>
          </li>
        </ul>
      </li>
    </ul>
  </div>
</template>

<style scoped>
.product-category-nav a { color: var(--hf-charcoal, #2e2e2d); display: inline-block; padding-block: .35rem; min-height: 36px; overflow-wrap: anywhere; }
.product-category-nav a:hover { text-decoration: underline !important; text-underline-offset: .3em; }
.product-category-nav a.text-decoration-underline { text-decoration-color: var(--hf-gold) !important; text-underline-offset: .3em; }
</style>
