<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import AppPagination from '@/components/common/AppPagination.vue'
import { getProductCategoryOptions } from '@/services/adminProductService'
import {
  getAdminInventory,
  adjustProductInventory,
  adjustVariantInventory,
} from '@/services/adminInventoryService'
import type { AdminInventoryItem, AdminInventoryQuery } from '@/types/adminInventory'
import type { Category } from '@/types/category'
import type { ApiErrorResponse, PaginationMeta } from '@/types/api'

const route = useRoute()
const router = useRouter()
const rows = ref<AdminInventoryItem[]>([])
const categories = ref<Category[]>([])
const pagination = ref<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
const search = ref('')
const category = ref('')
const status = ref('')
const isLoading = ref(false)
const errorMessage = ref('')
const categoryError = ref('')
const selected = ref<AdminInventoryItem | null>(null)
const adjustment = ref<string | number>('')
const submitting = ref(false)
const adjustmentError = ref('')
const success = ref('')
let requestId = 0
let contextId = 0
let disposed = false

const message = (error: unknown, fallback: string): string => {
  if (axios.isAxiosError<ApiErrorResponse>(error)) {
    const data = error.response?.data
    return data?.errors ? Object.values(data.errors).flat().join('；') : data?.message || fallback
  }
  return error instanceof Error ? error.message : fallback
}
const loadCategories = async () => {
  categoryError.value = ''
  try {
    const result = await getProductCategoryOptions()
    if (!disposed) categories.value = result
  } catch {
    if (!disposed) categoryError.value = '分類選項載入失敗，可重試或使用其他查詢條件。'
  }
}
const queryParams = (): AdminInventoryQuery => {
  const params: AdminInventoryQuery = {}
  for (const key of ['search', 'category_id', 'inventory_status', 'page'] as const) {
    const value = route.query[key]
    if (value === undefined || value === null || value === '') continue
    if (typeof value !== 'string') throw new Error('查詢條件格式不正確，請重新設定。')
    params[key] = value
  }
  return params
}
const fetchInventory = async () => {
  const current = ++requestId
  isLoading.value = true
  errorMessage.value = ''
  rows.value = []
  try {
    const result = await getAdminInventory(queryParams())
    if (current !== requestId) return
    if (result.meta.current_page > result.meta.last_page) {
      await router.replace({ query: { ...route.query, page: String(result.meta.last_page) } })
      return
    }
    rows.value = result.data
    pagination.value = result.meta
  } catch (error) {
    if (current === requestId) errorMessage.value = message(error, '庫存載入失敗，請稍後再試。')
  } finally {
    if (current === requestId) isLoading.value = false
  }
}
const applyFilters = () => {
  const query: Record<string, string> = { page: '1' }
  if (search.value.trim()) query.search = search.value.trim()
  if (category.value) query.category_id = category.value
  if (status.value) query.inventory_status = status.value
  void router.push({ query })
}
const changePage = (page: number) => {
  void router.push({ query: { ...route.query, page: String(page) } })
}
const beginAdjustment = (row: AdminInventoryItem) => {
  if (submitting.value) return
  selected.value = row
  adjustment.value = ''
  adjustmentError.value = ''
  success.value = ''
}
const submitAdjustment = async () => {
  if (submitting.value || !selected.value) return
  const value = Number(adjustment.value)
  if (
    !/^[+-]?\d+$/.test(String(adjustment.value).trim()) ||
    !Number.isSafeInteger(value) ||
    value === 0 ||
    Math.abs(value) > 4294967295
  ) {
    adjustmentError.value = '請輸入非 0 整數增減數量（正數增加、負數扣減）。'
    return
  }
  const currentContext = contextId
  const target = selected.value
  submitting.value = true
  adjustmentError.value = ''
  success.value = ''
  try {
    const result = await (
      target.stock_owner_type === 'product' ? adjustProductInventory : adjustVariantInventory
    )(target.stock_owner_id, { adjustment: value })
    if (disposed || currentContext !== contextId) return
    rows.value = rows.value.map((row) =>
      row.stock_owner_type === result.data.stock_owner_type &&
      row.stock_owner_id === result.data.stock_owner_id
        ? result.data
        : row,
    )
    success.value = `${result.message}，最新庫存：${result.data.stock}`
    selected.value = null
    // 重查目前filter/page：調整後不再符合篩選的row自然離開，不重送PATCH。
    await fetchInventory()
  } catch (error) {
    if (!disposed && currentContext === contextId)
      adjustmentError.value = message(error, '庫存調整失敗，請稍後手動再試。')
  } finally {
    if (!disposed) submitting.value = false
  }
}
const saleLabels: Record<string, string> = {
  active: '啟用',
  inactive: '停用販售',
  disabled: '管理性停用',
}
const inventoryLabels = { normal: '正常', low_stock: '低庫存', out_of_stock: '缺貨' }
watch(
  () => route.query,
  () => {
    ++contextId
    selected.value = null
    adjustmentError.value = ''
    success.value = ''
    search.value = typeof route.query.search === 'string' ? route.query.search : ''
    category.value = typeof route.query.category_id === 'string' ? route.query.category_id : ''
    status.value =
      typeof route.query.inventory_status === 'string' ? route.query.inventory_status : ''
    void fetchInventory()
  },
  { immediate: true },
)
void loadCategories()
onBeforeUnmount(() => {
  disposed = true
  ++requestId
  ++contextId
})
</script>

