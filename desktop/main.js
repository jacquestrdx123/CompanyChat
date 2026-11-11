// CompanyChat desktop shell. It loads the live web app, so every deploy reaches the desktop app too.
// It adds what a browser tab cannot: a Dock/taskbar badge, staying running when the window is closed,
// native notifications that work while the app is in the background, starting at login, and links opening in your browser.
const { app, BrowserWindow, Menu, Tray, nativeImage, shell, session, ipcMain, powerMonitor } = require('electron')
const path = require('node:path')
const fs = require('node:fs')

const APP_URL = process.env.COMPANYCHAT_URL || 'https://companychat-mog1jhcf.on-forge.com'
const APP_ORIGIN = new URL(APP_URL).origin
const SMOKE = process.env.COMPANYCHAT_SMOKE === '1'
const isMac = process.platform === 'darwin'
const isWindows = process.platform === 'win32'
const APP_ID = 'africa.ncloud.companychat' // must match build.appId: Windows ties toast notifications to it
const HIDDEN_FLAG = '--hidden' // passed when Windows/Linux start us at login: go straight to the tray

// Windows shows a toast only for a known AppUserModelID (the installer's Start menu shortcut carries this one).
if (isWindows) app.setAppUserModelId(APP_ID)

let win = null
let tray = null
let quitting = false

if (!app.requestSingleInstanceLock()) {
  app.quit()
} else {
  app.on('second-instance', () => showWindow())
}

function showWindow() {
  if (!win) return createWindow()
  if (win.isMinimized()) win.restore()
  win.show()
  win.focus()
}

// --- launch at login ----------------------------------------------------------------------
// On by default (the point of a chat app is not missing messages); the choice is remembered in userData.
const settingsFile = () => path.join(app.getPath('userData'), 'desktop-settings.json')

function readSettings() {
  try { return JSON.parse(fs.readFileSync(settingsFile(), 'utf8')) } catch { return {} }
}

function writeSettings(patch) {
  try { fs.writeFileSync(settingsFile(), JSON.stringify({ ...readSettings(), ...patch })) } catch (e) { console.error('settings not saved', e) }
}

function launchAtLogin() {
  return app.getLoginItemSettings(isMac ? {} : { args: [HIDDEN_FLAG] }).openAtLogin
}

function setLaunchAtLogin(on) {
  // Unpackaged (npm start) would register the bare Electron binary: never do that.
  if (!app.isPackaged) return false
  app.setLoginItemSettings({ openAtLogin: on, ...(isMac ? {} : { args: [HIDDEN_FLAG] }) })
  writeSettings({ launchAtLogin: on })
  refreshTrayMenu()
  return launchAtLogin()
}

function startedAtLogin() {
  if (process.argv.includes(HIDDEN_FLAG)) return true
  return isMac && !!app.getLoginItemSettings().wasOpenedAtLogin
}

// --- unread badge -------------------------------------------------------------------------
/** A small red dot for the Windows taskbar button (Windows has no numeric app badge). */
function dotImage() {
  const size = 16
  const buf = Buffer.alloc(size * size * 4)
  for (let y = 0; y < size; y++) {
    for (let x = 0; x < size; x++) {
      const dx = x - 7.5
      const dy = y - 7.5
      const d = Math.sqrt(dx * dx + dy * dy)
      const alpha = Math.max(0, Math.min(1, 7.5 - d)) // anti-aliased edge
      const i = (y * size + x) * 4
      buf[i] = 0x3b // B (bitmaps are BGRA)
      buf[i + 1] = 0x30 // G
      buf[i + 2] = 0xef // R
      buf[i + 3] = Math.round(alpha * 255) // A
    }
  }
  return nativeImage.createFromBitmap(buf, { width: size, height: size })
}
let dot = null

/** "(3) CompanyChat" or "(@2) CompanyChat" is the web app's title; the number becomes the badge. */
function applyBadge(title) {
  const m = /^\((@)?(\d+)\)/.exec(title)
  const count = m ? Number(m[2]) : 0
  const label = m ? `${count} ${m[1] ? (count === 1 ? 'mention' : 'mentions') : 'unread'}` : ''
  app.setBadgeCount(count) // Dock badge on macOS; launcher badge on some Linux desktops
  if (tray && !isMac) tray.setToolTip(count ? `CompanyChat (${label})` : 'CompanyChat')
  if (isWindows && win) {
    dot ||= dotImage()
    win.setOverlayIcon(count ? dot : null, count ? label : '') // red dot on the taskbar button
  }
  if (count && win && !win.isFocused() && !isMac) win.flashFrame(true) // Windows taskbar flash
}

function isAppUrl(url) {
  try { return new URL(url).origin === APP_ORIGIN } catch { return false }
}

