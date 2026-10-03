<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import axios from 'axios'
import { getAdminProduct } from '@/services/adminProductService'
import type { AdminProductDetail } from '@/types/adminProduct'
import type { ApiErrorResponse } from '@/types/api'

const route = useRoute()
const product = ref<AdminProductDetail | null>(null)
const isLoading = ref(false)
const errorMessage = ref('')
let requestId = 0
const labels: Record<string, string> = { active: '上架', inactive: '下架', disabled: '停用' }
const load = async () => {
  const current = ++requestId
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
