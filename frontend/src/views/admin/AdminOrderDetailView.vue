<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import axios from 'axios'
import { getAdminOrder, updateAdminOrderStatus, updateAdminOrderPaymentStatus, updateAdminOrderShipment, cancelAdminOrder } from '@/services/adminOrderService'
import type { AdminOrderDetail } from '@/types/adminOrder'
import type { ApiErrorResponse } from '@/types/api'

const route = useRoute()
const order = ref<AdminOrderDetail | null>(null)
const isLoading = ref(false), errorMessage = ref('')
const mutationPending = ref(false), mutationMessage = ref(''), mutationError = ref(''), refreshError = ref('')
const company = ref(''), tracking = ref('')
const cancelConfirm = ref(false)
let requestId = 0
let contextVersion = 0
const controlsDisabled = computed(() => mutationPending.value || isLoading.value || Boolean(refreshError.value))
const orderLabels = { pending: '待處理', processing: '處理中', shipped: '已出貨', completed: '已完成', cancelled: '已取消' }
const fetchDetail = async (preserve = false) => {
  const context = contextVersion
  const current = ++requestId
  const id = String(route.params.id)
  if (!preserve) order.value = null
  isLoading.value = true
  errorMessage.value = ''
  refreshError.value = ''
  try {
    const result = await getAdminOrder(id)
    if (context !== contextVersion || current !== requestId) return
    order.value = result
    company.value = result.logistics_company ?? ''
    tracking.value = result.tracking_number ?? ''
  } catch (error) {
    if (context !== contextVersion || current !== requestId) return
    const reason = axios.isAxiosError<ApiErrorResponse>(error) && error.response?.status === 404
      ? '訂單不存在或無法取得。'
      : axios.isAxiosError<ApiErrorResponse>(error)
        ? error.response?.data?.message || '訂單詳細載入失敗，請重試。'
        : '訂單詳細載入失敗，請重試。'
    if (preserve) refreshError.value = `最新資料重新載入失敗：${reason} 請重試讀取，勿重送操作。`
    else errorMessage.value = reason
  } finally {
    if (context === contextVersion && current === requestId) isLoading.value = false
  }
}
const load = async () => {
  if (mutationPending.value || isLoading.value) return
  await fetchDetail(Boolean(order.value))
}
const openCancel = () => {
  if (controlsDisabled.value || !order.value || !['pending', 'processing'].includes(order.value.order_status)) return
  cancelConfirm.value = true
}
const closeCancel = () => {
  if (mutationPending.value) return
  cancelConfirm.value = false
}
const mutate = async (action: 'processing' | 'completed' | 'payment' | 'shipment' | 'cancel') => {
  if (controlsDisabled.value || !order.value) return
  if (action === 'cancel' && !cancelConfirm.value) return
  const context = contextVersion
  const id = String(order.value.id)
  mutationPending.value = true
  mutationMessage.value = ''
  mutationError.value = ''
  try {
    const result = action === 'cancel'
      ? await cancelAdminOrder(id)
      : action === 'payment'
      ? await updateAdminOrderPaymentStatus(id, { payment_status: 'paid' })
      : action === 'shipment'
        ? await updateAdminOrderShipment(id, { logistics_company: company.value.trim(), tracking_number: tracking.value.trim() })
        : await updateAdminOrderStatus(id, { order_status: action })
    if (context !== contextVersion) return
    cancelConfirm.value = false
    mutationMessage.value = result.message
    // Mutation success is retained even if the authoritative GET fails. Never retry the mutation.
    await fetchDetail(true)
  } catch (error) {
    if (context !== contextVersion) return
    mutationError.value = axios.isAxiosError<ApiErrorResponse>(error)
      ? Object.values(error.response?.data?.errors ?? {}).flat().join(' ') || error.response?.data?.message || '訂單操作失敗，請稍後再試。'
      : '訂單操作失敗，請稍後再試。'
  } finally {
    if (context === contextVersion) mutationPending.value = false
  }
}
const formatDate = (value: string | null) => value ? new Date(value).toLocaleString('zh-TW', { timeZone: 'Asia/Taipei' }) : '—'
watch(() => route.params.id, () => {
  ++contextVersion
  mutationPending.value = false
  cancelConfirm.value = false
  mutationMessage.value = ''
  mutationError.value = ''
  company.value = ''
  tracking.value = ''
  void fetchDetail()
}, { immediate: true })
onBeforeUnmount(() => { ++contextVersion; ++requestId })
</script>

