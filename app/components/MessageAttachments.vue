<script setup lang="ts">
export interface AttachmentView {
  id: string
  path: string
  file_name: string
  mime_type: string | null
  size_bytes: number
  deleted_at: string | null
}

const props = defineProps<{ attachments: AttachmentView[]; canDelete?: boolean }>()
const emit = defineEmits<{ delete: [attachment: AttachmentView] }>()
const { urls, ensure } = useAttachmentUrls()

const live = computed(() => props.attachments.filter((a) => !a.deleted_at))
watch(() => live.value.map((a) => a.id).join('|'), () => { void ensure(live.value).catch(() => {}) }, { immediate: true })
</script>

<template>
  <div class="mt-1 flex flex-wrap gap-2">
    <template v-for="a in attachments" :key="a.id">
      <span v-if="a.deleted_at" class="flex items-center gap-1 rounded border border-dashed border-default px-2 py-1 text-xs italic text-muted">
        <UIcon name="i-lucide-trash-2" class="size-3" /> Attachment deleted
      </span>
      <div v-else class="group/attachment relative">
        <a v-if="isInlineImage(a.mime_type)" :href="urls[a.id]" target="_blank" rel="noopener" class="block">
          <img v-if="urls[a.id]" :src="urls[a.id]" :alt="a.file_name" class="max-h-64 max-w-full rounded border border-default" />
          <span v-else class="text-sm text-muted">{{ a.file_name }}…</span>
        </a>
        <a
          v-else
          :href="urls[a.id]"
          class="flex items-center gap-2 rounded border border-default px-3 py-2 text-sm hover:bg-elevated"
        >
          <UIcon name="i-lucide-file" class="size-4" />
          <span>{{ a.file_name }}</span>
          <span class="text-xs text-muted">{{ formatBytes(a.size_bytes) }}</span>
        </a>
        <UButton
          v-if="canDelete"
          icon="i-lucide-x"
          size="xs"
          color="neutral"
          variant="solid"
          class="absolute -right-2 -top-2 opacity-0 group-hover/attachment:opacity-100 focus:opacity-100"
          aria-label="Delete attachment"
          @click.prevent="emit('delete', a)"
        />
      </div>
    </template>
  </div>
</template>
