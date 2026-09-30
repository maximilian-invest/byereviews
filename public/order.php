<?php
// Single PHP entry point (the only .php file nginx executes). Routes ?a=<action> to the app in ../app.
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
require __DIR__ . '/../app/admin.php';

$action = preg_replace('/[^a-z_-]/', '', (string)($_GET['a'] ?? 'order'));
$routes = [
    // site
    'places' => 'action_places',
    'suggest' => 'action_suggest',
    'place' => 'action_place',
    'reviews' => 'action_reviews',
    'check-email' => 'action_check_email',
    'order' => 'action_order',
    'track' => 'action_track',
    // customer dashboard
    'login' => 'action_login',
    'logout' => 'action_logout',
    'me' => 'action_me',
    'message' => 'action_message',
    'reset' => 'action_reset',
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
    'admin-settings' => 'action_admin_settings',
    'admin-analytics' => 'action_admin_analytics',
    'admin-leads' => 'action_admin_leads',
    'admin-leads-run' => 'action_admin_leads_run',
    'admin-leads-step' => 'action_admin_leads_step',
    'admin-leads-cancel' => 'action_admin_leads_cancel',
    'admin-lead' => 'action_admin_lead',
];
if (!isset($routes[$action])) fail(404, 'unknown_action');
flush_notifications();
$routes[$action]();
