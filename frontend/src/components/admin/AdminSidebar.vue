<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { adminModules } from '@/router/adminModules'
import { useAdminAuthStore } from '@/stores/adminAuth'

const open = ref(false)
const auth = useAdminAuthStore()
const route = useRoute()
const links = computed(() => adminModules.filter(module => auth.isReadOnlyDemo
  ? ['dashboard', 'products', 'categories', 'inventory', 'home-content'].includes(module.path)
  : !module.permission || auth.hasPermission(module.permission)))
const groups = computed(() => [
  { title: '工作總覽', paths: ['dashboard'] },
  { title: '商品與內容', paths: ['products', 'categories', 'inventory', 'home-content'] },
  { title: '營運管理', paths: ['orders', 'users'] },
  { title: '系統管理', paths: ['admins'] },
].map(group => ({ ...group, links: links.value.filter(link => group.paths.includes(link.path)) })).filter(group => group.links.length))
const isCurrent = (path: string) => route.path === '/admin/' + path || route.path.startsWith('/admin/' + path + '/')
const icons: Record<string, string> = {
  dashboard: 'M3 3h7v7H3z M14 3h7v7h-7z M3 14h7v7H3z M14 14h7v7h-7z',
  products: 'M4 7l8-4 8 4v10l-8 4-8-4z M4 7l8 4 8-4 M12 11v10',
  categories: 'M3 5h7l2 3h9v12H3z',
  inventory: 'M4 20V10h4v10 M10 20V4h4v16 M16 20v-7h4v7',
  orders: 'M6 3h12v18H6z M9 7h6 M9 11h6 M9 15h4',
  users: 'M16 7a4 4 0 1 1-8 0a4 4 0 0 1 8 0 M4 21v-2a8 8 0 0 1 16 0v2',
  'home-content': 'M3 4h18v16H3z M3 9h18 M8 9v11',
  admins: 'M12 3l8 3v6c0 5-8 9-8 9s-8-4-8-9V6z M9 12l2 2 4-4',
}
</script>

<template>
  <aside class="admin-sidebar">
    <div class="d-flex justify-content-between align-items-center gap-2">
      <div class="admin-sidebar-brand"><span class="admin-brand-mark" aria-hidden="true">HF</span><div><span class="fw-bold">Home Fitness 後台</span><small class="d-block">管理工作台</small></div></div>
      <button type="button" class="btn btn-sm btn-outline-dark d-md-none" :aria-expanded="open" aria-controls="admin-menu" @click="open = !open">選單</button>
    </div>
    <nav id="admin-menu" class="admin-links mt-3" :class="{ 'is-open': open }" aria-label="後台導覽">
      <div v-for="group in groups" :key="group.title" class="admin-nav-group">
        <p class="admin-nav-heading">{{ group.title }}</p>
        <RouterLink v-for="{path, title} in group.links" :key="path" :to="'/admin/' + path" class="admin-nav-link" :class="{ 'is-current': isCurrent(path) }" :aria-current="isCurrent(path) ? (route.path === '/admin/' + path ? 'page' : 'location') : undefined" @click="open = false"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path :d="icons[path]" /></svg><span>{{ title }}</span></RouterLink>
      </div>
      <a href="/" class="admin-nav-return">返回前台 <span aria-hidden="true">↗</span></a>
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
