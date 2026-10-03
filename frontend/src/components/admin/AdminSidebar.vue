<script setup lang="ts">
import { ref } from 'vue'
import { RouterLink } from 'vue-router'

const open = ref(false)
const links = [
  ['dashboard', 'Dashboard'], ['products', '商品管理'], ['categories', '分類管理'],
  ['inventory', '庫存管理'], ['orders', '訂單管理'], ['users', '會員管理'],
  ['home-content', '首頁內容'], ['admins', '管理員管理'],
]
</script>

<template>
  <aside class="admin-sidebar border bg-light p-3">
    <div class="d-flex justify-content-between align-items-center gap-2">
      <span class="fw-bold">Home Fitness 後台</span>
      <button type="button" class="btn btn-sm btn-outline-dark d-md-none" :aria-expanded="open" aria-controls="admin-menu" @click="open = !open">選單</button>
    </div>
    <nav id="admin-menu" class="admin-links mt-3" :class="{ 'is-open': open }" aria-label="後台導覽">
      <RouterLink v-for="[path, title] in links" :key="path" :to="'/admin/' + path" class="d-block py-2" @click="open = false">{{ title }}</RouterLink>
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
