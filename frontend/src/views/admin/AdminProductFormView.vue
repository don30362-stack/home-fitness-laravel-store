<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import ProductForm from '@/components/admin/ProductForm.vue'
import {
  getAdminProduct,
  getProductCategoryOptions,
  createAdminProduct,
  updateAdminProduct,
} from '@/services/adminProductService'
import type { Category } from '@/types/category'
import type {
  AdminProductDetail,
  CreateAdminProductPayload,
  UpdateAdminProductPayload,
} from '@/types/adminProduct'
import type { ApiErrorResponse } from '@/types/api'
const route = useRoute(),
  router = useRouter()
const product = ref<AdminProductDetail>(),
  categories = ref<Category[]>([])
const loading = ref(true),
  submitting = ref(false),
  loadError = ref(''),
  saveError = ref('')
const errors = ref<Record<string, string[]>>({})
let sequence = 0
const load = async () => {
  const current = ++sequence
  loading.value = true
  product.value = undefined
  loadError.value = ''
  saveError.value = ''
  errors.value = {}
  submitting.value = false
  const id = route.params.id
  try {
    const [options, detail] = await Promise.all([
      getProductCategoryOptions(),
      id ? getAdminProduct(String(id)) : Promise.resolve(undefined),
    ])
    if (current !== sequence) return
    categories.value = options
    product.value = detail
  } catch (error) {
    if (current === sequence)
      loadError.value = axios.isAxiosError<ApiErrorResponse>(error)
        ? error.response?.data.message || '商品表單載入失敗，請重試。'
        : '商品表單載入失敗，請重試。'
  } finally {
    if (current === sequence) loading.value = false
  }
}
const save = async (payload: CreateAdminProductPayload | UpdateAdminProductPayload) => {
  if (submitting.value || loading.value) return
  const current = sequence,
    id = route.params.id
  submitting.value = true
  saveError.value = ''
  errors.value = {}
  try {
    const result = id
      ? await updateAdminProduct(String(id), payload)
      : await createAdminProduct(payload as CreateAdminProductPayload)
    if (current === sequence)
      await router.push({
        name: 'admin-product-detail',
        params: { id: result.data.id },
        query: route.query,
      })
  } catch (error) {
    if (current !== sequence) return
    if (axios.isAxiosError<ApiErrorResponse>(error)) {
      errors.value = error.response?.data.errors ?? {}
      saveError.value = error.response?.data.message || '商品儲存失敗，請稍後再試。'
    } else saveError.value = '商品儲存失敗，請稍後再試。'
  } finally {
    if (current === sequence) submitting.value = false
  }
}
watch(() => route.fullPath, load, { immediate: true })
onBeforeUnmount(() => {
  ++sequence
})
</script>
<template>
  <section>
    <RouterLink :to="{ name: 'admin-products', query: route.query }">返回商品列表</RouterLink>
    <h1 class="h3 mt-3">{{ route.params.id ? '編輯商品' : '新增商品' }}</h1>
    <p v-if="loading" role="status">商品表單載入中…</p>
    <div v-else-if="loadError" class="alert alert-danger" role="alert">
      {{ loadError }} <button class="btn btn-outline-danger" @click="load">重試</button>
    </div>
    <template v-else>
      <p v-if="saveError" class="alert alert-danger" role="alert">{{ saveError }}</p>
      <ProductForm
        :key="String(route.params.id ?? 'new')"
        :product="product"
        :categories="categories"
        :submitting="submitting"
        :errors="errors"
        @save="save"
      />
    </template>
  </section>
</template>
