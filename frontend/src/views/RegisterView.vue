<script setup lang="ts">
import axios from 'axios';
import type { ApiErrorResponse } from '@/types/api';
import { ref } from 'vue';
import { useRouter } from 'vue-router';

import { useAuthStore } from '@/stores/auth';

const router = useRouter();
const authStore = useAuthStore();

const name = ref('');
const email = ref('');
const phone = ref('');
const password = ref('');
const passwordConfirmation = ref('');

const isSubmitting = ref(false);
const errorMessage = ref('');
const fieldErrors = ref<Record<string, string[]>>({});

const handleRegister = async () => {
    if (isSubmitting.value) {
        return
    }

    errorMessage.value = ''
    fieldErrors.value = {}

    if (password.value !== passwordConfirmation.value) {
        fieldErrors.value.password = ['密碼與確認密碼不一致']
        return
    }

    isSubmitting.value = true

    try {
        await authStore.register({
            name: name.value.trim(),
            email: email.value.trim(),
            phone: phone.value.trim(),
            password: password.value,
            password_confirmation: passwordConfirmation.value
        })

        await router.push({
            path: '/login',
            query: {
                'registered': '1'
            }
        })

    } catch (error) {
        if (axios.isAxiosError<ApiErrorResponse>(error)) {
            if (error.response?.status === 422) {
                fieldErrors.value = error.response.data?.errors ?? {}
            }
            errorMessage.value = error.response?.data?.message ?? '註冊失敗，請稍後再試'
        } else {
            errorMessage.value = '註冊失敗，請稍後再試'
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
                <header class="hf-page-heading text-center"><p class="hf-eyebrow">JOIN HOME FIT</p><h1 class="h2">會員註冊</h1></header>

                <div v-if="errorMessage" class="alert alert-danger" role="alert">
                    {{ errorMessage }}
                </div>

                <form @submit.prevent="handleRegister">
                    <div class="mb-3">
                        <label for="name" class="form-label">姓名</label>

                        <input id="name" v-model="name" type="text" class="form-control"
                            :class="{ 'is-invalid': fieldErrors.name }" :aria-invalid="!!fieldErrors.name" :aria-describedby="fieldErrors.name ? 'address-error-name' : undefined" maxlength="50" autocomplete="name" required>

                        <div v-if="fieldErrors.name" id="address-error-name" class="invalid-feedback">
                            {{ fieldErrors.name[0] }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="email" class="form-label">電子郵件</label>

                        <input id="email" v-model="email" type="email" class="form-control"
                            :class="{ 'is-invalid': fieldErrors.email }" :aria-invalid="!!fieldErrors.email" :aria-describedby="fieldErrors.email ? 'address-error-email' : undefined" maxlength="255" autocomplete="email" required>

                        <div v-if="fieldErrors.email" id="address-error-email" class="invalid-feedback">
                            {{ fieldErrors.email[0] }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="phone" class="form-label">電話</label>

                        <input id="phone" v-model="phone" type="tel" class="form-control"
                            :class="{ 'is-invalid': fieldErrors.phone }" :aria-invalid="!!fieldErrors.phone" :aria-describedby="fieldErrors.phone ? 'address-error-phone' : undefined" maxlength="20" autocomplete="tel" required>

                        <div v-if="fieldErrors.phone" id="address-error-phone" class="invalid-feedback">
                            {{ fieldErrors.phone[0] }}
                        </div>
                    </div>

                    <div class="mb-3">
                        <label for="password" class="form-label">密碼</label>

                        <input id="password" v-model="password" type="password" class="form-control"
                            :class="{ 'is-invalid': fieldErrors.password }" :aria-invalid="!!fieldErrors.password" :aria-describedby="fieldErrors.password ? 'address-error-password' : undefined" minlength="8" autocomplete="new-password"
                            required>

                        <div v-if="fieldErrors.password" id="address-error-password" class="invalid-feedback">
                            {{ fieldErrors.password[0] }}
                        </div>
                    </div>

                    <div class="mb-4">
                        <label for="passwordConfirmation" class="form-label">確認密碼</label>

                        <input id="passwordConfirmation" v-model="passwordConfirmation" type="password"
                            class="form-control" minlength="8" autocomplete="new-password" required>
                    </div>

                    <button type="submit" class="btn btn-dark w-100" :disabled="isSubmitting">
                        {{ isSubmitting ? '註冊中...' : '註冊' }}
                    </button>
                </form>

                <p class="text-center mt-4 mb-0">
                    已經有會員帳號？
                    <RouterLink :to="{name:'login'}">
                        立即登入
                    </RouterLink>
                </p>
            </div>
        </div>
    </div>
</template>