<script setup lang="ts">
import { getAddresses, setDefaultAddress, deleteAddress } from '@/services/addressService';
import type { UserAddress } from '@/types/address';
import { ref, onMounted } from 'vue';
import UserAddressForm from '@/components/member/UserAddressForm.vue';

const addresses = ref<UserAddress[]>([])
const isLoading = ref(true)
const errorMessage = ref('')
const successMessage = ref('')

const isFormVisible = ref(false)
const selectedAddress = ref<UserAddress | null>(null)

const processingAddressId = ref<number | null>(null)

const loadAddresses = async () => {
    isLoading.value = true
    errorMessage.value = ''

    try {
        const response = await getAddresses()
        addresses.value = response.data
    } catch {
        errorMessage.value = '地址資料載入失敗，請稍後再試'
    } finally {
        isLoading.value = false
    }
}

const openCreateForm = () => {
    selectedAddress.value = null
    successMessage.value = ''
    isFormVisible.value = true
}

const openEditForm = (address: UserAddress) => {
    selectedAddress.value = address
    successMessage.value = ''
    isFormVisible.value = true
}

const closeForm = () => {
    isFormVisible.value = false
    selectedAddress.value = null
}

const handleSaved = async (message: string) => {
    successMessage.value = message
    closeForm()

    await loadAddresses()
}

const handleSetDefault = async (address: UserAddress) => {
    if (address.is_default || processingAddressId.value !== null) {
        return
    }

    successMessage.value = ''
    errorMessage.value = ''
    processingAddressId.value = address.id

    try {
        const response = await setDefaultAddress(address.id)
        successMessage.value = response.message
        await loadAddresses()
    } catch {
        errorMessage.value = '預設地址設定失敗，請稍後再試'
    } finally {
        processingAddressId.value = null
    }
}

const handleDelete = async (address: UserAddress) => {
    if (processingAddressId.value !== null) {
        return
    }

    const confirmed = window.confirm(
        `確定要刪除「${address.label}」嗎？`
    )

    if (!confirmed) {
        return
    }

    successMessage.value = ''
    errorMessage.value = ''
    processingAddressId.value = address.id

    try {
        const response = await deleteAddress(address.id)

        if (selectedAddress.value?.id === address.id) {
            closeForm()
        }

        successMessage.value = response.message

        await loadAddresses()
    } catch {
        errorMessage.value = '地址刪除失敗，請稍後再試'
    } finally {
        processingAddressId.value = null
    }
}

onMounted(() => { loadAddresses() })
</script>

<template>
    <section>
        <div class="d-flex flex-wrap gap-3 justify-content-between align-items-center mb-4">
            <h2 class="h4 mb-0">地址簿</h2>

            <button v-if="!isFormVisible" type="button" class="btn btn-dark" @click="openCreateForm">
                新增地址
            </button>
        </div>

        <div v-if="successMessage" class="alert alert-success" role="alert">
            {{ successMessage }}
        </div>

        <UserAddressForm v-if="isFormVisible" :address="selectedAddress" @saved="handleSaved" @cancel="closeForm" />

        <div v-if="isLoading" class="text-center py-5">
            <div class="spinner-border text-dark" role="status" aria-label="地址資料載入中"></div>
            <p class="text-muted mt-3 mb-0">地址資料載入中...</p>
        </div>

        <div v-else-if="errorMessage" class="alert alert-danger" role="alert">
            <p class="mb-2">{{ errorMessage }}</p>
            <button @click="loadAddresses" type="button" class="btn btn-sm btn-outline-danger">
                重新載入
            </button>
        </div>

        <div v-else-if="addresses.length === 0" class="card">
            <div class="card-body py-5 text-center">
                <p class="text-muted mb-0">尚未建立收件地址</p>
            </div>
        </div>

        <div v-else class="row g-3">
            <div v-for="address in addresses" :key="address.id" class="col-12">
                <article class="card hf-address-card">
                    <div class="card-body p-4">
                        <div class="d-flex justify-content-between align-self-start gap-3">
                            <div>
                                <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <h3 class="h6 mb-0">{{ address.label }}</h3>

                                    <span v-if="address.is_default" class="badge text-bg-dark">預設地址</span>

                                    <div class="d-flex flex-wrap gap-2 hf-address-actions">
                                        <button v-if="!address.is_default" type="button"
                                            class="btn btn-outline-secondary btn-sm"
                                            :disabled="processingAddressId === address.id"
                                            @click="handleSetDefault(address)">
                                            {{ processingAddressId === address.id ? '設定中...' : '設為預設' }}
                                        </button>

                                        <button type="button" class="btn btn-outline-dark btn-sm"
                                            @click="openEditForm(address)">
                                            編輯
                                        </button>

                                        <button type="button" class="btn btn-outline-danger btn-sm"
                                            :disabled="processingAddressId !== null" @click="handleDelete(address)">
                                            {{ processingAddressId === address.id ? '處理中...' : '刪除' }}
                                        </button>
                                    </div>
                                </div>

                                <p class="mb-1">
                                    {{ address.recipient_name }}
                                    <span class="text-muted ms-2">{{ address.recipient_phone }}</span>
                                </p>

                                <address class="text-muted mb-0">
                                    {{ address.district.postal_code }}
                                    {{ address.district.city.name }}
                                    {{ address.district.name }}
                                    {{ address.address }}
                                </address>
                            </div>
                        </div>
                    </div>
                </article>
            </div>
        </div>
    </section>
</template>