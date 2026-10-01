<script setup lang="ts">
import { useRouter, RouterLink, RouterView } from 'vue-router';
import { useAuthStore } from '@/stores/auth';

const router = useRouter()
const authStore = useAuthStore()

const handleLogout = async () => {
    if (await authStore.logout()) {
        await router.push({ name: 'login' })
    }
}
</script>

<template>
    <div class="container py-5">
        <div class="row g-4">
            <aside class="col-lg-3">
                <div class="card">
                    <div class="card-body border-bottom">
                        <h1 class="h5 mb-1">會員中心</h1>
                        <p class="text-muted small mb-0">
                            {{ authStore.currentUser?.name }}
                        </p>
                    </div>

                    <nav class="list-group">
                        <RouterLink :to="{ name: 'member-orders' }" class="list-group-item list-group-item-action"
                            active-class="active">
                            我的訂單
                        </RouterLink>
                        <RouterLink :to="{ name: 'member-profile' }" class="list-group-item list-group-item-action"
                            active-class="active">
                            基本資料
                        </RouterLink>
                        <RouterLink :to="{ name: 'member-addresses' }" class="list-group-item list-group-item-action"
                            active-class="active">
                            地址簿
                        </RouterLink>
                    </nav>

                    <div class="card-body">
                        <button type="button" class="btn btn-outline-danger w-100" @click="handleLogout">
                            登出
                        </button>
                    </div>
                </div>
            </aside>

            <div class="col-lg-9">
                <main>
                    <RouterView />
                </main>
            </div>
        </div>
    </div>
</template>
