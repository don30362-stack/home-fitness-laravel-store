<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import { getAdminCategories } from '@/services/adminCategoryService'
import type { AdminCategory, AdminCategoryChild, AdminCategoryMutationResponse } from '@/types/adminCategory'
import type { ApiErrorResponse } from '@/types/api'
import CategoryForm from '@/components/admin/CategoryForm.vue'

const categories = ref<AdminCategory[]>([])
const loading = ref(false)
const error = ref('')
const success = ref('')
const form = ref<{ category: AdminCategory | AdminCategoryChild | null; role: 'root' | 'child' } | null>(null)
const formContext = ref(0)
const formSubmitting = ref(false)
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
const openForm = (role: 'root' | 'child', category: AdminCategory | AdminCategoryChild | null = null) => {
  if (formSubmitting.value) return
  formContext.value++
  success.value = ''
  form.value = { role, category }
}
const closeForm = () => {
  if (formSubmitting.value) return
  formContext.value++
  form.value = null
}
const saved = async (response: AdminCategoryMutationResponse, context: number) => {
  if (context !== formContext.value) return
  success.value = response.message
  closeForm()
  await loadCategories()
}
onMounted(loadCategories)
onBeforeUnmount(() => { requestSequence++ })
</script>

<template>
  <section aria-labelledby="category-management-title">
    <h1 id="category-management-title" class="h3 mb-4">商品分類管理</h1>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <button type="button" class="btn btn-primary" :disabled="formSubmitting || loading || !!error" @click="openForm('root')">新增主分類</button>
      <button type="button" class="btn btn-outline-primary" :disabled="formSubmitting || loading || !!error" @click="openForm('child')">新增子分類</button>
    </div>
    <p v-if="success" role="status" class="alert alert-success">{{ success }}</p>
    <CategoryForm v-if="form" :key="formContext" :context="formContext" :categories="categories"
      :category="form.category" :role="form.role" @saved="saved" @cancel="closeForm"
      @submitting-change="formSubmitting = $event" />
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
          <button type="button" class="btn btn-sm btn-outline-secondary mb-2" :disabled="formSubmitting" @click="openForm('root', category)">編輯主分類</button>
          <div class="d-flex flex-wrap gap-3 small">
            <span>狀態：{{ statusLabel(category.status) }}</span>
            <span>排序：{{ category.sort_order }}</span>
            <span>子分類：{{ category.children_count }}</span>
          </div>
        </div>
        <ul v-if="category.children.length" class="list-group list-group-flush">
          <li v-for="child in category.children" :key="child.id" class="list-group-item">
            <h3 class="h6 mb-2">{{ child.name }}</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary mb-2" :disabled="formSubmitting" @click="openForm('child', child)">編輯子分類</button>
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
