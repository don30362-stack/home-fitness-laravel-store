import { computed, ref } from "vue";
import { defineStore } from "pinia";
import { useCartStore } from '@/stores/cart'
import axios from 'axios'
import {
    getSessionVersion,
    ensureSessionVersion,
    startSessionVersion,
    SessionInvalidatedError,
    type AuthFailureReason,
} from '@/services/sessionState'

import {
    getMe,
    login as loginApi,
    logout as logoutApi,
    register as registerApi,
    updatePassword as updatePasswordApi,
    updateProfile as updateProfileApi,
} from "@/services/authService";

import type {
    LoginPayload,
    RegisterPayload,
    User,
    UpdateProfilePayload,
    UpdatePasswordPayload,
} from "@/types/auth";

export const useAuthStore = defineStore('auth', () => {
    const currentUser = ref<User | null>(null)
    const isAuthInitialized = ref(false)
    const authFailureReason = ref<AuthFailureReason | null>(null)

    const cartStore = useCartStore()

    const isAuthenticated = computed(() => {
        return currentUser.value !== null
    })

    const resetMemberSession = (reason: AuthFailureReason | null = null) => {
        currentUser.value = null
        cartStore.resetMemberCart()
        // 並行請求後到的 401 不得蓋掉停用原因。
        if (authFailureReason.value !== 'disabled' || reason === 'disabled') {
            authFailureReason.value = reason
        }
    }

    const register = async (payload: RegisterPayload) => {
        const response = await registerApi(payload)

        return response
    }

    const login = async (payload: LoginPayload) => {
        const attemptVersion = startSessionVersion()
        const response = await loginApi(payload)
        if (attemptVersion !== getSessionVersion()) throw new SessionInvalidatedError()
        startSessionVersion()
        authFailureReason.value = null
        currentUser.value = response.data

        return response
    }

    const restoreAuth = async () => {
        const requestVersion = getSessionVersion()
        try {
            const response = await getMe()
            ensureSessionVersion(requestVersion)
            currentUser.value = response.data

            try {
                await cartStore.fetchMemberCart()
            } catch {
                // 購物車載入失敗不能讓會員被判定為未登入。
                if (requestVersion === getSessionVersion() || currentUser.value === null) {
                    cartStore.resetMemberCart()
                }
            }
        } catch {
            if (requestVersion === getSessionVersion() || currentUser.value === null) {
                resetMemberSession(authFailureReason.value)
            }
        } finally {
            isAuthInitialized.value = true
        }
    }

    const updateProfile = async (payload: UpdateProfilePayload) => {
        const requestVersion = getSessionVersion()
        const response = await updateProfileApi(payload)
        ensureSessionVersion(requestVersion)
        currentUser.value = response.data

        return response
    }

    const updatePassword = async (payload: UpdatePasswordPayload) => {
        return await updatePasswordApi(payload)
    }

    const logout = async () => {
        const requestVersion = getSessionVersion()
        try {
            await logoutApi()
        } catch (error) {
            if (error instanceof SessionInvalidatedError || (axios.isAxiosError(error) && error.response?.status === 401)) {
                if (requestVersion === getSessionVersion() || currentUser.value === null) {
                    resetMemberSession(authFailureReason.value ?? 'expired')
                }
                return false
            }
            throw error
        }
        startSessionVersion()
        resetMemberSession()
        return true
    }

    return {
        currentUser,
        isAuthInitialized,
        isAuthenticated,
        authFailureReason,
        resetMemberSession,
        register,
        login,
        restoreAuth,
        updateProfile,
        updatePassword,
        logout,
    }
})