<template>
  <section aria-labelledby="admin-order-detail-title">
    <RouterLink :to="{ name: 'admin-orders', query: route.query }">返回訂單列表</RouterLink>
    <h1 id="admin-order-detail-title" class="h3 mt-3">訂單詳細</h1>
    <p v-if="isLoading" role="status">訂單詳細載入中…</p>
    <div v-if="errorMessage" role="alert" class="alert alert-danger">
      {{ errorMessage }} <button type="button" class="btn btn-outline-danger ms-2" @click="load">重試</button>
    </div>
    <template v-if="order">
      <h2 class="h4">{{ order.order_no }}</h2>
      <p v-if="mutationPending" role="status">訂單操作處理中…</p>
      <p v-if="mutationMessage" role="status" class="alert alert-success">{{ mutationMessage }}</p>
      <p v-if="mutationError" role="alert" class="alert alert-danger">{{ mutationError }}</p>
      <div v-if="refreshError" role="alert" class="alert alert-warning">
        {{ refreshError }}
        <button type="button" class="btn btn-outline-dark ms-2" :disabled="mutationPending || isLoading" @click="load">重試讀取</button>
      </div>
      <section aria-label="訂單處理操作" class="border rounded p-3 mb-3">
        <h2 class="h5">訂單處理</h2>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <button v-if="order.order_status === 'pending'" type="button" class="btn btn-primary" :disabled="controlsDisabled" @click="mutate('processing')">設為處理中</button>
          <button v-if="order.order_status === 'shipped' && order.payment_status === 'paid'" type="button" class="btn btn-primary" :disabled="controlsDisabled" @click="mutate('completed')">設為已完成</button>
          <button v-if="order.payment_status === 'unpaid' && order.order_status !== 'cancelled'" type="button" class="btn btn-outline-primary" :disabled="controlsDisabled" @click="mutate('payment')">標記為已付款</button>
          <button v-if="order.order_status === 'pending' || order.order_status === 'processing'" type="button" class="btn btn-outline-danger" :disabled="controlsDisabled" @click="openCancel">取消訂單</button>
          <button type="button" class="btn btn-outline-secondary" :disabled="controlsDisabled" @click="load">重新讀取訂單</button>
        </div>
        <div v-if="cancelConfirm" aria-label="取消訂單確認" class="alert alert-warning">
          <p>確定取消訂單 {{ order.order_no }} 嗎？取消後訂單會成為已取消（cancelled），商品庫存會依原訂單明細恢復。付款狀態會保持原值；若已付款仍維持已付款，本系統不會自動退款。</p>
          <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn btn-danger" :disabled="controlsDisabled" @click="mutate('cancel')">確認取消訂單</button>
            <button type="button" class="btn btn-outline-secondary" :disabled="controlsDisabled" @click="closeCancel">返回，不取消</button>
          </div>
        </div>
        <p v-if="order.order_status === 'shipped' && order.payment_status === 'unpaid'">訂單尚未付款，請先標記為已付款後再完成。</p>
        <form v-if="order.order_status === 'processing' || order.order_status === 'shipped'" aria-label="物流維護表單" class="row g-3" @submit.prevent="mutate('shipment')">
          <div class="col-12 col-lg-5"><label for="order-logistics-company" class="form-label">物流公司</label><input id="order-logistics-company" v-model="company" class="form-control" required maxlength="100" :disabled="controlsDisabled" /></div>
          <div class="col-12 col-lg-5"><label for="order-tracking-number" class="form-label">物流單號</label><input id="order-tracking-number" v-model="tracking" class="form-control" required maxlength="100" :disabled="controlsDisabled" /></div>
          <div class="col-12 col-lg-2 d-flex align-items-end"><button type="submit" class="btn btn-primary" :disabled="controlsDisabled">{{ order.order_status === 'processing' ? '儲存物流並出貨' : '更新物流資料' }}</button></div>
        </form>
      </section>
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
