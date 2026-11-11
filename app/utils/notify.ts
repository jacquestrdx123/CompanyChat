import type { UserStatus } from './status'
import { tokensToDisplay } from './mentions'

export interface NotifyInput {
  fromSelf: boolean
  windowFocused: boolean
  status: UserStatus | undefined
  kind: 'dm' | 'project'
  mentionsMe: boolean
  watching: boolean
}

/**
 * DMs and @mentions always notify; other project messages only if you watch the channel.
 * Nothing while you are in the window, from yourself, or on Do not disturb.
 */
export function notifyDecision(i: NotifyInput): { notify: boolean; reason: string } {
  if (i.fromSelf) return { notify: false, reason: 'your own message' }
  if (i.windowFocused) return { notify: false, reason: 'this window has focus' }
  if (i.status === 'dnd') return { notify: false, reason: 'status is Do not disturb' }
  if (i.mentionsMe) return { notify: true, reason: 'you were mentioned' }
  if (i.kind === 'dm') return { notify: true, reason: 'direct message' }
  return i.watching
    ? { notify: true, reason: 'you watch this channel' }
    : { notify: false, reason: 'project message in a channel you do not watch (only mentions notify)' }
}

export const shouldNotify = (i: NotifyInput): boolean => notifyDecision(i).notify

export function truncate(text: string, max = 140): string {
  const flat = text.replace(/\s+/g, ' ').trim()
  return flat.length > max ? `${flat.slice(0, max - 1)}…` : flat
}

/** Notification text; mentions are shown by name and an attachment-only message says so. */
export function notificationText(opts: {
  kind: 'dm' | 'project'
  senderName: string
  channelName: string | null
  body: string
  nameOf: (id: string) => string
}): { title: string; body: string } {
  const readable = tokensToDisplay(opts.body, opts.nameOf).text
  const text = truncate(readable) || 'Sent an attachment'
  if (opts.kind === 'dm') return { title: opts.senderName, body: text }
  return { title: `# ${opts.channelName ?? 'channel'}`, body: `${opts.senderName}: ${text}` }
}

export interface CatchUpChannel {
  id: string
  kind: 'dm' | 'project'
}

/**
 * After being offline (sleep, lost connection) live messages were missed. Compare unread counts from before and after
 * reloading them and decide what to tell the person: new DMs, new mentions and new messages in watched channels.
 * Same Do-not-disturb / focus rules as live notifications. Returns null when there is nothing worth a notification.
 */
export function catchUpSummary(opts: {
  before: { unread: Record<string, number>; mentions: Record<string, number> }
  after: { unread: Record<string, number>; mentions: Record<string, number> }
  channels: CatchUpChannel[]
  watched: string[]
  windowFocused: boolean
  status: UserStatus | undefined
}): { count: number; channelIds: string[]; mentions: number } | null {
  if (opts.windowFocused || opts.status === 'dnd') return null
  const grew = (a: Record<string, number>, b: Record<string, number>, id: string) => Math.max(0, (b[id] ?? 0) - (a[id] ?? 0))
  let count = 0
  let mentions = 0
  const channelIds: string[] = []
  for (const c of opts.channels) {
    const newMentions = grew(opts.before.mentions, opts.after.mentions, c.id)
    const newMessages = grew(opts.before.unread, opts.after.unread, c.id)
    const counts = newMentions > 0 || c.kind === 'dm' || opts.watched.includes(c.id)
    if (!counts || (newMessages === 0 && newMentions === 0)) continue
    count += Math.max(newMessages, newMentions)
    mentions += newMentions
    channelIds.push(c.id)
  }
  return count ? { count, channelIds, mentions } : null
}

/** "3 new messages in 2 conversations while you were away" */
export function catchUpText(s: { count: number; channelIds: string[]; mentions: number }): { title: string; body: string } {
  const messages = `${s.count} new ${s.count === 1 ? 'message' : 'messages'}`
  const where = s.channelIds.length > 1 ? ` in ${s.channelIds.length} conversations` : ''
  const mentioned = s.mentions ? ` (${s.mentions} ${s.mentions === 1 ? 'mention' : 'mentions'})` : ''
  return { title: 'CompanyChat', body: `${messages}${where}${mentioned} while you were away` }
}
