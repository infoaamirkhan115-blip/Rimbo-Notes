# Rimbo-Notes — PHP + MySQL

Remember every idea that comes to mind. The minimal Idea Space frontend now connects to a same-origin PHP API and stores each user's workspace in MySQL.

## Included

- Registration, sign-in, sign-out, hashed passwords and PHP session cookies
- Separate private workspaces for each account
- Database persistence for ideas, notes, attachments, audio, links, history, stages, reminders and settings
- Saved-state indicator, retry/export recovery after failures, and revision checks against conflicting device edits
- Existing capture, search, graph, archive/trash, restore and backup UI
- CSRF checks, prepared SQL statements, bounded payload validation and sign-in throttling

## Requirements

- PHP 8.1 or later, with PDO MySQL, sessions and JSON enabled
- MySQL 8.0+ or MariaDB 10.6+
- Apache, Nginx with PHP-FPM, or PHP's built-in server for local development
- HTTPS for production and microphone recording; localhost HTTP is supported for development
- No Composer, npm install, frontend compilation or API key is required

## Local setup

### 1. Clone

```sh
git clone https://github.com/infoaamirkhan115-blip/Rimbo-Notes.git
cd Rimbo-Notes
```

### 2. Create a database and account

Use phpMyAdmin or your database administrator account to create a database named `rimbo_notes`, using `utf8mb4`, and a dedicated database user. Import `database/schema.sql` into this empty database. Example:

```sh
mysql -u root -p -e "CREATE DATABASE rimbo_notes CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p rimbo_notes < database/schema.sql
```

Give the runtime database account `SELECT`, `INSERT`, `UPDATE` and `DELETE` on this database. Use your hosting panel or MySQL administrator to set a strong password. The app does not need DROP, CREATE, GRANT or database administrator privileges at runtime.

### 3. Configure PHP

```sh
cp backend/config.example.php backend/config.php
```

On Windows, copy the file in Explorer or run:

```powershell
Copy-Item backend/config.example.php backend/config.php
```

Edit `backend/config.php` with your database host, name, username and password. Set `secure_cookies` to `false` **only for local HTTP development**. Set it to `true` on HTTPS hosting. `backend/config.php` is git-ignored and must stay outside the public web root.

Alternatively configure these server environment variables (which override the config file):

| Variable | Meaning |
|---|---|
| `RIMBO_DB_HOST` | Database host; defaults to `127.0.0.1` |
| `RIMBO_DB_PORT` | Database port; defaults to `3306` |
| `RIMBO_DB_NAME` | Database name |
| `RIMBO_DB_USER` | Runtime database username |
| `RIMBO_DB_PASSWORD` | Runtime database password |
| `RIMBO_SECURE_COOKIES` | `true` on production HTTPS; `false` for local HTTP |
| `RIMBO_REGISTRATION_OPEN` | `true` to allow signup; `false` to close new registrations |

### 4. Start the app

```sh
php -S localhost:8000 -t dist
```

Open **http://localhost:8000**, create your account, and save your first idea. On Windows/XAMPP, use `C:\xampp\php\php.exe -S localhost:8000 -t dist` from the project directory if PHP is not on PATH. Do not use the old Python static server: it cannot execute PHP and could expose PHP source.

New accounts start empty. No sample or previous browser data is automatically uploaded.

## cPanel / Hostinger / shared hosting

1. Select PHP 8.1+ and enable `pdo_mysql`.
2. Create the MySQL database and user in the hosting panel; grant the runtime privileges above.
3. Import `database/schema.sql` using phpMyAdmin.
4. Upload `backend/` outside `public_html` and put your actual settings in `backend/config.php`.
5. Upload the **contents** of `dist/` to `public_html` (including `api/` and `.htaccess`).
6. Keep the following relative layout, or adjust the two `require` paths at the top of `api/index.php` to the actual private backend path:

```text
account-directory/
  backend/
    bootstrap.php
    validation.php
    config.php
  public_html/
    index.html
    app.js
    server.js
    style.css
    .htaccess
    api/
      index.php
```

7. Enable HTTPS and set `secure_cookies => true`.
8. Confirm the app can register, sign in, save an idea, reload it, and sign out.

For Nginx/PHP-FPM, set the document root to `dist/` and configure PHP execution only for existing PHP files. The API uses a query parameter so URL rewrites are not needed. Set request body limits to at least 12 MB and PHP memory limit to at least 128 MB. MySQL `max_allowed_packet` should be at least 16 MB.

**Never serve the repository root publicly.** The database schema, tests and backend config should not be web-accessible. Keep PHP error display off and inspect server error logs when troubleshooting. The example Apache `.htaccess` needs `AllowOverride` support; if your host rejects `Options`, remove that line and disable directory listings through the panel.

