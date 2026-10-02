# byereviews.com — notes for Claude Code

## Shipping = pushing to `main`

- The VPS pulls `origin/main` every minute and deploys it automatically. Anything on `main` is live on https://byereviews.com within ~60 seconds.
- Work on a feature branch; to ship, merge or fast-forward it into `main` and push. No build step, no FTP, no GitHub Action.
- After shipping, verify with `curl -s https://byereviews.com/ | grep -o '<title>[^<]*'` (or the page you changed). You have no SSH access to the server.

## Before pushing to `main`

- `php -l public/order.php` if you touched PHP.
- The repo is **public**: never commit passwords, API keys, SMTP credentials or customer data.
- Never commit anything in `public/orders/` (real customer orders live there on the server; they are gitignored).

## Server layout (Hostinger VPS 187.124.166.153, shared with other projects)

| What | Where |
|------|-------|
| Git checkout | `/var/www/byereviews` (web root = `public/`) |
| nginx vhost | `/etc/nginx/sites-available/byereviews` (SSL added by certbot) |
| Stored orders | `/var/www/byereviews/public/orders/*.json` (untracked, blocked from the web) |
| SMTP credentials | `/etc/byereviews/smtp.php` (never in the repo; see `deploy/smtp.example.php`) |
| Deploy script / cron | `/usr/local/bin/byereviews-deploy`, `/etc/cron.d/byereviews-deploy` |
| Deploy log | `/var/log/byereviews-deploy.log` |

Files in `deploy/` are reference copies. Changing them does **not** change the server: nginx config, cron and the deploy script have to be updated on the VPS by hand.

## Site structure

