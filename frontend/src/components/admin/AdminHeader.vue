<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAdminAuthStore } from '@/stores/adminAuth'

const auth = useAdminAuthStore()
const router = useRouter()
const submitting = ref(false)
const errorMessage = ref('')
const logout = async () => {
  if (submitting.value) return
  submitting.value = true
  errorMessage.value = ''
  try {
    await auth.logout()
    if (!auth.isAdminAuthenticated) await router.replace({ name: 'admin-login' })
  } catch {
    errorMessage.value = '登出失敗，請稍後再試'
  } finally { submitting.value = false }
}
</script>

<template>
  <header class="border-bottom p-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
      <div class="admin-identity">
        <div class="fw-bold">{{ auth.currentAdmin?.name }}</div>
        <div class="small text-muted">{{ auth.currentAdmin?.email }}</div>
      </div>
      <button type="button" class="btn btn-outline-secondary" :disabled="submitting" @click="logout">{{ submitting ? '登出中…' : '登出' }}</button>
    </div>
    <p v-if="errorMessage" class="text-danger mt-2 mb-0" role="alert">{{ errorMessage }}</p>
  </header>
</template>

<style scoped>
.admin-identity { min-width: 0; overflow-wrap: anywhere; }
</style>
