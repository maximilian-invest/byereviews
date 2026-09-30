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
- Admin panel: https://byereviews.com/admin/ – **generated** from `design/admin.dc.html` by `python3 tools/build_admin.py` (+ `tools/admin_overrides.js`). JSON API in `app/admin.php` (`?a=admin-*`). Orders, review statuses (status emails are batched: sent 60 s after the last change), WhatsApp hand-off to the removal partner, Stripe Payment Links (+ C3 email), settings (`public/orders/settings/app.json`), analytics.
- Analytics: first-party funnel events from the site (`?a=track`, `app/analytics.php`) stored as `public/orders/events/YYYY-MM-DD.jsonl` – no cookies, no IP.
- Designed customer emails (status update, payment) are in `app/emails.php`; with `'mail_log_only' => true` HTML mails are written to `<data_dir>/mails/` for previewing.
- Legal pages: `imprint.html`, `terms.html`, `withdrawal.html`, `privacy.html`
- Blog: `public/blog/` is **generated** from `content/blog-posts.json` by `python3 tools/build_blog.py` (static HTML, schema, sitemap). Edit the JSON, rerun the script, commit the output. Internal links in the JSON use `[[n|text]]` (n = post number). `[AUTHOR]` bios and `[SCREENSHOT]` blocks are hidden until real content replaces them. `content/blog-posts/*.md` are reference copies only.
- Links into the app from static pages: `/#order` opens the order flow, `/#login` the login, `/#how` etc. scroll to sections.
