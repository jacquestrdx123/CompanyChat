<script setup lang="ts">
definePageMeta({ layout: 'blank' })

const auth = useAuth()

const email = ref('')
const password = ref('')
const loading = ref(false)
const failed = ref('')

async function signIn() {
  loading.value = true
  failed.value = ''
  try {
    await auth.signIn(email.value, password.value)
    await navigateTo('/')
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
        <h1 class="text-xl font-semibold">Sign in to CompanyChat</h1>
      </template>
      <form class="space-y-4" @submit.prevent="signIn">
        <UFormField label="Email">
          <UInput v-model="email" type="email" autocomplete="email" required class="w-full" />
        </UFormField>
        <UFormField label="Password">
          <UInput v-model="password" type="password" autocomplete="current-password" required class="w-full" />
        </UFormField>
        <UAlert v-if="failed" color="error" variant="subtle" :description="failed" />
        <UButton type="submit" block :loading="loading">Sign in</UButton>
      </form>
      <p class="mt-4 text-sm text-muted">
        Accounts are invite-only. Ask an admin for access.
        <NuxtLink to="/forgot-password" class="text-primary hover:underline">Forgot your password?</NuxtLink>
      </p>
    </UCard>
  </div>
</template>
