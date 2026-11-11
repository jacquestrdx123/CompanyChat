// CompanyChat service worker: makes the app installable and shows Web Push notifications.
// It deliberately caches nothing: a chat app must always load the latest deploy.
self.addEventListener('install', () => self.skipWaiting())
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()))

self.addEventListener('push', (event) => {
  let data = {}
  try {
    data = event.data ? event.data.json() : {}
  } catch {
    data = { body: event.data ? event.data.text() : '' } // e.g. the plain-text "Push" test button in DevTools
  }
  event.waitUntil(
    (async () => {
      // If you are looking at the app right now, it already updates live: no popup needed.
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      if (windows.some((w) => w.focused)) return
      // A visible (but unfocused) CompanyChat window gets the same message live and plays its own sound, honouring
      // the "Notification sound" switch: keep this one silent so there are not two sounds. Hidden tabs (and phones,
      // where background pages are frozen) stay quiet themselves, so the system sound plays here instead.
      const pageWillChime = windows.some((w) => w.visibilityState === 'visible')
      await self.registration.showNotification(data.title || 'CompanyChat', {
        body: data.body || '',
        tag: data.tag, // a newer message in the same channel replaces the older notification
        renotify: !!data.tag, // ...but still alerts
        silent: pageWillChime,
        icon: '/icon-192.png',
        badge: '/icon-192.png',
        data: { url: data.url || '/' },
      })
    })(),
  )
})

self.addEventListener('notificationclick', (event) => {
  event.notification.close()
  const url = (event.notification.data && event.notification.data.url) || '/'
  event.waitUntil(
    (async () => {
      const windows = await self.clients.matchAll({ type: 'window', includeUncontrolled: true })
      const open = windows[0]
      if (open) {
        await open.focus()
        open.postMessage({ type: 'navigate', url }) // the app routes without a reload
      } else {
        await self.clients.openWindow(url)
      }
    })(),
  )
})
