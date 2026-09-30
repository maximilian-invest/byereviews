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

- `public/index.html`: the site (design runtime in `support.js`, React served locally from `assets/vendor/`)
- `public/order.php`: order form endpoint (emails team + customer, stores JSON copy, per-IP rate limit)
- Legal pages: `imprint.html`, `terms.html`, `withdrawal.html`, `privacy.html`
