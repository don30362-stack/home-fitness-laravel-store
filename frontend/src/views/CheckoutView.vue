<script setup lang="ts">
import { isAxiosError } from 'axios'
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'

import { getAddresses } from '@/services/addressService'
import { checkout as checkoutApi, } from '@/services/checkoutService'
import { getCities, getDistricts } from '@/services/locationService'
import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'

import type { UserAddress } from '@/types/address'
import type { ApiErrorResponse } from '@/types/api'
import type { CheckoutForm, CheckoutOrder, CheckoutPayload } from '@/types/checkout'
import type { City, District } from '@/types/location'

const authStore = useAuthStore()
const cartStore = useCartStore()

let disposed = false, loadSequence = 0, districtSequence = 0
let initialized = false
onBeforeUnmount(() => { disposed = true; ++loadSequence; ++districtSequence })
const shippingFee = 100

const addresses = ref<UserAddress[]>([])
const isLoading = ref(false)
const errorMessage = ref('')
const addressMode = ref<'saved' | 'new'>('saved')
const selectedAddressId = ref<number | null>(null)
const cities = ref<City[]>([])
const districts = ref<District[]>([])
const selectedCityId = ref<number | null>(null)
const isDistrictsLoading = ref(false)
const addressErrorMessage = ref('')
const sameAsPurchaser = ref(false)
const isSubmitting = ref(false)
const createdOrder = ref<CheckoutOrder | null>(null)
const submitErrorMessage = ref('')
const submitSuccessMessage = ref('')

const selectedAddress = computed(() => {
    return (
        addresses.value.find(
            (address) =>
                address.id === selectedAddressId.value,
        ) ?? null
    )
})

const selectedDistrict = computed(() => {
    return (
        districts.value.find(
            (district) =>
                district.id ===
                form.recipient.district_id,
        ) ?? null
    )
})

const checkoutSubtotal = computed(() => {
    return Number(cartStore.memberSubtotal)
})

const totalAmount = computed(() => {
    return checkoutSubtotal.value + shippingFee
})

const formatCurrency = (
    value: string | number,
): string => {
    return Number(value).toLocaleString('zh-TW')
}

const form = reactive<CheckoutForm>({
    purchaser: {
        name: '',
        phone: '',
        email: '',
    },
    recipient: {
        name: '',
        phone: '',
        district_id: null,
        address: '',
    },
    shipping_method: 'home_delivery',
    payment_method: '',
})

const applyAddress = (address: UserAddress) => {
    form.recipient.name = address.recipient_name
    form.recipient.phone = address.recipient_phone
    form.recipient.district_id = address.district.id
    form.recipient.address = address.address
}

const clearRecipient = () => {
    form.recipient.name = ''
    form.recipient.phone = ''
    form.recipient.district_id = null
    form.recipient.address = ''
}

const handleSavedAddressChange = () => {
    if (selectedAddress.value === null) {
        clearRecipient()
        return
    }

    applyAddress(selectedAddress.value)
}

const handleCityChange = async () => {
    const current = ++districtSequence
    const cityId = selectedCityId.value
    const valid = () => !disposed && current === districtSequence && cityId === selectedCityId.value
    isDistrictsLoading.value = false
    form.recipient.district_id = null
    districts.value = []
    addressErrorMessage.value = ''

    if (selectedCityId.value === null) {
        return
    }

    isDistrictsLoading.value = true

    try {
        const response = await getDistricts(
            selectedCityId.value,
        )

        if (valid()) districts.value = response.data
    } catch {
        if (!valid()) return
        addressErrorMessage.value =
            '行政區資料載入失敗，請稍後再試。'
    } finally {
        if (valid()) isDistrictsLoading.value = false
    }
}

const handleSameAsPurchaserChange = () => {
    if (sameAsPurchaser.value) {
        form.recipient.name = form.purchaser.name
        form.recipient.phone = form.purchaser.phone
        return
    }

    form.recipient.name = ''
    form.recipient.phone = ''
}

const handleAddressModeChange = () => {
    ++districtSequence
    isDistrictsLoading.value = false
    addressErrorMessage.value = ''
    sameAsPurchaser.value = false
    selectedCityId.value = null
    districts.value = []

    if (addressMode.value === 'saved') {
        handleSavedAddressChange()
        return
    }

    clearRecipient()
}

