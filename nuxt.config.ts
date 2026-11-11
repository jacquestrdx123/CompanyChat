// https://nuxt.com/docs/api/configuration/nuxt-config
export default defineNuxtConfig({
  compatibilityDate: '2025-07-15',
  devtools: { enabled: true },

  // Internal tool behind a login: no SEO needs, so run as an SPA.
  ssr: false,

  modules: ['@nuxt/ui', '@pinia/nuxt'],

  css: ['~/assets/css/main.css'],

  app: {
    head: {
      link: [
        { rel: 'manifest', href: '/manifest.webmanifest' },
        { rel: 'icon', type: 'image/svg+xml', href: '/favicon.svg' },
        { rel: 'apple-touch-icon', href: '/apple-touch-icon.png' },
      ],
      meta: [
        { name: 'theme-color', content: '#0f172a' },
        { name: 'mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-capable', content: 'yes' },
        { name: 'apple-mobile-web-app-title', content: 'CompanyChat' },
      ],
    },
  },

  runtimeConfig: {
    public: {
      // The Laravel API (backend/). Set NUXT_PUBLIC_API_URL at build time.
      apiUrl: 'http://localhost:8000',
      // Reverb websockets. Set NUXT_PUBLIC_REVERB_KEY / _HOST / _PORT / _SCHEME to match the backend's REVERB_* values.
      reverbKey: '',
      reverbHost: 'localhost',
      reverbPort: 8080,
      reverbScheme: 'http',
      // Public Web Push (VAPID) key; set NUXT_PUBLIC_VAPID_PUBLIC_KEY at build time. Empty hides background notifications.
      vapidPublicKey: '',
    },
  },
})
