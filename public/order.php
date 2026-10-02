<?php
// Single PHP entry point (the only .php file nginx executes). Routes ?a=<action> to the app in ../app.
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
require __DIR__ . '/../app/admin.php';

// Google Ads' HTTPS fetcher sends the query string URL-encoded (twice): "?a%253Dads-feed%2526kind…".
// Decode it back into normal parameters when no "a" arrived.
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
if (!isset($_GET['a']) && preg_match('/^a%(25)*3D/i', $qs)) {
    for ($i = 0; $i < 3 && preg_match('/%(25)*(3D|26)/i', $qs); $i++) $qs = rawurldecode($qs);
    parse_str($qs, $fixed);
    if (isset($fixed['a'])) $_GET = $fixed;
}
$action = preg_replace('/[^a-z_-]/', '', (string)($_GET['a'] ?? 'order'));
// Diagnostics for the Google Ads feed: record requests that look like the feed but arrive mangled
$uri = (string)($_SERVER['REQUEST_URI'] ?? '');
if ($action !== 'ads-feed' && (stripos($uri, 'ads-feed') !== false || stripos($uri, '.csv') !== false)) {
    ads_last_feed('unexpected request ' . substr(preg_replace('/[^\x20-\x7e]/', '', $uri), 0, 160) . ' (' . ($_SERVER['REQUEST_METHOD'] ?? '') . ')');
}
$routes = [
    // site
    'places' => 'action_places',
    'suggest' => 'action_suggest',
    'place' => 'action_place',
    'reviews' => 'action_reviews',
    'check-email' => 'action_check_email',
    'order' => 'action_order',
    'track' => 'action_track',
    'ads-feed' => 'action_ads_feed', // Google Ads scheduled conversion upload (Basic auth)
    // customer dashboard
    'login' => 'action_login',
    'logout' => 'action_logout',
    'me' => 'action_me',
    'message' => 'action_message',
    'reset' => 'action_reset',
    'reset-check' => 'action_reset_check',
    'order-cancel' => 'action_order_cancel',
    'reset-confirm' => 'action_reset_confirm',
    'confirm-email' => 'action_confirm_email',
    'account-profile' => 'action_account_profile',
    'account-password' => 'action_account_password',
    'account-email' => 'action_account_email',
    'account-email-cancel' => 'action_account_email_cancel',
    'account-logout-all' => 'action_account_logout_all',
    'account-delete' => 'action_account_delete',
    'pay' => 'action_pay',
    'stripe-webhook' => 'action_stripe_webhook',
    // admin panel (/admin/)
    'admin' => 'action_admin_page',
    'admin-me' => 'action_admin_me',
    'admin-login' => 'action_admin_login',
    'admin-logout' => 'action_admin_logout',
    'admin-orders' => 'action_admin_orders',
    'admin-status' => 'action_admin_status',
    'admin-wa' => 'action_admin_wa',
    'admin-message' => 'action_admin_message',
    'admin-paylink' => 'action_admin_paylink',
    'admin-markpaid' => 'action_admin_markpaid',
    'admin-resetpw' => 'action_admin_resetpw',
    'admin-cancel' => 'action_admin_cancel',
    'admin-delete' => 'action_admin_delete',
    'admin-settings' => 'action_admin_settings',
    'admin-analytics' => 'action_admin_analytics',
    'admin-leads' => 'action_admin_leads',
    'admin-leads-run' => 'action_admin_leads_run',
    'admin-leads-step' => 'action_admin_leads_step',
    'admin-leads-cancel' => 'action_admin_leads_cancel',
    'admin-lead' => 'action_admin_lead',
    'admin-leads-limit' => 'action_admin_leads_limit',
    'admin-ads' => 'action_admin_ads',
    'admin-stripe' => 'action_admin_stripe',
    'admin-ads-export' => 'action_admin_ads_export',
    'admin-inbox' => 'action_admin_inbox',
    'admin-inbox-unread' => 'action_admin_inbox_unread',
    'admin-inbox-thread' => 'action_admin_inbox_thread',
    'admin-inbox-draft' => 'action_admin_inbox_draft',
    'admin-inbox-send' => 'action_admin_inbox_send',
    'admin-inbox-update' => 'action_admin_inbox_update',
    'admin-inbox-ai' => 'action_admin_inbox_ai',
];
if (!isset($routes[$action])) fail(404, 'unknown_action');
flush_notifications();
register_shutdown_function('inbox_background'); // mailbox sync + AI drafts after the response (php-fpm only)
$routes[$action]();
