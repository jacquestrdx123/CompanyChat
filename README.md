# CompanyChat

Internal team chat (Slack/Teams alternative). Nuxt 4 SPA in the repo root, Laravel 13 API + Reverb in
[`backend/`](backend/README.md) (it replaced Supabase; see the backend README for the API reference and the
one-off `php artisan chat:import-supabase`).

## Setup
Start the backend first (see [backend/README.md](backend/README.md): `php artisan serve`, `php artisan reverb:start`,
`php artisan queue:work`), then:
```bash
npm install
cp .env.example .env   # API URL + the Reverb key/host/port from backend/.env
npm run dev
```

Requires Node ^22.21 || ^24.11 || >=26 (Nuxt 4) and PHP 8.3+ for the backend.

## Auth
- Invite-only: there is no public sign-up. Create the first person (the `super_admin`) with
  `php artisan chat:create-super-admin`, then invite everyone else from Admin > Users; invited people are `member`s.
- Sign-in returns a bearer token (Laravel Sanctum) that the app keeps in localStorage and sends with every request.
  "Forgot your password?" on the login page emails a reset link.

## Roles and access
- App roles: `super_admin` > `admin` > `member` > `viewer`. Super admin and admin have full access: they create/archive clients and projects and decide who is in each. Members read and write in what they were added to (and DMs) but cannot create or change clients/projects. Viewers only read what they were added to, but can still send DMs. Only the super admin promotes/demotes admins; admins can switch people between member and viewer. Client/project "manager" roles are retired (the columns remain, unused).
- Client and project memberships decide who sees what; only admins change them.
- Projects are visible to all client members unless marked private (then only channel members and admins).
- DMs are created with `POST /api/dms` and are visible only to participants, admins included.
- All of this is enforced by the API (`backend/app/Support/Access.php`); the app's checks are only UI hints.

## Tests
- `npm test`: unit tests for the app's pure helpers (mentions, notifications, files, editing, status).
- `cd backend && php artisan test`: the access rules, messaging, attachments, unread counts, mentions, push targets,
  auth/admin flows and Reverb channel authorisation.

## Messaging
- Sidebar lists the clients you belong to with their projects, plus your DMs. Admins create clients and projects.
- Messages load the latest 100 and update live over Reverb (private channel per conversation, authorised with the same
  access rules as reads). Authors can edit/delete their own; admins can delete any message in a project.

## Resetting for go-live
Wipe test content but keep the schema and users (Postgres):
```sql
truncate messages, channel_members, channels, client_members, clients cascade;
```
`php artisan migrate:fresh` also deletes users; then run `php artisan chat:create-super-admin` again.

## Managing people
- Client page (`/clients/<id>`, click a client name in the sidebar): admins add/remove client members.
- Project members: the "Members" button in a project channel (private projects: only listed people and admins can see it).
- Users page (`/admin/users`, admins): invite, resend invites, send password emails, change roles, deactivate.

## Presence
- Connected users are tracked with the Reverb presence channel `office`; anyone not connected shows grey/Offline, connected users show their manual status (Online/Away/Busy/Do not disturb).
- Status, name and role changes reach other users live (`profile.updated` on the same channel).

## Unread counts
- Read markers live in `channel_reads`, reached through `POST /api/channels/{id}/read` and `GET /api/unread`.
- Opening (or focusing) a channel marks it read; new messages in other channels bump a sidebar badge and the browser tab title.
- A project you have never seen starts as "read now" so old history isn't flagged; a new DM counts from its first message.

## Inviting and deactivating users
- Invite: Admin > Users > "Invite someone". They get an email (Laravel mail, set `MAIL_*` in `backend/.env`) linking to
  `/accept-invite`, choose a password there and are signed in. Links last 7 days; "Resend invite" sends a fresh one.
- Deactivate: flips `users.is_active` and revokes all their tokens, so they are signed out at once; every API call and
  realtime channel refuses a deactivated account. History is kept. Reactivate reverses it. Admins can deactivate
  members; only the super admin can deactivate admins; nobody can deactivate the super admin or themselves.

