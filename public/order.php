<?php
// Single PHP entry point (the only .php file nginx executes). Routes ?a=<action> to the app in ../app.
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
require __DIR__ . '/../app/api.php';

$action = preg_replace('/[^a-z_-]/', '', (string)($_GET['a'] ?? 'order'));
$routes = [
    'places' => 'action_places',
    'suggest' => 'action_suggest',
    'place' => 'action_place',
    'reviews' => 'action_reviews',
    'check-email' => 'action_check_email',
    'order' => 'action_order',
    'login' => 'action_login',
    'logout' => 'action_logout',
    'me' => 'action_me',
    'message' => 'action_message',
    'reset' => 'action_reset',
    'pay' => 'action_pay',
    'stripe-webhook' => 'action_stripe_webhook',
    'admin' => 'action_admin',
];
if (!isset($routes[$action])) fail(404, 'unknown_action');
if ($action === 'admin') require __DIR__ . '/../app/admin.php';
$routes[$action]();