const buildCheckoutPayload =
    (): CheckoutPayload | null => {
        const purchaserName =
            form.purchaser.name.trim()
        const purchaserPhone =
            form.purchaser.phone.trim()
        const purchaserEmail =
            form.purchaser.email.trim()

        const recipientName =
            form.recipient.name.trim()
        const recipientPhone =
            form.recipient.phone.trim()
        const recipientAddress =
            form.recipient.address.trim()

        const districtId =
            form.recipient.district_id
        const paymentMethod =
            form.payment_method

        submitErrorMessage.value = ''

        if (
            purchaserName === '' ||
            purchaserPhone === '' ||
            purchaserEmail === ''
        ) {
            submitErrorMessage.value =
                '請完整填寫訂購人資料。'
            return null
        }

        if (!/^09[0-9]{8}$/.test(purchaserPhone)) {
            submitErrorMessage.value =
                '請輸入正確的訂購人手機號碼。'
            return null
        }

        if (
            !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(
                purchaserEmail,
            )
        ) {
            submitErrorMessage.value =
                '請輸入正確的 Email。'
            return null
        }

        if (
            recipientName === '' ||
            recipientPhone === '' ||
            districtId === null ||
            recipientAddress === ''
        ) {
            submitErrorMessage.value =
                '請完整填寫收件人與收件地址。'
            return null
        }

        if (!/^09[0-9]{8}$/.test(recipientPhone)) {
            submitErrorMessage.value =
                '請輸入正確的收件人手機號碼。'
            return null
        }

        if (addressMode.value === 'new' && selectedDistrict.value === null) {
            submitErrorMessage.value = '請選擇目前縣市的行政區。'
            return null
        }

        if (paymentMethod === '') {
            submitErrorMessage.value =
                '請選擇付款方式。'
            return null
        }

        return {
            purchaser: {
                name: purchaserName,
                phone: purchaserPhone,
                email: purchaserEmail,
            },
            recipient: {
                name: recipientName,
                phone: recipientPhone,
                district_id: districtId,
                address: recipientAddress,
            },
            shipping_method:
                form.shipping_method,
            payment_method: paymentMethod,
        }
    }

const getSubmitErrorMessage = (
    error: unknown,
): string => {
    if (!isAxiosError<ApiErrorResponse>(error)) {
        return '訂單送出失敗，請稍後再試。'
    }

    const responseData = error.response?.data

    if (responseData?.errors !== undefined) {
        const firstValidationMessage =
            Object.values(responseData.errors)
                .flat()[0]

        if (firstValidationMessage !== undefined) {
            return firstValidationMessage
        }
    }

    return (
        responseData?.message ??
        '訂單送出失敗，請稍後再試。'
    )
}

const handleSubmit = async () => {
    if (
        isSubmitting.value ||
        createdOrder.value !== null
    ) {
        return
    }

    submitErrorMessage.value = ''
    submitSuccessMessage.value = ''

    const payload = buildCheckoutPayload()

    if (payload === null) {
        return
    }

    isSubmitting.value = true

    try {
        const response = await checkoutApi(payload)

        createdOrder.value = response.data
        submitSuccessMessage.value = response.message

        // 後端已成功清空購物車。
        // 先清除前端舊的會員購物車狀態，
        // 避免導覽列仍顯示結帳前的數量。
        cartStore.resetMemberCart()
    } catch (error: unknown) {
        submitErrorMessage.value =
            getSubmitErrorMessage(error)
    } finally {
        isSubmitting.value = false
    }
}

