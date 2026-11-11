<script setup lang="ts">
import { tokensToDisplay } from '~/utils/mentions'

const open = defineModel<boolean>('open', { required: true })
const api = useApi()
const chat = useChatStore()

type Hit = { id: string; channel_id: string; user_id: string | null; body: string; created_at: string }

const query = ref('')
const hits = ref<Hit[]>([])
const searching = ref(false)
const failed = ref('')
let timer: ReturnType<typeof setTimeout> | undefined
let seq = 0

async function run() {
  const q = query.value.trim()
  const mine = ++seq
  if (!q) {
    hits.value = []
    searching.value = false
    return
  }
  searching.value = true
  failed.value = ''
  let data: Hit[] | null = null
  let error: unknown = null
  try {
    data = await api<Hit[]>('/search', { query: { q, limit: 30 } })
  } catch (e) {
    error = e
  }
  if (mine !== seq) return // a newer search superseded this one
  searching.value = false
  if (error) {
    failed.value = errorMessage(error)
    hits.value = []
  } else {
    hits.value = data ?? []
  }
}

watch(query, () => {
  clearTimeout(timer)
  timer = setTimeout(run, 250)
})

watch(open, (isOpen) => {
  if (!isOpen) {
    query.value = ''
    hits.value = []
    failed.value = ''
  }
})

function where(channelId: string) {
  const c = chat.channels.find((x) => x.id === channelId)
  if (!c) return 'Unknown channel'
  if (c.kind === 'dm') return `DM with ${chat.channelTitle(c)}`
  const client = chat.clients.find((x) => x.id === c.client_id)
  return client ? `${client.name} / ${c.name}` : chat.channelTitle(c)
}

const text = (h: Hit) => tokensToDisplay(h.body, chat.userName).text
const when = (iso: string) => new Date(iso).toLocaleString([], { dateStyle: 'short', timeStyle: 'short' })

async function go(h: Hit) {
  open.value = false
  await navigateTo({ path: `/c/${h.channel_id}`, query: { m: h.id } })
}
</script>

<template>
  <UModal v-model:open="open" title="Search messages" description="Searches every channel and conversation you can open.">
    <template #body>
      <UInput v-model="query" icon="i-lucide-search" placeholder="Search for words, e.g. invoice, deploy…" autofocus class="w-full" />
      <p v-if="failed" class="mt-3 text-sm text-error">Search failed: {{ failed }}</p>
      <p v-else-if="searching" class="mt-3 text-sm text-muted">Searching…</p>
      <p v-else-if="query.trim() && !hits.length" class="mt-3 text-sm text-muted">No messages found.</p>
      <ul v-if="hits.length" class="mt-3 max-h-96 divide-y divide-default overflow-y-auto">
        <li v-for="h in hits" :key="h.id">
          <button type="button" class="w-full rounded px-2 py-2 text-left hover:bg-elevated" @click="go(h)">
            <div class="flex items-center gap-2 text-xs text-muted">
              <span class="font-medium">{{ where(h.channel_id) }}</span>
              <span>{{ chat.userName(h.user_id) }}</span>
              <span class="ml-auto">{{ when(h.created_at) }}</span>
            </div>
            <p class="line-clamp-2 break-words text-sm">{{ text(h) }}</p>
          </button>
        </li>
      </ul>
    </template>
  </UModal>
</template>
