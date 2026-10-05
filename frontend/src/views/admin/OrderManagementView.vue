<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import AppPagination from '@/components/common/AppPagination.vue'
import { getAdminOrders } from '@/services/adminOrderService'
import type { AdminOrderQuery, AdminOrderSummary } from '@/types/adminOrder'
import type { ApiErrorResponse, PaginationMeta } from '@/types/api'

const route = useRoute()
const router = useRouter()
const orders = ref<AdminOrderSummary[]>([])
const pagination = ref<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
const search = ref(''), orderStatus = ref(''), paymentStatus = ref(''), dateFrom = ref(''), dateTo = ref('')
const isLoading = ref(false), errorMessage = ref('')
let requestId = 0
const orderLabels = { pending: '待處理', processing: '處理中', shipped: '已出貨', completed: '已完成', cancelled: '已取消' }
const paymentLabels = { unpaid: '未付款', paid: '已付款' }
const queryParams = (): AdminOrderQuery => {
  const params: AdminOrderQuery = {}
  for (const key of ['search', 'order_status', 'payment_status', 'date_from', 'date_to', 'page'] as const) {
    const value = route.query[key]
    if (value === undefined || value === null || value === '') continue
    if (typeof value !== 'string') throw new Error('查詢條件格式不正確，請重新設定。')
    params[key] = value
  }
  return params
}
const load = async () => {
  const current = ++requestId
  isLoading.value = true
  errorMessage.value = ''
  orders.value = []
  try {
    const result = await getAdminOrders(queryParams())
    if (current !== requestId) return
    if (result.meta.current_page > result.meta.last_page) {
      await router.replace({ query: { ...route.query, page: String(result.meta.last_page) } })
      return
    }
    orders.value = result.data
    pagination.value = result.meta
  } catch (error) {
    if (current !== requestId) return
    errorMessage.value = axios.isAxiosError<ApiErrorResponse>(error)
      ? error.response?.data?.message || '訂單載入失敗，請稍後再試。'
      : error instanceof Error ? error.message : '訂單載入失敗，請稍後再試。'
  } finally {
    if (current === requestId) isLoading.value = false
  }
}
const applyFilters = () => {
  const query: Record<string, string> = { page: '1' }
  if (search.value.trim()) query.search = search.value.trim()
  if (orderStatus.value) query.order_status = orderStatus.value
  if (paymentStatus.value) query.payment_status = paymentStatus.value
  if (dateFrom.value) query.date_from = dateFrom.value
  if (dateTo.value) query.date_to = dateTo.value
  void router.push({ query })
}
const changePage = (page: number) => { void router.push({ query: { ...route.query, page: String(page) } }) }
const formatDate = (value: string | null) => value ? new Date(value).toLocaleString('zh-TW', { timeZone: 'Asia/Taipei' }) : '—'
watch(() => route.query, () => {
  search.value = typeof route.query.search === 'string' ? route.query.search : ''
  orderStatus.value = typeof route.query.order_status === 'string' ? route.query.order_status : ''
  paymentStatus.value = typeof route.query.payment_status === 'string' ? route.query.payment_status : ''
  dateFrom.value = typeof route.query.date_from === 'string' ? route.query.date_from : ''
  dateTo.value = typeof route.query.date_to === 'string' ? route.query.date_to : ''
  void load()
}, { immediate: true })
onBeforeUnmount(() => { ++requestId })
</script>

<template>
  <section aria-labelledby="admin-orders-title">
    <h1 id="admin-orders-title" class="h3">訂單管理</h1>
    <form class="row g-3 mb-4" @submit.prevent="applyFilters">
      <div class="col-12 col-lg-4">
        <label for="order-search" class="form-label">訂單編號／目前會員姓名或 Email</label>
        <input id="order-search" v-model="search" type="search" maxlength="100" class="form-control" />
      </div>
      <div class="col-12 col-sm-6 col-lg-4">
        <label for="order-status" class="form-label">訂單狀態</label>
        <select id="order-status" v-model="orderStatus" class="form-select">
          <option value="">全部訂單狀態</option>
          <option v-for="(label, value) in orderLabels" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>
      <div class="col-12 col-sm-6 col-lg-4">
        <label for="order-payment-status" class="form-label">付款狀態</label>
        <select id="order-payment-status" v-model="paymentStatus" class="form-select">
          <option value="">全部付款狀態</option>
          <option v-for="(label, value) in paymentLabels" :key="value" :value="value">{{ label }}</option>
        </select>
      </div>
      <div class="col-12 col-sm-6 col-lg-4">
        <label for="order-date-from" class="form-label">開始日期（台北時間）</label>
        <input id="order-date-from" v-model="dateFrom" type="date" class="form-control" />
      </div>
      <div class="col-12 col-sm-6 col-lg-4">
        <label for="order-date-to" class="form-label">結束日期（台北時間，含當日）</label>
        <input id="order-date-to" v-model="dateTo" type="date" class="form-control" />
      </div>
      <div class="col-12 col-lg-4 d-flex align-items-end">
        <button type="submit" class="btn btn-dark">查詢</button>
      </div>
    </form>
    <p v-if="isLoading" role="status">訂單載入中…</p>
    <div v-else-if="errorMessage" role="alert" class="alert alert-danger">
      <p>{{ errorMessage }}</p>
      <button type="button" class="btn btn-outline-danger" @click="load">重試</button>
    </div>
    <p v-else-if="orders.length === 0">沒有符合條件的訂單。</p>
    <div v-else class="table-responsive">
      <table class="table align-middle">
        <caption class="visually-hidden">後台訂單列表</caption>
        <thead><tr><th>訂單編號</th><th>建立時間（台北）</th><th>目前會員</th><th>總金額</th><th>付款方式</th><th>付款狀態</th><th>訂單狀態</th><th>詳細</th></tr></thead>
        <tbody>
          <tr v-for="order in orders" :key="order.id">
            <td>{{ order.order_no }}</td><td>{{ formatDate(order.created_at) }}</td>
            <td>{{ order.user?.name ?? '—' }}<br />{{ order.user?.email ?? '—' }}</td>
            <td>NT$ {{ Number(order.total_amount).toLocaleString('zh-TW') }}</td>
            <td>{{ order.payment_method === 'cod' ? '貨到付款' : order.payment_method === 'mock_credit_card' ? '模擬信用卡' : order.payment_method }}</td>
            <td>{{ paymentLabels[order.payment_status] ?? order.payment_status }}</td>
            <td>{{ orderLabels[order.order_status] ?? order.order_status }}</td>
            <td><RouterLink :to="{ name: 'admin-order-detail', params: { id: order.id }, query: route.query }">查看詳細</RouterLink></td>
          </tr>
        </tbody>
      </table>
    </div>
    <AppPagination v-if="!isLoading && !errorMessage" :current-page="pagination.current_page" :last-page="pagination.last_page" label="後台訂單分頁" @change-page="changePage" />
  </section>
</template>

<style scoped>
table { min-width: 960px; overflow-wrap: normal; }
th, td:first-child { white-space: nowrap; }
</style>