This backend requires PHP hosting. GitHub Pages and the existing ChatGPT Sites static deployment cannot run it. The previously published static demo is unchanged; this repository is the PHP version to deploy on your own hosting.

## Moving existing browser ideas

In the old app, open Settings and export a JSON backup. In the PHP app, sign in, open Settings → Restore a backup, select that file, and confirm. Import **replaces** that account's workspace; export its current contents first if needed. Both the client and server validate IDs, attachment types and fields. Review the save status until it says **Saved to your account**.

Data in the old app's localStorage is not automatically migrated. Each account's API data is scoped by its server-side session, never by a client-supplied user ID.

## Persistence and simultaneous edits

`users` stores accounts and password hashes; `workspaces` stores preferences and revision; `ideas` stores one JSON document per idea, keyed by account and idea ID; `auth_limits` stores short-lived request counters. Flexible idea fields (including links, history and base64 attachments) live inside the idea document.

The frontend sends a bounded **whole-workspace snapshot** with its revision. The server validates it and saves it atomically in a transaction. A stale revision returns HTTP 409 without changing the stored workspace. On conflict, export pending changes, reload account data, and reconcile intentionally. No automatic merge or realtime collaboration is implemented. Use Settings → Refresh account data to load another device's updates when not editing.

Failed saves remain in the tab with retry/export controls. A per-account `sessionStorage` recovery copy helps recover an interrupted save in that same tab. It is removed after a successful save. Do not close a tab with unsaved changes without exporting them. The authoritative data is in MySQL, not the recovery cache.

This initial backend caps a workspace at 500 ideas, 12 MB JSON per request, 20 attachments per idea, and 2 MB per attachment/audio item. Supported attachments: PNG, JPG, WebP, GIF, PDF, plain text, binary downloads and common audio types. Large attachment collections should move to private object/file storage before scaling up.

## API

All requests go to `dist/api/index.php?route=...`. JSON is returned; API responses are not cached.

| Method | Route | Purpose |
|---|---|---|
| GET | `session` | User/session information and CSRF token |
| POST | `register` | `{name, email, password}`; creates an empty workspace |
| POST | `login` | `{email, password}`; starts a session |
| POST | `logout` | Ends the session |
| GET | `workspace` | Returns `{revision, state: {ideas, settings}}` |
| PUT | `workspace` | Accepts `{revision, state}`; returns new revision |

Send cookies with requests. All non-GET requests need `Content-Type: application/json` and `X-CSRF-Token` from `session` (or the rotated token returned after login/register). Sessions expire after 30 minutes of inactivity or 12 hours total. Auth requests are limited to 15 per email and 60 per client IP per 15-minute window. Only `REMOTE_ADDR` is trusted; configure the web server correctly if using a reverse proxy.

The API deliberately does not accept a user ID or email as a workspace selector. Do not add wildcard credentialed CORS; serve frontend and API on the same origin.

## Remaining features

- Reminder schedules are stored in MySQL, but notification delivery still runs only while the app is open. No email, scheduled worker or background push delivery is included.
- Email verification, self-service forgotten-password recovery and administration UI are not included. Passwords are 12–72 bytes at signup. Back up the database and configure an operational account-recovery process before a public launch.
- Microphone capture needs permission; transcription depends on browser support and may use the browser provider's speech service. Audio recording stops after 60 seconds.
- Google Fonts and sample image URLs need internet access.

## Tests

The backend integration suite passed on MySQL 8 in GitHub Actions. The frontend save-queue tests also cover serialized writes, coalesced edits, conflicts, recovery data and imported IDs.

The GitHub Actions workflow `.github/workflows/backend-tests.yml` provisions a disposable MySQL 8 database, lints PHP/JS and runs the API integration tests.

To run locally, initialize a **disposable test database**, configure the `RIMBO_DB_*` environment variables for it, set `RIMBO_SECURE_COOKIES=false`, then:

```sh
php -S 127.0.0.1:8080 -t dist
# In another terminal:
python3 tests/api_test.py
```

The tests create disposable accounts and check registration, login/logout, persistence, user isolation, attachments, CSRF rejection, invalid IDs/media, stale-write conflicts, trash/restore, linked ideas and auth throttling. Use a fresh database for repeat runs to avoid rate limits from earlier tests. Never point them at production.

Optional syntax checks:

```sh
node --check dist/app.js
node --check dist/server.js
node tests/sync_test.cjs
php -l backend/bootstrap.php
php -l backend/validation.php
php -l dist/api/index.php
```

Security API references: [PHP sessions](https://www.php.net/manual/en/book.session.php), [password hashing](https://www.php.net/manual/en/function.password-hash.php), [PDO prepared statements](https://www.php.net/manual/en/pdo.prepared-statements.php).
