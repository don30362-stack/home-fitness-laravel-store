<script setup lang="ts">
import { computed, onBeforeUnmount, ref } from 'vue'
import axios from 'axios'
import { createAdminCategory, updateAdminCategory } from '@/services/adminCategoryService'
import type { AdminCategory, AdminCategoryChild, AdminCategoryMutationResponse, AdminCategoryStatus } from '@/types/adminCategory'
import type { ApiErrorResponse } from '@/types/api'

const props = defineProps<{
  categories: AdminCategory[]
  category: AdminCategory | AdminCategoryChild | null
  role: 'root' | 'child'
  context: number
}>()
const emit = defineEmits<{
  saved: [response: AdminCategoryMutationResponse, context: number]
  cancel: []
  'submitting-change': [value: boolean]
}>()
const name = ref(props.category?.name ?? '')
const sortOrder = ref<string | number>(props.category?.sort_order ?? 0)
const currentParent = props.category && 'parent_id' in props.category ? props.category.parent_id : null
const parentId = ref<string | number>(currentParent ?? '')
const status = ref<AdminCategoryStatus>('active')
const submitting = ref(false)
const error = ref('')
const errors = ref<Record<string, string[]>>({})
const parents = computed(() => props.categories.filter(root => root.status === 'active' || root.id === currentParent))
let disposed = false
onBeforeUnmount(() => { disposed = true })

const setSubmitting = (value: boolean) => {
  if (submitting.value === value) return
  submitting.value = value
  emit('submitting-change', value)
}
const cancel = () => { if (!submitting.value) emit('cancel') }

const submit = async () => {
  if (submitting.value) return
  errors.value = {}
  error.value = ''
  if (!name.value.trim()) errors.value.name = ['請輸入分類名稱。']
  if (!Number.isInteger(Number(sortOrder.value)) || Number(sortOrder.value) < 0 || String(sortOrder.value).trim() === '') {
    errors.value.sort_order = ['排序必須是非負整數。']
  }
  if (props.role === 'child' && !parentId.value) errors.value.parent_id = ['請選擇主分類。']
  if (Object.keys(errors.value).length) return
  setSubmitting(true)
  const context = props.context
  try {
    const payload = { name: name.value.trim(), sort_order: Number(sortOrder.value),
      parent_id: props.role === 'root' ? null : Number(parentId.value) }
    const response = props.category
      ? await updateAdminCategory(props.category.id, payload)
      : await createAdminCategory({ ...payload, status: status.value })
    if (!disposed) {
      setSubmitting(false)
      emit('saved', response, context)
    }
  } catch (failure: unknown) {
    if (disposed) return
    if (axios.isAxiosError<ApiErrorResponse>(failure)) {
      errors.value = failure.response?.data?.errors ?? {}
      error.value = failure.response?.data?.message || '分類儲存失敗，請稍後再試。'
    } else error.value = '分類儲存失敗，請稍後再試。'
  } finally {
    if (!disposed) setSubmitting(false)
  }
}
</script>

<template>
  <form class="card card-body mb-4" aria-label="分類維護表單" @submit.prevent="submit">
    <h2 class="h5">{{ category ? '編輯分類' : '新增分類' }}</h2>
    <p>角色：{{ role === 'root' ? '主分類' : '子分類' }}（建立後不可變更）</p>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}</div>
    <div class="mb-3">
      <label for="category-name" class="form-label">名稱</label>
      <input id="category-name" v-model="name" class="form-control" maxlength="100" :disabled="submitting" />
      <p v-for="message in errors.name" :key="message" class="text-danger mb-0">{{ message }}</p>
    </div>
    <div v-if="role === 'child'" class="mb-3">
      <label for="category-parent" class="form-label">主分類</label>
      <select id="category-parent" v-model="parentId" class="form-select" :disabled="submitting">
        <option value="">請選擇主分類</option>
        <option v-for="parent in parents" :key="parent.id" :value="parent.id">
          {{ parent.name }}{{ parent.status === 'inactive' ? '（目前停用，可保留）' : '' }}
        </option>
      </select>
      <p v-for="message in errors.parent_id" :key="message" class="text-danger mb-0">{{ message }}</p>
    </div>
    <div class="mb-3">
      <label for="category-sort" class="form-label">排序</label>
      <input id="category-sort" v-model="sortOrder" type="number" min="0" step="1" class="form-control" :disabled="submitting" />
      <p v-for="message in errors.sort_order" :key="message" class="text-danger mb-0">{{ message }}</p>
    </div>
    <div v-if="!category" class="mb-3">
      <label for="category-initial-status" class="form-label">建立時的初始狀態</label>
      <select id="category-initial-status" v-model="status" class="form-select" :disabled="submitting">
        <option value="active">啟用</option><option value="inactive">停用</option>
      </select>
      <p v-for="message in errors.status" :key="message" class="text-danger mb-0">{{ message }}</p>
    </div>
    <div class="d-flex flex-wrap gap-2">
      <button type="submit" class="btn btn-primary" :disabled="submitting">{{ submitting ? '儲存中…' : '儲存分類' }}</button>
      <button type="button" class="btn btn-outline-secondary" :disabled="submitting" @click="cancel">放棄</button>
    </div>
  </form>
</template>
