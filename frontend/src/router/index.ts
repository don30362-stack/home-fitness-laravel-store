import { createRouter, createWebHistory } from 'vue-router'

import { useAuthStore } from '@/stores/auth'
import { adminRoutes, adminGuard } from './adminRoutes'
import { applyPageMetadata } from './pageMetadata'

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
          meta: { title: 'Home Fit｜居家訓練器材', description: 'Home Fit 提供居家重訓器材與訓練配件的商品瀏覽與選購資訊。' },
        },
        {
          path: 'about',
          name: 'about',
          component: AboutView,
          meta: { title: '品牌介紹｜Home Fit', description: '認識 Home Fit 的居家訓練理念與品牌定位。' },
        },
        {
          path: 'faq',
          name: 'faq',
          component: FAQView,
          meta: { title: '常見問題與購物須知｜Home Fit', description: '查看 Home Fit 的購物、會員、付款、配送與訂單常見問題。' },
        },
        {
          path: 'products',
          name: 'products',
          component: ProductListView,
          meta: { title: '商品｜Home Fit', description: '瀏覽 Home Fit 的居家重訓器材與訓練配件。' },
        },
        {
          path: 'products/:id',
          name: 'product-detail',
          component: ProductDetailView,
          meta: { title: '商品詳細｜Home Fit', description: '查看 Home Fit 商品圖片、介紹、規格與購買資訊。' },
        },
        {
          path: 'cart',
          name: 'cart',
          component: CartView,
          meta: { title: '購物車｜Home Fit', description: '查看目前加入 Home Fit 購物車的商品。' },
        },
        {
          path: 'checkout',
          name: 'checkout',
          component: CheckoutView,
          meta: {
            title: '結帳｜Home Fit',
            description: '確認收件資料、付款方式與訂單內容。',
            requiresAuth: true,
          },
        },
        {
          path: 'login',
          name: 'login',
          component: LoginView,
          meta: { title: '會員登入｜Home Fit', description: '登入 Home Fit 會員帳號。' },
        },
        {
          path: 'register',
          name: 'register',
          component: RegisterView,
          meta: { title: '會員註冊｜Home Fit', description: '建立 Home Fit 會員帳號。' },
        },
        {
          path: 'member',
          name: 'member',
          component: MemberView,
          meta: {
            title: '會員中心｜Home Fit',
            description: '管理 Home Fit 會員資料、地址與訂單。',
            requiresAuth: true,
          },
          children: [
            {
              path: 'orders',
              name: 'member-orders',
              component: MemberOrderListView,
              meta: { title: '我的訂單｜Home Fit', description: '查看 Home Fit 會員訂單。' },
            },
            {
              path: 'orders/:id',
              name: 'member-order-detail',
              component: MemberOrderDetailView,
              meta: { title: '訂單詳細｜Home Fit', description: '查看 Home Fit 訂單內容與目前狀態。' },
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
              meta: { title: '會員資料｜Home Fit', description: '管理 Home Fit 會員基本資料。' },
            },
            {
              path: 'addresses',
              name: 'member-addresses',
              component: MemberAddressView,
              meta: { title: '地址簿｜Home Fit', description: '管理 Home Fit 常用收件地址。' },
            }
          ],
        },
      ],
    },
    {
      path: '/:pathMatch(.*)*',
      name: 'not-found',
      component: NotFoundView,
      meta: { title: '找不到頁面｜Home Fit', description: '找不到您要查看的 Home Fit 頁面。' },
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

router.afterEach((to, _from, failure) => {
  if (!failure) applyPageMetadata(to.meta)
})

export default router
