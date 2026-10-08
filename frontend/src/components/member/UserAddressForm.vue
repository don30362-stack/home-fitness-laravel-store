<script setup lang="ts">
import axios from 'axios';
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue';

import { storeAddress, updateAddress } from '@/services/addressService';
import { getCities, getDistricts } from '@/services/locationService';

import type { ApiErrorResponse } from '@/types/api';
import type { UserAddress } from '@/types/address';
import type { City, District } from '@/types/location';

const props = withDefaults(
    defineProps<{
        address?: UserAddress | null
        disabled?: boolean
    }>(),
    {
        address: null,
        disabled: false
    }
)

const emit = defineEmits<{
    saved: [meaasge: string]
    cancel: []
    submittingChange: [value: boolean]
}>()

let disposed = false, districtSequence = 0, contextSequence = 0
onBeforeUnmount(() => { disposed = true; ++districtSequence; ++contextSequence })
const cities = ref<City[]>([])
const districts = ref<District[]>([])
const selectedCityId = ref<number | null>(null)

const form = reactive({
    district_id: 0,
    label: '',
    recipient_name: '',
    recipient_phone: '',
    address: '',
    is_default: false,
})

const isCitiesLoading = ref(false)
const isDistrictsLoading = ref(false)
const isSubmitting = ref(false)
const errorMessage = ref('')
const fieldErrors = ref<Record<string, string[]>>({})

const isEditing = computed(() => props.address !== null)

const selectedDistrict = computed(() => {
    return (
        districts.value.find(
            (district) => district.id === form.district_id
        ) ?? null
    )
})

const resetForm = () => {
    selectedCityId.value = null
    districts.value = []

    form.district_id = 0
    form.label = ''
    form.recipient_name = ''
    form.recipient_phone = ''
    form.address = ''
    form.is_default = false

    errorMessage.value = ''
    fieldErrors.value = {}
}

const loadCities = async () => {
    isCitiesLoading.value = true

    try {
        const response = await getCities()
        if (!disposed) cities.value = response.data
    } catch {
        if (!disposed) errorMessage.value = '縣市資料載入失敗'
    } finally {
        if (!disposed) isCitiesLoading.value = false
    }
}

const loadDistricts = async (cityId: number) => {
    const current = ++districtSequence
    const valid = () => !disposed && current === districtSequence && cityId === selectedCityId.value
    isDistrictsLoading.value = true

    try {
        const response = await getDistricts(cityId)
        if (valid()) districts.value = response.data
    } catch {
        if (!valid()) return
        districts.value = []
        errorMessage.value = '行政區資料載入失敗'
    } finally {
        if (valid()) isDistrictsLoading.value = false
    }
}

const handleCityChange = async () => {
    ++districtSequence
    isDistrictsLoading.value = false
    form.district_id = 0
    districts.value = []
    errorMessage.value = ''

    if (selectedCityId.value === null) {
        return
    }

    await loadDistricts(selectedCityId.value)
}

const handleSubmit = async () => {
    if (isSubmitting.value || props.disabled) {
        return
    }

    errorMessage.value = ''
    fieldErrors.value = {}

    if (selectedDistrict.value === null) {
        fieldErrors.value.district_id = ['請選擇行政區']
        return
    }

    const current = contextSequence
    isSubmitting.value = true
    emit('submittingChange', true)

    const payload = {
        district_id: form.district_id,
        label: form.label.trim(),
        recipient_name: form.recipient_name.trim(),
        recipient_phone: form.recipient_phone.trim(),
        address: form.address.trim(),
    }

    try {
        const response =
            isEditing.value && props.address ?
                await updateAddress(props.address.id, payload) :
                await storeAddress({ ...payload, 'is_default': form.is_default })

        if (!disposed && current === contextSequence) emit('saved', response.message)
    } catch (error) {
        if (disposed || current !== contextSequence) return
        if (axios.isAxiosError<ApiErrorResponse>(error)) {
            if (error.response?.status === 422) {
                fieldErrors.value = error.response.data.errors ?? {}
            }

            errorMessage.value = error.response?.status === 422 ? '請檢查地址資料' : '地址儲存失敗，請稍後再試'
        } else {
            errorMessage.value = '地址儲存失敗，請稍後再試'
        }
    } finally {
        if (!disposed && current === contextSequence) {
            isSubmitting.value = false
            emit('submittingChange', false)
        }
    }
}

watch(
    () => props.address,
    async (address) => {
        const current = ++contextSequence
        ++districtSequence
        resetForm()

        if (!address) {
            return
        }

        selectedCityId.value = address.district.city.id

        form.label = address.label
        form.recipient_name = address.recipient_name
        form.recipient_phone = address.recipient_phone
        form.address = address.address

        const districtRequest = districtSequence + 1
        await loadDistricts(address.district.city.id)

        if (!disposed && current === contextSequence && districtRequest === districtSequence && selectedCityId.value === address.district.city.id && districts.value.some(d => d.id === address.district.id)) form.district_id = address.district.id
    },
    {
        immediate: true
    }
)

onMounted(() => {
    loadCities()
})
</script>

