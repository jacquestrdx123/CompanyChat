import { describe, expect, it } from 'vitest'
import { catchUpSummary, catchUpText, notificationText, shouldNotify, truncate, type NotifyInput } from '../app/utils/notify'

const base: NotifyInput = { fromSelf: false, windowFocused: false, status: 'online', kind: 'project', mentionsMe: false, watching: false }

describe('shouldNotify', () => {
  it('stays quiet for ordinary project messages unless you watch the channel', () => {
    expect(shouldNotify(base)).toBe(false)
    expect(shouldNotify({ ...base, watching: true })).toBe(true)
  })

  it('always notifies for DMs and mentions', () => {
    expect(shouldNotify({ ...base, kind: 'dm' })).toBe(true)
    expect(shouldNotify({ ...base, mentionsMe: true })).toBe(true)
  })

  it('never notifies for your own messages or while the window is focused', () => {
    expect(shouldNotify({ ...base, kind: 'dm', fromSelf: true })).toBe(false)
    expect(shouldNotify({ ...base, kind: 'dm', windowFocused: true })).toBe(false)
  })

  it('respects Do not disturb, but not Busy or Away', () => {
    expect(shouldNotify({ ...base, kind: 'dm', status: 'dnd' })).toBe(false)
    expect(shouldNotify({ ...base, kind: 'dm', status: 'busy' })).toBe(true)
    expect(shouldNotify({ ...base, kind: 'dm', status: 'away' })).toBe(true)
  })
})

describe('notification text', () => {
  const nameOf = (id: string) => (id === '11111111-1111-4111-8111-111111111111' ? 'Bob' : 'Unknown')

  it('titles a DM with the sender and a project with the channel', () => {
    expect(notificationText({ kind: 'dm', senderName: 'Jane', channelName: null, body: 'hi', nameOf })).toEqual({ title: 'Jane', body: 'hi' })
    expect(notificationText({ kind: 'project', senderName: 'Jane', channelName: 'DTE', body: 'hi', nameOf })).toEqual({ title: '# DTE', body: 'Jane: hi' })
  })

  it('shows mentions by name and flattens whitespace', () => {
    const body = '@[11111111-1111-4111-8111-111111111111]\n  look   here'
    expect(notificationText({ kind: 'dm', senderName: 'J', channelName: null, body, nameOf }).body).toBe('@Bob look here')
  })

  it('describes attachment-only messages and truncates long ones', () => {
    expect(notificationText({ kind: 'dm', senderName: 'J', channelName: null, body: '', nameOf }).body).toBe('Sent an attachment')
    expect(truncate('x'.repeat(200))).toHaveLength(140)
    expect(truncate('x'.repeat(200)).endsWith('…')).toBe(true)
  })
})

describe('catch-up after being offline', () => {
  const channels = [
    { id: 'dm1', kind: 'dm' as const },
    { id: 'p1', kind: 'project' as const },
    { id: 'p2', kind: 'project' as const },
  ]
  const empty = { unread: {}, mentions: {} }
  const base = { before: empty, channels, watched: [] as string[], windowFocused: false, status: 'online' as const }

  it('counts new DMs, new mentions and watched channels, not other project chatter', () => {
    const s = catchUpSummary({
      ...base,
      watched: ['p2'],
      after: { unread: { dm1: 2, p1: 5, p2: 1 }, mentions: {} },
    })
    expect(s).toEqual({ count: 3, channelIds: ['dm1', 'p2'], mentions: 0 })

    const m = catchUpSummary({ ...base, after: { unread: { p1: 5 }, mentions: { p1: 1 } } })
    expect(m).toEqual({ count: 5, channelIds: ['p1'], mentions: 1 })
  })

  it('only reports what arrived since the snapshot', () => {
    expect(catchUpSummary({ ...base, before: { unread: { dm1: 2 }, mentions: {} }, after: { unread: { dm1: 2 }, mentions: {} } })).toBeNull()
    expect(catchUpSummary({ ...base, before: { unread: { dm1: 2 }, mentions: {} }, after: { unread: { dm1: 3 }, mentions: {} } })?.count).toBe(1)
  })

  it('stays quiet when focused or on Do not disturb', () => {
    const after = { unread: { dm1: 1 }, mentions: {} }
    expect(catchUpSummary({ ...base, after, windowFocused: true })).toBeNull()
    expect(catchUpSummary({ ...base, after, status: 'dnd' })).toBeNull()
  })

  it('writes one short summary', () => {
    expect(catchUpText({ count: 1, channelIds: ['a'], mentions: 0 }).body).toBe('1 new message while you were away')
    expect(catchUpText({ count: 4, channelIds: ['a', 'b'], mentions: 1 }).body).toBe('4 new messages in 2 conversations (1 mention) while you were away')
  })
})
