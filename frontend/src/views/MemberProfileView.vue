<script setup lang="ts">
import axios from 'axios'
import { reactive, ref, watch } from 'vue'

import { useAuthStore } from '@/stores/auth'

import type { ApiErrorResponse } from '@/types/api'
import type { UpdateProfilePayload, UpdatePasswordPayload } from '@/types/auth'

const authStore = useAuthStore()

const profileForm = reactive<UpdateProfilePayload>({
  name: '',
  email: '',
  phone: '',
})

const profileMessage = ref('')
const profileErrorMessage = ref('')
const isProfileSubmitting = ref(false)
const profileFieldErrors = ref<Record<string, string[]>>({})

const passwordForm = reactive<UpdatePasswordPayload>({
  current_password: '',
  password: '',
  password_confirmation: '',
})

const isPasswordSubmitting = ref(false)
const passwordMessage = ref('')
const passwordErrorMessage = ref('')
const passwordFieldErrors = ref<Record<string, string[]>>({})

watch(
  () => authStore.currentUser,
  (user) => {
    if (!user) {
      return
    }

    profileForm.name = user.name
    profileForm.email = user.email
    profileForm.phone = user.phone
  },
  {
    immediate: true,
  },
)

const handleProfileUpdate = async () => {
  if (isProfileSubmitting.value) {
    return
  }

  isProfileSubmitting.value = true
  profileMessage.value = ''
  profileErrorMessage.value = ''
  profileFieldErrors.value = {}

  try {
    const response = await authStore.updateProfile({
      name: profileForm.name.trim(),
      email: profileForm.email.trim(),
      phone: profileForm.phone.trim(),
    })

    profileMessage.value = response.message
  } catch (error) {
    if (axios.isAxiosError<ApiErrorResponse>(error)) {
      if (error.response?.status === 422) {
        profileFieldErrors.value = error.response.data.errors ?? {}
      }

      profileErrorMessage.value = error.response?.data.message ?? '會員資料更新失敗，請稍後再試'
    } else {
      profileErrorMessage.value = '會員資料更新失敗，請稍後再試'
    }
  } finally {
    isProfileSubmitting.value = false
  }
}

const handlePasswordUpdate = async () => {
  if (isPasswordSubmitting.value) {
    return
  }

  passwordMessage.value = ''
  passwordErrorMessage.value = ''
  passwordFieldErrors.value = {}

  if (passwordForm.password !== passwordForm.password_confirmation) {
    passwordFieldErrors.value.password = ['新密碼與確認密碼不一致']
    return
  }

  isPasswordSubmitting.value = true

  try {
    const response = await authStore.updatePassword(passwordForm)
    passwordMessage.value = response.message

    passwordForm.current_password = ''
    passwordForm.password = ''
    passwordForm.password_confirmation = ''
  } catch (error) {
    if (axios.isAxiosError<ApiErrorResponse>(error)) {
      if (error.response?.status === 422) {
        passwordFieldErrors.value = error.response.data.errors ?? {}
      }
      passwordErrorMessage.value = error.response?.data.message ?? '密碼更新失敗，請稍後再試'
    } else {
      passwordErrorMessage.value = '密碼更新失敗，請稍後再試'
    }
  } finally {
    isPasswordSubmitting.value = false
  }
}
</script>

<template>
  <section class="hf-account-section">
    <h2 class="h4 mb-4">基本資料</h2>

    <div v-if="profileMessage" class="alert alert-success" role="alert">
      {{ profileMessage }}
    </div>

    <div v-if="profileErrorMessage" class="alert alert-danger" role="alert">
      {{ profileErrorMessage }}
    </div>

    <div class="card">
      <div class="card-body p-4">
        <form @submit.prevent="handleProfileUpdate">
          <div class="mb-3">
            <label for="profileName" class="form-label">姓名</label>

            <input
              v-model="profileForm.name"
              type="text"
              id="profileName"
              class="form-control"
              :class="{ 'is-invalid': profileFieldErrors.name }"
              maxlength="50"
              autocomplete="name"
              required
            />

            <div v-if="profileFieldErrors.name" class="invalid-feedback">
              {{ profileFieldErrors.name[0] }}
            </div>
          </div>

          <div class="mb-3">
            <label for="profileEmail" class="form-label">電子郵件</label>

            <input
              v-model="profileForm.email"
              type="email"
              id="profileEmail"
              class="form-control"
              :class="{ 'is-invalid': profileFieldErrors.email }"
              maxlength="255"
              autocomplete="email"
              required
            />

            <div v-if="profileFieldErrors.email" class="invalid-feedback">
              {{ profileFieldErrors.email[0] }}
            </div>
          </div>

          <div class="mb-4">
            <label for="profilePhone" class="form-label">手機號碼</label>

            <input
              v-model="profileForm.phone"
              type="tel"
              id="profilePhone"
              class="form-control"
              :class="{ 'is-invalid': profileFieldErrors.phone }"
              maxlength="10"
              pattern="09[0-9]{8}"
              inputmode="numeric"
              autocomplete="tel"
              required
            />

            <div v-if="profileFieldErrors.phone" class="invalid-feedback">
              {{ profileFieldErrors.phone[0] }}
            </div>

            <div class="form-text">請輸入 09 開頭的 10 碼手機號碼</div>
          </div>

          <button type="submit" class="btn btn-dark" :disabled="isProfileSubmitting">
            {{ isProfileSubmitting ? '儲存中...' : '儲存基本資料' }}
          </button>
        </form>
      </div>
    </div>

    <h2 class="h4 mt-5 mb-4">修改密碼</h2>

    <div v-if="passwordMessage" class="alert alert-success" role="alert">
      {{ passwordMessage }}
    </div>

    <div v-if="passwordErrorMessage" class="alert alert-danger" role="alert">
      {{ passwordErrorMessage }}
    </div>

    <div class="card">
      <div class="card-body p-4">
        <form @submit.prevent="handlePasswordUpdate">
          <div class="mb-3">
            <label for="currentPassword" class="form-label">目前密碼</label>
            <input
              v-model="passwordForm.current_password"
              type="password"
              id="currentPassword"
              class="form-control"
              :class="{ 'is-invalid': passwordFieldErrors.current_password }"
              autocomplete="current-password"
              required
            />
            <div v-if="passwordFieldErrors.current_password" class="invalid-feedback">
              {{ passwordFieldErrors.current_password[0] }}
            </div>
          </div>

          <div class="mb-3">
            <label for="newPassword" class="form-label">新密碼</label>
            <input
              v-model="passwordForm.password"
              type="password"
              id="newPassword"
              class="form-control"
              :class="{ 'is-invalid': passwordFieldErrors.password }"
              minlength="8"
              autocomplete="new-password"
              required
            />
            <div v-if="passwordFieldErrors.password" class="invalid-feedback">
              {{ passwordFieldErrors.password[0] }}
            </div>
          </div>

          <div class="mb-4">
            <label for="newPasswordConfirmation" class="form-label">確認新密碼</label>
            <input
              v-model="passwordForm.password_confirmation"
              type="password"
              id="newPasswordConfirmation"
              class="form-control"
              :class="{ 'is-invalid': passwordFieldErrors.password_confirmation }"
              minlength="8"
              autocomplete="new-password"
              required
            />
          </div>

          <div>
            <button type="submit" class="btn btn-dark" :disabled="isPasswordSubmitting">
              {{ isPasswordSubmitting ? '更新中...' : '更新密碼' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </section>
</template>
