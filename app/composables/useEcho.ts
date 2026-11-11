import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

// One Reverb connection for the whole app (Laravel Echo over the Pusher protocol). Created on first use
// after sign-in, torn down on sign-out.
let echo: Echo<'reverb'> | null = null

export function useEcho(): Echo<'reverb'> {
  if (echo) return echo
  const config = useRuntimeConfig().public
  const secure = config.reverbScheme === 'https'
  const port = Number(config.reverbPort) || (secure ? 443 : 80)

  ;(window as unknown as { Pusher: typeof Pusher }).Pusher = Pusher
  echo = new Echo({
    broadcaster: 'reverb',
    key: String(config.reverbKey),
    wsHost: String(config.reverbHost),
    wsPort: port,
    wssPort: port,
    forceTLS: secure,
    enabledTransports: ['ws', 'wss'],
    // Private and presence channels are authorised by the API with our bearer token.
    authorizer: (channel: { name: string }) => ({
      authorize: (socketId: string, callback: (error: Error | null, data: { auth: string; channel_data?: string } | null) => void) => {
        useApi()<{ auth: string; channel_data?: string }>('/broadcasting/auth', { method: 'POST', body: { socket_id: socketId, channel_name: channel.name } })
          .then((data) => callback(null, data))
          .catch((e) => callback(e instanceof Error ? e : new Error(String(e)), null))
      },
    }),
  })
  return echo
}

/** The live connection, if one is open (does not create one). */
export function currentEcho(): Echo<'reverb'> | null {
  return echo
}

/** The socket id to send as X-Socket-ID, if connected. */
export function echoSocketId(): string | undefined {
  return echo?.socketId() || undefined
}

/** Fires on every (re)connection; returns an unbind function. */
export function onEchoConnected(fn: () => void): () => void {
  const connection = (useEcho().connector as unknown as { pusher: Pusher }).pusher.connection
  connection.bind('connected', fn)
  return () => connection.unbind('connected', fn)
}

export function disconnectEcho() {
  echo?.disconnect()
  echo = null
}
