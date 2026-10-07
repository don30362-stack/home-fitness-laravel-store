<script setup lang="ts">
import { ref, watch } from 'vue';
import { RouterLink, useRouter, useRoute } from 'vue-router'

import { useAuthStore } from '@/stores/auth';
import logoUrl from '@/assets/images/brand/logo-s2.png'

const router = useRouter()
const route = useRoute()
const menuOpen = ref(false)
watch(() => route.fullPath, () => { menuOpen.value = false })
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
    <header class="storefront-header">
        <nav class="navbar">
            <div class="container">
                <RouterLink class="navbar-brand fw-bold" :to="{ name: 'home' }">
                    <img :src="logoUrl" alt="Home Fitness" class="brand-logo" width="851" height="609">
                </RouterLink>

                <button type="button" class="menu-toggle" aria-label="切換導覽選單" aria-controls="storefront-navigation" :aria-expanded="menuOpen" @click="menuOpen = !menuOpen"><span aria-hidden="true">☰</span> 選單</button>
                <div id="storefront-navigation" class="navbar-nav" :class="{ 'is-open': menuOpen }" @click="menuOpen = false">
                    <RouterLink class="nav-link" :to="{ name: 'home' }">
                        首頁
                    </RouterLink>
                    <RouterLink class="nav-link" :to="{ name: 'products' }">
                        商品
                    </RouterLink>
                    <RouterLink class="nav-link" :to="{ name: 'about' }">
                        品牌介紹
                    </RouterLink>
                    <RouterLink class="nav-link" :to="{ name: 'faq' }">
                        常見問題
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

<style scoped>
.storefront-header { background: #fff; border-bottom: 1px solid var(--hf-stone); }
.navbar { padding: .65rem 0; }
.navbar .container { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 1rem; }
.navbar-brand { margin: 0; padding: 0; }
.brand-logo { height: 62px; width: auto; display: block; }
.navbar-nav { flex-direction: row; align-items: center; gap: 1.6rem; }
.nav-link { position: relative; color: var(--hf-charcoal); font-weight: 600; padding: .7rem 0; overflow-wrap: anywhere; }
.nav-link::after { content: ''; position: absolute; bottom: .3rem; left: 0; width: 0; height: 2px; background: var(--hf-gold); transition: width 200ms; }
.nav-link:hover::after, .nav-link.router-link-exact-active::after { width: 100%; }
.menu-toggle { display: none; background: transparent; border: 1px solid var(--hf-stone); padding: .65rem .8rem; color: var(--hf-charcoal); }
nav :focus-visible { outline: 3px solid var(--hf-charcoal); outline-offset: 4px; }
@media (max-width: 991.98px) {
 .brand-logo { height: 50px; }
 .menu-toggle { display: block; }
 .navbar-nav { display: none; flex-basis: 100%; align-items: stretch; flex-direction: column; gap: 0; }
 .navbar-nav.is-open { display: flex; }
 .nav-link { min-height: 44px; text-align: left; }
}
@media (prefers-reduced-motion: reduce) { .nav-link::after { transition: none; } }
</style>
