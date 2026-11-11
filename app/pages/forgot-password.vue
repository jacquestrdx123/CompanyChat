<script setup lang="ts">
definePageMeta({ layout: 'blank' })

const api = useApi()
const email = ref('')
const loading = ref(false)
const sent = ref(false)
const failed = ref('')

async function send() {
  loading.value = true
  failed.value = ''
  try {
    await api('/auth/forgot-password', { method: 'POST', body: { email: email.value } })
    sent.value = true
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
        <h1 class="text-xl font-semibold">Reset your password</h1>
      </template>
      <p v-if="sent" class="text-sm">If {{ email }} has an account, an email with a link to choose a new password is on its way.</p>
      <form v-else class="space-y-4" @submit.prevent="send">
        <UFormField label="Email">
          <UInput v-model="email" type="email" autocomplete="email" required class="w-full" />
        </UFormField>
        <UAlert v-if="failed" color="error" variant="subtle" :description="failed" />
        <UButton type="submit" block :loading="loading">Email me a link</UButton>
      </form>
      <p class="mt-4 text-sm"><NuxtLink to="/login" class="text-primary hover:underline">Back to sign in</NuxtLink></p>
    </UCard>
  </div>
</template>
