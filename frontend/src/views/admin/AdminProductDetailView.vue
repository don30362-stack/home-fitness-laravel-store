<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import {
  getAdminProduct,
  updateAdminProductStatus,
  deleteAdminProduct,
} from '@/services/adminProductService'
import type { ProductStatus } from '@/types/adminProduct'
import type { AdminProductDetail } from '@/types/adminProduct'
import type { ApiErrorResponse } from '@/types/api'

const route = useRoute()
const router = useRouter()
const submitting = ref(false),
  mutationError = ref(''),
  mutationMessage = ref(''),
  confirmDelete = ref(false)
const product = ref<AdminProductDetail | null>(null)
const isLoading = ref(false)
const errorMessage = ref('')
let requestId = 0
const labels: Record<string, string> = { active: '上架', inactive: '下架', disabled: '停用' }
const load = async () => {
  const current = ++requestId
  submitting.value = false
  mutationError.value = ''
  mutationMessage.value = ''
  confirmDelete.value = false
  product.value = null
  errorMessage.value = ''
  isLoading.value = true
  try {
    const result = await getAdminProduct(String(route.params.id))
    if (current === requestId) product.value = result
  } catch (error) {
    if (current !== requestId) return
    errorMessage.value =
      axios.isAxiosError<ApiErrorResponse>(error) && error.response?.status === 404
        ? '商品不存在或無法取得。'
        : axios.isAxiosError<ApiErrorResponse>(error)
          ? error.response?.data?.message || '商品詳細載入失敗，請重試。'
          : '商品詳細載入失敗，請重試。'
  } finally {
    if (current === requestId) isLoading.value = false
  }
}
const mutate = async (status?: ProductStatus) => {
  if (submitting.value || !product.value || (status === undefined && !confirmDelete.value)) return
  const current = requestId,
    id = String(product.value.id)
  submitting.value = true
  mutationError.value = ''
  mutationMessage.value = ''
  try {
    if (status !== undefined) {
      const result = await updateAdminProductStatus(id, { status })
      if (current === requestId) {
        product.value = result.data
        mutationMessage.value = result.message
      }
    } else {
      await deleteAdminProduct(id)
      if (current === requestId) await router.push({ name: 'admin-products', query: route.query })
    }
  } catch (error) {
    if (current === requestId)
      mutationError.value = axios.isAxiosError<ApiErrorResponse>(error)
        ? error.response?.data.message || '商品操作失敗，請稍後再試。'
        : '商品操作失敗，請稍後再試。'
  } finally {
    if (current === requestId) submitting.value = false
  }
}
const formatDate = (value: string | null) => (value ? new Date(value).toLocaleString('zh-TW') : '—')
watch(() => route.params.id, load, { immediate: true })
onBeforeUnmount(() => {
  ++requestId
})
</script>

