import type { ApiMember, MembershipRole as Role } from '~/types/api'

export type MemberKind = 'client' | 'channel'
export type MembershipRole = Role
export type Member = ApiMember

/** Read and edit the members of a client or a project channel. The API decides who may change what. */
export function useMembers(kind: MemberKind, id: Ref<string>) {
  const api = useApi()
  const members = ref<Member[]>([])
  const loading = ref(false)
  const base = () => `/${kind === 'client' ? 'clients' : 'channels'}/${id.value}/members`

  async function load() {
    loading.value = true
    try {
      members.value = await api<Member[]>(base())
    } finally {
      loading.value = false
    }
  }

  async function add(userId: string) {
    await api(base(), { method: 'POST', body: { user_id: userId } })
    await load()
  }

  async function setRole(userId: string, role: MembershipRole) {
    await api(`${base()}/${userId}`, { method: 'PATCH', body: { role } })
    await load()
  }

  async function remove(userId: string) {
    await api(`${base()}/${userId}`, { method: 'DELETE' })
    await load()
  }

  return { members, loading, load, add, setRole, remove }
}
