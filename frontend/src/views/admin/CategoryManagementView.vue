<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import { getAdminCategories } from '@/services/adminCategoryService'
import type { AdminCategory } from '@/types/adminCategory'
import type { ApiErrorResponse } from '@/types/api'

const categories = ref<AdminCategory[]>([])
const loading = ref(false)
const error = ref('')
let requestSequence = 0

const loadCategories = async () => {
  const sequence = ++requestSequence
  loading.value = true
  error.value = ''
  try {
    const response = await getAdminCategories()
    if (sequence === requestSequence) categories.value = response.data
  } catch (failure: unknown) {
    if (sequence !== requestSequence) return
    error.value = axios.isAxiosError<ApiErrorResponse>(failure)
      ? failure.response?.data?.message || '分類載入失敗，請稍後重試'
      : '分類載入失敗，請稍後重試'
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

const statusLabel = (status: string) => status === 'active' ? '啟用' : status === 'inactive' ? '停用' : status
onMounted(loadCategories)
onBeforeUnmount(() => { requestSequence++ })
</script>

<template>
  <section aria-labelledby="category-management-title">
    <h1 id="category-management-title" class="h3 mb-4">商品分類管理</h1>
    <p v-if="loading" role="status">分類載入中…</p>
    <div v-else-if="error" class="alert alert-danger" role="alert">
      <p class="mb-2">{{ error }}</p>
      <button type="button" class="btn btn-outline-danger" @click="loadCategories">重試</button>
    </div>
    <p v-else-if="categories.length === 0" class="text-muted">目前沒有分類</p>
    <div v-else class="d-grid gap-3">
      <article v-for="category in categories" :key="category.id" class="card">
        <div class="card-header">
          <h2 class="h5 mb-2">{{ category.name }}</h2>
          <div class="d-flex flex-wrap gap-3 small">
            <span>狀態：{{ statusLabel(category.status) }}</span>
            <span>排序：{{ category.sort_order }}</span>
            <span>子分類：{{ category.children_count }}</span>
          </div>
        </div>
        <ul v-if="category.children.length" class="list-group list-group-flush">
          <li v-for="child in category.children" :key="child.id" class="list-group-item">
            <h3 class="h6 mb-2">{{ child.name }}</h3>
            <div class="d-flex flex-wrap gap-3 small">
              <span>狀態：{{ statusLabel(child.status) }}</span>
              <span>排序：{{ child.sort_order }}</span>
              <span>商品：{{ child.product_count }}</span>
            </div>
          </li>
        </ul>
        <p v-else class="card-body mb-0 text-muted">此分類尚無子分類</p>
      </article>
    </div>
  </section>
</template>
