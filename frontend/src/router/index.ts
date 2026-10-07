import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import { adminRoutes, adminGuard } from './adminRoutes'

import DefaultLayout from '@/layouts/DefaultLayout.vue'
import HomeView from '@/views/HomeView.vue'
import AboutView from '@/views/AboutView.vue'
import FAQView from '@/views/FAQView.vue'
import ProductListView from '@/views/ProductListView.vue'
import ProductDetailView from '@/views/ProductDetailView.vue'
import CartView from '@/views/CartView.vue'
import CheckoutView from '@/views/CheckoutView.vue'
import LoginView from '@/views/LoginView.vue'
import RegisterView from '@/views/RegisterView.vue'
import MemberView from '@/views/MemberView.vue'
import NotFoundView from '@/views/NotFoundView.vue'
import MemberProfileView from '@/views/MemberProfileView.vue'
import MemberAddressView from '@/views/MemberAddressView.vue'
import MemberOrderListView from '@/views/MemberOrderListView.vue'
import MemberOrderDetailView from '@/views/MemberOrderDetailView.vue'

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),

  routes: [
    ...adminRoutes,
    {
      path: '/',
      component: DefaultLayout,
      children: [
        {
          path: '',
          name: 'home',
          component: HomeView,
        },
        {
          path: 'about',
          name: 'about',
          component: AboutView,
        },
        {
          path: 'faq',
          name: 'faq',
          component: FAQView,
        },
        {
          path: 'products',
          name: 'products',
          component: ProductListView,
        },
        {
          path: 'products/:id',
          name: 'product-detail',
          component: ProductDetailView,
        },
        {
          path: 'cart',
          name: 'cart',
          component: CartView,
        },
        {
          path: 'checkout',
          name: 'checkout',
          component: CheckoutView,
          meta: {
            requiresAuth: true,
          },
        },
        {
          path: 'login',
          name: 'login',
          component: LoginView,
        },
        {
          path: 'register',
          name: 'register',
          component: RegisterView,
        },
        {
          path: 'member',
          name: 'member',
          component: MemberView,
          meta: {
            requiresAuth: true,
          },
          children: [
            {
              path: 'orders',
              name: 'member-orders',
              component: MemberOrderListView,
            },
            {
              path: 'orders/:id',
              name: 'member-order-detail',
              component: MemberOrderDetailView,
            },
            {
              path: '',
              redirect: {
                name: 'member-profile',
              },
            },
            {
              path: 'profile',
              name: 'member-profile',
              component: MemberProfileView,
            },
            {
              path: 'addresses',
              name: 'member-addresses',
              component: MemberAddressView,
            }
          ],
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: NotFoundView,
    }
  ],
})

router.beforeEach(adminGuard)
router.beforeEach((to) => {
  if (!to.meta.requiresAuth) return
  const authStore = useAuthStore()

  if (to.meta.requiresAuth && !authStore.isAuthenticated) {
    return {
      name: 'login',
      query: {
        redirect: to.fullPath,
      },
    }
  }
})

export default router
