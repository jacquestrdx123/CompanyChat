// Runs in the page with Node disabled. Exposes nothing sensitive: a flag so the web app knows it is the desktop app,
// the "Start at login" setting, a way to bring the window back, and a wake-from-sleep signal.
const { contextBridge, ipcRenderer } = require('electron')

contextBridge.exposeInMainWorld('companychatDesktop', {
  platform: process.platform,
  getLaunchAtLogin: () => ipcRenderer.invoke('desktop:get-launch-at-login'),
  setLaunchAtLogin: (on) => ipcRenderer.invoke('desktop:set-launch-at-login', !!on),
  showWindow: () => ipcRenderer.send('desktop:show-window'),
  onResume: (cb) => {
    const listener = () => cb()
    ipcRenderer.on('desktop:resumed', listener)
    return () => ipcRenderer.removeListener('desktop:resumed', listener)
  },
})
