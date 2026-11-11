// Shapes returned by the Laravel API (backend/app/Http/Resources).

export type AppRole = 'super_admin' | 'admin' | 'member' | 'viewer'
export type UserStatusValue = 'online' | 'away' | 'busy' | 'dnd'
export type MembershipRole = 'manager' | 'member'
export type ChannelKind = 'project' | 'dm'

export interface ApiUser {
  id: string
  display_name: string
  avatar_url: string | null
  status: UserStatusValue
  app_role: AppRole
  is_active: boolean
  email?: string // only for admins and yourself
}

export interface ApiClient {
  id: string
  name: string
  archived_at: string | null
  created_at: string | null
}

export interface ApiChannel {
  id: string
  kind: ChannelKind
  client_id: string | null
  name: string | null
  is_private: boolean
  archived_at: string | null
  created_at: string | null
  member_ids: string[]
}

export interface ApiAttachment {
  id: string
  message_id: string
  channel_id: string
  path: string
  file_name: string
  mime_type: string | null
  size_bytes: number
  deleted_at: string | null
}

export interface ApiMessage {
  id: string
  channel_id: string
  user_id: string | null
  body: string
  created_at: string
  edited_at: string | null
  message_attachments?: ApiAttachment[]
}

export interface ApiMember {
  user_id: string
  role: MembershipRole
}

export interface AdminUser extends ApiUser {
  email: string
  pending: boolean
  last_sign_in_at: string | null
}

export interface AuthResponse {
  token: string
  token_type: 'Bearer'
  user: ApiUser
}
