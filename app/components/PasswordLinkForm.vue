<script setup lang="ts">
import type { AuthResponse } from '~/types/api'

// Landing page for the links in invite and password-reset emails (?token=...&email=...):
// choose a password, and you are signed in.
const props = defineProps<{ endpoint: '/auth/accept-invite' | '/auth/reset-password'; title: string; intro: string }>()

const route = useRoute()
const api = useApi()
const auth = useAuth()

const token = computed(() => (typeof route.query.token === 'string' ? route.query.token : ''))
const email = computed(() => (typeof route.query.email === 'string' ? route.query.email : ''))
const password = ref('')
const confirm = ref('')
const loading = ref(false)
const failed = ref('')

async function save() {
  failed.value = ''
  if (password.value.length < 8) return void (failed.value = 'Use at least 8 characters.')
  if (password.value !== confirm.value) return void (failed.value = 'The passwords do not match.')
  loading.value = true
  try {
    const res = await api<AuthResponse>(props.endpoint, {
      method: 'POST',
      body: { email: email.value, token: token.value, password: password.value, device_name: deviceName() },
    })
    auth.signInWith(res)
    await navigateTo('/', { replace: true })
  } catch (e) {
    failed.value = errorMessage(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center p-4">
    <UCard class="w-full max-w-sm">
      <template #header>
        <h1 class="text-xl font-semibold">{{ title }}</h1>
      </template>
      <div v-if="!token || !email" class="space-y-3 text-sm">
        <p>This link is incomplete. Open it straight from the email, or ask an admin to send you a new one.</p>
        <UButton to="/login" color="neutral" variant="outline">Go to sign in</UButton>
      </div>
      <form v-else class="space-y-4" @submit.prevent="save">
        <p class="text-sm text-muted">{{ intro }} <span class="font-medium text-default">{{ email }}</span></p>
        <UFormField label="New password">
          <UInput v-model="password" type="password" autocomplete="new-password" required class="w-full" />
        </UFormField>
        <UFormField label="Repeat password">
          <UInput v-model="confirm" type="password" autocomplete="new-password" required class="w-full" />
        </UFormField>
        <UAlert v-if="failed" color="error" variant="subtle" :description="failed" />
        <UButton type="submit" block :loading="loading">Save and continue</UButton>
      </form>
    </UCard>
  </div>
</template>