## Attachments
- Attach with the paperclip, paste an image, or drop files onto the composer (up to 5 files, 10 MB each).
- A message and its files are sent in one request and saved together, so a failed upload never leaves half a message.
  Files live on the backend's private `attachments` disk at `<channel_id>/<message_id>/<n>_<name>` (local storage by
  default, or S3); `message_attachments` records them.
- Viewed through signed URLs (6 hours) from `POST /api/attachments/urls`. PNG/JPEG/GIF/WebP show inline; everything else (including SVG) downloads.
- Deleting one attachment (the x on hover; the author or an admin) removes the file but keeps the message, which then shows an "Attachment deleted" tag. Deleting the whole message removes its files too.
- The backend's PHP must accept the uploads: `upload_max_filesize = 10M` and `post_max_size = 55M` or more.

## Editing messages
Hover your own message and click the pencil, or press Up in an empty composer to edit your latest message. Enter saves, Esc cancels; edited messages show "(edited)". Authors can change only the text (not the author, channel or time), and only while they can still post in the channel.

## Mentions
- Type `@` in the composer for a people picker (Up/Down, Enter or Tab to choose, Esc to close). In the stored message a mention is `@[<user uuid>]`, shown as the person's current name, so renames never break it.
- The API derives `message_mentions` from the body: only active people who can see the channel are recorded, never the author, at most 20 per message. Editing a message updates its mentions.
- Mentioned people get a red `@N` badge in the sidebar, `(@N)` in the tab title, and the message is highlighted with their name emphasised.
- Limits: new mentions can't be added while editing an old message yet (existing ones are kept).

## Desktop notifications
- Browser notifications (plus an optional short sound) while the window is in the background. Direct messages and @mentions always notify; other project messages only for channels you "watch" (bell button in the channel header). Nothing is sent for your own messages, while the window is focused, or while your status is Do not disturb.
- A banner offers to turn them on; afterwards the name menu has Desktop notifications / Notification sound / Message previews toggles. These are per browser (localStorage); watched channels are per user (`channel_watches`).
- Works only while the app is open in a tab or window. Notifications with the app closed need push, which comes with the desktop/mobile wrapper.

## Deploying (Laravel Forge)