function createWindow({ hidden = false } = {}) {
  win = new BrowserWindow({
    width: 1280,
    height: 820,
    minWidth: 480,
    minHeight: 480,
    show: !SMOKE && !hidden,
    backgroundColor: '#000000',
    title: 'CompanyChat',
    titleBarStyle: isMac ? 'hiddenInset' : 'default',
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
      sandbox: true,
      // A hidden window must keep running at full speed: Chromium otherwise slows its timers to about once
      // a minute, the realtime heartbeat misses its slot, the server drops the socket and messages arrive
      // without a notification.
      backgroundThrottling: false,
      // The notification beep comes from the page; let it play even before anyone has clicked in the window.
      autoplayPolicy: 'no-user-gesture-required',
    },
  })

  win.loadURL(APP_URL)

  win.webContents.on('page-title-updated', (_e, title) => applyBadge(title))
  win.on('focus', () => win.flashFrame(false))

  // Only the app itself loads in this window; every other link opens in the default browser.
  win.webContents.setWindowOpenHandler(({ url }) => {
    if (/^https?:/.test(url)) shell.openExternal(url)
    return { action: 'deny' }
  })
  win.webContents.on('will-navigate', (e, url) => {
    if (isAppUrl(url) || url.startsWith('file:')) return
    e.preventDefault()
    if (/^https?:/.test(url)) shell.openExternal(url)
  })

  // Offline or server down: show a friendly page that retries on its own.
  win.webContents.on('did-fail-load', (_e, code, _desc, failedUrl, isMainFrame) => {
    if (!isMainFrame || code === -3 /* aborted */) return
    win.loadFile(path.join(__dirname, 'offline.html'), { query: { retry: isAppUrl(failedUrl) ? failedUrl : APP_URL } })
  })

  if (SMOKE) {
    win.webContents.on('did-finish-load', async () => {
      if (win.webContents.getURL().startsWith('file:')) return
      await new Promise((r) => setTimeout(r, 2500))
      const info = await win.webContents.executeJavaScript(
        `(async () => ({ title: document.title, url: location.href, hasBridge: typeof window.companychatDesktop, bridgeKeys: Object.keys(window.companychatDesktop || {}), launchAtLogin: await window.companychatDesktop?.getLaunchAtLogin?.(), notification: typeof Notification !== 'undefined' ? Notification.permission : 'none', electronUA: /Electron/.test(navigator.userAgent) }))()`,
      )
      console.log('SMOKE_RESULT ' + JSON.stringify(info))
      quitting = true
      app.quit()
    })
    win.webContents.on('did-fail-load', (_e, code, desc) => { console.log('SMOKE_FAIL ' + code + ' ' + desc) })
  }

  // Closing the window keeps the app running (so notifications keep arriving); Quit really quits.
  win.on('close', (e) => {
    if (quitting) return
    e.preventDefault()
    win.hide()
  })
  win.on('closed', () => { win = null })
}

function createTray() {
  if (isMac) return // the Dock icon does the job on macOS
  const icon = nativeImage.createFromPath(path.join(__dirname, 'build', 'icon.png')).resize({ width: 20, height: 20 })
  tray = new Tray(icon)
  tray.setToolTip('CompanyChat')
  refreshTrayMenu()
  tray.on('click', showWindow)
}

function refreshTrayMenu() {
  if (!tray) return
  tray.setContextMenu(Menu.buildFromTemplate([
    { label: 'Open CompanyChat', click: showWindow },
    { type: 'separator' },
    ...(app.isPackaged ? [{ label: 'Start at login', type: 'checkbox', checked: launchAtLogin(), click: (item) => setLaunchAtLogin(item.checked) }] : []),
    { label: 'Quit', click: () => { quitting = true; app.quit() } },
  ]))
}

function createMenu() {
  const template = [
    ...(isMac ? [{ role: 'appMenu' }] : []),
    { role: 'editMenu' }, // copy / paste / undo
    { label: 'View', submenu: [{ role: 'reload' }, { role: 'forceReload' }, { type: 'separator' }, { role: 'resetZoom' }, { role: 'zoomIn' }, { role: 'zoomOut' }, { type: 'separator' }, { role: 'togglefullscreen' }] },
    { role: 'windowMenu' },
  ]
  Menu.setApplicationMenu(Menu.buildFromTemplate(template))
}

app.whenReady().then(() => {
  // Notifications and clipboard only; deny everything else the page might ask for.
  const allowed = new Set(['notifications', 'clipboard-sanitized-write', 'fullscreen'])
  session.defaultSession.setPermissionRequestHandler((_wc, permission, cb) => cb(allowed.has(permission)))
  session.defaultSession.setPermissionCheckHandler((_wc, permission) => allowed.has(permission))

  // The page asks about / changes "Start at login" from its user menu.
  ipcMain.handle('desktop:get-launch-at-login', () => ({ supported: app.isPackaged, on: app.isPackaged && launchAtLogin() }))
  ipcMain.handle('desktop:set-launch-at-login', (_e, on) => setLaunchAtLogin(!!on))
  // Clicking a notification while the window is hidden in the tray: bring it back.
  ipcMain.on('desktop:show-window', () => showWindow())

  // First run of an installed build: start at login unless the person turned it off before.
  if (app.isPackaged && readSettings().launchAtLogin === undefined) setLaunchAtLogin(true)

  createMenu()
  createTray()
  createWindow({ hidden: startedAtLogin() })
  if (isMac && app.dock) app.dock.setIcon(path.join(__dirname, 'build', 'icon.png'))

  // After sleep the socket is usually dead; tell the page so it reconnects and reports what it missed.
  const wake = () => win?.webContents.send('desktop:resumed')
  powerMonitor.on('resume', wake)
  powerMonitor.on('unlock-screen', wake)

  app.on('activate', showWindow) // clicking the Dock icon
})

app.on('before-quit', () => { quitting = true })
app.on('window-all-closed', () => { if (!isMac && quitting) app.quit() })
