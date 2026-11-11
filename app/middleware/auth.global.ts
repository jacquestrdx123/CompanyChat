// Everything except the sign-in and link pages needs a session.
export default defineNuxtRouteMiddleware((to) => {
  const { signedIn } = useAuth()
  const isPublic = isPublicPath(to.path)
  if (!signedIn.value && !isPublic) return navigateTo('/login')
  if (signedIn.value && (to.path.replace(/\/+$/, '') === '/login')) return navigateTo('/')
})
