<?php
// Copy to /etc/byereviews/config.php on the server:
//   chown root:www-data /etc/byereviews/config.php && chmod 640 /etc/byereviews/config.php
// Never commit the real file – the repo is public.
return [
    // Google Cloud → APIs & Services → enable "Places API (New)" → Credentials → API key
    // (restrict the key to the Places API; server-side key, no referrer restriction)
    'google_places_key' => '',

    // serpapi.com → API key. Loads the complete review list of a business (Places API alone returns max. 5 reviews).
    'serpapi_key' => '',
    'serpapi_pages' => 4,            // 4 pages ≈ up to ~70 reviews, lowest rating first

    // Stripe Dashboard → Developers → API keys (secret key, sk_live_…)
    'stripe_secret_key' => '',
    // Stripe Dashboard → Developers → Webhooks → endpoint https://byereviews.com/order.php?a=stripe-webhook,
    // event "checkout.session.completed" → signing secret (whsec_…)
    'stripe_webhook_secret' => '',

    // Admin login at https://byereviews.com/order.php?a=admin
    // Generate with:  php -r "echo password_hash('YOUR-PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
    'admin_password_hash' => '',
];
