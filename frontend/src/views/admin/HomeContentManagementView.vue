<script setup lang="ts">
import { useAdminAuthStore } from '@/stores/adminAuth'
const demoAuth = useAdminAuthStore()
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import axios from 'axios'
import { getAdminBanners, createAdminBanner, updateAdminBanner, updateAdminBannerStatus, reorderAdminBanners, deleteAdminBanner } from '@/services/adminHomeContentService'
import type { AdminBanner, BannerForm } from '@/types/adminHomeContent'
import RecommendedProductManager from '@/components/admin/RecommendedProductManager.vue'

const banners = ref<AdminBanner[]>([])
const workingIds = ref<number[]>([])
const loading = ref(false), pending = ref(false), stale = ref(false)
const error = ref(''), message = ref(''), fieldErrors = ref<string[]>([])
const formOpen = ref(false), editingId = ref<number | null>(null)
const confirmation = ref<{ kind: 'status' | 'delete'; banner: AdminBanner } | null>(null)
const blank = (): BannerForm => ({ title: '', subtitle: null, button_text: null, link_url: null, sort_order: 0, image: null, status: 'active' })
const draft = reactive<BannerForm>(blank())
const preview = ref(''), fileInput = ref<HTMLInputElement | null>(null)
let disposed = false, sequence = 0
const blocked = computed(() => pending.value || loading.value || stale.value)
const ordered = computed(() => workingIds.value.map(id => banners.value.find(b => b.id === id)).filter((b): b is AdminBanner => !!b))
const orderDirty = computed(() => workingIds.value.some((id, i) => id !== banners.value[i]?.id))
const failure = (cause: unknown): string => {
  if (axios.isAxiosError(cause)) {
    const data = cause.response?.data as { message?: string; errors?: Record<string, string[]> } | undefined
    fieldErrors.value = data?.errors ? Object.values(data.errors).flat() : []
    if (cause.response?.status === 422) return data?.message || '請檢查欄位內容。'
    if (cause.response?.status === 419) return '登入狀態／CSRF 發生問題，請重試。'
  }
  return '輪播操作失敗，請稍後再試。'
}
const releasePreview = () => { if (preview.value) URL.revokeObjectURL(preview.value); preview.value = '' }
const resetForm = () => { releasePreview(); draft.image = null; if (fileInput.value) fileInput.value.value = ''; formOpen.value = false }
const load = async (refresh = false) => {
  const current = ++sequence
  loading.value = true; error.value = ''; fieldErrors.value = []
  try {
    const result = await getAdminBanners()
    if (disposed || current !== sequence) return
    banners.value = result.data; workingIds.value = result.data.map(b => b.id); stale.value = false
  } catch (cause) {
    if (disposed || current !== sequence) return
    error.value = refresh ? '最新輪播資料重新載入失敗，請手動重試。' : failure(cause)
    stale.value = true
  } finally { if (!disposed && current === sequence) loading.value = false }
}
const retry = () => { if (!pending.value) return load(stale.value && !!message.value) }
const openForm = (banner?: AdminBanner) => { if (demoAuth.isReadOnlyDemo) return;
  if (blocked.value) return
  releasePreview(); fieldErrors.value = []; error.value = ''; confirmation.value = null
  editingId.value = banner?.id ?? null
  Object.assign(draft, blank(), banner ? { title: banner.title, subtitle: banner.subtitle, button_text: banner.button_text, link_url: banner.link_url, sort_order: banner.sort_order, status: banner.status } : {})
  formOpen.value = true
}
const closeForm = () => { if (!pending.value) resetForm() }
const chooseFile = (event: Event) => {
  if (pending.value) return
  const file = (event.target as HTMLInputElement).files?.[0] ?? null
  releasePreview(); draft.image = null; fieldErrors.value = []
  if (!file) return
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 5 * 1024 * 1024) {
    fieldErrors.value = ['圖片限 JPEG、PNG、WebP，單張最大 5 MiB。']; return
  }
  draft.image = file; preview.value = URL.createObjectURL(file)
}
const mutate = async (operation: () => Promise<{ message: string }>, staleOnValidation = false) => { if (demoAuth.isReadOnlyDemo) return;
  if (blocked.value) return
  pending.value = true; message.value = ''; error.value = ''; fieldErrors.value = []
  const current = ++sequence
  try {
    const result = await operation()
    if (disposed || current !== sequence) return
    message.value = result.message; resetForm(); confirmation.value = null
    stale.value = true
    await load(true)
  } catch (cause) {
    if (disposed || current !== sequence) return
    error.value = failure(cause)
    if (staleOnValidation && axios.isAxiosError(cause) && cause.response?.status === 422) stale.value = true
  } finally { if (!disposed) pending.value = false }
}
const submit = () => {
  if (blocked.value) return
  const optional = (value: string | null) => value?.trim() || null
  const fields = { title: draft.title.trim(), subtitle: optional(draft.subtitle), button_text: optional(draft.button_text), link_url: draft.link_url?.replace(/^ +| +$/g, '') || null, sort_order: draft.sort_order }
  if (!fields.title || (!editingId.value && !draft.image) || (!!fields.button_text !== !!fields.link_url)) {
    fieldErrors.value = ['請填寫標題與建立用圖片；按鈕文字和站內連結須同時填寫或清除。']; return
  }
  const id = editingId.value, image = draft.image
  return mutate(() => id === null ? createAdminBanner({ ...fields, image: image!, status: draft.status }) : updateAdminBanner(id, { ...fields, ...(image ? { image } : {}) }))
}
const ask = (kind: 'status' | 'delete', banner: AdminBanner) => { if (demoAuth.isReadOnlyDemo) return;
  if (blocked.value) return
  resetForm(); error.value = ''; fieldErrors.value = []; confirmation.value = { kind, banner }
}
const confirm = () => {
  const target = confirmation.value
  if (!target || blocked.value) return
  return mutate(() => target.kind === 'delete' ? deleteAdminBanner(target.banner.id) : updateAdminBannerStatus(target.banner.id, target.banner.status === 'active' ? 'inactive' : 'active'))
}
const move = (index: number, delta: number) => { if (demoAuth.isReadOnlyDemo) return;
  if (blocked.value || index + delta < 0 || index + delta >= workingIds.value.length) return
  const ids = [...workingIds.value], [id] = ids.splice(index, 1)
  if (id !== undefined) ids.splice(index + delta, 0, id)
  workingIds.value = ids
}
const saveOrder = () => { if (orderDirty.value) return mutate(() => reorderAdminBanners({ ids: [...workingIds.value] }), true) }
onMounted(() => load())
onBeforeUnmount(() => { disposed = true; sequence++; releasePreview() })
</script>

