<script setup lang="ts">
import { reactive, ref } from 'vue'
import axios from 'axios'
import type { ApiErrorResponse } from '@/types/api'
import { useAdminAuthStore } from '@/stores/adminAuth'
import { AdminSessionInvalidatedError } from '@/services/adminSessionState'
import { notifyAdminLoginSuccess } from '@/services/adminSessionNavigation'

const auth = useAdminAuthStore()
const form = reactive({ email: '', password: '' })
const submitting = ref(false)
const loginError = ref('')
const fieldErrors = ref<Record<string, string[]>>({})
const enterDemo = async () => {
  if (submitting.value) return
  submitting.value = true
  loginError.value = ''
  try {
    await auth.startDemo()
    await notifyAdminLoginSuccess()
  } catch (error) {
    if (error instanceof AdminSessionInvalidatedError) return
    loginError.value = axios.isAxiosError<ApiErrorResponse>(error)
      ? error.response?.data?.message || '唯讀Demo暫時無法進入，請稍後再試。'
      : '唯讀Demo暫時無法進入，請稍後再試。'
  } finally { submitting.value = false }
}

const submit = async () => {
  if (submitting.value) return
  submitting.value = true
  loginError.value = ''
  fieldErrors.value = {}
  try {
    await auth.login({ email: form.email, password: form.password })
    await notifyAdminLoginSuccess()
  } catch (error) {
    if (error instanceof AdminSessionInvalidatedError) return
    if (axios.isAxiosError<ApiErrorResponse>(error)) {
      const status = error.response?.status
      const data = error.response?.data
      if (status === 422) {
        fieldErrors.value = data?.errors ?? {}
        loginError.value = data?.message || '請檢查輸入欄位'
      } else if (status === 401) {
        loginError.value = '管理員帳號或密碼錯誤'
      } else if (status === 403 && data?.code === 'ADMIN_ACCOUNT_DISABLED') {
        loginError.value = data.message
      } else if (status === 429) {
        loginError.value = data?.message ?? '嘗試次數過多，請稍後再試。'
      } else if (status === 419) {
        loginError.value = '登入狀態或 CSRF 發生問題，請重試'
      } else {
        loginError.value = '登入失敗，請稍後再試'
      }
    } else {
      loginError.value = '登入失敗，請稍後再試'
    }
  } finally {
    submitting.value = false
  }
}
const retryRestore = async () => {
  try {
    await auth.restoreAdmin()
    if (auth.isAdminAuthenticated) await notifyAdminLoginSuccess()
  } catch { /* store 保留恢復失敗提示 */ }
}
</script>

<template>
  <section class="admin-login container py-5" style="max-width: 480px">
    <h1 class="h3 mb-4">管理員登入</h1>
    <button type="button" class="btn btn-outline-primary w-100 mb-3" :disabled="submitting" @click="enterDemo">進入唯讀Demo</button>
    <div v-if="auth.restoreError" class="alert alert-warning" role="alert">
      {{ auth.restoreError }}
      <button type="button" class="btn btn-sm btn-outline-dark ms-2" :disabled="auth.isRestoring" @click="retryRestore">重試</button>
    </div>
    <div v-if="auth.adminFailureMessage" class="alert alert-warning" role="alert">{{ auth.adminFailureMessage }}</div>
    <div v-if="loginError" class="alert alert-danger" role="alert">{{ loginError }}</div>
    <form @submit.prevent="submit">
      <div class="mb-3">
        <label for="admin-email" class="form-label">Email</label>
        <input id="admin-email" v-model="form.email" type="email" autocomplete="username" class="form-control" :disabled="submitting" required>
        <div v-for="message in fieldErrors.email" :key="message" class="text-danger small">{{ message }}</div>
      </div>
      <div class="mb-3">
        <label for="admin-password" class="form-label">Password</label>
        <input id="admin-password" v-model="form.password" type="password" autocomplete="current-password" class="form-control" :disabled="submitting" required>
        <div v-for="message in fieldErrors.password" :key="message" class="text-danger small">{{ message }}</div>
      </div>
      <button type="submit" class="btn btn-primary w-100" :disabled="submitting">{{ submitting ? '登入中…' : '登入' }}</button>
    </form>
  </section>
</template>
