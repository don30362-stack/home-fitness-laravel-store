<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import AppPagination from '@/components/common/AppPagination.vue'
import { getAdminProducts, getProductCategoryOptions } from '@/services/adminProductService'
import type { AdminProductListItem, AdminProductQuery } from '@/types/adminProduct'
import type { Category } from '@/types/category'
import type { ApiErrorResponse, PaginationMeta } from '@/types/api'

const route = useRoute()
const router = useRouter()
const products = ref<AdminProductListItem[]>([])
const categories = ref<Category[]>([])
const pagination = ref<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
const search = ref('')
const category = ref('')
const status = ref('')
const isLoading = ref(false)
const errorMessage = ref('')
const categoryError = ref('')
let requestId = 0
let disposed = false

const loadCategories = async () => {
  categoryError.value = ''
  try {
    const result = await getProductCategoryOptions()
    if (!disposed) categories.value = result
  } catch {
    if (!disposed) categoryError.value = '分類選項載入失敗，可重試或使用其他查詢條件。'
  }
}
const queryParams = (): AdminProductQuery => {
  const params: AdminProductQuery = {}
  for (const key of ['search', 'category_id', 'status', 'page'] as const) {
    const value = route.query[key]
    if (value === undefined || value === null || value === '') continue
    if (typeof value !== 'string') throw new Error('查詢條件格式不正確，請重新設定。')
    params[key] = value
  }
  return params
}
const fetchProducts = async () => {
  const current = ++requestId
  isLoading.value = true
  errorMessage.value = ''
  products.value = []
  try {
    const result = await getAdminProducts(queryParams())
    if (current !== requestId) return
    if (result.meta.current_page > result.meta.last_page) {
      await router.replace({ query: { ...route.query, page: String(result.meta.last_page) } })
      return
    }
    products.value = result.data
    pagination.value = result.meta
  } catch (error) {
    if (current !== requestId) return
    errorMessage.value = axios.isAxiosError<ApiErrorResponse>(error)
      ? error.response?.data?.message || '商品載入失敗，請稍後再試。'
      : error instanceof Error
        ? error.message
        : '商品載入失敗，請稍後再試。'
  } finally {
    if (current === requestId) isLoading.value = false
  }
}
const applyFilters = () => {
  const query: Record<string, string> = { page: '1' }
  if (search.value.trim()) query.search = search.value.trim()
  if (category.value) query.category_id = category.value
  if (status.value) query.status = status.value
  void router.push({ query })
}
const changePage = (page: number) => {
  void router.push({ query: { ...route.query, page: String(page) } })
}
const labels: Record<string, string> = { active: '上架', inactive: '下架', disabled: '停用' }
watch(
  () => route.query,
  () => {
    search.value = typeof route.query.search === 'string' ? route.query.search : ''
    category.value = typeof route.query.category_id === 'string' ? route.query.category_id : ''
    status.value = typeof route.query.status === 'string' ? route.query.status : ''
    void fetchProducts()
  },
  { immediate: true },
)
void loadCategories()
onBeforeUnmount(() => {
  disposed = true
  ++requestId
})
</script>

<template>
  <section aria-labelledby="admin-products-title">
    <h1 id="admin-products-title" class="h3">商品管理</h1>
    <form class="row g-3 mb-4" @submit.prevent="applyFilters">
      <div class="col-12 col-lg-5">
        <label for="product-search" class="form-label">名稱或商品編號</label>
        <input
          id="product-search"
          v-model="search"
          class="form-control"
          maxlength="100"
          type="search"
        />
      </div>
      <div class="col-12 col-md-5 col-lg-3">
        <label for="product-category" class="form-label">子分類</label>
        <select id="product-category" v-model="category" class="form-select">
          <option value="">全部分類</option>
          <optgroup v-for="parent in categories" :key="parent.id" :label="parent.name">
            <option v-for="child in parent.children" :key="child.id" :value="String(child.id)">
              {{ child.name }}
            </option>
          </optgroup>
          <option
            v-if="
              category &&
              !categories.some((parent) =>
                parent.children.some((child) => String(child.id) === category),
              )
            "
            :value="category"
          >
            目前篩選分類 #{{ category }}
          </option>
        </select>
      </div>
      <div class="col-12 col-md-4 col-lg-2">
        <label for="product-status" class="form-label">商品狀態</label>
        <select id="product-status" v-model="status" class="form-select">
          <option value="">全部狀態</option>
          <option value="active">上架</option>
          <option value="inactive">下架</option>
          <option value="disabled">停用</option>
        </select>
      </div>
      <div class="col-12 col-md-3 col-lg-2 d-flex align-items-end">
        <button type="submit" class="btn btn-dark">查詢</button>
      </div>
    </form>
    <div v-if="categoryError" role="alert" class="alert alert-warning">
      {{ categoryError }}
      <button type="button" class="btn btn-sm btn-outline-dark ms-2" @click="loadCategories">
        重試分類
      </button>
    </div>
    <p v-if="isLoading" role="status">商品載入中…</p>
    <div v-else-if="errorMessage" role="alert" class="alert alert-danger">
      <p>{{ errorMessage }}</p>
      <button type="button" class="btn btn-outline-danger" @click="fetchProducts">重試</button>
    </div>
    <p v-else-if="products.length === 0">沒有符合條件的商品。</p>
    <div v-else class="table-responsive">
      <table class="table align-middle">
        <caption class="visually-hidden">
          後台商品列表
        </caption>
        <thead>
          <tr>
            <th>商品編號</th>
            <th>名稱</th>
            <th>分類</th>
            <th>價格</th>
            <th>商品狀態</th>
            <th>庫存模式</th>
            <th>詳細</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="product in products" :key="product.id">
            <td>{{ product.product_code }}</td>
            <td>{{ product.name }}</td>
            <td>{{ product.category?.name ?? '—' }}</td>
            <td>NT$ {{ Number(product.price).toLocaleString('zh-TW') }}</td>
            <td>{{ labels[product.status] ?? product.status }}</td>
            <td>
              {{ product.has_variants ? '依購買規格管理' : `無規格：${product.stock ?? '—'}` }}
            </td>
            <td>
              <RouterLink
                :to="{
                  name: 'admin-product-detail',
                  params: { id: product.id },
                  query: route.query,
                }"
                >查看詳細</RouterLink
              >
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <AppPagination
      v-if="!isLoading && !errorMessage"
      :current-page="pagination.current_page"
      :last-page="pagination.last_page"
      label="後台商品分頁"
      @change-page="changePage"
    />
  </section>
</template>
