<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import { useAdminAuthStore } from '@/stores/adminAuth'
import { getAdminPermissionContext, notifyAdminPermissionsChanged } from '@/services/adminSessionNavigation'
import { getManagedAdmins, getAdminPermissionCatalog, getManagedAdmin, createManagedAdmin, updateManagedAdmin, updateManagedAdminStatus, replaceManagedAdminPermissions } from '@/services/adminManagementService'
import type { AdminListItem, AdminDetail, AdminPermissionOption, AdminManagementResponse, CreateManagedAdminPayload } from '@/types/adminManagement'

const auth = useAdminAuthStore()
const admins = ref<AdminListItem[]>([]), catalog = ref<AdminPermissionOption[]>([]), detail = ref<AdminDetail | null>(null)
const selected = ref<number | null>(null), creating = ref(false), pending = ref(false)
const listLoading = ref(false), catalogLoading = ref(false), detailLoading = ref(false)
const listError = ref(''), catalogError = ref(''), detailError = ref(''), identityError = ref('')
const error = ref(''), message = ref(''), fieldErrors = ref<string[]>([])
const blank = (): CreateManagedAdminPayload => ({ name: '', email: '', password: '', password_confirmation: '', status: 'active' })
const draft = reactive(blank()), basic = reactive({ name: '', email: '' })
const permissionIds = ref<number[]>([]), confirmingStatus = ref(false)
let disposed = false, context = 0, listSequence = 0, catalogSequence = 0, detailSequence = 0
const stale = computed(() => !!(listError.value || catalogError.value || detailError.value || identityError.value))
const blocked = computed(() => pending.value || listLoading.value || catalogLoading.value || detailLoading.value || stale.value)
const valid = (version: number) => !disposed && version === context
const failure = (cause: unknown) => {
  if (axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(cause)) {
    if (cause.response?.status === 422) {
      fieldErrors.value = Object.values(cause.response.data.errors ?? {}).flat()
      return cause.response.data.message ?? '請檢查欄位內容。'
    }
    if (cause.response?.status === 419) return '登入狀態／CSRF 發生問題，請稍後再試。'
  }
  return '管理員操作失敗，請稍後再試。'
}
const loadList = async () => {
  const version = context, request = ++listSequence
  listLoading.value = true
  try {
    const data = await getManagedAdmins()
    if (!valid(version) || request !== listSequence) return
    admins.value = data; listError.value = ''
  } catch {
    if (valid(version) && request === listSequence) listError.value = '管理員列表重新載入失敗，請手動重試。'
  } finally { if (valid(version) && request === listSequence) listLoading.value = false }
}
const loadCatalog = async () => {
  const version = context, request = ++catalogSequence
  catalogLoading.value = true
  try {
    const data = await getAdminPermissionCatalog()
    if (!valid(version) || request !== catalogSequence) return
    catalog.value = data; catalogError.value = ''
  } catch {
    if (valid(version) && request === catalogSequence) catalogError.value = '權限目錄載入失敗，請手動重試。'
  } finally { if (valid(version) && request === catalogSequence) catalogLoading.value = false }
}
const loadDetail = async () => {
  const id = selected.value
  if (id === null) return
  const version = context, request = ++detailSequence
  detailLoading.value = true
  try {
    const data = await getManagedAdmin(id)
    if (!valid(version) || request !== detailSequence || selected.value !== id) return
    detail.value = data; Object.assign(basic, { name: data.name, email: data.email })
    permissionIds.value = data.permissions.map(p => p.id); detailError.value = ''
  } catch {
    if (valid(version) && request === detailSequence) detailError.value = '管理員詳細資料重新載入失敗，請手動重試。'
  } finally { if (valid(version) && request === detailSequence) detailLoading.value = false }
}
const retry = (kind: 'list' | 'catalog' | 'detail') => {
  if (pending.value) return
  return kind === 'list' ? loadList() : kind === 'catalog' ? loadCatalog() : loadDetail()
}
const refreshIdentity = async (version: number) => {
  const navigationContext = getAdminPermissionContext()
  const result = await auth.refreshAdminIdentity()
  if (!valid(version)) return false
  if (result === 'authenticated') {
    identityError.value = ''
    if (!auth.hasPermission('admin_manage')) {
      await notifyAdminPermissionsChanged(navigationContext, false)
      return false
    }
    return true
  }
  if (result === 'stale') identityError.value = '操作已成功，但目前管理員身分重新載入失敗，請手動重試。'
  return false
}
const retryIdentity = async () => {
  if (pending.value) return
  pending.value = true
  try { await refreshIdentity(context) } finally { if (!disposed) pending.value = false }
}
const openCreate = () => {
  if (blocked.value) return
  creating.value = true; selected.value = null; detail.value = null; confirmingStatus.value = false
  Object.assign(draft, blank()); message.value = ''; error.value = ''; fieldErrors.value = []
}
const select = (id: number) => {
  if (pending.value || stale.value) return
  detailSequence++; creating.value = false; selected.value = id; detail.value = null
  confirmingStatus.value = false; message.value = ''; error.value = ''; fieldErrors.value = []
  return loadDetail()
}
const close = () => {
  if (pending.value) return
  creating.value = false; confirmingStatus.value = false
  draft.password = ''; draft.password_confirmation = ''
}
const mutate = async (operation: () => Promise<AdminManagementResponse>, self: boolean, create = false, permissionMutation = false) => {
  if (blocked.value) return
  const version = context
  pending.value = true; message.value = ''; error.value = ''; fieldErrors.value = []
  try {
    const result = await operation()
    if (!valid(version)) return
    message.value = result.message; confirmingStatus.value = false
    if (create) { creating.value = false; draft.password = ''; draft.password_confirmation = '' }
    if (self && !await refreshIdentity(version)) return
    if (!valid(version)) return
    await Promise.all([loadList(), ...(create ? [] : [loadDetail()])])
  } catch (cause) {
    if (valid(version)) {
      if (permissionMutation && detail.value) permissionIds.value = detail.value.permissions.map(option => option.id)
      error.value = failure(cause)
    }
  } finally { if (valid(version)) pending.value = false }
}
const create = () => mutate(() => createManagedAdmin({ ...draft }), false, true)
const saveBasic = () => {
  const id = selected.value
  if (id === null || blocked.value) return
  return mutate(() => updateManagedAdmin(id, { ...basic }), id === auth.currentAdmin?.id)
}
const askStatus = () => { if (!blocked.value && detail.value) confirmingStatus.value = true }
const status = () => {
  const target = detail.value
  if (!target || !confirmingStatus.value || blocked.value) return
  return mutate(() => updateManagedAdminStatus(target.id, { status: target.status === 'active' ? 'disabled' : 'active' }), target.id === auth.currentAdmin?.id)
}
const toggle = (id: number, event: Event) => {
  if (blocked.value) return
  const checked = (event.target as HTMLInputElement).checked
  permissionIds.value = checked ? [...new Set([...permissionIds.value, id])] : permissionIds.value.filter(value => value !== id)
}
const savePermissions = () => {
  const id = selected.value
  if (id === null || blocked.value) return
  return mutate(() => replaceManagedAdminPermissions(id, { permission_ids: [...permissionIds.value] }), id === auth.currentAdmin?.id, false, true)
}
onMounted(() => { void loadList(); void loadCatalog() })
onBeforeUnmount(() => { disposed = true; context++ })
</script>

