<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import AppPagination from '@/components/common/AppPagination.vue'
import { getOrders } from '@/services/orderService'
import type { OrderSummary } from '@/types/order'
import type { PaginationMeta } from '@/types/api'

const route = useRoute()
const router = useRouter()
const orders = ref<OrderSummary[]>([])
const pagination = ref<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
const isLoading = ref(false)
const errorMessage = ref('')
let requestId = 0

const fetchOrders = async () => {
  const currentRequest = ++requestId
  isLoading.value = true
  errorMessage.value = ''
  orders.value = []
  try {
    const page = Array.isArray(route.query.page) ? route.query.page[0] : route.query.page
    const response = await getOrders(page ?? undefined)
    if (currentRequest !== requestId) return
    // 與既有商品列表一致：超出末頁時同步修正 URL，再讀取有效頁。
    if (response.meta.current_page > response.meta.last_page) {
      await router.replace({ query: { ...route.query, page: String(response.meta.last_page) } })
      return
    }
    orders.value = response.data
    pagination.value = response.meta
  } catch {
    if (currentRequest === requestId) errorMessage.value = '訂單載入失敗，請稍後再試。'
  } finally {
    if (currentRequest === requestId) isLoading.value = false
  }
}

const changePage = (page: number) => {
  void router.push({ query: { ...route.query, page: String(page) } })
}
const formatMoney = (value: string) => `NT$ ${Number(value).toLocaleString('zh-TW')}`
const formatDate = (value: string | null) => value
  ? new Date(value).toLocaleString('zh-TW', { timeZone: 'Asia/Taipei' }) : '—'
const orderLabels: Record<string, string> = {
  pending: '待處理', processing: '處理中', shipped: '已出貨', completed: '已完成', cancelled: '已取消',
}
const paymentLabels: Record<string, string> = { unpaid: '未付款', paid: '已付款' }
const methodLabels: Record<string, string> = { cod: '貨到付款', mock_credit_card: '模擬信用卡' }

watch(() => route.query.page, fetchOrders, { immediate: true })
onBeforeUnmount(() => { ++requestId })
</script>

<template>
  <section aria-labelledby="orders-title">
    <h2 id="orders-title" class="h4 mb-4">我的訂單</h2>
    <p v-if="isLoading" role="status">訂單載入中…</p>
    <div v-else-if="errorMessage" class="alert alert-danger" role="alert">
      <p>{{ errorMessage }}</p>
      <button class="btn btn-outline-danger" type="button" @click="fetchOrders">重新載入</button>
    </div>
    <p v-else-if="orders.length === 0">目前這一頁沒有訂單。</p>
    <div v-else class="table-responsive">
      <table class="table align-middle">
        <caption class="visually-hidden">本人訂單列表</caption>
        <thead><tr><th scope="col">訂單編號</th><th scope="col">成立時間</th><th scope="col">總金額</th><th scope="col">付款方式／狀態</th><th scope="col">訂單狀態</th></tr></thead>
        <tbody>
          <tr v-for="order in orders" :key="order.id">
            <td>{{ order.order_no }}</td>
            <td>{{ formatDate(order.created_at) }}</td>
            <td>{{ formatMoney(order.total_amount) }}</td>
            <td>{{ methodLabels[order.payment_method] ?? order.payment_method }}／{{ paymentLabels[order.payment_status] ?? order.payment_status }}</td>
            <td>{{ orderLabels[order.order_status] ?? order.order_status }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <AppPagination v-if="!isLoading && !errorMessage" :current-page="pagination.current_page"
      :last-page="pagination.last_page" label="訂單分頁" @change-page="changePage" />
  </section>
</template>
