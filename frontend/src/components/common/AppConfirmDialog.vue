<script setup lang="ts">
import { nextTick, onBeforeUnmount, ref, useId } from 'vue'
import AppNotice from './AppNotice.vue'

const props = defineProps<{ fallbackFocus: string }>()
const id = useId()
const dialog = ref<HTMLDialogElement | null>(null)
const cancelButton = ref<HTMLButtonElement | null>(null)
const options = ref<{ title: string; description: string; confirmLabel: string } | null>(null)
const pending = ref(false)
const unexpectedError = ref('')
let action: (() => Promise<void>) | null = null
let trigger: HTMLElement | null = null
let disposed = false

// Local to this view: the callback includes the original mutation and its refresh.
const ask = async (details: NonNullable<typeof options.value>, run: () => Promise<void>) => {
  if (options.value || disposed) return
  trigger = document.activeElement instanceof HTMLElement ? document.activeElement : null
  options.value = details
  unexpectedError.value = ''
  action = run
  await nextTick()
  if (disposed || !options.value) return
  dialog.value?.showModal()
  cancelButton.value?.focus()
}
const close = async () => {
  dialog.value?.close()
  options.value = null
  action = null
  await nextTick()
  if (disposed) return
  const target = trigger?.isConnected && !trigger.matches(':disabled') ? trigger : document.querySelector<HTMLElement>(props.fallbackFocus)
  target?.focus()
  trigger = null
}
const cancel = () => { if (!pending.value) void close() }
const confirm = async () => {
  if (pending.value || !action) return
  pending.value = true
  try {
    await action()
    if (!disposed) await close()
  } catch {
    // Callers normally handle API errors; an unexpected error must not imply success.
    action = null
    if (!disposed) unexpectedError.value = '操作未能完成，請關閉視窗並確認目前資料後再操作。'
  } finally {
    pending.value = false
  }
}
const trapFocus = (event: KeyboardEvent) => {
  if (event.key !== 'Tab') return
  const buttons = dialog.value?.querySelectorAll<HTMLButtonElement>('button:not(:disabled)')
  if (!buttons?.length) { event.preventDefault(); return }
  const first = buttons[0], last = buttons[buttons.length - 1]
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus() }
}
onBeforeUnmount(() => { disposed = true; action = null; dialog.value?.close() })
defineExpose({ ask })
</script>

<template>
  <dialog ref="dialog" class="hf-confirm" :aria-labelledby="`${id}-title`" :aria-describedby="`${id}-description`" :aria-busy="pending" @cancel.prevent="cancel" @keydown="trapFocus">
    <template v-if="options">
      <div class="hf-confirm-body">
        <p class="hf-confirm-eyebrow">確認操作</p>
        <h2 :id="`${id}-title`">{{ options.title }}</h2>
        <p :id="`${id}-description`" class="hf-confirm-description">{{ options.description }}</p>
        <AppNotice v-if="unexpectedError">{{ unexpectedError }}</AppNotice>
        <p v-if="pending" class="hf-confirm-progress" role="status">正在處理，請稍候。送出後無法取消。</p>
        <div class="hf-confirm-actions">
          <button ref="cancelButton" type="button" class="btn btn-outline-dark" :disabled="pending" @click="cancel">取消</button>
          <button type="button" class="btn btn-danger" :disabled="pending || !!unexpectedError" @click="confirm">{{ pending ? '處理中...' : options.confirmLabel }}</button>
        </div>
      </div>
    </template>
  </dialog>
</template>

<style scoped>
.hf-confirm { width: min(28rem, calc(100% - 2rem)); max-height: calc(100dvh - 2rem); margin: auto; padding: 0; border: 1px solid var(--hf-stone, #D8D3CA); border-radius: .5rem; color: var(--hf-charcoal, #2E2E2D); background: #fffdf9; box-shadow: 0 1.5rem 4rem #0003; overflow-wrap: anywhere; }
.hf-confirm::backdrop { background: #18181699; }
.hf-confirm-body { padding: clamp(1.25rem, 4vw, 2rem); }
.hf-confirm-eyebrow { color: #795a2f; font-size: .8rem; letter-spacing: .12em; margin: 0 0 .65rem; }
.hf-confirm h2 { font-size: 1.35rem; margin: 0 0 .85rem; line-height: 1.4; }
.hf-confirm-description { line-height: 1.8; margin: 0 0 1.5rem; }
.hf-confirm-progress { font-size: .9rem; line-height: 1.6; }
.hf-confirm-actions { display: flex; flex-wrap: wrap; gap: .75rem; justify-content: flex-end; }
.hf-confirm-actions .btn { min-height: 44px; min-width: 5.5rem; }
.hf-confirm .btn:focus-visible { outline: 3px solid #8a6638; outline-offset: 3px; box-shadow: none; }
</style>