<template>
  <section aria-labelledby="admin-inventory-title">
    <h1 id="admin-inventory-title" class="h3">庫存管理</h1>
    <form class="row g-3 mb-4" @submit.prevent="applyFilters">
      <div class="col-12 col-lg-5">
        <label for="inventory-search" class="form-label">名稱或商品編號</label
        ><input
          id="inventory-search"
          v-model="search"
          class="form-control"
          maxlength="100"
          type="search"
        />
      </div>
      <div class="col-12 col-md-5 col-lg-3">
        <label for="inventory-category" class="form-label">子分類</label>
        <select id="inventory-category" v-model="category" class="form-select">
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
        <label for="inventory-status" class="form-label">庫存狀態</label
        ><select id="inventory-status" v-model="status" class="form-select">
          <option value="">全部</option>
          <option value="normal">正常</option>
          <option value="low_stock">低庫存</option>
          <option value="out_of_stock">缺貨</option>
        </select>
      </div>
      <div class="col-12 col-md-3 col-lg-2 d-flex align-items-end">
        <button type="submit" class="btn btn-dark">查詢</button>
      </div>
    </form>
    <div v-if="categoryError" role="alert" class="alert alert-warning">
      {{ categoryError }}
      <button type="button" class="btn btn-sm btn-outline-dark" @click="loadCategories">
        重試分類
      </button>
    </div>
    <p v-if="success" role="status" class="alert alert-success">{{ success }}</p>
    <section v-if="selected" class="border rounded p-3 mb-3" aria-label="庫存調整">
      <h2 class="h5">調整 {{ selected.product_name }} {{ selected.variant?.option_value }}</h2>
      <p>目前庫存：{{ selected.stock }}。正數增加、負數扣減；輸入的是增減數量，不是新庫存。</p>
      <form @submit.prevent="submitAdjustment">
        <label for="inventory-adjustment" class="form-label">增減數量（例如 +5 或 -3）</label>
        <input
          id="inventory-adjustment"
          v-model="adjustment"
          class="form-control mb-2"
          type="number"
          step="1"
          :disabled="submitting"
        />
        <p v-if="adjustmentError" role="alert" class="text-danger">{{ adjustmentError }}</p>
        <button type="submit" class="btn btn-primary me-2" :disabled="submitting">
          {{ submitting ? '調整中…' : '確認調整' }}
        </button>
        <button
          type="button"
          class="btn btn-outline-secondary"
          :disabled="submitting"
          @click="selected = null"
        >
          放棄
        </button>
      </form>
    </section>
    <p v-if="isLoading" role="status">庫存載入中…</p>
    <div v-else-if="errorMessage" role="alert" class="alert alert-danger">
      <p>{{ errorMessage }}</p>
      <button type="button" class="btn btn-outline-danger" @click="fetchInventory">重試</button>
    </div>
    <p v-else-if="rows.length === 0">沒有符合條件的庫存。</p>
    <div v-else class="table-responsive">
      <table class="table align-middle">
        <caption class="visually-hidden">
          後台庫存列表
        </caption>
        <thead>
          <tr>
            <th scope="col">商品編號／名稱</th>
            <th scope="col">分類</th>
            <th scope="col">購買規格</th>
            <th scope="col">販售狀態</th>
            <th scope="col">庫存</th>
            <th scope="col">低庫存門檻</th>
            <th scope="col">庫存狀態</th>
            <th scope="col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="`${row.stock_owner_type}:${row.stock_owner_id}`">
            <td>{{ row.product_code }}<br />{{ row.product_name }}</td>
            <td>{{ row.category.name }}</td>
            <td>
              {{
                row.variant
                  ? `${row.variant.option_name}：${row.variant.option_value}`
                  : '無購買規格'
              }}
            </td>
            <td>
              商品：{{ saleLabels[row.product_status] ?? row.product_status
              }}<template v-if="row.variant"
                ><br />規格：{{ saleLabels[row.variant.status] ?? row.variant.status }}</template
              >
            </td>
            <td>{{ row.stock }}</td>
            <td>{{ row.low_stock_threshold }}</td>
            <td>{{ inventoryLabels[row.inventory_status] }}</td>
            <td>
              <button
                type="button"
                class="btn btn-sm btn-outline-primary"
                :disabled="submitting"
                @click="beginAdjustment(row)"
              >
                調整庫存
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <AppPagination
      v-if="!isLoading && !errorMessage"
      :current-page="pagination.current_page"
      :last-page="pagination.last_page"
      label="後台庫存分頁"
      @change-page="changePage"
    />
  </section>
</template>

<style scoped>
/* Stock-owner columns scroll locally instead of squeezing identifiers into single letters. */
table {
  min-width: 900px;
  overflow-wrap: normal;
}
th {
  white-space: nowrap;
}
td:first-child {
  min-width: 160px;
}
</style>
