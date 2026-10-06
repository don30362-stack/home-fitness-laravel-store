<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'
const auth = useAdminAuthStore()
</script>

<template>
  <section>
    <h1 class="h3">沒有此功能權限</h1>
    <p>您目前沒有使用此管理功能的權限，請聯絡有權限的管理員。</p>
    <div v-if="auth.permissionRefreshError" class="alert alert-danger" role="alert">
      <p>{{ auth.permissionRefreshError }}</p>
      <button class="btn btn-outline-danger" :disabled="auth.isRefreshingPermissions" @click="auth.refreshAdminIdentity()">重試權限資料</button>
    </div>
    <RouterLink to="/admin/dashboard">返回後台首頁</RouterLink>
  </section>
</template>
