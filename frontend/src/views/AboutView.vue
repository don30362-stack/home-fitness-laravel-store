<script setup lang="ts">
import { RouterLink } from 'vue-router'
import aboutImage from '@/assets/images/about/about-home-training.webp'
</script>

<template>
  <article class="about-page" aria-labelledby="about-title">
    <header class="about-hero">
      <img :src="aboutImage" alt="自然光住宅空間中的健身椅、啞鈴與居家重訓器材"
        width="1536" height="1024" loading="eager" decoding="async" class="about-hero-image">
      <div class="about-hero-copy container">
        <p class="about-eyebrow">ABOUT HOME FIT</p>
        <h1 id="about-title">把訓練，帶回自己的生活</h1>
      </div>
    </header>

    <section class="about-philosophy" aria-labelledby="philosophy-title">
      <div class="container about-editorial">
        <h2 id="philosophy-title">為日常而設計的訓練選擇</h2>
        <p>居家訓練不需要一次擁有所有設備。從真正會使用的器材開始，依照空間、訓練方式與目前需求逐步建立，讓運動更容易成為生活的一部分。</p>
      </div>
    </section>

    <section class="about-context container" aria-labelledby="context-title">
      <p class="about-eyebrow">HOME TRAINING</p>
      <h2 id="context-title">依照自己的空間與訓練方式</h2>
      <p class="about-intro">Home Fit 聚焦居家重訓器材與訓練配件，希望讓訓練不再受限於時間與場地。從一組啞鈴、一張健身椅，到日常使用的訓練配件，依照自己的空間與訓練方式，找到更貼近生活的訓練選擇。</p>
    </section>

    <section class="about-values container" aria-label="三項品牌價值">
      <ol>
        <li><span>01</span><h2>居家訓練導向</h2></li>
        <li><span>02</span><h2>依訓練需求選購</h2></li>
        <li><span>03</span><h2>清楚查看器材規格</h2></li>
      </ol>
    </section>

    <section class="about-positioning" aria-labelledby="positioning-title">
      <div class="container about-editorial">
        <h2 id="positioning-title">讓器材資訊與選擇更清楚</h2>
        <p>Home Fit 將商品分類、圖片、規格與購買選項整理在一致的瀏覽流程中，讓使用者從認識器材到選擇商品，都能快速找到需要的資訊。</p>
      </div>
    </section>

    <section class="about-cta container" aria-label="探索訓練器材">
      <RouterLink class="hf-button" :to="{ name: 'products' }">探索訓練器材<span aria-hidden="true">↗</span></RouterLink>
    </section>
  </article>
</template>

<style scoped>
.about-page { color: var(--hf-charcoal); }
.about-hero { position: relative; background: var(--hf-ivory); }
.about-hero-image { display: block; width: 100%; aspect-ratio: 2 / 1; height: auto; object-fit: cover; object-position: center 65%; }
.about-hero-copy { position: absolute; inset: 0; display: flex; flex-direction: column; justify-content: center; align-items: flex-start; }
.about-hero-copy h1 { max-width: 42%; font-size: clamp(2rem, 4vw, 3.8rem); line-height: 1.35; letter-spacing: -.03em; font-weight: 600; margin: 0; }
.about-eyebrow { color: var(--hf-charcoal); font-size: .75rem; letter-spacing: .16em; font-weight: 600; margin-bottom: 1.2rem; }
.about-philosophy, .about-context, .about-positioning { padding-block: var(--hf-section-space); }
.about-philosophy { background: var(--hf-ivory); }
.about-editorial { display: grid; grid-template-columns: 1fr 1fr; gap: clamp(2rem, 6vw, 5rem); }
h2 { font-weight: 600; font-size: clamp(1.5rem, 2.4vw, 2.2rem); line-height: 1.5; margin: 0; }
p { line-height: 2; margin-bottom: 0; }
.about-editorial > p { max-width: 34rem; font-size: 1.05rem; }
.about-context { max-width: 52rem; text-align: center; }
.about-intro { margin-top: 1.8rem; }
.about-values { padding-bottom: var(--hf-section-space); }
.about-values ol { list-style: none; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 2.5rem; padding: 0; margin: 0; }
.about-values li { border-top: 1px solid var(--hf-stone); padding-top: 1.5rem; }
.about-values span { display: block; color: #856239; font-size: .85rem; margin-bottom: 2rem; }
.about-values h2 { font-size: clamp(1.25rem, 1.8vw, 1.65rem); }
.about-positioning { background: var(--hf-charcoal); color: var(--hf-ivory); }
.about-cta { padding-block: 3rem; text-align: center; }
.about-cta span { margin-left: 1.5rem; }
@media (max-width: 767.98px) {
  .about-hero-image { aspect-ratio: 3 / 2; object-position: center; }
  .about-hero-copy { position: static; padding-block: 2.2rem; }
  .about-hero-copy h1 { max-width: 100%; font-size: clamp(2rem, 7vw, 2.8rem); }
  .about-editorial { grid-template-columns: 1fr; gap: 1.5rem; }
  .about-context { text-align: left; }
  .about-values ol { grid-template-columns: 1fr; gap: 2rem; }
  .about-values span { margin-bottom: 1rem; }
}
</style>
