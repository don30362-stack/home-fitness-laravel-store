<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import axios from 'axios'
import { getAdminRecommendedProducts, createAdminRecommendedProduct, deleteAdminRecommendedProduct, reorderAdminRecommendedProducts } from '@/services/adminHomeContentService'
import { getAdminProducts } from '@/services/adminProductService'
import type { AdminRecommendedProduct } from '@/types/adminHomeContent'
import type { AdminProductListItem } from '@/types/adminProduct'

const rows = ref<AdminRecommendedProduct[]>([]), workingIds = ref<number[]>([])
const loading = ref(false), pending = ref(false), stale = ref(false)
const error = ref(''), message = ref(''), errors = ref<string[]>([])
const removing = ref<AdminRecommendedProduct | null>(null)
const selectorOpen = ref(false), products = ref<AdminProductListItem[]>([])
const search = ref(''), page = ref(1), lastPage = ref(1), selectorLoading = ref(false), selectorError = ref('')
let disposed = false, sequence = 0, selectorSequence = 0
const blocked = computed(() => pending.value || loading.value || stale.value)
const ordered = computed(() => workingIds.value.map(id => rows.value.find(row => row.id === id)).filter((row): row is AdminRecommendedProduct => !!row))
const recommendedIds = computed(() => new Set(rows.value.map(row => row.product_id)))
const orderDirty = computed(() => workingIds.value.some((id, i) => id !== rows.value[i]?.id))
const describe = (cause: unknown) => {
  if (axios.isAxiosError(cause)) {
    const data = cause.response?.data as { message?: string } | undefined
    if (cause.response?.status === 422) return data?.message || '請檢查提交內容。'
    if (cause.response?.status === 419) return '登入狀態／CSRF 發生問題，請重試。'
  }
  return '推薦商品操作失敗，請稍後再試。'
}
const load = async (refresh = false) => {
  const current = ++sequence; loading.value = true; error.value = ''; errors.value = []
  try {
    const result = await getAdminRecommendedProducts()
    if (disposed || current !== sequence) return
    rows.value = result.data; workingIds.value = result.data.map(row => row.id); stale.value = false
  } catch (cause) {
    if (disposed || current !== sequence) return
    error.value = refresh ? '最新推薦資料重新載入失敗，請手動重試。' : describe(cause); stale.value = true
  } finally { if (!disposed && current === sequence) loading.value = false }
}
const retry = () => { if (!pending.value) return load(!!message.value && stale.value) }
const loadProducts = async (targetPage = page.value) => {
  if (pending.value) return
  const current = ++selectorSequence; page.value = targetPage; selectorLoading.value = true; selectorError.value = ''
  try {
    const result = await getAdminProducts({ search: search.value.trim() || undefined, page: String(targetPage) })
    if (disposed || current !== selectorSequence) return
    products.value = result.data; page.value = result.meta.current_page; lastPage.value = result.meta.last_page
  } catch (cause) {
    if (disposed || current !== selectorSequence) return
    selectorError.value = describe(cause)
  } finally { if (!disposed && current === selectorSequence) selectorLoading.value = false }
}
const openSelector = () => { if (!blocked.value) { selectorOpen.value = true; return loadProducts() } }
const mutate = async (operation: () => Promise<{ message: string }>, staleOnValidation = false) => {
  if (blocked.value) return
  pending.value = true; error.value = ''; errors.value = []; message.value = ''
  const current = ++sequence
  try {
    const result = await operation()
    if (disposed || current !== sequence) return
    message.value = result.message; removing.value = null; stale.value = true
    await load(true)
  } catch (cause) {
    if (disposed || current !== sequence) return
    error.value = describe(cause)
    if (axios.isAxiosError(cause)) {
      const data = cause.response?.data as { errors?: Record<string, string[]> } | undefined
      errors.value = data?.errors ? Object.values(data.errors).flat() : []
      if (staleOnValidation && cause.response?.status === 422) stale.value = true
    }
  } finally { if (!disposed) pending.value = false }
}
const add = (product: AdminProductListItem) => {
  if (blocked.value || selectorLoading.value || selectorError.value || recommendedIds.value.has(product.id)) return
  return mutate(() => createAdminRecommendedProduct(product.id))
}
const askRemove = (row: AdminRecommendedProduct) => { if (!blocked.value) removing.value = row }
const confirmRemove = () => { const row = removing.value; if (row) return mutate(() => deleteAdminRecommendedProduct(row.id)) }
const move = (index: number, delta: number) => {
  if (blocked.value || index + delta < 0 || index + delta >= workingIds.value.length) return
  const ids = [...workingIds.value], [id] = ids.splice(index, 1)
  if (id !== undefined) ids.splice(index + delta, 0, id)
  workingIds.value = ids
}
const saveOrder = () => { if (orderDirty.value) return mutate(() => reorderAdminRecommendedProducts({ ids: [...workingIds.value] }), true) }
onMounted(() => load())
onBeforeUnmount(() => { disposed = true; sequence++; selectorSequence++ })
</script>

