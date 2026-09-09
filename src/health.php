<?php
declare(strict_types=1);
// Installed OUTSIDE the document root. Only the local FPM socket invokes this file.
$nonce = $_SERVER['RECOVERY_GUARD_NONCE'] ?? '';
if (PHP_SAPI !== 'fpm-fcgi' || !is_string($nonce) || !preg_match('/\A[a-f0-9]{32}\z/D', $nonce)) {
    http_response_code(400);
    exit;
}
header('Content-Type: text/plain');
header('Cache-Control: no-store');
echo 'recovery-guard:' . $nonce;
