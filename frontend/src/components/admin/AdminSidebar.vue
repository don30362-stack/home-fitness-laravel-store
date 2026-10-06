<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { adminModules } from '@/router/adminModules'
import { useAdminAuthStore } from '@/stores/adminAuth'

const open = ref(false)
const auth = useAdminAuthStore()
const links = computed(() => adminModules.filter(module => !module.permission || auth.hasPermission(module.permission)))
</script>

<template>
  <aside class="admin-sidebar border bg-light p-3">
    <div class="d-flex justify-content-between align-items-center gap-2">
      <span class="fw-bold">Home Fitness 後台</span>
      <button type="button" class="btn btn-sm btn-outline-dark d-md-none" :aria-expanded="open" aria-controls="admin-menu" @click="open = !open">選單</button>
    </div>
    <nav id="admin-menu" class="admin-links mt-3" :class="{ 'is-open': open }" aria-label="後台導覽">
      <RouterLink v-for="{path, title} in links" :key="path" :to="'/admin/' + path" class="d-block py-2" @click="open = false">{{ title }}</RouterLink>
      <a href="/" class="d-block mt-3">返回前台</a>
    </nav>
  </aside>
</template>

<style scoped>
.admin-sidebar { width: 100%; min-width: 0; }
.admin-links { display: none; }
.admin-links.is-open { display: block; }
@media (min-width: 768px) {
  .admin-sidebar { width: 15rem; flex-shrink: 0; }
  .admin-links { display: block; }
}
</style>
