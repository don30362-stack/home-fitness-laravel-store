import 'bootstrap/dist/css/bootstrap.min.css'
import 'bootstrap/dist/js/bootstrap.bundle.min.js'
import './assets/main.css'

import { createApp } from 'vue'
import { createPinia } from 'pinia'

import App from './App.vue'
import router from './router'
import { useAuthStore } from '@/stores/auth'
import { setSessionFailureHandler } from '@/services/sessionState'
import { createSessionNavigation, shouldRedirectSessionFailure } from '@/services/sessionNavigation'
import { restoreInitialIdentity } from '@/services/adminBootstrap'
import { connectAdminNavigation } from '@/router/adminRoutes'

const bootstrap = async () => {
    const app = createApp(App)
    const pinia = createPinia()

    app.use(pinia)

    const sessionNavigation = createSessionNavigation(router)
    const adminNavigation = connectAdminNavigation(router)
    setSessionFailureHandler((reason, redirect) => {
        const authStore = useAuthStore(pinia)
        const wasAuthenticated = authStore.isAuthenticated
        authStore.resetMemberSession(reason)
        if (redirect && shouldRedirectSessionFailure(reason, wasAuthenticated, Boolean(router.currentRoute.value.meta.requiresAuth))) {
            sessionNavigation.request()
        }
    })

    await restoreInitialIdentity(window.location.pathname, pinia, () => useAuthStore(pinia).restoreAuth())

    app.use(router)

    await router.isReady()
    await sessionNavigation.ready()
    await adminNavigation.ready()

    app.mount('#app')
}

void bootstrap()
