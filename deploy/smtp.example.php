<?php
// Copy to /etc/byereviews/smtp.php on the server (readable by the PHP-FPM user, NOT inside the web root)
return [
    'host' => 'smtp.hostinger.com',
    'port' => 465,
    'user' => 'info@byereviews.com',
    'pass' => 'MAILBOX_PASSWORD',
];
