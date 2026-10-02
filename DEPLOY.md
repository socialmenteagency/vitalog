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
   - `ANTHROPIC_API_KEY`: optional, for extracting lab PDFs with Claude. Without it you
     can still enter or import results by hand.
   - `GEMINI_API_KEY` / `GEMINI_MODEL`: optional, for the AI summary and recommendations.
     Use a key from an account **with billing enabled**: the free tier lets Google use
     what you send.
3. Open `https://your-domain/salud`. With `SEED_DUMMY` set to `true`, the first visit creates
   the database and fills it with invented sample data so you can see the design.
4. When you are ready for real data: log into `/backend` → *Delete sample data*.

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
- Login rate limit: 8 failed attempts / 15 min per IP, plus a 1 s penalty.
- Session cookies are `HttpOnly`, `SameSite=Lax`, and `Secure` over HTTPS.
- Per-person viewer passwords are stored hashed in the database. The admin password and the
  API keys live **in plain text** in `data/config.php` (so they can be edited from a file
  manager). Whoever can read your server files can read them: the hosting account is the real
  perimeter. Do not reuse passwords from other services.
- This is a personal/family tool, not a multi-tenant service. Do not host other people's health
  data without doing your own privacy and legal review (see the disclaimer in the README).
