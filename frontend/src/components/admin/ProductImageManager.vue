<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import axios from 'axios'
import {
  getAdminProduct,
  uploadAdminProductImage,
  updateAdminProductImage,
  deleteAdminProductImage,
} from '@/services/adminProductService'
import type { AdminProductImage, UpdateAdminProductImagePayload } from '@/types/adminProduct'
import type { ApiErrorResponse } from '@/types/api'

const props = defineProps<{
  productId: number
  initialImages: AdminProductImage[]
  disabled: boolean
}>()
const emit = defineEmits<{ busy: [value: boolean] }>()
const images = ref<AdminProductImage[]>([])
const drafts = ref<Record<number, { image_type: 'gallery' | 'detail'; sort_order: number }>>({})
const file = ref<File | null>(null),
  fileInput = ref<HTMLInputElement | null>(null)
const uploadType = ref<'gallery' | 'detail'>('gallery'),
  uploadOrder = ref(0),
  uploadPrimary = ref('0')
const submitting = ref(false),
  errorMessage = ref(''),
  message = ref(''),
  refreshNeeded = ref(false)
const errors = ref<Record<string, string[]>>({}),
  deleting = ref<number | null>(null)
let sequence = 0
const acceptImages = (rows: AdminProductImage[]) => {
  images.value = rows
  drafts.value = Object.fromEntries(
    rows.map((row) => [
      row.id,
      { image_type: row.image_type as 'gallery' | 'detail', sort_order: row.sort_order },
    ]),
  )
}
watch(
  () => props.productId,
  () => {
    ++sequence
    acceptImages(props.initialImages)
    errorMessage.value = ''
    errors.value = {}
    message.value = ''
    file.value = null
    deleting.value = null
    refreshNeeded.value = false
    submitting.value = false
    emit('busy', false)
  },
  { immediate: true },
)
const selectFile = (event: Event) => {
  file.value = (event.target as HTMLInputElement).files?.[0] ?? null
}
const displayError = (error: unknown, fallback: string) => {
  if (axios.isAxiosError<ApiErrorResponse>(error)) {
    errors.value = error.response?.data.errors ?? {}
    errorMessage.value = error.response?.data.message || fallback
  } else errorMessage.value = fallback
}
const refresh = async () => {
  if (submitting.value || props.disabled) return
  const current = sequence,
    id = props.productId
  submitting.value = true
  emit('busy', true)
  try {
    const result = await getAdminProduct(String(id))
    if (current === sequence) {
      acceptImages(result.images)
      refreshNeeded.value = false
      errorMessage.value = ''
      errors.value = {}
    }
  } catch (error) {
    if (current === sequence) displayError(error, '圖片狀態載入失敗，請重試。')
  } finally {
    if (current === sequence) {
      submitting.value = false
      emit('busy', false)
    }
  }
}
const mutate = async (action: () => Promise<{ message: string }>, uploaded = false) => {
  if (submitting.value || props.disabled || refreshNeeded.value) return
  const current = sequence,
    id = props.productId
  submitting.value = true
  emit('busy', true)
  errorMessage.value = ''
  errors.value = {}
  message.value = ''
  try {
    const result = await action()
    if (current !== sequence) return
    message.value = result.message
    deleting.value = null
    if (uploaded) {
      file.value = null
      if (fileInput.value) fileInput.value.value = ''
    }
    // Mutation已成功；若GET失敗只允許手動重新GET，不重送mutation。
    refreshNeeded.value = true
    try {
      const detail = await getAdminProduct(String(id))
      if (current === sequence) {
        acceptImages(detail.images)
        refreshNeeded.value = false
      }
    } catch (error) {
      if (current === sequence)
        displayError(error, '操作已完成，但圖片狀態尚未更新，請重新載入圖片。')
    }
  } catch (error) {
    if (current === sequence) displayError(error, '圖片操作失敗，請稍後再試。')
  } finally {
    if (current === sequence) {
      submitting.value = false
      emit('busy', false)
    }
  }
}
const upload = async () => {
  if (submitting.value || props.disabled || refreshNeeded.value) return
  const selected = file.value
  if (
    !selected ||
    !['image/jpeg', 'image/png', 'image/webp'].includes(selected.type) ||
    selected.size > 5 * 1024 * 1024
  ) {
    errorMessage.value = '請選擇 JPEG、PNG 或 WebP 圖片，單張最大 5 MiB。'
    return
  }
  await mutate(
    () =>
      uploadAdminProductImage(props.productId, {
        image: selected,
        image_type: uploadType.value,
        sort_order: uploadOrder.value,
        is_primary: uploadPrimary.value === '1',
      }),
    true,
  )
}
const metadata = (id: number, payload: UpdateAdminProductImagePayload) =>
  mutate(() => updateAdminProductImage(id, payload))
