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

const bootstrap = async () => {
    const app = createApp(App)
    const pinia = createPinia()

    app.use(pinia)

    const authStore = useAuthStore(pinia)
    const sessionNavigation = createSessionNavigation(router)
    setSessionFailureHandler((reason, redirect) => {
        const wasAuthenticated = authStore.isAuthenticated
        authStore.resetMemberSession(reason)
        if (redirect && shouldRedirectSessionFailure(reason, wasAuthenticated, Boolean(router.currentRoute.value.meta.requiresAuth))) {
            sessionNavigation.request()
        }
    })

    await authStore.restoreAuth()

    app.use(router)

    await router.isReady()
    await sessionNavigation.ready()

    app.mount('#app')
}

void bootstrap()