<template>
  <section class="mt-4" aria-labelledby="recommended-management-title">
    <h2 id="recommended-management-title" class="h4">推薦商品管理</h2>
    <p v-if="message" class="alert alert-success" role="status">{{ message }}</p>
    <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
    <ul v-if="errors.length" class="alert alert-danger"><li v-for="item in errors" :key="item">{{ item }}</li></ul>
    <p v-if="stale && rows.length" class="text-warning">目前推薦清單為舊資料；重新載入成功前暫停推薦操作。</p>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <button class="btn btn-primary" :disabled="blocked" @click="openSelector">選擇推薦商品</button>
      <button class="btn btn-outline-secondary" :disabled="pending" @click="retry">重新載入推薦商品</button>
      <button v-if="orderDirty" class="btn btn-outline-primary" :disabled="blocked" @click="saveOrder">儲存推薦排序</button>
    </div>
    <div v-if="selectorOpen" class="card card-body mb-3" aria-label="推薦商品選擇器">
      <form class="d-flex flex-wrap gap-2 mb-2" @submit.prevent="loadProducts(1)">
        <label for="recommended-search">商品名稱／編號</label><input id="recommended-search" v-model="search" class="form-control" maxlength="100" :disabled="pending">
        <button class="btn btn-outline-primary" type="submit" :disabled="pending">搜尋商品</button>
      </form>
      <p v-if="selectorLoading">載入商品選項中…</p>
      <div v-else-if="selectorError" class="alert alert-danger">{{ selectorError }}<button class="btn btn-outline-danger ms-2" :disabled="pending" @click="loadProducts()">重試商品選項</button></div>
      <template v-else>
        <p v-if="!products.length">沒有符合條件的商品。</p>
        <div v-for="product in products" :key="product.id" class="border rounded p-2 mb-2 selector-row">
          <p>{{ product.product_code }} · {{ product.name }} · {{ product.status }} · {{ product.category?.name || '無分類' }} · {{ product.price }}</p>
          <button class="btn btn-outline-primary" :disabled="blocked || recommendedIds.has(product.id)" @click="add(product)">{{ recommendedIds.has(product.id) ? '已推薦' : '加入推薦' }}</button>
        </div>
        <div class="d-flex gap-2 align-items-center"><button class="btn btn-outline-secondary" :disabled="pending || page <= 1" @click="loadProducts(page - 1)">上一頁商品</button><span>第 {{ page }} / {{ lastPage }} 頁</span><button class="btn btn-outline-secondary" :disabled="pending || page >= lastPage" @click="loadProducts(page + 1)">下一頁商品</button></div>
      </template>
    </div>
    <div v-if="removing" role="dialog" aria-label="移除推薦確認" class="card card-body mb-3">
      <p>確認移除「{{ removing.product?.name || removing.product_id }}」？只移除首頁推薦設定，不會刪除商品、不會修改商品上／下架狀態，也不影響歷史訂單。</p>
      <div class="d-flex gap-2"><button class="btn btn-danger" :disabled="blocked" @click="confirmRemove">確認移除推薦</button><button class="btn btn-secondary" :disabled="pending" @click="!pending && (removing = null)">放棄移除推薦</button></div>
    </div>
    <p v-if="loading">載入推薦商品中…</p><p v-else-if="!error && !rows.length">目前沒有推薦商品。</p>
    <article v-for="(row, index) in ordered" :key="row.id" class="card card-body mb-2 recommendation-row">
      <h3 class="h5">{{ row.product?.name || '商品目前不存在' }}</h3>
      <p>{{ row.product?.product_code }} · {{ row.product?.status }} · {{ row.product?.category?.name || '無分類' }} · 排序值 {{ row.sort_order }}</p>
      <p>{{ row.is_publicly_visible ? '前台顯示中' : '目前不會顯示於前台' }}</p><p v-if="row.unavailable_reason">{{ row.unavailable_reason }}</p>
      <div class="d-flex flex-wrap gap-2"><button class="btn btn-outline-danger" :disabled="blocked" @click="askRemove(row)">移除推薦</button><button class="btn btn-outline-secondary" :disabled="blocked || index === 0" @click="move(index, -1)">推薦上移</button><button class="btn btn-outline-secondary" :disabled="blocked || index === ordered.length - 1" @click="move(index, 1)">推薦下移</button></div>
    </article>
  </section>
</template>
<style scoped>
.selector-row, .recommendation-row { overflow-wrap: anywhere; min-width: 0; }
</style>
