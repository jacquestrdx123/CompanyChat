# CompanyChat API (Laravel + Reverb)

An API-only Laravel 13 backend that replaces Supabase for CompanyChat:

| Supabase piece | Here |
| --- | --- |
| Auth (email + password, invites, resets) | Sanctum bearer tokens, `/api/auth/*`, invite + reset emails |
| Postgres tables + RLS policies | Migrations + `App\Support\Access` (one definition of who can see a channel) |
| RPCs (`get_or_create_dm`, `get_unread_counts`, `search_messages`, …) | REST endpoints |
| Realtime (`postgres_changes`, presence) | Laravel Reverb (Pusher protocol), see [Realtime](#realtime) |
| Storage bucket `attachments` + signed URLs | `attachments` disk + short-lived signed URLs |
| Edge Function `admin-users` | `/api/admin/users/*` |
| Edge Function `send-push` + DB webhook | `SendMessagePush` queued job (Web Push / VAPID) |

The rules are ported 1:1 from `../supabase/migrations` (roles, viewers read-only, archived clients/projects
invisible, deactivated users cut off, mentions derived from the body, unread markers, watches, push targets).

## Run it locally

```bash
cd backend
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
php artisan chat:create-super-admin        # the first person; everyone else is invited
```

Then run these (each in its own terminal, or use Herd for the HTTP server):

```bash
php artisan serve                          # API on http://localhost:8000
```

```bash
php artisan reverb:start                   # websockets on ws://localhost:8080
```

```bash
php artisan queue:work                     # push notifications (and queued mail)
```

Tests: `php artisan test` (33 feature tests, ported from the Supabase Vitest suite).

### Production notes

- Use **PostgreSQL** (`DB_CONNECTION=pgsql`): the migration then adds the `body_tsv` full-text index used by search.
  SQLite/MySQL work too, with a `LIKE` fallback for search.
- Configure real mail (`MAIL_*`): invites and password resets are emailed. Set `FRONTEND_URL` so links open the app.
- Run `reverb:start` and `queue:work` under a process manager (Forge daemons / Supervisor), and put Reverb behind
  TLS (`wss://`). Generate your own `REVERB_APP_ID/KEY/SECRET`.
- Push: `php artisan chat:vapid-keys`, put the keys in `.env`. Without them, push is skipped silently.
- Attachments default to `storage/app/private/attachments`. Point `ATTACHMENTS_DISK` at an S3 disk to use object storage.

## Moving data off Supabase

```bash
SUPABASE_DB_URL="postgresql://postgres.<ref>:<password>@<pooler host>:5432/postgres" php artisan chat:import-supabase
```

Copies every row with its original UUID (so `/c/<id>` links keep working). Passwords carry over: Supabase's bcrypt
hashes are converted to the `$2y$` prefix Laravel expects. To bring files too, download the bucket first and pass
the folder with `--files=./supabase-files` (`npx supabase storage cp -r ss:///attachments ./supabase-files --experimental`).

## Authentication

Every request other than `/api/auth/*`, `/api/files/*` and `/api/push/public-key` needs
`Authorization: Bearer <token>`. JSON in, JSON out (send `Accept: application/json`). Errors look like
`{ "message": "...", "errors": { field: [...] } }`.

| Method | Path | Body | Notes |
| --- | --- | --- | --- |
| POST | `/api/auth/login` | `email, password, device_name?` | → `{ token, token_type, user }` |
| POST | `/api/auth/logout` | | revokes the current token |
| POST | `/api/auth/accept-invite` | `email, token, password` | from the invite email link `FRONTEND_URL/accept-invite?token=&email=`; → `{ token, user }` |
| POST | `/api/auth/forgot-password` | `email` | always `{ ok: true }` |
| POST | `/api/auth/reset-password` | `email, token, password` | from `FRONTEND_URL/reset-password?token=&email=`; → `{ token, user }` |
| GET | `/api/me` | | your profile (with email) |
| PATCH | `/api/me` | `display_name?, avatar_url?, status?` | `status`: `online \| away \| busy \| dnd` |
| PUT | `/api/me/password` | `password` | signs out your other devices |

## Endpoints

**Directory, clients, projects**

| Method | Path | Who | Notes |
| --- | --- | --- | --- |
| GET | `/api/users` | everyone | `[{ id, display_name, avatar_url, status, app_role, is_active, email? }]` (email for admins) |
| GET | `/api/clients` | everyone | admins: all (incl. archived); others: their active clients |
| POST | `/api/clients` | admin | `{ name }` |
| PATCH | `/api/clients/{id}` | admin | `{ name }` |
| PUT | `/api/clients/{id}/archived` | admin | `{ archived: bool }` |
| GET/POST | `/api/clients/{id}/members` | see / admin | POST `{ user_id, role? }` → `[{ user_id, role }]` |
| PATCH/DELETE | `/api/clients/{id}/members/{userId}` | admin | PATCH `{ role }` |
| GET | `/api/channels` | everyone | channels you can open, each with `member_ids`; admins also get archived projects |
| POST | `/api/channels` | admin | `{ client_id, name, is_private? }` (names unique per client) |
| PATCH | `/api/channels/{id}` | admin | `{ name?, is_private? }` |
| PUT | `/api/channels/{id}/archived` | admin | `{ archived: bool }` |
| GET/POST | `/api/channels/{id}/members` | see / admin | as for clients |
| PATCH/DELETE | `/api/channels/{id}/members/{userId}` | admin | |
| POST | `/api/dms` | everyone | `{ user_id }` → the DM channel (found or created) |

**Messages and files**

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/channels/{id}/messages` | oldest first, with `message_attachments`. `?before=<msgId>` older page, `?from=<msgId>` jump (that message + up to 500 after), `?limit=` (default 100) |
| POST | `/api/channels/{id}/messages` | `multipart/form-data`: `body`, `files[]` (≤ 5 files, ≤ 10 MB each). Message and files are saved together. Send `X-Socket-ID` so you don't get your own broadcast back |
| PATCH | `/api/messages/{id}` | `{ body }`, author only |
| DELETE | `/api/messages/{id}` | author, or admin for project messages. Files are deleted too |
| POST | `/api/attachments/urls` | `{ ids: [...], download?: bool }` → `{ urls: { id: url }, expires_in }` (6 h signed URLs, usable in `<img src>`) |
| DELETE | `/api/attachments/{id}` | removes the file, keeps the message, sets `deleted_at` |
| GET | `/api/search?q=&limit=` | word-prefix search in messages you can read, newest first |

Mentions work as before: put `@[<user id>]` or `@[channel]` in the body; the server derives who was mentioned.

**Unread, watches, push**

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/unread` | `{ unread: { channelId: n }, mentions: { channelId: n } }` |
| POST | `/api/channels/{id}/read` | mark read up to now |
| GET | `/api/watches` | `[channelId, ...]` |
| PUT/DELETE | `/api/channels/{id}/watch` | watch / unwatch |
| GET | `/api/push/public-key` | VAPID public key for `pushManager.subscribe` |
| POST | `/api/push-subscriptions` | the browser's `subscription.toJSON()` plus `show_preview?, user_agent?` |
| PATCH | `/api/push-subscriptions` | `{ endpoint, show_preview }` |
| DELETE | `/api/push-subscriptions` | `{ endpoint }` |

**Admin** (`/api/admin/users`, admins only; the finer rules match the old Edge Function)

| Method | Path | Notes |
| --- | --- | --- |
| GET | `/api/admin/users` | everyone with `email`, `pending` (invite not accepted), `last_sign_in_at` |
| POST | `/api/admin/users` | invite `{ email, display_name? }` |
| POST | `/api/admin/users/{id}/resend-invite` | |
| POST | `/api/admin/users/{id}/password-reset` | emails a reset link |
| POST | `/api/admin/users/{id}/deactivate` · `/reactivate` | deactivation revokes all their tokens at once |
| PUT | `/api/admin/users/{id}/role` | `{ role: admin \| member \| viewer }` |

## Realtime

Reverb speaks the Pusher protocol, so the Nuxt app can use `laravel-echo` + `pusher-js`:

```ts
const echo = new Echo({
  broadcaster: 'reverb',
  key: REVERB_APP_KEY, wsHost: REVERB_HOST, wsPort: REVERB_PORT, wssPort: REVERB_PORT,
  forceTLS: REVERB_SCHEME === 'https', enabledTransports: ['ws', 'wss'],
  authEndpoint: `${API_URL}/api/broadcasting/auth`,
  auth: { headers: { Authorization: `Bearer ${token}` } },
})
// send echo.socketId() as the X-Socket-ID header on API calls
```

| Channel | Events (note the leading dot in Echo: `.listen('.message.created', …)`) | Replaces |
| --- | --- | --- |
| `presence-office` (`echo.join('office')`) | presence `here/joining/leaving` · `profile.updated { user }` · `directory.changed` (refetch clients/channels) | `office-presence`, `profiles-live` |
| `private-channel.{id}` | `message.created { message }` · `message.updated { message }` (edits and deleted attachments) · `message.deleted { id, channel_id }` | `messages:{id}`, `messages-all` |
| `private-user.{yourId}` | `mention.created { message_id, channel_id }` | `mentions-me` |

For the sidebar's unread badges (the old `messages-all` subscription), subscribe to `private-channel.{id}` for every
channel from `GET /api/channels`; one socket carries them all. Messages arrive with their attachments, so there is
no separate attachment event any more.

If Reverb is down, writes still succeed (the broadcast failure is logged); clients should refetch on reconnect, as
the app already does on wake/reconnect.
