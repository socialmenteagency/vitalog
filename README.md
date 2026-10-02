# Vitalog

A self-hosted health record for you and your family: lab results over time, vitals from
Apple Health, and an AI-written summary you review before publishing. Plain PHP + SQLite,
no build step, runs on cheap shared hosting. The interface is in Spanish and Brazilian
Portuguese (toggle in the page).

> **Not a medical device. Not medical advice.** Vitalog shows your own numbers against
> general reference ranges and offers educational habit tips. It does not diagnose, treat or
> prescribe anything, and it does not replace your doctor. Reference ranges, tips and texts
> were compiled from public guidelines and written with the help of an AI; they are not
> reviewed by a physician. Check anything that matters with a health professional.
> The software is provided "as is", without warranty (see [LICENSE](LICENSE)).

## What it does

- **Lab history matrix.** Every analyte in rows, every exam date in columns, with reference
  ranges, out-of-range highlighting, and ▲/▼ arrows when a value moved 5 % or more since the
  previous exam (green = toward the range, red = away).
- **Detail view per analyte or vital.** Chart with zones, hover values, ticks, slanted date
  labels when crowded, a plain-language explanation, habit tips, and ← / → to walk through
  rows.
- **Vitals from Apple Health.** Weight, steps, exercise minutes and resting heart rate
  (monthly averages), drawn aligned with the lab columns.
- **Several people.** One account per person with its own password; the admin can view anyone.
  Sex, age, height, location, food preferences, allergies and medications feed sex-aware
  ranges and personalised tips.
- **AI summary (optional).** Gemini writes a short status, recommendations and questions to
  ask the doctor, in Spanish and Portuguese. It is saved as a **draft**, you edit and publish
  it. It never diagnoses and never suggests medication; the person's name is not sent to the
  model.
- **PDF extraction (optional).** Upload a lab PDF; Claude extracts the values for you to review
  before saving.
- **Overdue-exam reminders**, a printable view, and a backend for uploads and imports.

## Quick start

See [DEPLOY.md](DEPLOY.md). In short: copy `salud/`, `backend/`, `lib/`, `data/` to a PHP 8.1+
web root, copy `config.sample.php` to `data/config.php` and set the two passwords, open
`/salud`.

To try it locally without a server config:

```bash
SALUD_DEV=1 php -S localhost:8080 -t .
```

then open `http://localhost:8080/salud/` (password `demo`) and `/backend/` (password `demo-admin`).
Development mode only; never use it on a public host.

## Privacy: read this before using it with real data

- Your data stays in a SQLite file and uploaded PDFs on **your** server (`data/`, blocked from
  the web).
- If you enable the optional AI features, the relevant values (and PDFs for extraction) are sent
  to Google (Gemini) and/or Anthropic (Claude) through their APIs. Use keys from accounts whose
  terms keep your data out of model training, and read their policies.
- The page loads fonts from Google Fonts. Self-host them if that matters to you.
- Admin password and API keys sit in plain text in `data/config.php`; protect your hosting
  account. See [DEPLOY.md](DEPLOY.md#security-what-it-covers-and-what-it-does-not).
- If you host other people's data, you are responsible for their consent and for the privacy
  laws that apply to you (for example LGPD in Brazil, GDPR in Europe, Ley 18.331 in Uruguay).

## Layout

```
salud/      viewer page (index.php + assets: app.js, styles.css, content JSON)
backend/    admin panel: people, uploads, imports, AI draft/publish
lib/        PHP libraries: db (SQLite, migrations), auth, ranges, payload, gemini, claude, apple
data/       created at runtime: database, PDFs, config.php (never in git)
tools/      apple_health_to_json.py (offline Apple Health export converter)
```

## Contributing

Issues and pull requests are welcome, especially: reference ranges with cited sources,
age-adjusted ranges, more languages, other import formats, and tests. Please never include real
health data in issues or pull requests.

## License

[GNU AGPL-3.0](LICENSE). If you run a modified version as a network service, you must offer its
source code to the people who use it (the page footer links to this repository).
