<script setup lang="ts">
import type { AdminUser, AppRole } from '~/types/api'

type UserRow = AdminUser

const api = useApi()
const store = useProfileStore()
const chat = useChatStore()
const toast = useToast()

const users = ref<UserRow[]>([])
const loading = ref(true)

const isSuperAdmin = computed(() => store.isSuperAdmin)

const ROLE_LABELS: Record<AppRole, string> = { super_admin: 'Super admin', admin: 'Admin', member: 'Member', viewer: 'Viewer' }

// Super admin: admin/member/viewer for anyone. Admins: member/viewer for members and viewers.
const roleItems = computed(() => (isSuperAdmin.value ? ['admin', 'member', 'viewer'] : ['member', 'viewer']).map((r) => ({ label: ROLE_LABELS[r as AppRole], value: r })))

function canChangeRole(u: UserRow) {
  if (!u.is_active || u.id === store.profile?.id || u.app_role === 'super_admin') return false
  return isSuperAdmin.value || u.app_role === 'member' || u.app_role === 'viewer'
}

// People who have not accepted their invite yet.
const pending = computed(() => users.value.filter((u) => u.pending).map((u) => u.id))

async function load() {
  try {
    users.value = await api<UserRow[]>('/admin/users')
  } catch (error) {
    toast.add({ title: 'Could not load users', description: errorMessage(error), color: 'error' })
  } finally {
    loading.value = false
  }
}

/** Run an admin action; returns the API's own error message, or null when it worked. */
async function callAdmin(path: string, options: { method?: 'POST' | 'PUT'; body?: Record<string, unknown> } = {}): Promise<string | null> {
  try {
    await api(`/admin/users${path}`, { method: options.method ?? 'POST', body: options.body })
    return null
  } catch (e) {
    return errorMessage(e)
  }
}

async function changeRole(user: UserRow, role: AppRole) {
  const problem = await callAdmin(`/${user.id}/role`, { method: 'PUT', body: { role } })
  if (problem) toast.add({ title: 'Could not change role', description: problem, color: 'error' })
  await load()
  await chat.load()
}

async function sendPasswordReset(user: UserRow) {
  if (!confirm(`Email ${user.display_name} a link to choose a new password?`)) return
  busyId.value = user.id
  const problem = await callAdmin(`/${user.id}/password-reset`)
  busyId.value = null
  if (problem) toast.add({ title: 'Could not send the password email', description: problem, color: 'error' })
  else toast.add({ title: 'Password email sent', description: `${user.email} will get a link to choose a new password.` })
}

async function resendInvite(user: UserRow) {
  busyId.value = user.id
  const problem = await callAdmin(`/${user.id}/resend-invite`)
  busyId.value = null
  if (problem) toast.add({ title: 'Could not resend the invite', description: problem, color: 'error' })
  else toast.add({ title: 'Invite resent', description: `A new email is on its way to ${user.email}.` })
  await load()
}

const inviteEmail = ref('')
const inviteName = ref('')
const inviting = ref(false)

async function invite() {
  inviting.value = true
  const problem = await callAdmin('', { body: { email: inviteEmail.value, display_name: inviteName.value } })
  inviting.value = false
  if (problem) {
    toast.add({ title: 'Could not send invite', description: problem, color: 'error' })
    return
  }
  toast.add({ title: 'Invite sent', description: `An email with a sign-up link is on its way to ${inviteEmail.value}.` })
  inviteEmail.value = ''
  inviteName.value = ''
  await load()
  await chat.load()
}

const busyId = ref<string | null>(null)

async function setActive(user: UserRow, active: boolean) {
  if (!active && !confirm(`Deactivate ${user.display_name}? They will be signed out and unable to sign in until reactivated. Their messages stay.`)) return
  busyId.value = user.id
  const problem = await callAdmin(`/${user.id}/${active ? 'reactivate' : 'deactivate'}`)
  busyId.value = null
  if (problem) toast.add({ title: active ? 'Could not reactivate' : 'Could not deactivate', description: problem, color: 'error' })
  await load()
  await chat.load()
}

