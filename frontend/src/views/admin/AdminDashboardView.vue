<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import axios from 'axios'
import { getAdminDashboard } from '@/services/dashboardService'
import type { AdminDashboardStats } from '@/types/adminDashboard'
import type { ApiErrorResponse } from '@/types/api'

const stats = ref<AdminDashboardStats | null>(null)
const isLoading = ref(false)
const errorMessage = ref('')
let requestId = 0
const allHidden = computed(() => stats.value !== null &&
  stats.value.products === null && stats.value.members === null && stats.value.orders === null)
const orderLabels: Record<string, string> = {
  pending: '待處理', processing: '處理中', shipped: '已出貨', completed: '已完成', cancelled: '已取消',
}
const paymentLabels: Record<string, string> = { unpaid: '未付款', paid: '已付款' }
const statusLabel = (labels: Record<string, string>, value: string) =>
  Object.hasOwn(labels, value) ? labels[value] : value || '未知狀態'
// Only format the server decimal string; retain cents and large totals without floating-point conversion.
const formatAmount = (value: string) => value.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
const formatDate = (value: string) => {
  const date = new Date(value)
  return value && !Number.isNaN(date.getTime()) ? date.toLocaleString('zh-TW', { timeZone: 'Asia/Taipei' }) : '—'
}
const load = async () => {
  const current = ++requestId
  isLoading.value = true
  errorMessage.value = ''
  try {
    const result = await getAdminDashboard()
    if (current === requestId) stats.value = result
  } catch (error) {
    if (current !== requestId) return
    errorMessage.value = axios.isAxiosError<ApiErrorResponse>(error)
      ? error.response?.data?.message || 'Dashboard 載入失敗，請稍後再試。'
      : 'Dashboard 載入失敗，請稍後再試。'
  } finally {
    if (current === requestId) isLoading.value = false
  }
}
const refresh = () => {
  if (isLoading.value) return
  void load()
}
onMounted(() => { void load() })
onBeforeUnmount(() => { ++requestId })
</script>

<template>
  <section aria-labelledby="admin-dashboard-title" :aria-busy="isLoading">
    <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
      <h1 id="admin-dashboard-title" class="h3 mb-0">Dashboard／管理後台首頁</h1>
      <button type="button" class="btn btn-outline-primary" :disabled="isLoading" @click="refresh">重新整理</button>
    </div>
    <p v-if="isLoading" role="status">{{ stats ? '正在更新 Dashboard…' : '正在載入 Dashboard…' }}</p>
    <div v-if="errorMessage" class="alert alert-danger" role="alert">
      <p v-if="stats">最新資料重新載入失敗，以下保留上次成功取得的資料，可能已過時。</p>
      <p>{{ errorMessage }}</p>
      <button type="button" class="btn btn-outline-danger" :disabled="isLoading" @click="refresh">重試</button>
    </div>
    <template v-if="stats">
      <div v-if="allHidden" class="alert alert-info">
        歡迎使用管理後台。您目前沒有可查看的統計區塊，請從選單進入已授權的管理功能。
      </div>
      <div class="row g-3 mb-4">
        <div v-if="stats.products !== null" class="col-12 col-sm-6 col-xl-4" data-stat="products">
          <div class="card h-100"><div class="card-body"><h2 class="h6">商品總數</h2><p class="fs-3 mb-0">{{ stats.products.total }}</p></div></div>
        </div>
        <div v-if="stats.members !== null" class="col-12 col-sm-6 col-xl-4" data-stat="members">
          <div class="card h-100"><div class="card-body"><h2 class="h6">會員總數</h2><p class="fs-3 mb-0">{{ stats.members.total }}</p></div></div>
        </div>
        <template v-if="stats.orders !== null">
          <div v-for="item in [
            { key: 'orders', title: '訂單總數', value: stats.orders.total },
            { key: 'pending', title: '待處理訂單', value: stats.orders.pending },
            { key: 'awaiting-shipment', title: '待出貨訂單', value: stats.orders.awaiting_shipment },
            { key: 'completed-amount', title: '已完成訂單總額', value: 'NT$ ' + formatAmount(stats.orders.completed_order_amount) },
          ]" :key="item.key" class="col-12 col-sm-6 col-xl-4" :data-stat="item.key">
            <div class="card h-100"><div class="card-body"><h2 class="h6">{{ item.title }}</h2><p class="fs-3 mb-0">{{ item.value }}</p><p v-if="item.key === 'completed-amount'" class="small text-muted mb-0">累計已完成訂單金額，包含運費。</p></div></div>
          </div>
        </template>
      </div>
      <section v-if="stats.orders !== null" aria-labelledby="dashboard-recent-title">
        <h2 id="dashboard-recent-title" class="h5">近期訂單</h2>
        <p v-if="stats.orders.recent_orders.length === 0">目前沒有近期訂單。</p>
        <div v-else class="table-responsive">
          <table class="table align-middle dashboard-orders">
            <caption class="visually-hidden">最近五筆訂單</caption>
            <thead><tr><th scope="col">訂單編號</th><th scope="col">建立時間</th><th scope="col">訂單金額</th><th scope="col">訂單狀態</th><th scope="col">付款狀態</th><th scope="col">詳細</th></tr></thead>
            <tbody><tr v-for="order in stats.orders.recent_orders" :key="order.id">
              <td>{{ order.order_no }}</td><td>{{ formatDate(order.created_at) }}</td><td>NT$ {{ formatAmount(order.total_amount) }}</td>
              <td>{{ statusLabel(orderLabels, order.order_status) }}</td>
              <td>{{ statusLabel(paymentLabels, order.payment_status) }}</td>
              <td><RouterLink :to="{ name: 'admin-order-detail', params: { id: order.id } }" :aria-label="'查看訂單 ' + order.order_no">查看</RouterLink></td>
            </tr></tbody>
          </table>
        </div>
      </section>
    </template>
  </section>
</template>

<style scoped>
.dashboard-orders { min-width: 46rem; }
.dashboard-orders th, .dashboard-orders td { white-space: nowrap; }
</style>
