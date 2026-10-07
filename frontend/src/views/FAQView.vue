<script setup lang="ts">
import { faqGroups, demonstrationNotice } from '@/content/faq'
</script>

<template>
  <article class="faq-page container" aria-labelledby="faq-title">
    <header class="faq-heading">
      <p class="faq-eyebrow">SHOPPING GUIDE</p>
      <h1 id="faq-title">常見問題與購物須知</h1>
    </header>

    <section v-for="(group, index) in faqGroups" :key="group.id" class="faq-group" :aria-labelledby="`faq-${group.id}`">
      <div class="faq-group-heading">
        <p class="faq-index">{{ String(index + 1).padStart(2, '0') }}</p>
        <h2 :id="`faq-${group.id}`">{{ group.title }}</h2>
      </div>
      <div class="faq-questions">
        <details v-for="item in group.items" :key="item.question">
          <summary>{{ item.question }}<span class="faq-indicator" aria-hidden="true"></span></summary>
          <p>{{ item.answer }}</p>
        </details>
      </div>
    </section>

    <aside class="faq-notice" aria-labelledby="notice-title">
      <h2 id="notice-title">展示環境說明</h2>
      <p>{{ demonstrationNotice }}</p>
    </aside>
  </article>
</template>

<style scoped>
.faq-page { padding-block: var(--hf-section-space); color: var(--hf-charcoal); }
.faq-heading { padding-bottom: clamp(3rem, 6vw, 5rem); }
.faq-eyebrow { font-size: .75rem; letter-spacing: .16em; margin-bottom: 1rem; font-weight: 600; }
h1 { font-size: clamp(2rem, 3.8vw, 3.4rem); line-height: 1.4; font-weight: 600; margin: 0; }
.faq-group { display: grid; grid-template-columns: 1fr 2fr; gap: 3rem; padding-block: 2.5rem; border-top: 1px solid var(--hf-stone); }
.faq-group-heading { min-width: 0; }
.faq-index { color: #856239; font-size: .8rem; margin-bottom: 1rem; }
h2 { font-size: 1.4rem; line-height: 1.5; font-weight: 600; margin: 0; }
.faq-questions { min-width: 0; }
details { border-bottom: 1px solid var(--hf-stone); }
details:last-child { border-bottom: 0; }
summary { list-style: none; position: relative; cursor: pointer; padding: 1.25rem 3rem 1.25rem .5rem; min-height: 48px; font-size: 1.05rem; line-height: 1.7; font-weight: 600; }
summary::-webkit-details-marker { display: none; }
summary::marker { content: ''; }
summary:focus-visible { outline: 3px solid var(--hf-gold); outline-offset: 2px; }
.faq-indicator { position: absolute; top: 1.7rem; right: .75rem; width: 1rem; height: 1rem; }
.faq-indicator::before, .faq-indicator::after { content: ''; position: absolute; background: currentColor; }
.faq-indicator::before { top: .45rem; width: 1rem; height: 1px; }
.faq-indicator::after { left: .45rem; width: 1px; height: 1rem; }
details[open] .faq-indicator::after { display: none; }
details[open] summary { background: var(--hf-ivory); }
details > p { padding: .5rem 2rem 1.5rem .5rem; margin: 0; line-height: 2; }
.faq-notice { margin-top: 2rem; padding: 2rem; background: var(--hf-ivory); border-left: 2px solid var(--hf-stone); }
.faq-notice h2 { font-size: .9rem; margin-bottom: .75rem; }
.faq-notice p { font-size: .875rem; line-height: 1.9; max-width: 52rem; margin: 0; }
@media (max-width: 767.98px) {
  .faq-group { grid-template-columns: 1fr; gap: 1rem; padding-block: 2rem; }
  .faq-group-heading { display: flex; align-items: baseline; gap: 1rem; }
  .faq-index { margin: 0; }
  .faq-notice { padding: 1.5rem; }
  details > p { padding-right: .5rem; }
}
</style>