const remove = (id: number) => {
  if (deleting.value === id) return mutate(() => deleteAdminProductImage(id))
}
onBeforeUnmount(() => {
  ++sequence
  emit('busy', false)
})
</script>

<template>
  <section class="border rounded p-3 mt-4" aria-labelledby="product-images-title">
    <h2 id="product-images-title" class="h4">商品圖片管理</h2>
    <p>可無圖片上架。gallery／detail 為圖片 metadata；整個商品有圖片時恰好一張主圖。</p>
    <p v-if="message" role="status" class="alert alert-success">{{ message }}</p>
    <div v-if="errorMessage" role="alert" class="alert alert-danger">
      {{ errorMessage }}
      <ul v-if="Object.keys(errors).length">
        <li v-for="(messages, field) in errors" :key="field">
          {{ field }}：{{ messages.join('；') }}
        </li>
      </ul>
    </div>
    <div v-if="refreshNeeded" class="alert alert-warning">
      畫面仍是先前圖片資料，請重新載入確認最新狀態。<button
        type="button"
        class="btn btn-outline-secondary"
        :disabled="submitting || disabled"
        @click="refresh"
      >
        重新載入圖片
      </button>
    </div>
    <p v-if="submitting" role="status">圖片處理中…</p>
    <form @submit.prevent="upload">
      <fieldset :disabled="submitting || disabled || refreshNeeded" class="mb-3">
        <label for="image-file" class="form-label">新圖片（JPEG／PNG／WebP，最大 5 MiB）</label>
        <input
          id="image-file"
          ref="fileInput"
          type="file"
          accept="image/jpeg,image/png,image/webp"
          class="form-control mb-2"
          @change="selectFile"
        />
        <label for="image-upload-type">圖片類型</label
        ><select id="image-upload-type" v-model="uploadType" class="form-select mb-2">
          <option value="gallery">gallery</option>
          <option value="detail">detail</option>
        </select>
        <label for="image-upload-order">排序</label
        ><input
          id="image-upload-order"
          v-model.number="uploadOrder"
          type="number"
          min="0"
          step="1"
          required
          class="form-control mb-2"
        />
        <label for="image-upload-primary">主圖</label
        ><select id="image-upload-primary" v-model="uploadPrimary" class="form-select mb-2">
          <option value="0">保留現有主圖（首張自動為主圖）</option>
          <option value="1">設為主圖</option>
        </select>
        <button type="submit" class="btn btn-primary">上傳圖片</button>
      </fieldset>
    </form>
    <p v-if="!images.length">沒有圖片。</p>
    <div v-for="image in images" :key="image.id" class="border rounded p-3 mb-3">
      <img :src="image.image_url" alt="商品圖片" class="img-fluid image-thumbnail" />
      <p>
        {{ image.is_primary ? '主圖' : '非主圖' }}／{{ image.image_type }}／排序
        {{ image.sort_order }}
      </p>
      <fieldset :disabled="submitting || disabled || refreshNeeded">
        <label :for="`image-type-${image.id}`">圖片類型</label
        ><select
          :id="`image-type-${image.id}`"
          v-model="drafts[image.id]!.image_type"
          class="form-select mb-2"
        >
          <option value="gallery">gallery</option>
          <option value="detail">detail</option>
        </select>
        <label :for="`image-order-${image.id}`">排序</label
        ><input
          :id="`image-order-${image.id}`"
          v-model.number="drafts[image.id]!.sort_order"
          type="number"
          min="0"
          step="1"
          required
          class="form-control mb-2"
        />
        <div class="d-flex flex-wrap gap-2">
          <button
            type="button"
            class="btn btn-outline-primary"
            @click="metadata(image.id, drafts[image.id]!)"
          >
            儲存圖片資料
          </button>
          <button
            type="button"
            class="btn btn-outline-secondary"
            :disabled="image.is_primary"
            @click="metadata(image.id, { is_primary: true })"
          >
            設為主圖
          </button>
          <button type="button" class="btn btn-outline-danger" @click="deleting = image.id">
            刪除圖片
          </button>
        </div>
        <div v-if="deleting === image.id" class="alert alert-warning mt-2">
          確定刪除此圖片？主圖刪除後會由後端遞補；最後一張也可刪除。
          <button type="button" class="btn btn-danger me-2" @click="remove(image.id)">
            確認刪圖
          </button>
          <button type="button" class="btn btn-secondary" @click="deleting = null">放棄刪圖</button>
        </div>
      </fieldset>
    </div>
  </section>
</template>
<style scoped>
.image-thumbnail {
  max-width: 180px;
  max-height: 160px;
  object-fit: contain;
}
</style>
