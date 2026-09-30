# byereviews

Website for [byereviews.com](https://byereviews.com), built from the design export `Google-Bewertungen entfernen.zip`.

## Structure

```
public/            → everything that goes into Hostinger's public_html
  index.html       → the site (design runtime in support.js, React served locally)
  order.php        → receives orders, emails them to info@byereviews.com + confirmation to the customer
  orders/          → orders are also stored here as JSON (web access blocked)
  imprint.html, terms.html, withdrawal.html, privacy.html
  assets/          → logo, favicon, fonts, media, vendor scripts
```

## Deployment (VPS)

1. Copy the contents of `public/` to `/var/www/byereviews` (e.g. `git pull` + `rsync -a --delete --exclude orders/ public/ /var/www/byereviews/`).
2. `mkdir -p /var/www/byereviews/orders && chown www-data /var/www/byereviews/orders` so orders can be stored.
3. Nginx: use `deploy/nginx-byereviews.conf` (needs `php-fpm`; certificate via `certbot --nginx -d byereviews.com -d www.byereviews.com`).
   On Apache the included `public/.htaccess` does the same job.
4. Email: copy `deploy/smtp.example.php` to `/etc/byereviews/smtp.php`, enter the password of the
   `info@byereviews.com` mailbox (Hostinger SMTP) and make it readable for the PHP user only
   (`chown root:www-data`, `chmod 640`). Without this file PHP `mail()` is used, which usually does not work on a VPS.
5. Test: place a test order, both the team email and the customer confirmation should arrive.

## Local preview

```
cd public && php -S localhost:8000
```

## Before going live

Fill in the operator details (company, address, register, VAT ID) in
`imprint.html`, `terms.html`, `withdrawal.html` and `privacy.html` (search for `[`).
