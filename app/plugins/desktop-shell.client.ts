// The Electron desktop app on macOS draws its window buttons (red/yellow/green) on top of the page.
// Mark the page so the header can leave room for them and double as the window's drag handle.
export default defineNuxtPlugin(() => {
  const desktop = desktopBridge()
  if (desktop?.platform === 'darwin') document.documentElement.classList.add('desktop-mac')
})
