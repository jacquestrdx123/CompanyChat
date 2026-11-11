import { defineStore } from 'pinia'
import type { ApiUser, AppRole } from '~/types/api'
import type { UserStatus } from '~/utils/status'

export interface Profile {
  id: string
  email: string
  display_name: string
  avatar_url: string | null
  app_role: AppRole
  status: UserStatus
}

export const useProfileStore = defineStore('profile', () => {
  const api = useApi()
  const auth = useAuth()
  const profile = ref<Profile | null>(null)

  const isSuperAdmin = computed(() => profile.value?.app_role === 'super_admin')
  const isAdmin = computed(() => profile.value?.app_role === 'super_admin' || profile.value?.app_role === 'admin')
  /** Viewers can read what they were added to and send direct messages, nothing else. */
  const isViewer = computed(() => profile.value?.app_role === 'viewer')

  async function load() {
    if (!auth.signedIn.value) {
      profile.value = null
      return
    }
    const me = await api<ApiUser>('/me')
    auth.user.value = me
    profile.value = { ...me, email: me.email ?? '' }
  }

  async function setStatus(status: UserStatus) {
    if (!profile.value) return
    const previous = profile.value.status
    profile.value.status = status
    try {
      await api('/me', { method: 'PATCH', body: { status } })
    } catch (e) {
      profile.value.status = previous
      throw e
    }
  }

  return { profile, isAdmin, isSuperAdmin, isViewer, load, setStatus }
})
