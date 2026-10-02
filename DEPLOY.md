# Deploying Vitalog

Vitalog is plain PHP + SQLite: no Composer, no MySQL, no build step. Copy the
folders to a web root and create one config file. It runs on any shared hosting
(cPanel included) or a small VPS with PHP.

Resulting URLs: `https://your-domain/salud` (viewer) and `https://your-domain/backend` (admin).

## Requirements

- PHP **8.1+** (works from 8.0) with `pdo_sqlite`, `sqlite3`, `curl`, `mbstring`, `fileinfo`.
- Apache with `.htaccess` support (the `data/` and `lib/` folders are blocked by
  `.htaccess`). On nginx, add equivalent `deny all` rules for `/data/` and `/lib/`.
- HTTPS (session cookies are marked `Secure` when HTTPS is on).
- To upload lab PDFs comfortably: `upload_max_filesize = 32M`, `post_max_size = 40M`,
  `max_execution_time = 300` (AI extraction can take 1-2 minutes on long PDFs).

## Install

1. Copy `salud/`, `backend/`, `lib/` and `data/` (it only contains the `.htaccess` and
   `.gitignore` protections) into your web root (`public_html/` on cPanel).
2. Copy `config.sample.php` to **`data/config.php`** and edit it:
   - `SALUD_PASSWORD`: viewer password of the first person (the one you share with a doctor).
   - `BACKEND_PASSWORD`: admin password. **Change both; the sample values are placeholders.**
   - `SALUD_PATIENT_NAME`: name of the first person (optional).
   - The AI keys do **not** need to go in this file: paste them in the backend instead
     (card **Claves de IA**), and they are saved in the database. If you prefer the file, the
     constants `ANTHROPIC_API_KEY` (lab PDF extraction with Claude) and `GEMINI_API_KEY` /
     `GEMINI_MODEL` (summary, recommendations and "Ask the AI") still work as a fallback; a key
     pasted in the backend takes priority. Without any key you can still enter or import results by hand.
   - Use a Gemini key from an account **with billing enabled**: the free tier lets Google use
     what you send. Google Search (used by "Ask the AI" to read product labels) may need billing
     too; if Google reports no quota, the app answers without searching.
3. Open `https://your-domain/backend`, log in with `BACKEND_PASSWORD`, and paste your keys in **Claves de IA** (optional).
4. Open `https://your-domain/salud`. With `SEED_DUMMY` set to `true`, the first visit creates
   the database and fills it with invented sample data so you can see the design.
5. When you are ready for real data: log into `/backend` → *Delete sample data*.

If you use the cPanel *Git Version Control* feature, the included `.cpanel.yml` copies the
code to `public_html/` and never touches `data/` (database, PDFs and config survive every
deploy). It must sit at the root of the cloned repository.

## Apple Health (every 1-3 months)

1. iPhone → Health → profile picture → **Export All Health Data** → `export.zip`.
2. `/backend` → **Apple Health** → upload `export.zip` as is → Import. The server streams it and
   computes monthly averages of weight, exercise, resting heart rate and steps. Re-importing
   is safe (upsert by month).
3. If the zip is bigger than your upload limit: on a computer run
   `python3 tools/apple_health_to_json.py export.zip vitalog-apple.json` and upload that JSON
   to the same form.

## Security: what it covers and what it does not

- `data/` and `lib/` are blocked from the web; PDFs are only served through an admin session.
- **XSS:** the boot data of `/salud` is a `<script type="application/json">` block encoded with `JSON_HEX_*`, so stored text can never close it, and a **Content-Security-Policy** (`default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'`) forbids inline scripts and third-party origins. That is why there are no inline handlers (the backend uses `backend/backend.js` with `data-confirm` / `data-busy`) and fonts are self-hosted in `salud/assets/fonts/`. Anything you add (a script, an external image) must be served from your own site.
- **Headers** (`lib/bootstrap.php`): CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: no-referrer`, `Permissions-Policy`, COOP; no `X-Powered-By`.
- **HTTPS:** `http://` is redirected (301) to `https://` and HSTS is sent (1 year, no subdomains). HTTPS is detected behind Cloudflare too. Turn it off with `define('SALUD_FORCE_HTTPS', false)` in `data/config.php` (it never acts on localhost). Browsers remember HSTS: if your domain loses its certificate, the page will stop opening.
- **Login rate limit** (`lib/auth.php`, file lock; the attempt is recorded BEFORE the password is checked): 8 failures / 15 min per IP, 10 for the backend password across all IPs and 60 overall. A correct login gives its attempt back. **If you lock yourself out**, wait 15 minutes or delete `data/login_attempts.json` in the File Manager. New person passwords need 12+ characters.
- **Sessions:** no session cookie is created for anonymous visits; admin sessions expire after 2 h idle or 12 h total, people after 12 h idle or 30 days. Cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` over HTTPS.
- **Upload limits:** Apple Health zip ≤ 4 GB inflated (read in 32 KB chunks), imported JSON ≤ 8 MB, PDFs must start with `%PDF-`.
- **HTTP basic auth on `/backend` (optional, recommended).** It contains a `/salud` XSS or a leaked person password by putting the admin behind a second secret no viewer has. In `backend/.htaccess`:

```apache
AuthType Basic
AuthName "Vitalog backend"
AuthUserFile /home/ACCOUNT/.htpasswds/vitalog
Require valid-user
```

  Keep the password file OUTSIDE `public_html` and create it on your computer with `htpasswd -nbB -C 12 user 'a-long-password'`.
- Per-person viewer passwords are stored hashed. The admin password lives **in plain text** in `data/config.php` unless you set `BACKEND_PASSWORD_HASH` (see `config.sample.php`); API keys are plain text too (in the database when pasted in the backend, or in `data/config.php`). Whoever can read your server files can read them: the hosting account is the real perimeter. Do not reuse passwords from other services.
- This is a personal/family tool, not a multi-tenant service. Do not host other people's health data without doing your own privacy and legal review (see the disclaimer in the README).
