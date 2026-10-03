<script setup lang="ts">
import { RouterView, useRouter, useRoute } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
import AdminSidebar from '@/components/admin/AdminSidebar.vue'
import AdminHeader from '@/components/admin/AdminHeader.vue'

const auth = useAdminAuthStore()
const router = useRouter()
const route = useRoute()
const retry = async () => {
  try {
    await auth.restoreAdmin()
    if (!auth.isAdminAuthenticated) await router.replace({ name: 'admin-login', query: { redirect: route.fullPath } })
  } catch { /* store 保留恢復失敗語意 */ }
}
</script>

<template>
  <div class="admin-shell">
    <section v-if="auth.restoreError || !auth.isAdminInitialized" class="p-3" role="alert">
      <h1 class="h4">無法恢復管理員登入狀態</h1>
      <p>{{ auth.restoreError }}</p>
      <button type="button" class="btn btn-primary" :disabled="auth.isRestoring" @click="retry">{{ auth.isRestoring ? '重試中…' : '重試' }}</button>
      <a href="/" class="d-block mt-3">返回前台</a>
    </section>
    <div v-else-if="auth.isAdminAuthenticated" class="d-flex flex-column flex-md-row min-vh-100">
      <AdminSidebar />
      <div class="admin-content flex-grow-1">
        <AdminHeader />
        <main class="p-3"><RouterView /></main>
      </div>
    </div>
    <p v-else class="p-3" role="status">正在確認管理員登入狀態…</p>
  </div>
</template>

<style scoped>
.admin-shell, .admin-content { min-width: 0; width: 100%; overflow-wrap: anywhere; }
:global(#app:has(.admin-shell)), :global(#app:has(.admin-login)) { display: block; width: 100%; }
</style>