<template>
    <div class="card mb-4">
        <div class="card-body p-4">
            <h3 class="h5 mb-4">
                {{ isEditing ? '修改地址' : '新增地址' }}
            </h3>

            <div v-if="errorMessage" class="alert alert-danger" role="alert">
                {{ errorMessage }}
            </div>

            <form @submit.prevent="handleSubmit">
                <fieldset :disabled="disabled || isSubmitting">
                <div class="row g-3">
                    <div class="col-md-6"></div>

                    <div class="col-md-6">
                        <label for="addressLabel" class="form-label">
                            地址名稱
                        </label>

                        <input id="addressLabel" v-model="form.label" type="text" class="form-control"
                            :class="{ 'is-invalid': fieldErrors.label }" :aria-invalid="!!fieldErrors.label" :aria-describedby="fieldErrors.label ? 'address-error-label' : undefined" maxlength="30" placeholder="例如：住家、公司" required>

                        <div v-if="fieldErrors.label" id="address-error-label" class="invalid-feedback">
                            {{ fieldErrors.label[0] }}
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="recipientName" class="form-label">
                            收件人
                        </label>

                        <input id="recipientName" v-model="form.recipient_name" type="text" class="form-control"
                            :class="{ 'is-invalid': fieldErrors.recipient_name }" :aria-invalid="!!fieldErrors.recipient_name" :aria-describedby="fieldErrors.recipient_name ? 'address-error-recipient_name' : undefined" maxlength="50" autocomplete="name"
                            required>

                        <div v-if="fieldErrors.recipient_name" id="address-error-recipient_name" class="invalid-feedback">
                            {{ fieldErrors.recipient_name[0] }}
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="recipientPhone" class="form-label">
                            收件人手機
                        </label>

                        <input id="recipientPhone" v-model="form.recipient_phone" type="text" class="form-control"
                            :class="{ 'is-invalid': fieldErrors.recipient_phone }" :aria-invalid="!!fieldErrors.recipient_phone" :aria-describedby="fieldErrors.recipient_phone ? 'address-error-recipient_phone' : undefined" maxlength="50" pattern="09[0-9]{8}"
                            inputmode="numeric" autocomplete="tel" required>

                        <div v-if="fieldErrors.recipient_phone" id="address-error-recipient_phone" class="invalid-feedback">
                            {{ fieldErrors.recipient_phone[0] }}
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="city" class="form-label">
                            縣市
                        </label>

                        <select id="city" v-model="selectedCityId" class="form-select" :disabled="isCitiesLoading"
                            required @change="handleCityChange">
                            <option :value="null">
                                請選擇縣市
                            </option>

                            <option v-for="city in cities" :key="city.id" :value="city.id">
                                {{ city.name }}
                            </option>
                        </select>
                    </div>

                    <div class="col-md-6">
                        <label for="district" class="form-label">
                            行政區
                        </label>

                        <select id="district" v-model="form.district_id" class="form-select"
                            :class="{ 'is-invalid': fieldErrors.district_id }" :aria-invalid="!!fieldErrors.district_id" :aria-describedby="fieldErrors.district_id ? 'address-error-district_id' : undefined"
                            :disabled="selectedCityId === null || isDistrictsLoading" required>
                            <option value="0">
                                請選擇行政區
                            </option>

                            <option v-for="district in districts" :key="district.id" :value="district.id">
                                {{ district.name }}
                            </option>
                        </select>

                        <div v-if="fieldErrors.district_id" id="address-error-district_id" class="invalid-feedback">
                            {{ fieldErrors.district_id[0] }}
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label for="postalCode" class="form-label">
                            郵遞區號
                        </label>

                        <input type="text" id="postalCode" :value="selectedDistrict?.postal_code ?? ''"
                            class="form-control" readonly>
                    </div>

                    <div class="col-12">
                        <label for="detailAddress" class="form-label">
                            詳細地址
                        </label>

                        <input id="detailAddress" v-model="form.address" type="text" class="form-control"
                            :class="{ 'is-invalid': fieldErrors.address }" :aria-invalid="!!fieldErrors.address" :aria-describedby="fieldErrors.address ? 'address-error-address' : undefined" maxlength="255" autocomplete="street-address"
                            placeholder="路、街、巷、弄、號、樓" required>

                        <div v-if="fieldErrors.address" id="address-error-address" class="invalid-feedback">
                            {{ fieldErrors.address[0] }}
                        </div>
                    </div>

                    <div v-if="!isEditing" class="col-12">
                        <div class="form-check">
                            <input type="checkbox" id="isDefault" v-model="form.is_default" class="form-check-input">

                            <label for="isDefault" class="form-check-label">
                                設為預設地址
                            </label>
                        </div>
                    </div>
                </div>

                <div class="d-flex gap-2 mt-4">
                    <button type="submit" class="btn btn-dark" :disabled="isSubmitting">
                        {{ isSubmitting ? '儲存中...' : '儲存地址' }}
                    </button>

                    <button type="button" class="btn btn-outline-secondary" :disabled="isSubmitting" @click="emit('cancel')">
                        取消
                    </button>
                </div>
                </fieldset>
            </form>
        </div>
    </div>
</template>