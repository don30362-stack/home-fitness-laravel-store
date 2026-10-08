<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import AppPagination from '@/components/common/AppPagination.vue'
import { getAdminUsers } from '@/services/adminUserService'
import type { AdminUser, AdminUserQuery } from '@/types/adminUser'
import type { ApiErrorResponse, PaginationMeta } from '@/types/api'

const route = useRoute(), router = useRouter()
const users = ref<AdminUser[]>([])
const pagination = ref<PaginationMeta>({ current_page: 1, last_page: 1, per_page: 10, total: 0 })
const search = ref(''), status = ref(''), isLoading = ref(false), errorMessage = ref('')
const labels = { active: '啟用', disabled: '停用', inactive: '舊停用狀態（inactive）' }
let requestId = 0
const params = (): AdminUserQuery => {
  const result: AdminUserQuery = {}
  for (const key of ['search', 'status', 'page'] as const) {
    const value = route.query[key]
    if (value == null || value === '') continue
    if (typeof value !== 'string') throw new Error('查詢條件格式不正確，請重新設定。')
    result[key] = value
  }
  return result
}
const load = async () => {
  const current = ++requestId
  isLoading.value = true
  errorMessage.value = ''
  users.value = []
  try {
    const result = await getAdminUsers(params())
    if (current !== requestId) return
    if (result.meta.current_page > result.meta.last_page) {
      await router.replace({ query: { ...route.query, page: String(result.meta.last_page) } })
      return
    }
    users.value = result.data
    pagination.value = result.meta
  } catch (error) {
    if (current !== requestId) return
    errorMessage.value = axios.isAxiosError<ApiErrorResponse>(error)
      ? error.response?.data?.message || '會員載入失敗，請稍後再試。'
      : error instanceof Error ? error.message : '會員載入失敗，請稍後再試。'
  } finally { if (current === requestId) isLoading.value = false }
}
const applyFilters = () => {
  const query: Record<string, string> = { page: '1' }
  if (search.value.trim()) query.search = search.value.trim()
  if (status.value) query.status = status.value
  void router.push({ query })
}
const changePage = (page: number) => { void router.push({ query: { ...route.query, page: String(page) } }) }
const formatDate = (value: string | null) => value ? new Date(value).toLocaleString('zh-TW', { timeZone: 'Asia/Taipei' }) : '—'
watch(() => route.query, () => {
  search.value = typeof route.query.search === 'string' ? route.query.search : ''
  status.value = typeof route.query.status === 'string' ? route.query.status : ''
  void load()
}, { immediate: true })
onBeforeUnmount(() => { ++requestId })
</script>

<template>
  <section aria-labelledby="admin-users-title">
    <h1 id="admin-users-title" class="h3">會員管理</h1>
    <form class="row g-3 mb-4" @submit.prevent="applyFilters">
      <div class="col-12 col-md-6"><label for="user-search" class="form-label">會員姓名／Email</label><input id="user-search" v-model="search" type="search" maxlength="100" class="form-control" /></div>
      <div class="col-12 col-md-4"><label for="user-status" class="form-label">會員狀態</label><select id="user-status" v-model="status" class="form-select"><option value="">全部狀態</option><option v-for="(label, value) in labels" :key="value" :value="value">{{ label }}</option></select></div>
      <div class="col-12 col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-dark">查詢</button></div>
    </form>
    <p v-if="isLoading" role="status">會員載入中…</p>
    <div v-else-if="errorMessage" role="alert" class="alert alert-danger">{{ errorMessage }} <button type="button" class="btn btn-outline-danger" @click="load">重試</button></div>
    <p v-else-if="users.length === 0">沒有符合條件的會員。</p>
    <div v-else class="table-responsive"><table class="table align-middle">
      <caption class="visually-hidden">後台會員列表</caption>
      <thead><tr><th scope="col">姓名</th><th scope="col">Email</th><th scope="col">電話</th><th scope="col">狀態</th><th scope="col">建立時間（台北）</th><th scope="col">詳細</th></tr></thead>
      <tbody><tr v-for="user in users" :key="user.id"><td>{{ user.name }}</td><td>{{ user.email }}</td><td>{{ user.phone ?? '—' }}</td><td>{{ labels[user.status] ?? user.status }}</td><td>{{ formatDate(user.created_at) }}</td><td><RouterLink :to="{ name: 'admin-user-detail', params: { id: user.id }, query: route.query }">查看詳細</RouterLink></td></tr></tbody>
    </table></div>
    <AppPagination v-if="!isLoading && !errorMessage" :current-page="pagination.current_page" :last-page="pagination.last_page" label="後台會員分頁" @change-page="changePage" />
  </section>
</template>

<style scoped>
table { min-width: 720px; overflow-wrap: normal; }
th { white-space: nowrap; }
</style>
