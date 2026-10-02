<script setup lang="ts">
import axios from 'axios'
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { getOrder } from '@/services/orderService'
import type { OrderDetail } from '@/types/order'

const route = useRoute()
const order = ref<OrderDetail | null>(null)
const isLoading = ref(false)
const errorMessage = ref('')
let requestId = 0

const fetchOrder = async () => {
  const currentRequest = ++requestId
  order.value = null
  errorMessage.value = ''
  isLoading.value = true
  try {
    const response = await getOrder(String(route.params.id))
    if (currentRequest === requestId) order.value = response.data
  } catch (error) {
    if (currentRequest !== requestId) return
    errorMessage.value = axios.isAxiosError(error) && error.response?.status === 404
      ? '找不到此訂單。' : '訂單載入失敗，請稍後再試。'
  } finally {
    if (currentRequest === requestId) isLoading.value = false
  }
}

const money = (value: string) => `NT$ ${Number(value).toLocaleString('zh-TW')}`
const date = (value: string | null) => value
  ? new Date(value).toLocaleString('zh-TW', { timeZone: 'Asia/Taipei' }) : '—'
const orderLabels: Record<string, string> = {
  pending: '待處理', processing: '處理中', shipped: '已出貨', completed: '已完成', cancelled: '已取消',
}
const paymentLabels: Record<string, string> = { unpaid: '未付款', paid: '已付款' }
const methodLabels: Record<string, string> = { cod: '貨到付款', mock_credit_card: '模擬信用卡' }
watch(() => route.params.id, fetchOrder, { immediate: true })
onBeforeUnmount(() => { ++requestId })
</script>

<template>
  <section aria-labelledby="order-title">
    <h2 id="order-title" class="h4 mb-4">訂單詳細</h2>
    <RouterLink :to="{ name: 'member-orders' }" class="btn btn-outline-secondary mb-4">返回我的訂單</RouterLink>
    <p v-if="isLoading" role="status">訂單載入中…</p>
    <div v-else-if="errorMessage" class="alert alert-danger" role="alert">
      <p>{{ errorMessage }}</p>
      <button type="button" class="btn btn-outline-danger" @click="fetchOrder">重新載入</button>
    </div>
    <div v-else-if="order">
      <dl class="row">
        <dt class="col-sm-4">訂單編號</dt><dd class="col-sm-8 text-break">{{ order.order_no }}</dd>
        <dt class="col-sm-4">成立時間</dt><dd class="col-sm-8">{{ date(order.created_at) }}</dd>
        <dt class="col-sm-4">付款方式</dt><dd class="col-sm-8">{{ methodLabels[order.payment_method] ?? order.payment_method }}</dd>
        <dt class="col-sm-4">付款狀態</dt><dd class="col-sm-8">{{ paymentLabels[order.payment_status] ?? order.payment_status }}</dd>
        <dt class="col-sm-4">訂單狀態</dt><dd class="col-sm-8">{{ orderLabels[order.order_status] ?? order.order_status }}</dd>
      </dl>
      <h3 class="h5 mt-4">商品明細</h3>
      <div class="table-responsive">
        <table class="table align-middle">
          <caption class="visually-hidden">訂單商品快照</caption>
          <thead><tr><th scope="col">商品</th><th scope="col">規格</th><th scope="col">數量</th><th scope="col">單價</th><th scope="col">小計</th></tr></thead>
          <tbody><tr v-for="item in order.items" :key="item.id">
            <td>{{ item.product_name }}<small class="d-block text-muted">{{ item.product_code }}</small></td>
            <td>{{ item.variant ?? '無規格' }}</td><td>{{ item.quantity }}</td>
            <td>{{ money(item.unit_price) }}</td><td>{{ money(item.subtotal) }}</td>
          </tr></tbody>
        </table>
      </div>
      <dl class="row">
        <dt class="col-sm-4">商品小計</dt><dd class="col-sm-8">{{ money(order.subtotal) }}</dd>
        <dt class="col-sm-4">運費</dt><dd class="col-sm-8">{{ money(order.shipping_fee) }}</dd>
        <dt class="col-sm-4">總金額</dt><dd class="col-sm-8 fw-bold">{{ money(order.total_amount) }}</dd>
      </dl>
      <div class="row g-4 mt-2">
        <section class="col-md-6">
          <h3 class="h5">訂購人資料</h3>
          <dl><dt>姓名</dt><dd>{{ order.purchaser.name }}</dd><dt>電話</dt><dd>{{ order.purchaser.phone }}</dd><dt>電子郵件</dt><dd class="text-break">{{ order.purchaser.email }}</dd></dl>
        </section>
        <section class="col-md-6">
          <h3 class="h5">收件人資料</h3>
          <dl><dt>姓名</dt><dd>{{ order.recipient.name }}</dd><dt>電話</dt><dd>{{ order.recipient.phone }}</dd><dt>地址</dt><dd>{{ order.recipient.postal_code }} {{ order.recipient.city }}{{ order.recipient.district }}{{ order.recipient.address }}</dd></dl>
        </section>
      </div>
      <h3 class="h5 mt-4">物流資訊</h3>
      <dl class="row">
        <dt class="col-sm-4">配送方式</dt><dd class="col-sm-8">{{ order.shipping_method === 'home_delivery' ? '宅配' : order.shipping_method }}</dd>
        <dt class="col-sm-4">物流公司</dt><dd class="col-sm-8">{{ order.logistics_company ?? '尚無資料' }}</dd>
        <dt class="col-sm-4">追蹤編號</dt><dd class="col-sm-8 text-break">{{ order.tracking_number ?? '尚無資料' }}</dd>
      </dl>
    </div>
  </section>
</template>
