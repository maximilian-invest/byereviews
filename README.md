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

## Deployment (Hostinger)

Every push to `main` that touches `public/` is uploaded automatically via FTP
(`.github/workflows/deploy.yml`). It can also be started manually under
**Actions → Deploy to Hostinger → Run workflow**.

One-time setup: in GitHub under **Settings → Secrets and variables → Actions** add

| Secret | Where to find it (hPanel → Websites → byereviews.com → Files → FTP Accounts) |
| --- | --- |
| `FTP_SERVER` | FTP IP / hostname, e.g. `ftp.byereviews.com` |
| `FTP_USERNAME` | FTP username, e.g. `u123456789` or `u123456789.byereviews.com` |
| `FTP_PASSWORD` | FTP password |
| `FTP_SERVER_DIR` | optional, default `public_html/` (use `/` if the FTP account already starts in public_html) |

Also in hPanel:

1. **Emails**: create the mailbox `info@byereviews.com` (order emails are sent from and to this address).
2. **Security → SSL**: make sure the free SSL certificate is active (the site forces HTTPS).

## Local preview

```
cd public && php -S localhost:8000
```

## Before going live

Fill in the operator details (company, address, register, VAT ID) in
`imprint.html`, `terms.html`, `withdrawal.html` and `privacy.html` (search for `[`).
