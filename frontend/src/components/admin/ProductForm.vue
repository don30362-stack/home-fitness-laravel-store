<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import type { Category } from '@/types/category'
import type {
  AdminProductDetail,
  AdminSpecificationPayload,
  CreateAdminProductPayload,
  UpdateAdminProductPayload,
  ProductStatus,
} from '@/types/adminProduct'

const props = defineProps<{
  product?: AdminProductDetail
  categories: Category[]
  submitting: boolean
  errors: Record<string, string[]>
}>()
const emit = defineEmits<{
  save: [payload: CreateAdminProductPayload | UpdateAdminProductPayload]
}>()
const editing = !!props.product
interface VariantRow {
  id?: number
  option_name: string
  option_value: string
  stock: number
  status: 'active' | 'inactive'
}
const form = reactive({
  category_id: props.product?.category?.id ?? 0,
  name: props.product?.name ?? '',
  price: Number(props.product?.price ?? 0),
  short_description: props.product?.short_description ?? '',
  description: props.product?.description ?? '',
  low_stock_threshold: props.product?.low_stock_threshold ?? 5,
  status: (props.product?.status ?? 'active') as ProductStatus,
  mode: props.product?.has_variants ? 'variant' : 'plain',
  stock: props.product?.stock ?? 0,
  specifications: (props.product?.specifications ?? []).map(
    ({ spec_name, spec_value, sort_order }) => ({ spec_name, spec_value, sort_order }),
  ) as AdminSpecificationPayload[],
  variants: (props.product?.variants ?? []).map((row) => ({
    ...row,
    status: row.status as 'active' | 'inactive',
  })) as VariantRow[],
})
const options = computed(() => props.categories.flatMap((parent) => parent.children))
const currentMissing = computed(
  () =>
    editing &&
    props.product?.category &&
    !options.value.some((c) => c.id === props.product?.category?.id),
)
const addVariant = () =>
  form.variants.push({
    option_name: form.variants[0]?.option_name ?? '',
    option_value: '',
    stock: 0,
    status: 'active',
  })
const removedVariants = ref<VariantRow[]>([])
const removeVariant = (index: number) => {
  const row = form.variants[index]
  if (row?.id) removedVariants.value.push({ ...row })
  form.variants.splice(index, 1)
}
const restoreVariant = (index: number) => {
  const row = removedVariants.value[index]
  if (row) form.variants.push({ ...row })
  removedVariants.value.splice(index, 1)
}
const save = () => {
  if (props.submitting) return
  const basic = {
    category_id: form.category_id,
    name: form.name,
    price: form.price,
    short_description: form.short_description || null,
    description: form.description || null,
    low_stock_threshold: form.low_stock_threshold,
    specifications: form.specifications.map((s) => ({ ...s })),
  }
  const variants = form.variants.map((row) =>
    row.id !== undefined
      ? {
          id: row.id,
          option_name: row.option_name,
          option_value: row.option_value,
          status: row.status,
        }
      : {
          option_name: row.option_name,
          option_value: row.option_value,
          stock: row.stock,
          status: row.status,
        },
  )
  if (editing) emit('save', { ...basic, ...(form.mode === 'variant' ? { variants } : {}) })
  else
    emit('save', {
      ...basic,
      status: form.status,
      stock: form.mode === 'plain' ? form.stock : null,
      ...(form.mode === 'variant' ? { variants: variants.filter((v) => !('id' in v)) } : {}),
    })
}
</script>