const loadCheckoutData = async () => {
    if (isLoading.value || isSubmitting.value || createdOrder.value || disposed) return
    const current = ++loadSequence
    const valid = () => !disposed && current === loadSequence
    errorMessage.value = ''

    const currentUser = authStore.currentUser

    if (currentUser === null) {
        errorMessage.value = '無法取得會員資料，請重新登入。'
        return
    }

    if (!form.purchaser.name) form.purchaser.name = currentUser.name
    if (!form.purchaser.phone) form.purchaser.phone = currentUser.phone
    if (!form.purchaser.email) form.purchaser.email = currentUser.email

    isLoading.value = true

    try {
        const [
            cartResponse,
            addressResponse,
            cityResponse,
        ] = await Promise.all([
            cartStore.fetchMemberCart(),
            getAddresses(),
            getCities(),
        ])

        if (!valid()) return
        addresses.value = addressResponse.data
        cities.value = cityResponse.data

        if (cartResponse.data.items.length === 0) {
            errorMessage.value =
                '購物車目前沒有商品，請先加入商品後再結帳。'
            return
        }

        if (cartResponse.data.has_unavailable_items) {
            errorMessage.value =
                '購物車內有無法購買的商品，請先回購物車處理。'
            return
        }

        const defaultAddress =
            addresses.value.find(
                (address) => address.is_default,
            ) ??
            addresses.value[0] ??
            null

        if (!initialized && selectedAddressId.value === null && !form.recipient.address && defaultAddress !== null) {
            selectedAddressId.value = defaultAddress.id
            applyAddress(defaultAddress)
        } else if (!initialized && defaultAddress === null) {
            addressMode.value = 'new'
        }
        initialized = true
    } catch {
        if (!valid()) return
        errorMessage.value =
            '結帳資料載入失敗，請稍後再試。'
    } finally {
        if (valid()) isLoading.value = false
    }
}

watch(
    [
        () => form.purchaser.name,
        () => form.purchaser.phone,
    ],
    ([name, phone]) => {
        if (!sameAsPurchaser.value) {
            return
        }

        form.recipient.name = name
        form.recipient.phone = phone
    },
)

watch(
    form,
    () => {
        submitErrorMessage.value = ''
        submitSuccessMessage.value = ''
    },
    {
        deep: true,
    },
)

onMounted(() => {
    loadCheckoutData()
})
</script>

