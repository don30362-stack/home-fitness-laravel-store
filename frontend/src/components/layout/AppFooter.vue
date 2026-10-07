<script setup lang="ts">
import { RouterLink } from 'vue-router'
</script>

<template>
  <footer class="storefront-footer mt-auto">
    <div class="container">
      <div class="footer-columns">
        <div class="footer-brand">
          <p class="footer-brand-name">Home Fit</p>
          <p>把訓練，帶回自己的生活。</p>
        </div>
        <nav aria-labelledby="footer-explore">
          <h2 id="footer-explore">探索</h2>
          <ul>
            <li><RouterLink :to="{ name: 'products' }">商品</RouterLink></li>
            <li><RouterLink :to="{ name: 'about' }">品牌介紹</RouterLink></li>
          </ul>
        </nav>
        <nav aria-labelledby="footer-shopping">
          <h2 id="footer-shopping">購物資訊</h2>
          <ul>
            <li><RouterLink :to="{ name: 'faq' }">常見問題與購物須知</RouterLink></li>
            <li><RouterLink :to="{ name: 'member' }">會員中心</RouterLink></li>
            <li><RouterLink :to="{ name: 'cart' }">購物車</RouterLink></li>
          </ul>
        </nav>
      </div>
      <p class="footer-copyright">© Home Fitness</p>
    </div>
  </footer>
</template>

<style scoped>
.storefront-footer { background: var(--hf-charcoal); color: var(--hf-ivory); }
.footer-columns { display: grid; grid-template-columns: 1.5fr 1fr 1fr; gap: 3rem; padding-block: 3.5rem; }
.footer-columns > * { min-width: 0; }
.footer-brand-name { font-size: 1.6rem; font-weight: 600; letter-spacing: .04em; margin-bottom: .75rem; }
.footer-brand p:last-child { color: var(--hf-stone); line-height: 1.9; margin: 0; }
h2 { font-size: .9rem; color: var(--hf-gold); font-weight: 600; letter-spacing: .08em; margin: 0 0 .75rem; }
ul { list-style: none; padding: 0; margin: 0; }
a { display: inline-block; color: var(--hf-ivory); padding-block: .5rem; line-height: 1.8; }
a:hover { color: var(--hf-gold); text-decoration: underline; text-underline-offset: .3em; }
a:focus-visible { outline: 3px solid var(--hf-gold); outline-offset: 4px; }
.footer-copyright { padding-block: 1.4rem; border-top: 1px solid #565650; color: var(--hf-stone); font-size: .8rem; margin: 0; }
@media (max-width: 767.98px) { .footer-columns { grid-template-columns: 1fr; gap: 2rem; padding-block: 2.5rem; } }
</style>