<template>
  <section>
    <h1 class="h3">首頁內容</h1><h2 class="h4">輪播管理</h2>
    <p v-if="message" class="alert alert-success" role="status">{{ message }}</p>
    <div v-if="error" class="alert alert-danger" role="alert">{{ error }}<button class="btn btn-outline-danger ms-2" :disabled="pending" @click="retry">重新載入</button></div>
    <ul v-if="fieldErrors.length" class="alert alert-danger"><li v-for="item in fieldErrors" :key="item">{{ item }}</li></ul>
    <p v-if="stale && banners.length" class="text-warning">目前顯示舊資料；重新載入成功前暫停操作。</p>
    <div class="d-flex flex-wrap gap-2 mb-3">
      <button v-if="!demoAuth.isReadOnlyDemo" class="btn btn-primary" :disabled="blocked" @click="openForm()">新增輪播</button>
      <button class="btn btn-outline-secondary" :disabled="pending" @click="retry">重新載入輪播</button>
      <button v-if="!demoAuth.isReadOnlyDemo && (orderDirty)" class="btn btn-outline-primary" :disabled="blocked" @click="saveOrder">儲存排序</button>
    </div>
    <form v-if="formOpen" class="card card-body mb-3" @submit.prevent="submit">
      <h3 class="h5">{{ editingId === null ? '新增輪播' : '編輯輪播' }}</h3>
      <fieldset :disabled="pending">
        <label for="banner-title" class="form-label">標題</label><input id="banner-title" v-model="draft.title" class="form-control mb-2" required maxlength="150">
        <label for="banner-subtitle" class="form-label">副標題</label><input id="banner-subtitle" v-model="draft.subtitle" class="form-control mb-2" maxlength="255">
        <label for="banner-button" class="form-label">按鈕文字</label><input id="banner-button" v-model="draft.button_text" class="form-control mb-2" maxlength="50">
        <label for="banner-link" class="form-label">站內連結</label><input id="banner-link" v-model="draft.link_url" class="form-control mb-2" maxlength="255"><p class="form-text">僅站內路徑，例如 /products、/products/12；與按鈕文字配對。</p>
        <label for="banner-sort" class="form-label">排序值</label><input id="banner-sort" v-model.number="draft.sort_order" type="number" min="0" max="4294967295" class="form-control mb-2" required>
        <template v-if="editingId === null"><label for="banner-status" class="form-label">初始狀態</label><select id="banner-status" v-model="draft.status" class="form-select mb-2"><option value="active">上架</option><option value="inactive">下架</option></select></template>
        <img v-if="preview || editingId" :src="preview || banners.find(b => b.id === editingId)?.image_url" alt="輪播預覽" class="banner-preview mb-2">
        <label for="banner-image" class="form-label">{{ editingId === null ? '圖片（必填）' : '替換圖片（可省略）' }}</label><input id="banner-image" ref="fileInput" type="file" class="form-control mb-2" accept="image/jpeg,image/png,image/webp" :required="editingId === null" @change="chooseFile">
      </fieldset>
      <div class="d-flex gap-2"><button type="submit" class="btn btn-primary" :disabled="blocked">{{ pending ? '提交中…' : '儲存輪播' }}</button><button type="button" class="btn btn-secondary" :disabled="pending" @click="closeForm">放棄</button></div>
    </form>
    <div v-if="confirmation" class="card card-body mb-3" role="dialog" aria-label="輪播操作確認">
      <p v-if="confirmation.kind === 'delete'">將永久刪除「{{ confirmation.banner.title }}」輪播設定；managed 輪播圖片會在資料提交成功後清理，不影響商品資料。</p>
      <p v-else>確認{{ confirmation.banner.status === 'active' ? '下架' : '上架' }}「{{ confirmation.banner.title }}」？</p>
      <div class="d-flex gap-2"><button class="btn btn-danger" :disabled="blocked" @click="confirm">確認</button><button class="btn btn-secondary" :disabled="pending" @click="!pending && (confirmation = null)">取消</button></div>
    </div>
    <p v-if="loading">載入輪播中…</p><p v-else-if="!error && !banners.length">目前沒有輪播。</p>
    <div class="row g-3">
      <article v-for="(banner, index) in ordered" :key="banner.id" class="col-12 col-lg-6">
        <div class="card card-body h-100 banner-card">
          <img :src="banner.image_url" :alt="banner.title" class="banner-preview mb-2">
          <h3 class="h5">{{ banner.title }}</h3><p>{{ banner.subtitle }}</p>
          <p>{{ banner.button_text }} <span>{{ banner.link_url }}</span></p>
          <p>{{ banner.status === 'active' ? '上架' : '下架' }} · 排序值 {{ banner.sort_order }}</p>
          <div class="d-flex flex-wrap gap-2">
            <button v-if="!demoAuth.isReadOnlyDemo" class="btn btn-outline-primary" :disabled="blocked" @click="openForm(banner)">編輯</button>
            <button v-if="!demoAuth.isReadOnlyDemo" class="btn btn-outline-secondary" :disabled="blocked" @click="ask('status', banner)">{{ banner.status === 'active' ? '下架' : '上架' }}</button>
            <button v-if="!demoAuth.isReadOnlyDemo" class="btn btn-outline-danger" :disabled="blocked" @click="ask('delete', banner)">刪除</button>
            <button v-if="!demoAuth.isReadOnlyDemo" class="btn btn-outline-secondary" :disabled="blocked || index === 0" @click="move(index, -1)">上移</button>
            <button v-if="!demoAuth.isReadOnlyDemo" class="btn btn-outline-secondary" :disabled="blocked || index === ordered.length - 1" @click="move(index, 1)">下移</button>
          </div>
        </div>
      </article>
    </div>
    <RecommendedProductManager />
  </section>
</template>
<style scoped>
.banner-preview { max-width: 100%; width: 100%; max-height: 240px; object-fit: contain; }
.banner-card { overflow-wrap: anywhere; }
fieldset { min-width: 0; }
</style>
