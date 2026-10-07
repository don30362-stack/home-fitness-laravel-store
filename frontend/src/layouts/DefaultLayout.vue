<script setup lang="ts">
import { RouterView } from 'vue-router'

import AppHeader from '@/components/layout/AppHeader.vue';
import AppFooter from '@/components/layout/AppFooter.vue';
</script>

<template>
    <div class="storefront-shell d-flex flex-column min-vh-100">
        <AppHeader />

        <main>
            <RouterView />
        </main>

        <AppFooter />
    </div>
</template>