// Same rules as the API; the API is what actually enforces them.
function canToggle(u: UserRow) {
  if (u.id === store.profile?.id || u.app_role === 'super_admin') return false
  return u.app_role === 'member' || u.app_role === 'viewer' || isSuperAdmin.value
}

watch(() => store.profile?.id, (id) => { if (id && store.isAdmin) load() }, { immediate: true })
</script>

<template>
  <div class="mx-auto h-full max-w-3xl space-y-6 overflow-y-auto p-6">
    <p v-if="store.profile && !store.isAdmin" class="text-muted">You don't have access to this page.</p>
    <template v-else-if="store.profile">
      <h1 class="text-2xl font-semibold">Users</h1>

      <section>
        <h2 class="mb-2 text-sm font-semibold uppercase text-muted">Invite someone</h2>
        <form class="flex flex-wrap items-end gap-2" @submit.prevent="invite">
          <UFormField label="Email" class="min-w-56 flex-1">
            <UInput v-model="inviteEmail" type="email" required class="w-full" />
          </UFormField>
          <UFormField label="Name (optional)" class="min-w-40 flex-1">
            <UInput v-model="inviteName" class="w-full" />
          </UFormField>
          <UButton type="submit" :loading="inviting" :disabled="!inviteEmail">Send invite</UButton>
        </form>
        <p class="mt-2 text-sm text-muted">They get an email, choose a password, and appear below. Then add them to clients and projects from the client page.</p>
      </section>

      <section>
        <h2 class="mb-2 text-sm font-semibold uppercase text-muted">Everyone</h2>
        <p class="mb-2 text-sm text-muted">Admins have full access. Members read and write in what they are added to and cannot create or change clients and projects. Viewers only read, plus direct messages. Only the super admin can promote or demote admins.</p>
        <p v-if="loading" class="text-muted">Loading…</p>
        <ul v-else class="divide-y divide-default">
          <li v-for="u in users" :key="u.id" class="flex flex-wrap items-center justify-between gap-3 py-2" :class="{ 'opacity-60': !u.is_active }">
            <div>
              <div>
                {{ u.display_name }}<span v-if="u.id === store.profile.id" class="text-muted"> (you)</span>
                <UBadge v-if="!u.is_active" color="error" variant="subtle" class="ml-2">Deactivated</UBadge>
              </div>
              <div class="text-xs text-muted">{{ u.email }}</div>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <UBadge v-if="u.app_role === 'super_admin'" color="primary" variant="subtle">Super admin</UBadge>
              <USelect
                v-else-if="canChangeRole(u)"
                :model-value="u.app_role"
                :items="roleItems"
                size="sm"
                class="w-32"
                @update:model-value="(r: string) => changeRole(u, r as AppRole)"
              />
              <UBadge v-else color="neutral" variant="subtle">{{ ROLE_LABELS[u.app_role] }}</UBadge>
              <UBadge v-if="pending.includes(u.id)" color="warning" variant="subtle">Invite pending</UBadge>
              <UButton
                v-if="pending.includes(u.id) && u.is_active"
                size="xs"
                color="neutral"
                variant="outline"
                icon="i-lucide-mail"
                :loading="busyId === u.id"
                @click="resendInvite(u)"
              >
                Resend invite
              </UButton>
              <UButton
                v-if="!pending.includes(u.id) && u.is_active && u.id !== store.profile.id"
                size="xs"
                color="neutral"
                variant="outline"
                icon="i-lucide-key-round"
                :loading="busyId === u.id"
                title="Email this person a link to choose a new password"
                @click="sendPasswordReset(u)"
              >
                Change password
              </UButton>
              <UButton
                v-if="canToggle(u)"
                size="xs"
                color="neutral"
                variant="outline"
                :loading="busyId === u.id"
                @click="setActive(u, !u.is_active)"
              >
                {{ u.is_active ? 'Deactivate' : 'Reactivate' }}
              </UButton>
            </div>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>
