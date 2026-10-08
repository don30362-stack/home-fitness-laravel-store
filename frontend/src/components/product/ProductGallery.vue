<script setup lang="ts">
import { computed, ref, watch } from 'vue';

import type { ProductImage } from '@/types/product';

const props = defineProps<{
    images: ProductImage[]
    productName: string
}>()

const selectedImageId = ref<number | null>(null)
const failedImageIds = ref<number[]>([])

const selectedImage = computed(() => {
    return (
        props.images.find((image) =>
            image.id === selectedImageId.value
        ) ??
        props.images.find((image) => image.is_primary) ??
        props.images[0] ??
        null
    )
})

const hasImageFailed = (id: number) => {
    return failedImageIds.value.includes(id)
}

const handleImageError = (id: number) => {
    if (!failedImageIds.value.includes(id)) {
        failedImageIds.value.push(id)
    }
}

const selectImage = (id: number) => {
    selectedImageId.value = id
}

const isImageSelected = (id: number) => {
    return selectedImageId.value === id
}

watch(
    () => props.images,
    (images) => {
        const primaryImage =
            images.find((image) => image.is_primary) ?? images[0]

        selectedImageId.value = primaryImage?.id ?? null
        failedImageIds.value = []
    },
    {
        immediate: true
    }
)
</script>

<template>
    <div>
        <div class="product-gallery-stage d-flex align-items-center justify-content-center mb-3">
            <img v-if="selectedImage && !hasImageFailed(selectedImage.id)" :src="selectedImage.image_url"
                :alt="productName" class="product-gallery-image img-fluid"
                @error="handleImageError(selectedImage.id)">

            <span v-else class="text-muted">商品圖片準備中</span>
        </div>

        <div v-if="images.length > 1" class="d-flex flex-wrap gap-2">
            <button v-for="image in images" :key="image.id" type="button" class="rounded bg-white p-1"
                :class="isImageSelected(image.id) ? 'border border-2 border-dark' : 'border'"
                style="width: 80px; height: 80px;" :aria-label="`查看 ${productName} 圖片 ${image.sort_order}`"
                :aria-pressed="isImageSelected(image.id)" @click="selectImage(image.id)">
                <img v-if="!hasImageFailed(image.id)" :src="image.image_url" :alt="productName" class="w-100 h-100"
                    style="object-fit: contain;" @error="handleImageError(image.id)">

                <span v-else class="small text-muted">圖片</span>
            </button>
        </div>
    </div>
</template>
<style scoped>
.product-gallery-stage { aspect-ratio: 1; background: var(--hf-ivory, #f5f2ec); border: 1px solid var(--hf-stone, #d8d3ca); min-width: 0; padding: .5rem; }
.product-gallery-image { width: 100%; height: 100%; max-height: 560px; object-fit: contain; }
</style>