- `design/site.dc.html` + `design/blog.dc.html`: the design handoff (prototype, do not edit for fixes). `design/HANDOFF.md` = full spec.
- `public/index.html`, `public/order/`, `public/login/`, `public/dashboard/` are **generated**: `python3 tools/build_site.py` (applies `tools/site_overrides.js` = real backend, routing, fixes to the design file), then `node tools/prerender.js <local-url>` (static snapshot for crawlers; needs `php -S 127.0.0.1:8123 -t public`). Change behaviour in `tools/site_overrides.js` / template patches in `tools/build_site.py`, never by hand in the output.
- Backend: `app/*.php` (not web-accessible). `public/order.php` is the only PHP entry point nginx runs; it routes `?a=places|reviews|check-email|order|login|logout|me|message|reset|pay|stripe-webhook|admin`.
- Data: JSON files in `public/orders/` (`order/`, `customers/`, `cache/`, `ratelimit/`, `app.log`) – on the server only, gitignored, blocked by nginx.
- Server config with API keys: `/etc/byereviews/config.php` (template: `deploy/config.example.php`). Local testing: `BYEREVIEWS_CONFIG=/path/test-config.php php -S …` with `'mock_google' => true, 'mail_log_only' => true, 'data_dir' => '/tmp/…'`.
- Admin panel: https://byereviews.com/admin/ – **generated** from `design/admin.dc.html` by `python3 tools/build_admin.py` (+ `tools/admin_overrides.js`). JSON API in `app/admin.php` (`?a=admin-*`). Lead Finder (`design/LeadFinder.dc.html` + `tools/leads_overrides.js` → `public/admin/LeadFinder.dc.html`, API `app/leads.php` `?a=admin-leads*`, categories/cities in `app/leads_catalog.php`, data in `<data_dir>/leads/` + `leadsys/`, Google content auto-stripped after 30 days). Orders, review statuses (status emails are batched: sent 60 s after the last change), WhatsApp hand-off to the removal partner, Stripe Payment Links (one per order, created in `stripe_payment_link()` when the admin requests payment or the customer clicks Pay; `invoice_creation` gives a numbered invoice + PDF after payment, off with `'stripe_invoice_pdf' => false`; webhook `checkout.session.completed` (idempotent) marks the order paid; `stripe_invoice()` = Invoicing-API alternative, currently unused); Stripe key + webhook secret are pasted by the owner in Admin → Settings → Stripe (`?a=admin-stripe`, tested against Stripe, stored in `<data_dir>/settings/stripe.json`; `stripe_secret_key`/`stripe_webhook_secret` in config.php take precedence) (+ C3 email), settings (`public/orders/settings/app.json`), analytics.
- Inbox (admin → Inbox, `design/Inbox.dc.html` → `public/admin/Inbox.dc.html`, API `app/inbox.php` `?a=admin-inbox*`): mirrors info@byereviews.com over IMAP with the SMTP login from `/etc/byereviews/smtp.php` (host = smtp host with `imap.`, override `imap_host`/`imap_port`), replies via SMTP + APPEND to Sent. Background sync + AI drafts run after normal site requests (`register_shutdown_function` + `fastcgi_finish_request`, every 10 min). Drafts use the Anthropic API key saved in the admin (`<data_dir>/settings/ai.json`) and the owner's last mails in Sent as style examples. Local test: dovecot on 127.0.0.1:9993 + `'imap_verify' => false, 'mock_ai' => true`.
- Analytics: first-party funnel events from the site (`?a=track`, `app/analytics.php`) stored as `public/orders/events/YYYY-MM-DD.jsonl` – no cookies, no IP.
- Checks (admin → Checks, `#checks`, API `?a=admin-checks`, `checks_data()` in `app/analytics.php`): every Google profile looked up on the order page, one row per visitor session + profile, with source (`ads` = gclid/gbraid/wbraid/cpc), landing page, selected reviews, reached step and matching order. Events from a browser logged in to the admin are flagged `int` (own tests). Each new check emails the team (`alert_profile_check()`, once per profile per 6 h, off with `'check_alerts' => false`).
- Pricing (`app/lib.php`): $90 / 90 € (≤ 4 weeks), $125 / 125 € (older); volume discount 2 → 5 %, 3–5 → 10 %, 6–9 → 15 % (by removed reviews); bulk price: from `BULK_MIN` = 10 **submitted** reviews every removed review costs `BULK_PRICE` = 50 (10 submitted, 8 removed = 8 × 50). Same numbers in `tools/build_site.py` (site copy, order calculator, FAQ, JSON-LD), `tools/build_admin.py` (admin calc), `tools/site_overrides.js` (dashboard), `app/inbox.php` (AI draft facts) and `public/terms.html` §4.
- Designed customer emails (status update, payment) are in `app/emails.php`; with `'mail_log_only' => true` HTML mails are written to `<data_dir>/mails/` for previewing.
- Legal pages: `imprint.html`, `terms.html`, `withdrawal.html`, `privacy.html`
- Blog: `public/blog/` is **generated** from `content/blog-posts.json` by `python3 tools/build_blog.py` (static HTML, schema, sitemap). Edit the JSON, rerun the script, commit the output. Internal links in the JSON use `[[n|text]]` (n = post number). `[AUTHOR]` bios and `[SCREENSHOT]` blocks are hidden until real content replaces them. `content/blog-posts/*.md` are reference copies only.
- Customer account (`app/account.php`): profile, password change, email change (confirmation link to the new address, `?a=confirm-email`), password reset by one-time link (`/login/?reset=…`, 1 h), log out on all devices (session version), deletion request. UI = the account area of `design/site.dc.html` (app header, Settings, forgot/reset); `tools/site_overrides.js` → `accountVals()` replaces its simulated actions with the API. `tools/site_account_patch.py` only adds what the design lacks (cancel order card, notices). Print designs (German) are in `design/print/` – not part of the website.
- `/pricing/`, `/how-it-works/`, `/faq/`, `/results/` = landing page scrolled to that section (own URLs for Google Ads sitelinks); `/order/thanks/` = confirmation after an order (Google Ads conversion URL).
- Intent landing pages for the Google Ads ad groups: `/fake-review-removal/`, `/remove-bad-google-reviews/`, `/review-removal-service/` = landing page with its own hero, subline and first section (`INTENTS` in `tools/build_site.py`, own title/canonical, in the sitemap via `tools/build_blog.py`).
- The site template is wrapped in `<x-dc><script type="text/x-dc-template">…</script></x-dc>` (inert: the browser doesn't fetch its media before render, and crawlers like Ahrefs don't read the app's unrendered `{{ }}` links or the hidden screens' H1s); `public/support.js` reads this, `<template>` or plain `<x-dc>`. The template must not contain `</script`. Hero posters (`hero-*-poster-v2.webp` = first video frame) are preloaded per breakpoint (760 px) on landing-page URLs.
- Google Ads conversions without cookies (`app/ads.php`, Admin → Settings, off by default): the site keeps gclid/gbraid/wbraid + UTM tags from the landing URL in memory and sends them with the order; stored as `order.ads` only when enabled. `?a=admin-ads-export&kind=order|paid` = CSV for Google Ads offline conversion upload (conversions from clicks). Scheduled import runs via Google Ads Data Manager (HTTPS, Basic auth user `googleads`, password = feed token shown in Admin → Settings): `order.php?a=ads-feed&kind=order&file=ads-orders.csv` (daily 7–8 h) and `…kind=paid&file=ads-payments.csv` (daily 3–4 h). Quirks: Google requires the URL to end in `.csv`, sends the query string double-encoded (decoded at the top of `public/order.php`), caches the column schema per URL and can't read an empty file (an example row with an invalid click ID is sent until real conversions exist). The admin shows the last fetch result.
- Links into the app from static pages: `/#order` opens the order flow, `/#login` the login, `/#how` etc. scroll to sections.

## SEO rules (Ahrefs site audit)

- `<title>` ≤ 60 chars, meta description ≤ 155 chars on every indexable page. Blog posts: `seo_title` + `meta` in `content/blog-posts.json` (the H1 keeps the full `title`); intent pages: `INTENTS` in `tools/build_site.py`. Both build scripts assert the limits.
- App pages (`/order/`, `/login/`, `/dashboard/`, `/order/thanks/`) are `noindex`. Legal pages are indexable, have description, canonical, Open Graph + Twitter tags and are in the sitemap.
- The intent landing pages are linked from the site footer and the blog footer (otherwise they're orphans).
- IndexNow key file: `public/4f2f8d26cdd5b58451388ca13e70ede9.txt` (key = file name).
