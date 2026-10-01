<script setup lang="ts">
import { ref } from 'vue';
import { RouterLink, useRouter } from 'vue-router'

import { useAuthStore } from '@/stores/auth';

const router = useRouter()
const authStore = useAuthStore()

const isLoggingOut = ref(false)

const handleLogout = async () => {
    if (isLoggingOut.value) {
        return
    }

    isLoggingOut.value = true

    try {
        if (await authStore.logout()) {
            await router.push({ name: 'home' })
        }
    } finally {
        isLoggingOut.value = false
    }
}
</script>

<template>
    <header class="border-bottom">
        <nav class="navbar navbar-expand-lg">
            <div class="container">
                <RouterLink class="navbar-brand fw-bold" :to="{ name: 'home' }">
                    Home Fitness
                </RouterLink>

                <div class="navbar-nav ms-auto">
                    <RouterLink class="nav-link" :to="{ name: 'home' }">
                        首頁
                    </RouterLink>
                    <RouterLink class="nav-link" :to="{ name: 'products' }">
                        商品
                    </RouterLink>
                    <RouterLink class="nav-link" :to="{ name: 'about' }">
                        品牌介紹
                    </RouterLink>
                    <RouterLink class="nav-link" :to="{ name: 'cart' }">
                        購物車
                    </RouterLink>

                    <template v-if="authStore.isAuthenticated">
                        <RouterLink class="nav-link" :to="{ name: 'member-profile' }">
                            {{ authStore.currentUser?.name }}
                        </RouterLink>

                        <button type="button" class="nav-link btn btn-link" :disabled="isLoggingOut"
                            @click="handleLogout">
                            {{ isLoggingOut ? '登出中...' : '登出' }}
                        </button>
                    </template>

                    <template v-else>
                        <RouterLink class="nav-link" :to="{ name: 'login' }">登入</RouterLink>
                        <RouterLink class="nav-link" :to="{ name: 'register' }">註冊</RouterLink>
                    </template>
                </div>
            </div>
        </nav>
    </header>
</template>