<template>
  <section>
    <RouterLink :to="{ name: 'admin-products', query: route.query }">返回商品列表</RouterLink>
    <h1 class="h3 mt-3">商品詳細</h1>
    <p v-if="isLoading" role="status">商品詳細載入中…</p>
    <div v-else-if="errorMessage" class="alert alert-danger" role="alert">
      {{ errorMessage }}
      <button type="button" class="btn btn-outline-danger ms-2" @click="load">重試</button>
    </div>
    <template v-else-if="product">
      <h2 class="h4">{{ product.name }}</h2>
      <RouterLink
        class="btn btn-outline-primary mb-3"
        :to="{ name: 'admin-product-edit', params: { id: product.id }, query: route.query }"
        >編輯商品</RouterLink
      >
      <p v-if="mutationError" class="alert alert-danger" role="alert">{{ mutationError }}</p>
      <p v-if="mutationMessage" class="alert alert-success" role="status">{{ mutationMessage }}</p>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <button
          v-for="status in ['active', 'inactive', 'disabled'] as const"
          :key="status"
          type="button"
          class="btn btn-outline-secondary"
          :disabled="submitting || product.status === status"
          @click="mutate(status)"
        >
          設為{{ labels[status] }}
        </button>
        <button
          type="button"
          class="btn btn-outline-danger"
          :disabled="submitting"
          @click="confirmDelete = true"
        >
          刪除商品
        </button>
      </div>
      <p v-if="submitting" role="status">處理中…</p>
      <div v-if="confirmDelete" class="alert alert-warning">
        <p>
          確定實體刪除此商品？若沒有歷史訂單但存在會員購物車項目，刪除後這些購物車項目會一併移除。有歷史訂單的商品無法刪除，請改為下架或停用。
        </p>
        <button type="button" class="btn btn-danger me-2" :disabled="submitting" @click="mutate()">
          確認刪除
        </button>
        <button
          type="button"
          class="btn btn-secondary"
          :disabled="submitting"
          @click="confirmDelete = false"
        >
          放棄
        </button>
      </div>
      <dl class="row">
        <dt class="col-sm-3">商品編號</dt>
        <dd class="col-sm-9">{{ product.product_code }}</dd>
        <dt class="col-sm-3">價格</dt>
        <dd class="col-sm-9">NT$ {{ Number(product.price).toLocaleString('zh-TW') }}</dd>
        <dt class="col-sm-3">商品狀態</dt>
        <dd class="col-sm-9">{{ labels[product.status] ?? product.status }}</dd>
        <dt class="col-sm-3">分類</dt>
        <dd class="col-sm-9">
          {{ product.category?.parent?.name ?? '—' }} / {{ product.category?.name ?? '—' }}（{{
            product.category?.status ?? '—'
          }}）
        </dd>
        <dt class="col-sm-3">庫存模式</dt>
        <dd class="col-sm-9">
          {{ product.has_variants ? '依購買規格管理' : `無規格：${product.stock ?? '—'}` }}
        </dd>
        <dt class="col-sm-3">低庫存門檻</dt>
        <dd class="col-sm-9">{{ product.low_stock_threshold }}</dd>
        <dt class="col-sm-3">建立／更新時間</dt>
        <dd class="col-sm-9">
          {{ formatDate(product.created_at) }} / {{ formatDate(product.updated_at) }}
        </dd>
        <dt class="col-sm-3">短介</dt>
        <dd class="col-sm-9">{{ product.short_description ?? '—' }}</dd>
        <dt class="col-sm-3">詳細說明</dt>
        <dd class="col-sm-9 description">{{ product.description ?? '—' }}</dd>
      </dl>
      <h2 class="h5">固定規格</h2>
      <p v-if="!product.specifications.length">沒有固定規格。</p>
      <dl v-else>
        <template v-for="spec in product.specifications" :key="spec.id"
          ><dt>{{ spec.spec_name }}</dt>
          <dd>{{ spec.spec_value }}</dd></template
        >
      </dl>
      <h2 class="h5">購買規格</h2>
      <p v-if="!product.variants.length">沒有購買規格。</p>
      <ul v-else>
        <li v-for="variant in product.variants" :key="variant.id">
          {{ variant.option_name }}：{{ variant.option_value }}／庫存 {{ variant.stock }}／{{
            variant.status
          }}
        </li>
      </ul>
      <h2 class="h5">圖片（唯讀）</h2>
      <p v-if="!product.images.length">沒有圖片。</p>
      <div class="row g-3">
        <figure v-for="image in product.images" :key="image.id" class="col-12 col-sm-6 col-lg-4">
          <img :src="image.image_url" :alt="product.name" class="img-fluid border" />
          <figcaption>
            {{ image.is_primary ? '主圖／' : '' }}{{ image.image_type }}／排序
            {{ image.sort_order }}
          </figcaption>
        </figure>
      </div>
    </template>
  </section>
</template>

<style scoped>
.description {
  white-space: pre-wrap;
}
img {
  max-height: 240px;
  object-fit: contain;
}
</style>