<template>
  <section>
    <h1 class="h3">管理員管理</h1>
    <p v-if="message" class="alert alert-success" role="status">{{ message }}</p>
    <p v-if="error" class="alert alert-danger" role="alert">{{ error }}</p>
    <ul v-if="fieldErrors.length" class="alert alert-danger"><li v-for="item in fieldErrors" :key="item">{{ item }}</li></ul>
    <p v-if="identityError" class="alert alert-warning">{{ identityError }} <button :disabled="pending" class="btn btn-outline-secondary" @click="retryIdentity">重試目前身分</button></p>
    <p v-if="stale" class="text-warning">資料尚未重新確認，暫停管理操作；請手動重新載入。</p>
    <div class="d-flex flex-wrap gap-2 mb-3"><button class="btn btn-primary" :disabled="blocked" @click="openCreate">新增管理員</button><button class="btn btn-outline-secondary" :disabled="pending || listLoading" @click="retry('list')">重新載入列表</button></div>
    <p v-if="listLoading" role="status">載入管理員列表中…</p>
    <p v-if="listError" class="alert alert-danger">{{ listError }} <button :disabled="pending || listLoading" @click="retry('list')">重試列表</button></p>
    <p v-if="!listLoading && !listError && !admins.length">目前沒有管理員。</p>
    <div class="table-responsive mb-3"><table v-if="admins.length" class="table"><thead><tr><th>名稱</th><th>Email</th><th>狀態</th><th>詳細</th></tr></thead><tbody><tr v-for="item in admins" :key="item.id"><td>{{ item.name }}</td><td class="text-break">{{ item.email }}</td><td>{{ item.status === 'active' ? '啟用' : '停用' }}</td><td><button class="btn btn-outline-primary" :disabled="pending || stale" @click="select(item.id)">查看 {{ item.name }}</button></td></tr></tbody></table></div>
    <form v-if="creating" id="managed-admin-create" class="card card-body mb-3" @submit.prevent="create">
      <h2 class="h4">新增管理員</h2><fieldset :disabled="pending">
        <label for="managed-name">名稱</label><input id="managed-name" v-model="draft.name" required maxlength="50" class="form-control mb-2">
        <label for="managed-email">Email</label><input id="managed-email" v-model="draft.email" type="email" required maxlength="255" class="form-control mb-2">
        <label for="managed-password">密碼</label><input id="managed-password" v-model="draft.password" type="password" required minlength="8" autocomplete="new-password" class="form-control mb-2">
        <label for="managed-confirmation">確認密碼</label><input id="managed-confirmation" v-model="draft.password_confirmation" type="password" required autocomplete="new-password" class="form-control mb-2">
        <label for="managed-status">初始狀態</label><select id="managed-status" v-model="draft.status" class="form-select mb-2"><option value="active">啟用</option><option value="disabled">停用</option></select>
        <p>新帳號沒有功能權限，建立後另行設定。</p><button class="btn btn-primary" :disabled="blocked" type="submit">建立管理員</button><button class="btn btn-outline-secondary ms-2" :disabled="pending" type="button" @click="close">放棄</button>
      </fieldset>
    </form>
    <p v-if="detailLoading" role="status">載入管理員詳細中…</p>
    <p v-if="detailError" class="alert alert-danger">{{ detailError }} <button :disabled="pending || detailLoading" @click="retry('detail')">重試詳細</button></p>
    <div v-if="detail" class="card card-body mb-3">
      <h2 class="h4">{{ detail.name }} 的管理資料</h2><p class="text-break">{{ detail.email }} · {{ detail.status === 'active' ? '啟用' : '停用' }}</p>
      <p>建立：{{ detail.created_at }}／更新：{{ detail.updated_at }}</p>
      <form id="managed-admin-basic" @submit.prevent="saveBasic"><fieldset :disabled="pending"><label for="managed-edit-name">名稱</label><input id="managed-edit-name" v-model="basic.name" required maxlength="50" class="form-control mb-2"><label for="managed-edit-email">Email</label><input id="managed-edit-email" v-model="basic.email" type="email" required maxlength="255" class="form-control mb-2"><button :disabled="blocked" class="btn btn-primary" type="submit">儲存基本資料</button></fieldset></form>
      <div class="my-3"><button class="btn btn-outline-warning" :disabled="blocked" @click="askStatus">{{ detail.status === 'active' ? '停用帳號' : '啟用帳號' }}</button>
        <div v-if="confirmingStatus" class="alert alert-warning mt-2">確認變更帳號狀態？停用後將無法登入或繼續使用後台；權限資料仍保留。系統至少保留一位啟用的管理員管理者。<button class="btn btn-warning" :disabled="blocked" @click="status">確認變更狀態</button><button class="btn btn-outline-secondary ms-2" :disabled="pending" @click="close">放棄變更</button></div>
      </div>
      <h3 class="h5">功能權限</h3><p>儲存會取代正式七項功能權限；未勾選的正式權限會移除。</p>
      <p v-if="catalogLoading">載入權限目錄中…</p>
      <p v-if="catalogError" class="alert alert-danger">{{ catalogError }} <button :disabled="pending || catalogLoading" @click="retry('catalog')">重試權限目錄</button></p>
      <div v-for="option in catalog" :key="option.id" class="form-check"><input :id="'managed-permission-' + option.id" type="checkbox" class="form-check-input" :checked="permissionIds.includes(option.id)" :disabled="blocked" @change="toggle(option.id, $event)"><label class="form-check-label" :for="'managed-permission-' + option.id">{{ option.name }}</label></div>
      <button class="btn btn-primary mt-2 align-self-start" :disabled="blocked || !catalog.length" @click="savePermissions">儲存功能權限</button>
    </div>
    <p v-if="catalogError && !detail" class="alert alert-danger">{{ catalogError }} <button :disabled="pending || catalogLoading" @click="retry('catalog')">重試權限目錄</button></p>
  </section>
</template>

<style scoped>
/* Keep names and actions readable; table-responsive provides local scrolling. */
table { min-width: 640px; }
th, td:first-child, td:last-child { white-space: nowrap; }
</style>
