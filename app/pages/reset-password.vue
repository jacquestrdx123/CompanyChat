<script setup lang="ts">
definePageMeta({ layout: 'blank' })
</script>

<template>
  <PasswordLinkForm endpoint="/auth/reset-password" title="Choose a new password" intro="New password for" />
</template>
