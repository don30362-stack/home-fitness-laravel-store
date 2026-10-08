<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import AppPagination from '@/components/common/AppPagination.vue'
import { getAdminUser, updateAdminUserStatus } from '@/services/adminUserService'
import type { AdminUserDetail } from '@/types/adminUser'
import type { ApiErrorResponse } from '@/types/api'
import { useAdminAuthStore } from '@/stores/adminAuth'

const route = useRoute(), router = useRouter()
const auth = useAdminAuthStore()
const detail = ref<AdminUserDetail | null>(null)
const isLoading = ref(false), errorMessage = ref(''), refreshError = ref('')
const mutationPending = ref(false), mutationMessage = ref(''), mutationError = ref(''), confirming = ref(false)
const controlsDisabled = computed(() => mutationPending.value || isLoading.value || Boolean(refreshError.value))
const labels = { active: '啟用', disabled: '停用', inactive: '舊停用狀態（inactive）' }
const orderLabels = { pending: '待處理', processing: '處理中', shipped: '已出貨', completed: '已完成', cancelled: '已取消' }
const listQuery = computed(() => Object.fromEntries(Object.entries(route.query).filter(([key]) => key !== 'order_page')))
let contextVersion = 0, requestId = 0
const fetchDetail = async (preserve = false) => {
  const context = contextVersion, current = ++requestId, id = String(route.params.id)
  if (!preserve) detail.value = null
  isLoading.value = true
  errorMessage.value = ''
  refreshError.value = ''
  try {
    const page = route.query.order_page
    if (page != null && typeof page !== 'string') throw new Error('歷史訂單分頁格式不正確。')
    const result = await getAdminUser(id, page ?? undefined)
    if (context !== contextVersion || current !== requestId) return
    if (result.orders.meta.current_page > result.orders.meta.last_page) {
      await router.replace({ query: { ...route.query, order_page: String(result.orders.meta.last_page) } })
      return
    }
    detail.value = result
  } catch (error) {
    if (context !== contextVersion || current !== requestId) return
    const reason = axios.isAxiosError<ApiErrorResponse>(error) && error.response?.status === 404 ? '會員不存在或無法取得。'
      : axios.isAxiosError<ApiErrorResponse>(error) ? error.response?.data?.message || '會員詳細載入失敗，請重試。'
        : error instanceof Error ? error.message : '會員詳細載入失敗，請重試。'
    if (preserve) refreshError.value = `最新資料重新載入失敗：${reason} 請重試讀取，勿重送操作。`
    else errorMessage.value = reason
  } finally { if (context === contextVersion && current === requestId) isLoading.value = false }
}
const load = async () => {
  if (mutationPending.value || isLoading.value) return
  await fetchDetail(Boolean(detail.value))
}
const openConfirm = () => {
  if (controlsDisabled.value || !detail.value || !['active', 'disabled', 'inactive'].includes(detail.value.user.status)) return
  confirming.value = true
}
const closeConfirm = () => { if (!mutationPending.value) confirming.value = false }
const mutate = async () => {
  if (controlsDisabled.value || !detail.value || !confirming.value) return
  const context = contextVersion, id = String(detail.value.user.id)
  const status = detail.value.user.status === 'active' ? 'disabled' : 'active'
  mutationPending.value = true
  mutationMessage.value = ''
  mutationError.value = ''
  try {
    const result = await updateAdminUserStatus(id, { status })
    if (context !== contextVersion) return
    confirming.value = false
    mutationMessage.value = result.message
    await fetchDetail(true)
  } catch (error) {
    if (context !== contextVersion) return
    mutationError.value = axios.isAxiosError<ApiErrorResponse>(error)
      ? Object.values(error.response?.data?.errors ?? {}).flat().join(' ') || error.response?.data?.message || '會員狀態操作失敗，請稍後再試。'
      : '會員狀態操作失敗，請稍後再試。'
  } finally { if (context === contextVersion) mutationPending.value = false }
}
const changePage = (page: number) => {
  if (mutationPending.value || isLoading.value) return
  void router.push({ query: { ...route.query, order_page: String(page) } })
}
const formatDate = (value: string | null) => value ? new Date(value).toLocaleString('zh-TW', { timeZone: 'Asia/Taipei' }) : '—'
watch(() => [route.params.id, route.query.order_page], () => {
  ++contextVersion
  mutationPending.value = false
  confirming.value = false
  mutationMessage.value = ''
  mutationError.value = ''
  void fetchDetail()
}, { immediate: true })
onBeforeUnmount(() => { ++contextVersion; ++requestId })
</script>

