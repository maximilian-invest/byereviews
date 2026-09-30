# byereviews

Website for [byereviews.com](https://byereviews.com), built from the design export `Google-Bewertungen entfernen.zip`.

## Structure

```
public/            → web root on the VPS
  index.html       → the site (design runtime in support.js, React served locally)
  order.php        → receives orders, emails them to info@byereviews.com + confirmation to the customer
  orders/          → orders are also stored here as JSON (web access blocked)
  imprint.html, terms.html, withdrawal.html, privacy.html
  assets/          → logo, favicon, fonts, media, vendor scripts
```

## Deployment (VPS, automatic)

Push to `main` → the VPS pulls it within ~60 seconds (cron + `deploy/deploy.sh`). Details and server paths: see `CLAUDE.md`.

One-time server setup (already done on 187.124.166.153):

1. `git clone https://github.com/maximilian-invest/byereviews.git /var/www/byereviews`
2. nginx vhost from `deploy/nginx-byereviews.conf`, then `certbot --nginx -d byereviews.com -d www.byereviews.com --redirect`
3. `install -m 755 deploy/deploy.sh /usr/local/bin/byereviews-deploy` and `cp deploy/byereviews-deploy.cron /etc/cron.d/byereviews-deploy`
4. Email: copy `deploy/smtp.example.php` to `/etc/byereviews/smtp.php`, enter the password of the
   `info@byereviews.com` mailbox (Hostinger SMTP), `chown root:www-data`, `chmod 640`.
   Without this file PHP `mail()` is used, which does not work on this VPS.
5. Test: place a test order, both the team email and the customer confirmation should arrive.

## Blog

Posts live in `content/blog-posts.json`. After editing run `python3 tools/build_blog.py`; it regenerates `public/blog/**` and `public/sitemap.xml`. Commit both.

## Local preview

```
cd public && php -S localhost:8000
```

## Before going live

Fill in the operator details (company, address, register, VAT ID) in
`imprint.html`, `terms.html`, `withdrawal.html` and `privacy.html` (search for `[`).