<template>
  <form @submit.prevent="save">
    <div v-if="Object.keys(errors).length" class="alert alert-danger" role="alert">
      <ul class="mb-0">
        <li v-for="(messages, field) in errors" :key="field">
          {{ field }}：{{ messages.join('；') }}
        </li>
      </ul>
    </div>
    <fieldset :disabled="submitting">
      <label for="core-code" class="form-label">商品編號</label>
      <input
        v-if="editing"
        id="core-code"
        :value="product?.product_code"
        readonly
        class="form-control mb-3"
      />
      <p v-else>系統建立後自動產生</p>
      <label for="core-name" class="form-label">商品名稱</label>
      <input
        id="core-name"
        v-model="form.name"
        required
        maxlength="150"
        class="form-control mb-3"
      />
      <label for="core-category" class="form-label">子分類</label>
      <select
        id="core-category"
        v-model.number="form.category_id"
        required
        class="form-select mb-3"
      >
        <option :value="0" disabled>請選擇子分類</option>
        <option v-if="currentMissing" :value="product?.category?.id">
          {{ product?.category?.name }}（目前分類，可保留）
        </option>
        <option v-for="category in options" :key="category.id" :value="category.id">
          {{ category.name }}
        </option>
      </select>
      <label for="core-price" class="form-label">售價</label>
      <input
        id="core-price"
        v-model.number="form.price"
        type="number"
        min="0"
        step="0.01"
        required
        class="form-control mb-3"
      />
      <label for="core-short" class="form-label">簡短介紹</label>
      <input
        id="core-short"
        v-model="form.short_description"
        maxlength="255"
        class="form-control mb-3"
      />
      <label for="core-description" class="form-label">詳細介紹</label>
      <textarea id="core-description" v-model="form.description" class="form-control mb-3" />
      <label for="core-threshold" class="form-label">低庫存門檻</label>
      <input
        id="core-threshold"
        v-model.number="form.low_stock_threshold"
        type="number"
        min="0"
        step="1"
        required
        class="form-control mb-3"
      />
      <template v-if="!editing">
        <label for="core-status" class="form-label">商品狀態</label>
        <select id="core-status" v-model="form.status" class="form-select mb-3">
          <option value="active">上架</option>
          <option value="inactive">下架</option>
          <option value="disabled">停用</option>
        </select>
        <label for="core-mode" class="form-label">建立時庫存模式</label>
        <select id="core-mode" v-model="form.mode" class="form-select mb-3">
          <option value="plain">無購買規格</option>
          <option value="variant">有購買規格</option>
        </select>
      </template>
      <p v-else>
        既定庫存模式：{{ form.mode === 'plain' ? '無購買規格' : '有購買規格' }}（不可轉換）
      </p>
      <template v-if="form.mode === 'plain'">
        <label for="core-stock" class="form-label">{{ editing ? '目前庫存' : '初始庫存' }}</label>
        <input
          id="core-stock"
          v-model.number="form.stock"
          :readonly="editing"
          type="number"
          min="0"
          step="1"
          required
          class="form-control mb-3"
        />
      </template>
      <p v-if="editing">庫存調整請至庫存管理；商品狀態請在詳細頁操作。</p>
      <h2 class="h5">固定展示規格</h2>
      <div
        v-for="(spec, index) in form.specifications"
        :key="index"
        class="border rounded p-2 mb-2"
      >
        <label :for="`spec-name-${index}`">規格名稱</label
        ><input
          :id="`spec-name-${index}`"
          v-model="spec.spec_name"
          required
          maxlength="100"
          class="form-control"
        />
        <label :for="`spec-value-${index}`">規格值</label
        ><input
          :id="`spec-value-${index}`"
          v-model="spec.spec_value"
          required
          maxlength="255"
          class="form-control"
        />
        <label :for="`spec-sort-${index}`">排序</label
        ><input
          :id="`spec-sort-${index}`"
          v-model.number="spec.sort_order"
          type="number"
          min="0"
          step="1"
          required
          class="form-control"
        />
        <button
          type="button"
          class="btn btn-outline-danger mt-2"
          @click="form.specifications.splice(index, 1)"
        >
          移除固定規格
        </button>
      </div>
      <button
        type="button"
        class="btn btn-outline-secondary mb-3"
        @click="form.specifications.push({ spec_name: '', spec_value: '', sort_order: 0 })"
      >
        新增固定規格
      </button>
      <template v-if="form.mode === 'variant'">
        <h2 class="h5">購買規格（單一規格軸）</h2>
        <p>已被購物車或訂單引用的選項不能改名或刪除，請新增選項並將舊選項下架。</p>
        <div
          v-for="(variant, index) in form.variants"
          :key="variant.id ?? `new-${index}`"
          class="border rounded p-2 mb-2"
        >
          <label :for="`variant-name-${index}`">規格軸名稱</label
          ><input
            :id="`variant-name-${index}`"
            v-model="variant.option_name"
            required
            maxlength="50"
            class="form-control"
          />
          <label :for="`variant-value-${index}`">選項值</label
          ><input
            :id="`variant-value-${index}`"
            v-model="variant.option_value"
            required
            maxlength="100"
            class="form-control"
          />
          <label :for="`variant-stock-${index}`">{{
            variant.id ? '目前庫存（唯讀）' : '初始庫存'
          }}</label
          ><input
            :id="`variant-stock-${index}`"
            v-model.number="variant.stock"
            :readonly="!!variant.id"
            type="number"
            min="0"
            step="1"
            required
            class="form-control"
          />
          <label :for="`variant-status-${index}`">規格狀態</label
          ><select :id="`variant-status-${index}`" v-model="variant.status" class="form-select">
            <option value="active">啟用</option>
            <option value="inactive">下架</option>
          </select>
          <button type="button" class="btn btn-outline-danger mt-2" @click="removeVariant(index)">
            移除購買規格
          </button>
        </div>
        <button type="button" class="btn btn-outline-secondary mb-3" @click="addVariant">
          新增購買規格
        </button>
        <div v-for="(row, index) in removedVariants" :key="row.id" class="alert alert-warning">
          待刪除：{{ row.option_name }}／{{ row.option_value }}（儲存成功才會實際刪除）
          <button type="button" class="btn btn-outline-secondary" @click="restoreVariant(index)">
            保留此規格
          </button>
        </div>
      </template>
      <div>
        <button class="btn btn-primary" type="submit">
          {{ submitting ? '儲存中…' : '儲存商品' }}
        </button>
      </div>
    </fieldset>
  </form>
</template>
