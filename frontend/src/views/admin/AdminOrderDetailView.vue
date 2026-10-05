<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import axios from 'axios'
import { getAdminOrder } from '@/services/adminOrderService'
import type { AdminOrderDetail } from '@/types/adminOrder'
import type { ApiErrorResponse } from '@/types/api'

const route = useRoute()
const order = ref<AdminOrderDetail | null>(null)
const isLoading = ref(false), errorMessage = ref('')
let requestId = 0
const orderLabels = { pending: '待處理', processing: '處理中', shipped: '已出貨', completed: '已完成', cancelled: '已取消' }
const load = async () => {
  const current = ++requestId
  order.value = null
  isLoading.value = true
  errorMessage.value = ''
  try {
    const result = await getAdminOrder(String(route.params.id))
    if (current === requestId) order.value = result
  } catch (error) {
    if (current !== requestId) return
    errorMessage.value = axios.isAxiosError<ApiErrorResponse>(error) && error.response?.status === 404
      ? '訂單不存在或無法取得。'
      : axios.isAxiosError<ApiErrorResponse>(error)
        ? error.response?.data?.message || '訂單詳細載入失敗，請重試。'
        : '訂單詳細載入失敗，請重試。'
  } finally {
    if (current === requestId) isLoading.value = false
  }
}
const formatDate = (value: string | null) => value ? new Date(value).toLocaleString('zh-TW', { timeZone: 'Asia/Taipei' }) : '—'
watch(() => route.params.id, load, { immediate: true })
onBeforeUnmount(() => { ++requestId })
</script>

<template>
  <section aria-labelledby="admin-order-detail-title">
    <RouterLink :to="{ name: 'admin-orders', query: route.query }">返回訂單列表</RouterLink>
    <h1 id="admin-order-detail-title" class="h3 mt-3">訂單詳細</h1>
    <p v-if="isLoading" role="status">訂單詳細載入中…</p>
    <div v-else-if="errorMessage" role="alert" class="alert alert-danger">
      {{ errorMessage }} <button type="button" class="btn btn-outline-danger ms-2" @click="load">重試</button>
    </div>
    <template v-else-if="order">
      <h2 class="h4">{{ order.order_no }}</h2>
      <dl class="row">
        <dt class="col-sm-3">建立／更新時間（台北）</dt><dd class="col-sm-9">{{ formatDate(order.created_at) }} / {{ formatDate(order.updated_at) }}</dd>
        <dt class="col-sm-3">目前會員</dt><dd class="col-sm-9">{{ order.user?.name ?? '—' }} / {{ order.user?.email ?? '—' }} / {{ order.user?.status ?? '—' }}</dd>
        <dt class="col-sm-3">訂單狀態</dt><dd class="col-sm-9">{{ orderLabels[order.order_status] ?? order.order_status }}</dd>
        <dt class="col-sm-3">付款方式／狀態</dt><dd class="col-sm-9">{{ order.payment_method === 'cod' ? '貨到付款' : order.payment_method === 'mock_credit_card' ? '模擬信用卡' : order.payment_method }} / {{ order.payment_status === 'paid' ? '已付款' : '未付款' }}</dd>
        <dt class="col-sm-3">配送方式</dt><dd class="col-sm-9">{{ order.shipping_method === 'home_delivery' ? '宅配' : order.shipping_method }}</dd>
        <dt class="col-sm-3">物流公司／單號</dt><dd class="col-sm-9">{{ order.logistics_company ?? '尚未提供' }} / {{ order.tracking_number ?? '尚未提供' }}</dd>
      </dl>
      <h2 class="h5">訂購人（下單快照）</h2>
      <p>{{ order.purchaser.name }} / {{ order.purchaser.phone }} / {{ order.purchaser.email }}</p>
      <h2 class="h5">收件人（下單快照）</h2>
      <p>{{ order.recipient.name }} / {{ order.recipient.phone }}</p>
      <p>{{ order.recipient.postal_code }} {{ order.recipient.city }}{{ order.recipient.district }}{{ order.recipient.address }}</p>
      <h2 class="h5">商品明細（下單快照）</h2>
      <div class="table-responsive">
        <table class="table">
          <caption class="visually-hidden">訂單商品快照</caption>
          <thead><tr><th>商品編號</th><th>商品名稱</th><th>購買規格</th><th>單價</th><th>數量</th><th>小計</th></tr></thead>
          <tbody><tr v-for="item in order.items" :key="item.id"><td>{{ item.product_code }}</td><td>{{ item.product_name }}</td><td>{{ item.variant ?? '無規格' }}</td><td>NT$ {{ item.unit_price }}</td><td>{{ item.quantity }}</td><td>NT$ {{ item.subtotal }}</td></tr></tbody>
        </table>
      </div>
      <dl class="row">
        <dt class="col-sm-3">商品小計</dt><dd class="col-sm-9">NT$ {{ order.subtotal }}</dd>
        <dt class="col-sm-3">運費</dt><dd class="col-sm-9">NT$ {{ order.shipping_fee }}</dd>
        <dt class="col-sm-3">總金額</dt><dd class="col-sm-9">NT$ {{ order.total_amount }}</dd>
      </dl>
    </template>
  </section>
</template>

<style scoped>
table { min-width: 660px; overflow-wrap: normal; }
th, td:first-child { white-space: nowrap; }
</style>