<template>
    <div class="container py-5 hf-functional-page hf-checkout-page">
        <header class="hf-page-heading"><p class="hf-eyebrow">CHECKOUT</p><h1>結帳</h1></header>

        <div v-if="isLoading" class="text-center py-5">
            <div class="spinner-border" role="status" aria-label="載入中"></div>

            <p class="text-muted mt-3 mb-0">
                正在載入結帳資料...
            </p>
        </div>

        <div v-else-if="errorMessage" class="alert alert-warning" role="alert">
            <p class="mb-3">
                {{ errorMessage }}
                <button type="button" class="btn btn-outline-dark ms-2" :disabled="isLoading || isSubmitting" @click="loadCheckoutData">重新載入結帳資料</button>
            </p>

            <RouterLink class="btn btn-outline-dark" :to="{ name: 'cart' }">
                返回購物車
            </RouterLink>
        </div>

        <div v-else-if="createdOrder !== null" class="row justify-content-center">
            <div class="col-lg-8">
                <section class="card shadow-sm">
                    <div class="card-body p-4 p-md-5">
                        <div class="text-center mb-4">
                            <span class="badge text-bg-success mb-3">
                                訂單成立
                            </span>

                            <h2 class="h3 mb-3">
                                感謝您的訂購
                            </h2>

                            <p class="text-muted mb-0">
                                {{ submitSuccessMessage }}
                            </p>
                        </div>

                        <dl class="row border-top pt-4 mb-0">
                            <dt class="col-sm-4 mb-2">
                                訂單編號
                            </dt>

                            <dd class="col-sm-8 mb-3 font-monospace text-break">
                                {{ createdOrder.order_no }}
                            </dd>

                            <dt class="col-sm-4 mb-2">
                                付款方式
                            </dt>

                            <dd class="col-sm-8 mb-3">
                                {{
                                    createdOrder.payment_method ===
                                        'cod'
                                        ? '貨到付款'
                                        : '模擬信用卡付款'
                                }}
                            </dd>

                            <dt class="col-sm-4 mb-2">
                                付款狀態
                            </dt>

                            <dd class="col-sm-8 mb-3">
                                {{
                                    createdOrder.payment_status ===
                                        'paid'
                                        ? '已付款'
                                        : '尚未付款'
                                }}
                            </dd>

                            <dt class="col-sm-4 mb-2">
                                訂單金額
                            </dt>

                            <dd class="col-sm-8 mb-3 fw-bold">
                                NT$
                                {{
                                    formatCurrency(
                                        createdOrder.total_amount,
                                    )
                                }}
                            </dd>

                            <dt class="col-sm-4 mb-2">
                                收件人
                            </dt>

                            <dd class="col-sm-8 mb-3">
                                {{ createdOrder.recipient.name }}
                            </dd>

                            <dt class="col-sm-4 mb-2">
                                配送地址
                            </dt>

                            <dd class="col-sm-8 mb-0">
                                {{
                                    createdOrder.recipient
                                        .postal_code
                                }}
                                {{
                                    createdOrder.recipient.city
                                }}{{
                                    createdOrder.recipient
                                        .district
                                }}{{
                                    createdOrder.recipient.address
                                }}
                            </dd>
                        </dl>

                        <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center mt-5">
                            <RouterLink class="btn btn-outline-dark"
                                :to="{ name: 'member-order-detail', params: { id: createdOrder.id } }">
                                查看訂單
                            </RouterLink>
                            <RouterLink class="btn btn-dark" :to="{ name: 'products' }">
                                繼續購物
                            </RouterLink>

                            <RouterLink class="btn btn-outline-dark" :to="{ name: 'home' }">
                                返回首頁
                            </RouterLink>
                        </div>
                    </div>
                </section>
            </div>
        </div>

        <form v-else novalidate @submit.prevent="handleSubmit">
            <div class="row g-4">
                <div class="col-lg-8">
                    <section class="card">
                        <div class="card-body p-4">
                            <h2 class="h4 mb-2">
                                訂購人資料
                            </h2>

                            <p class="text-muted mb-4">
                                已自動帶入會員資料。本次修改只套用於這筆訂單，
                                不會修改會員基本資料。
                            </p>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label for="purchaserName" class="form-label">
                                        姓名
                                    </label>

                                    <input id="purchaserName" v-model.trim="form.purchaser.name" type="text"
                                        class="form-control" maxlength="50" autocomplete="name" required>
                                </div>

                                <div class="col-md-6">
                                    <label for="purchaserPhone" class="form-label">
                                        手機號碼
                                    </label>

                                    <input id="purchaserPhone" v-model.trim="form.purchaser.phone" type="tel"
                                        class="form-control" maxlength="20" pattern="09[0-9]{8}" inputmode="numeric"
                                        autocomplete="tel" placeholder="例如：0912345678" required>
                                </div>

                                <div class="col-12">
                                    <label for="purchaserEmail" class="form-label">
                                        Email
                                    </label>

                                    <input id="purchaserEmail" v-model.trim="form.purchaser.email" type="email"
                                        class="form-control" maxlength="255" autocomplete="email" required>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="card mt-4">
                        <div class="card-body p-4">
                            <h2 class="h4 mb-4">
                                收件人資料
                            </h2>

                            <div class="d-flex flex-column gap-2 mb-4">
                                <div class="form-check">
                                    <input id="useSavedAddress" v-model="addressMode" class="form-check-input"
                                        type="radio" value="saved" :disabled="addresses.length === 0"
                                        @change="handleAddressModeChange">

                                    <label class="form-check-label" for="useSavedAddress">
                                        使用地址簿
                                    </label>
                                </div>

                                <div class="form-check">
                                    <input id="useNewAddress" v-model="addressMode" class="form-check-input"
                                        type="radio" value="new" @change="handleAddressModeChange">

                                    <label class="form-check-label" for="useNewAddress">
                                        填寫新地址
                                    </label>
                                </div>
                            </div>

                            <div v-if="addressMode === 'saved'">
                                <label for="savedAddress" class="form-label">
                                    選擇收件地址
                                </label>

                                <select id="savedAddress" v-model.number="selectedAddressId" class="form-select"
                                    required @change="handleSavedAddressChange">
                                    <option :value="null" disabled>
                                        請選擇地址
                                    </option>

                                    <option v-for="address in addresses" :key="address.id" :value="address.id">
                                        {{ address.label }}－
                                        {{ address.recipient_name }}
                                    </option>
                                </select>

                                <div v-if="selectedAddress" class="border rounded p-3 mt-3 bg-light">
                                    <p class="fw-semibold mb-1">
                                        {{ selectedAddress.recipient_name }}
                                        {{ selectedAddress.recipient_phone }}
                                    </p>

                                    <p class="mb-0">
                                        {{ selectedAddress.district.postal_code }}
                                        {{ selectedAddress.district.city.name }}
                                        {{ selectedAddress.district.name }}
                                        {{ selectedAddress.address }}
                                    </p>

                                    <span v-if="selectedAddress.is_default" class="badge text-bg-dark mt-2">
                                        預設地址
                                    </span>
                                </div>
                            </div>

                            <div v-else>
                                <div v-if="addressErrorMessage" class="alert alert-danger" role="alert">
                                    {{ addressErrorMessage }}
                                </div>

                                <div class="form-check mb-4">
                                    <input id="sameAsPurchaser" v-model="sameAsPurchaser" class="form-check-input"
                                        type="checkbox" @change="handleSameAsPurchaserChange">

                                    <label for="sameAsPurchaser" class="form-check-label">
                                        收件人姓名與電話同訂購人
                                    </label>
                                </div>

                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <label for="recipientName" class="form-label">
                                            收件人姓名
                                        </label>

                                        <input id="recipientName" v-model.trim="form.recipient.name" type="text"
                                            class="form-control" maxlength="50" autocomplete="name" required>
                                    </div>

                                    <div class="col-md-6">
                                        <label for="recipientPhone" class="form-label">
                                            收件人手機
                                        </label>

                                        <input id="recipientPhone" v-model.trim="form.recipient.phone" type="tel"
                                            class="form-control" maxlength="20" pattern="09[0-9]{8}" inputmode="numeric"
                                            autocomplete="tel" placeholder="例如：0912345678" required>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="recipientCity" class="form-label">
                                            縣市
                                        </label>

                                        <select id="recipientCity" v-model="selectedCityId" class="form-select" required
                                            @change="handleCityChange">
                                            <option :value="null">
                                                請選擇縣市
                                            </option>

                                            <option v-for="city in cities" :key="city.id" :value="city.id">
                                                {{ city.name }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="recipientDistrict" class="form-label">
                                            行政區
                                        </label>

                                        <select id="recipientDistrict" v-model="form.recipient.district_id"
                                            class="form-select" :disabled="selectedCityId === null ||
                                                isDistrictsLoading
                                                " required>
                                            <option :value="null">
                                                {{
                                                    isDistrictsLoading
                                                        ? '載入中...'
                                                        : '請選擇行政區'
                                                }}
                                            </option>

                                            <option v-for="district in districts" :key="district.id"
                                                :value="district.id">
                                                {{ district.name }}
                                            </option>
                                        </select>
                                    </div>

                                    <div class="col-md-4">
                                        <label for="recipientPostalCode" class="form-label">
                                            郵遞區號
                                        </label>

                                        <input id="recipientPostalCode" :value="selectedDistrict?.postal_code ?? ''
                                            " type="text" class="form-control" readonly>
                                    </div>

                                    <div class="col-12">
                                        <label for="recipientAddress" class="form-label">
                                            詳細地址
                                        </label>

                                        <input id="recipientAddress" v-model.trim="form.recipient.address" type="text"
                                            class="form-control" maxlength="255" autocomplete="street-address"
                                            placeholder="路、街、巷、弄、號、樓" required>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <section class="card mt-4">
                        <div class="card-body p-4">
                            <h2 class="h4 mb-4">
                                配送方式
                            </h2>

                            <label class="border rounded p-3 d-flex align-items-center gap-3" for="homeDelivery">
                                <input id="homeDelivery" v-model="form.shipping_method" class="form-check-input mt-0"
                                    type="radio" value="home_delivery" required>

                                <span class="flex-grow-1">
                                    <span class="d-block fw-semibold">
                                        宅配
                                    </span>

                                    <span class="d-block text-muted small">
                                        配送至指定收件地址
                                    </span>
                                </span>

                                <span class="fw-semibold">
                                    NT$ {{ shippingFee }}
                                </span>
                            </label>
                        </div>
                    </section>

                    <section class="card mt-4">
                        <div class="card-body p-4">
                            <h2 class="h4 mb-4">
                                付款方式
                            </h2>

                            <div class="d-flex flex-column gap-3">
                                <label class="border rounded p-3 d-flex align-items-start gap-3" for="cashOnDelivery">
                                    <input id="cashOnDelivery" v-model="form.payment_method"
                                        class="form-check-input mt-1" type="radio" value="cod" required>

                                    <span>
                                        <span class="d-block fw-semibold">
                                            貨到付款
                                        </span>

                                        <span class="d-block text-muted small">
                                            商品送達時再支付款項
                                        </span>
                                    </span>
                                </label>

                                <label class="border rounded p-3 d-flex align-items-start gap-3" for="mockCreditCard">
                                    <input id="mockCreditCard" v-model="form.payment_method"
                                        class="form-check-input mt-1" type="radio" value="mock_credit_card" required>

                                    <span>
                                        <span class="d-block fw-semibold">
                                            模擬信用卡付款
                                        </span>

                                        <span class="d-block text-muted small">
                                            僅供專題展示，不會進行真實扣款
                                        </span>
                                    </span>
                                </label>
                            </div>

                            <div v-if="
                                form.payment_method ===
                                'mock_credit_card'
                            " class="alert alert-info mt-3 mb-0" role="alert">
                                模擬付款將於送出訂單時執行，
                                不需要輸入真實信用卡資料。
                            </div>
                        </div>
                    </section>
                </div>

                <aside class="col-lg-4">
                    <div class="card">
                        <div class="card-body p-4">
                            <h2 class="h4 mb-3">
                                訂單摘要
                            </h2>

                            <div>
                                <article v-for="item in cartStore.memberItems" :key="item.id"
                                    class="py-3 border-bottom">
                                    <div class="d-flex gap-3">
                                        <RouterLink :to="{
                                            name: 'product-detail',
                                            params: {
                                                id: item.product_id,
                                            },
                                        }" class="flex-shrink-0">
                                            <img v-if="item.product.primary_image" :src="item.product.primary_image
                                                .image_url
                                                " :alt="item.product.name" class="checkout-item-image rounded border">

                                            <div v-else
                                                class="checkout-item-image rounded border bg-light d-flex align-items-center justify-content-center text-muted small">
                                                無圖片
                                            </div>
                                        </RouterLink>

                                        <div class="flex-grow-1">
                                            <RouterLink :to="{
                                                name: 'product-detail',
                                                params: {
                                                    id: item.product_id,
                                                },
                                            }" class="text-dark text-decoration-none">
                                                <h3 class="h6 mb-1">
                                                    {{ item.product.name }}
                                                </h3>
                                            </RouterLink>

                                            <p v-if="item.variant" class="text-muted small mb-1">
                                                {{ item.variant.option_name }}：
                                                {{ item.variant.option_value }}
                                            </p>

                                            <p class="text-muted small mb-1">
                                                數量：{{ item.quantity }}
                                            </p>

                                            <p class="fw-semibold mb-0">
                                                NT$
                                                {{
                                                    formatCurrency(
                                                        item.subtotal,
                                                    )
                                                }}
                                            </p>
                                        </div>
                                    </div>
                                </article>
                            </div>

                            <div class="pt-3">
                                <div class="d-flex justify-content-between mb-2">
                                    <span>
                                        商品小計
                                    </span>

                                    <span>
                                        NT$
                                        {{
                                            formatCurrency(
                                                checkoutSubtotal,
                                            )
                                        }}
                                    </span>
                                </div>

                                <div class="d-flex justify-content-between mb-3">
                                    <span>
                                        宅配運費
                                    </span>

                                    <span>
                                        NT$ {{ formatCurrency(shippingFee) }}
                                    </span>
                                </div>

                                <div class="d-flex justify-content-between border-top pt-3">
                                    <span class="fw-bold">
                                        訂單總額
                                    </span>

                                    <span class="fs-5 fw-bold">
                                        NT$
                                        {{ formatCurrency(totalAmount) }}
                                    </span>
                                </div>
                            </div>

                            <div v-if="submitErrorMessage" class="alert alert-danger mt-4" role="alert">
                                {{ submitErrorMessage }}
                            </div>

                            <button type="submit" class="btn btn-dark w-100 mt-4"
                                :disabled="isSubmitting || createdOrder !== null">
                                {{
                                    createdOrder
                                        ? '訂單已成立'
                                        : isSubmitting
                                            ? '處理中...'
                                            : '確認送出訂單'
                                }}
                            </button>

                            <RouterLink :to="{ name: 'cart' }" class="btn btn-outline-secondary w-100 mt-2">
                                返回修改購物車
                            </RouterLink>
                        </div>
                    </div>
                </aside>
            </div>
        </form>
    </div>
</template>

<style scoped>
.checkout-item-image {
    width: 72px;
    height: 72px;
    object-fit: cover;
}
</style>
