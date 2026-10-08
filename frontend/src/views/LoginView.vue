<script setup lang="ts">
import axios from 'axios'
import { computed, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type { ApiErrorResponse } from '@/types/api'

import { useAuthStore } from '@/stores/auth'
import { useCartStore } from '@/stores/cart'
import { ACCOUNT_DISABLED_MESSAGE, SessionInvalidatedError } from '@/services/sessionState'

const route = useRoute()
const router = useRouter()
const authStore = useAuthStore()
const cartStore = useCartStore()

const email = ref('')
const password = ref('')

const isSubmitting = ref(false)
const errorMessage = ref('')
const visibleErrorMessage = computed(() =>
  authStore.authFailureReason === 'disabled' ? ACCOUNT_DISABLED_MESSAGE : errorMessage.value,
)

const isRegistered = computed(() => {
  return route.query.registered === '1'
})

const handleLogin = async () => {
  if (isSubmitting.value) {
    return
  }

  errorMessage.value = ''
  isSubmitting.value = true

  try {
    await authStore.login({
      email: email.value,
      password: password.value,
    })

    try {
      await cartStore.mergeGuestCart()
    } catch (error) {
      if (!authStore.isAuthenticated || error instanceof SessionInvalidatedError) return
      // 此時帳號已登入成功，只是購物車同步失敗。
      // 訪客 localStorage 購物車仍然保留。
      await router.push({
        name: 'cart',
        query: {
          cart_sync: 'failed',
        },
      })

      return
    }

    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/member'

    await router.push(redirect)
  } catch (error) {
    if (error instanceof SessionInvalidatedError) return
    if (!axios.isAxiosError<ApiErrorResponse>(error)) {
      errorMessage.value = '登入失敗，請稍後再試'
      return
    }

    const status = error.response?.status
    const responseData = error.response?.data

    if (status === 422) {
      const firstFieldError = Object.values(responseData?.errors ?? {})[0]?.[0]

      errorMessage.value = firstFieldError ?? '請檢查登入資料'
    } else if (status === 429) {
      errorMessage.value = responseData?.message ?? '嘗試次數過多，請稍後再試。'
    } else if (status === 401 || status === 403) {
      errorMessage.value = responseData?.message ?? '登入失敗，請稍後再試'
    } else {
      errorMessage.value = '登入失敗，請稍後再試'
    }
  } finally {
    isSubmitting.value = false
  }
}
</script>

<template>
  <div class="container py-5 hf-functional-page hf-auth-page">
    <div class="row justify-content-center">
      <div class="col-12 col-md-8 col-lg-5 hf-auth-panel">
        <header class="hf-page-heading text-center"><p class="hf-eyebrow">WELCOME BACK</p><h1 class="h2">會員登入</h1></header>

        <div v-if="isRegistered" class="alert alert-success" role="alert">
          會員註冊成功，請登入。
        </div>

        <div v-if="visibleErrorMessage" class="alert alert-danger" role="alert">
          {{ visibleErrorMessage }}
        </div>

        <form @submit.prevent="handleLogin">
          <div class="mb-3">
            <label for="email" class="form-label">電子郵件</label>
            <input v-model.trim="email" type="email" id="email" class="form-control" autocomplete="email" required />
          </div>

          <div class="mb-4">
            <label for="password" class="form-label">密碼</label>
            <input v-model="password" type="password" id="password" class="form-control" autocomplete="current-password"
              required />
          </div>

          <button type="submit" class="btn btn-dark w-100" :disabled="isSubmitting">
            {{ isSubmitting ? '登入中...' : '登入' }}
          </button>
        </form>

        <p class="text-center mt-4 mb-0">
          還沒有會員帳號？
          <RouterLink :to="{ name: 'register' }"> 立即註冊 </RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>