**API** (`backend/`): a normal Laravel site in Forge with PostgreSQL (needed for the full-text search index), web
directory `backend/public`. Add two daemons, `php artisan reverb:start` and `php artisan queue:work`, and put Reverb
behind TLS (Forge's Reverb integration or an Nginx `wss://` proxy). Set `FRONTEND_URL` to the app's URL and configure
`MAIL_*`. After the first deploy run `php artisan chat:create-super-admin`, or import the old data with
`php artisan chat:import-supabase` (see the backend README).

**App**: a static site (`ssr: false`).
1. In Forge create a site in **Static site** mode and connect this repo, branch `main`.
2. Build command `npm run generate`, publish directory `.output/public`, Node 20+.
3. Build-time environment variables (baked into the bundle, so public values only): `NUXT_PUBLIC_API_URL`,
   `NUXT_PUBLIC_REVERB_KEY`, `NUXT_PUBLIC_REVERB_HOST`, `NUXT_PUBLIC_REVERB_PORT`, `NUXT_PUBLIC_REVERB_SCHEME`
   (`https`), `NUXT_PUBLIC_VAPID_PUBLIC_KEY`. Never put `REVERB_APP_SECRET` or other server secrets here.
4. Enable quick deploy so merging to `main` updates the site.
5. Unknown paths return the app's fallback page (with a 404 status), so refreshing on `/c/<id>` works.

Desktop notifications need HTTPS, which the live site has.

## Search

`Cmd/Ctrl+K` (or the sidebar button) searches messages. `GET /api/search?q=` is a word-prefix full-text
search (`simple` config, all words must match, newest first, max 100) over a generated `body_tsv` column with a GIN
index on Postgres. It only looks in channels you can open: private channels you are not in and other people's DMs
never appear. Mention tokens are excluded from the index. Clicking a result opens
the channel at `?m=<message id>`, scrolls to that message (loading older history if needed) and highlights it for a few seconds.

## @channel

Typing `@` offers **@channel** first in project channels and group conversations (not 1:1 DMs); hand-typing `@channel` works too.
It is stored as the token `@[channel]`. The API expands it into `message_mentions` rows for
everyone who can see the channel (minus the author and deactivated users), so unread `@` badges, the highlighted message
and desktop notifications behave exactly like personal mentions. Private channels only reach their own members.

## Installable app (PWA)

CompanyChat is installable: `public/manifest.webmanifest`, icons in `public/` and a service worker (`public/sw.js`,
registered by `app/plugins/pwa.client.ts`). The service worker caches nothing on purpose, so the app always loads the
latest deploy. Chrome/Edge/Android show an install icon in the address bar (and "Install app" appears in the user menu);
on iPhone use Share, then Add to Home Screen. Needs HTTPS (or localhost).
The icons are placeholders generated by `python3 scripts/generate-icons.py`; replace the PNGs with real artwork any time.

## Background notifications (Web Push)

Lets people get DM / @mention / watched-channel notifications even when CompanyChat is closed (the browser or
installed app must still be running). Each device opts in from the user menu: "Notify when the app is closed".

How it works: after a message is saved the API queues `SendMessagePush`, which works out who to notify (DMs always,
mentions and @channel always, watched channels; never the author, deactivated users or Do not disturb) and delivers to
their `push_subscriptions` with Web Push (VAPID). The service worker (`public/sw.js`) shows the notification unless a
CompanyChat window is focused, and clicking it opens that conversation. A device with push on no longer shows in-page
notifications (only the sound), so there are no duplicates.

One-time setup:

1. `cd backend && php artisan chat:vapid-keys`, and put the three lines it prints in `backend/.env`.
2. Make sure `php artisan queue:work` runs (a Forge daemon).
3. In Forge add the app build variable `NUXT_PUBLIC_VAPID_PUBLIC_KEY=<the public key>` and redeploy.

Troubleshooting: `backend/storage/logs/laravel.log` and the queue worker output. Browsers must allow notifications for
the site, and on iPhone the app must be added to the Home Screen (iOS 16.4+).

## Desktop app (Electron)

`desktop/` is a thin Electron shell that loads the live site, so every deploy reaches the desktop app automatically (no separate release needed for web changes). It adds a Dock/taskbar badge with your unread count, keeps running when the window is closed (notifications keep arriving), opens external links in your browser, and shows a retry page when offline. Background Web Push is not used in the desktop app (the app itself stays running instead), so "Notify when the app is closed" is hidden there.

How it stays reliable in the background:
- The window keeps running at full speed while hidden (`backgroundThrottling: false`), so the Reverb connection's heartbeat keeps running and the live connection is not dropped while the app sits in the tray.
- **Start at login** is on by default for installed builds and starts the app hidden in the tray. Toggle it in the tray menu (Windows/Linux) or the user menu ("Start at login").
- After sleep or a dropped connection, the app reloads the unread counts and shows one summary notification for the DMs, mentions and watched channels that arrived meanwhile.
- Windows: the app sets its AppUserModelID (`africa.ncloud.companychat`, same as `build.appId`) so toasts show reliably, and puts a red dot on the taskbar button while there is anything unread.
- If notifications still don't appear, check Windows Focus Assist / Do not disturb or macOS Focus, and the OS notification settings for CompanyChat.

```bash
cd desktop
npm install
npm run dev        # points at http://localhost:3000 (run the web app with npm run dev first)
npm start          # points at the live site (override with COMPANYCHAT_URL)
npm run dist:mac   # .dmg in desktop/dist (run on a Mac)
npm run dist:win   # .exe installer (best run on Windows)
```

Builds are unsigned: on a Mac, right-click the app and choose Open the first time; Windows may show a SmartScreen warning ("More info" then "Run anyway"). Signing needs an Apple Developer account (macOS) and a code-signing certificate (Windows). The icon is the same placeholder as the PWA (`desktop/build/icon.png`).