<template>
  <section aria-labelledby="admin-user-detail-title">
    <RouterLink :to="{ name: 'admin-users', query: listQuery }">返回會員列表</RouterLink>
    <h1 id="admin-user-detail-title" class="h3 mt-3">會員詳細</h1>
    <p v-if="isLoading" role="status">會員詳細載入中…</p>
    <div v-if="errorMessage" role="alert" class="alert alert-danger">{{ errorMessage }} <button type="button" class="btn btn-outline-danger" @click="load">重試</button></div>
    <template v-if="detail">
      <dl class="row"><dt class="col-sm-3">姓名</dt><dd class="col-sm-9">{{ detail.user.name }}</dd><dt class="col-sm-3">Email</dt><dd class="col-sm-9 text-break">{{ detail.user.email }}</dd><dt class="col-sm-3">電話</dt><dd class="col-sm-9">{{ detail.user.phone ?? '—' }}</dd><dt class="col-sm-3">狀態</dt><dd class="col-sm-9">{{ labels[detail.user.status] ?? detail.user.status }}</dd><dt class="col-sm-3">建立／更新時間（台北）</dt><dd class="col-sm-9">{{ formatDate(detail.user.created_at) }} / {{ formatDate(detail.user.updated_at) }}</dd></dl>
      <p v-if="mutationPending" role="status">會員狀態操作處理中…</p>
      <p v-if="mutationMessage" role="status" class="alert alert-success">{{ mutationMessage }}</p>
      <p v-if="mutationError" role="alert" class="alert alert-danger">{{ mutationError }}</p>
      <div v-if="refreshError" role="alert" class="alert alert-warning">{{ refreshError }} <button type="button" class="btn btn-outline-dark" :disabled="mutationPending || isLoading" @click="load">重試讀取</button></div>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <button v-if="['active', 'disabled', 'inactive'].includes(detail.user.status)" type="button" class="btn btn-outline-danger" :disabled="controlsDisabled" @click="openConfirm">{{ detail.user.status === 'active' ? '停用會員' : detail.user.status === 'inactive' ? '恢復啟用（舊狀態）' : '恢復啟用' }}</button>
        <button type="button" class="btn btn-outline-secondary" :disabled="controlsDisabled" @click="load">重新讀取會員</button>
      </div>
      <div v-if="confirming" aria-label="會員狀態確認" class="alert alert-warning">
        <p v-if="detail.user.status === 'active'">確定停用此會員嗎？會員資料、地址、購物車與歷史訂單都會保留。這不是立即全裝置登出；會員下一次受保護請求才由 C06 拒絕並撤銷該 session。同一 session 的 Admin 身分也可能因此失效。</p>
        <p v-else>確定恢復啟用嗎？會員可重新登入使用；已撤銷的舊 session 不會復活，必須重新登入。</p>
        <div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-danger" :disabled="controlsDisabled" @click="mutate">確認變更會員狀態</button><button type="button" class="btn btn-outline-secondary" :disabled="controlsDisabled" @click="closeConfirm">返回，不變更</button></div>
      </div>
      <h2 class="h5">歷史訂單</h2>
      <p v-if="detail.orders.data.length === 0">此會員尚無歷史訂單。</p>
      <div v-else class="table-responsive"><table class="table"><caption class="visually-hidden">會員歷史訂單</caption><thead><tr><th scope="col">訂單編號</th><th scope="col">建立時間（台北）</th><th scope="col">總金額</th><th scope="col">付款方式</th><th scope="col">付款狀態</th><th scope="col">訂單狀態</th><th scope="col">詳細</th></tr></thead><tbody><tr v-for="order in detail.orders.data" :key="order.id"><td>{{ order.order_no }}</td><td>{{ formatDate(order.created_at) }}</td><td>NT$ {{ order.total_amount }}</td><td>{{ order.payment_method === 'cod' ? '貨到付款' : order.payment_method === 'mock_credit_card' ? '模擬信用卡' : order.payment_method }}</td><td>{{ order.payment_status === 'paid' ? '已付款' : '未付款' }}</td><td>{{ orderLabels[order.order_status] ?? order.order_status }}</td><td><RouterLink v-if="auth.hasPermission('order_manage')" :to="{ name: 'admin-order-detail', params: { id: order.id } }">查看訂單</RouterLink><span v-else>無訂單管理權限</span></td></tr></tbody></table></div>
      <AppPagination v-if="!isLoading" :current-page="detail.orders.meta.current_page" :last-page="detail.orders.meta.last_page" label="會員歷史訂單分頁" @change-page="changePage" />
    </template>
  </section>
</template>

<style scoped>
table { min-width: 800px; overflow-wrap: normal; }
th, td:first-child { white-space: nowrap; }
</style>
