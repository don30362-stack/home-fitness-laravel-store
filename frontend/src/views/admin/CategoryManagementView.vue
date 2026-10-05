<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, computed } from 'vue'
import axios from 'axios'
import { getAdminCategories, updateAdminCategoryStatus, deleteAdminCategory } from '@/services/adminCategoryService'
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
const statusSubmitting = ref(false)
const deleteSubmitting = ref(false)
const deleteTarget = ref<{ id: number; name: string; role: 'root' | 'child' } | null>(null)
const deleteError = ref('')
const mutationPending = computed(() => formSubmitting.value || statusSubmitting.value || deleteSubmitting.value)
const statusTarget = ref<{ id: number; name: string; status: 'active' | 'inactive'; role: 'root' | 'child' } | null>(null)
const statusError = ref('')
let disposed = false
const openStatus = (category: AdminCategory | AdminCategoryChild, role: 'root' | 'child') => {
  if (mutationPending.value) return
  closeForm()
  deleteTarget.value = null
  success.value = ''
  statusError.value = ''
  statusTarget.value = { id: category.id, name: category.name, status: category.status === 'active' ? 'inactive' : 'active', role }
}
const openDelete = (category: AdminCategory | AdminCategoryChild, role: 'root' | 'child') => {
  if (mutationPending.value) return
  closeForm()
  statusTarget.value = null
  success.value = ''
  deleteError.value = ''
  deleteTarget.value = { id: category.id, name: category.name, role }
}
const cancelDelete = () => { if (!mutationPending.value) deleteTarget.value = null }
const submitDelete = async () => {
  if (mutationPending.value || !deleteTarget.value) return
  const target = { ...deleteTarget.value }
  deleteSubmitting.value = true
  deleteError.value = ''
  try {
    const response = await deleteAdminCategory(target.id)
    if (disposed) return
    success.value = response.message
    deleteTarget.value = null
    await loadCategories()
  } catch (failure: unknown) {
    if (disposed) return
    deleteError.value = axios.isAxiosError<ApiErrorResponse>(failure)
      ? Object.values(failure.response?.data?.errors || {}).flat().join(' ') || failure.response?.data?.message || '分類刪除失敗，請稍後重試'
      : '分類刪除失敗，請稍後重試'
  } finally {
    if (!disposed) deleteSubmitting.value = false
  }
}
const cancelStatus = () => { if (!mutationPending.value) statusTarget.value = null }
const submitStatus = async () => {
  if (mutationPending.value || !statusTarget.value) return
  const target = { ...statusTarget.value }
  statusSubmitting.value = true
  statusError.value = ''
  try {
    const response = await updateAdminCategoryStatus(target.id, { status: target.status })
    if (disposed) return
    success.value = response.message
    statusTarget.value = null
    await loadCategories()
  } catch (failure: unknown) {
    if (disposed) return
    statusError.value = axios.isAxiosError<ApiErrorResponse>(failure)
      ? Object.values(failure.response?.data?.errors || {}).flat().join(' ') || failure.response?.data?.message || '分類狀態更新失敗，請稍後重試'
      : '分類狀態更新失敗，請稍後重試'
  } finally {
    if (!disposed) statusSubmitting.value = false
  }
}
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
  if (mutationPending.value) return
  statusTarget.value = null
  deleteTarget.value = null
  formContext.value++
  success.value = ''
  form.value = { role, category }
}
const closeForm = () => {
  if (mutationPending.value) return
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
onBeforeUnmount(() => { disposed = true; requestSequence++ })
</script>

<template>
  <section aria-labelledby="category-management-title">
    <h1 id="category-management-title" class="h3 mb-4">商品分類管理</h1>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <button type="button" class="btn btn-primary" :disabled="mutationPending || loading || !!error" @click="openForm('root')">新增主分類</button>
      <button type="button" class="btn btn-outline-primary" :disabled="mutationPending || loading || !!error" @click="openForm('child')">新增子分類</button>
    </div>
    <p v-if="success" role="status" class="alert alert-success">{{ success }}</p>
    <CategoryForm v-if="form" :key="formContext" :context="formContext" :categories="categories"
      :category="form.category" :role="form.role" @saved="saved" @cancel="closeForm"
      @submitting-change="formSubmitting = $event" />
    <div v-if="statusTarget" class="card card-body mb-3" role="dialog" aria-label="確認分類狀態">
      <h2 class="h5">{{ statusTarget.name }}：{{ statusTarget.status === 'inactive' ? '停用' : '啟用' }}</h2>
      <p v-if="statusTarget.status === 'inactive'">
        停用後此分類不再出現在前台有效分類，{{ statusTarget.role === 'root' ? '底下' : '該子分類的' }}商品暫時不可公開／不可購買。
        不會修改{{ statusTarget.role === 'root' ? '子分類或商品' : '商品' }}自己的 status。
      </p>
      <p v-else>只恢復此分類；商品是否可販售仍依主分類、子分類、商品、規格及庫存的最新狀態判斷。</p>
      <p v-if="statusError" class="alert alert-danger" role="alert">{{ statusError }}</p>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-primary" :disabled="mutationPending" @click="submitStatus">確認變更狀態</button>
        <button type="button" class="btn btn-outline-secondary" :disabled="mutationPending" @click="cancelStatus">取消狀態變更</button>
      </div>
    </div>
    <div v-if="deleteTarget" class="card card-body mb-3" role="dialog" aria-label="確認刪除分類">
      <h2 class="h5">刪除{{ deleteTarget.role === 'root' ? '主分類' : '子分類' }}：{{ deleteTarget.name }}</h2>
      <p>此操作會永久刪除此分類，無法復原。若分類仍有子分類或商品，後端會拒絕刪除。</p>
      <p v-if="deleteError" class="alert alert-danger" role="alert">{{ deleteError }}</p>
      <div class="d-flex gap-2">
        <button type="button" class="btn btn-danger" :disabled="mutationPending" @click="submitDelete">確認刪除分類</button>
        <button type="button" class="btn btn-outline-secondary" :disabled="mutationPending" @click="cancelDelete">取消刪除</button>
      </div>
    </div>
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
          <button type="button" class="btn btn-sm btn-outline-secondary mb-2" :disabled="mutationPending" @click="openForm('root', category)">編輯主分類</button>
          <button type="button" class="btn btn-sm btn-outline-warning mb-2 ms-2" :disabled="mutationPending" @click="openStatus(category, 'root')">{{ category.status === 'active' ? '停用主分類' : '啟用主分類' }}</button>
          <button type="button" class="btn btn-sm btn-outline-danger mb-2 ms-2" :disabled="mutationPending" @click="openDelete(category, 'root')">刪除主分類</button>
          <div class="d-flex flex-wrap gap-3 small">
            <span>狀態：{{ statusLabel(category.status) }}</span>
            <span>排序：{{ category.sort_order }}</span>
            <span>子分類：{{ category.children_count }}</span>
          </div>
        </div>
        <ul v-if="category.children.length" class="list-group list-group-flush">
          <li v-for="child in category.children" :key="child.id" class="list-group-item">
            <h3 class="h6 mb-2">{{ child.name }}</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary mb-2" :disabled="mutationPending" @click="openForm('child', child)">編輯子分類</button>
            <button type="button" class="btn btn-sm btn-outline-warning mb-2 ms-2" :disabled="mutationPending" @click="openStatus(child, 'child')">{{ child.status === 'active' ? '停用子分類' : '啟用子分類' }}</button>
            <button type="button" class="btn btn-sm btn-outline-danger mb-2 ms-2" :disabled="mutationPending" @click="openDelete(child, 'child')">刪除子分類</button>
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
