<script setup lang="ts">
import { errorMessage as errorText } from '~/utils/errors'

definePageMeta({ layout: 'blank' })

const api = useApi()
const password = ref('')
const confirm = ref('')
const loading = ref(false)
const errorMessage = ref('')

async function save() {
  errorMessage.value = ''
  if (password.value.length < 8) return void (errorMessage.value = 'Use at least 8 characters.')
  if (password.value !== confirm.value) return void (errorMessage.value = 'The passwords do not match.')
  loading.value = true
  try {
    await api('/me/password', { method: 'PUT', body: { password: password.value } })
    await navigateTo('/')
  } catch (e) {
    errorMessage.value = errorText(e)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="flex min-h-screen items-center justify-center p-4">
    <UCard class="w-full max-w-sm">
      <template #header>
        <h1 class="text-xl font-semibold">Choose your password</h1>
      </template>
      <form class="space-y-4" @submit.prevent="save">
        <UFormField label="New password">
          <UInput v-model="password" type="password" autocomplete="new-password" required class="w-full" />
        </UFormField>
        <UFormField label="Repeat password">
          <UInput v-model="confirm" type="password" autocomplete="new-password" required class="w-full" />
        </UFormField>
        <UAlert v-if="errorMessage" color="error" variant="subtle" :description="errorMessage" />
        <UButton type="submit" block :loading="loading">Save and continue</UButton>
      </form>
    </UCard>
  </div>
</template>
