<?php
declare(strict_types=1);
// Temporary local GUI-only test identity; removed in finally, no OS/shell account.
if (PHP_OS !== 'FreeBSD' || posix_geteuid() !== 0 || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' ||
    !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Isolated pfSense lab required');
require_once('config.inc'); require_once('auth.inc');
umask(0077);
$cert = openssl_x509_parse(file_get_contents('/var/etc/cert.crt'));
$host = $cert['subject']['CN'] ?? '';
if (!preg_match('/\A[a-zA-Z0-9.-]+\z/D', $host)) throw new RuntimeException('Native certificate hostname required');
$name = 'rg_lab_' . bin2hex(random_bytes(5)); $password = bin2hex(random_bytes(32));
$users = config_get_path('system/user', []);
$uid = max(array_map(fn($u) => (int)$u['uid'], $users)) + 1;
$entry = ['item' => ['name' => $name, 'uid' => (string)$uid, 'scope' => 'user', 'descr' => 'Ephemeral Recovery Guard GUI privilege test', 'priv' => ['page-system-login-logout']]];
local_user_set_password($entry, $password);
$created = false; $checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks++; }
function request($client, string $path, ?array $post = null): array {
    global $host;
    curl_setopt_array($client, [CURLOPT_URL => "https://{$host}{$path}", CURLOPT_POST => $post !== null]);
    if ($post !== null) curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($post));
    $body = curl_exec($client);
    if ($body === false) throw new RuntimeException('Verified local HTTPS request failed');
    return ['body' => $body, 'status' => curl_getinfo($client, CURLINFO_RESPONSE_CODE), 'redirect' => curl_getinfo($client, CURLINFO_REDIRECT_URL)];
}
function login() {
    global $host, $name, $password;
    $c = curl_init();
    curl_setopt_array($c, [CURLOPT_CAINFO => '/var/etc/cert.crt', CURLOPT_RESOLVE => ["{$host}:443:127.0.0.1"],
        CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Host: localhost', 'Referer: https://localhost/']]);
    $r = request($c, '/index.php');
    $post = ['usernamefld' => $name, 'passwordfld' => $password, 'login' => 'Sign In'];
    if (preg_match('/var csrfMagicToken\s*=\s*[\'"]([^\'"]+)/', $r['body'], $m)) $post['__csrf_magic'] = $m[1];
    request($c, '/index.php', $post);
    $r = request($c, '/index.php');
    check($r['status'] === 200 && !str_contains($r['body'], 'usernamefld'), 'Temporary restricted user did not authenticate');
    return $c;
}
try {
    $users[] = $entry['item']; config_set_path('system/user', $users); $created = true;
    write_config('Create temporary isolated GUI privilege test identity');
    $c = login(); $hash = hash_file('sha256', '/cf/conf/config.xml');
    foreach (['/services_recovery_guard.php', '/services_recovery_guard.php?download=1'] as $path) {
        $r = request($c, $path);
        check($r['status'] === 302 && str_ends_with($r['redirect'], '/index.php'), 'Restricted user reached package settings or diagnostics');
    }
    $r = request($c, '/services_recovery_guard.php', ['save' => 'Save configuration', 'interface' => 'lan']);
    check(in_array($r['status'], [302, 403], true) && hash_file('sha256', '/cf/conf/config.xml') === $hash, 'Restricted POST changed configuration');
    curl_close($c);
    $users = config_get_path('system/user', []);
    foreach ($users as &$user) if ($user['name'] === $name) $user['priv'][] = 'page-services-recoveryguard';
    unset($user); config_set_path('system/user', $users); write_config('Grant only Recovery Guard privilege to temporary laboratory identity');
    $c = login(); $r = request($c, '/services_recovery_guard.php');
    check($r['status'] === 200 && str_contains($r['body'], 'Save configuration') && !str_contains($r['body'], 'usernamefld'), 'Declared package privilege did not grant page access');
    $r = request($c, '/services_recovery_guard.php?download=1');
    $data = json_decode($r['body'], true);
    check($r['status'] === 200 && ($data['version'] ?? null) === 2 && is_array($data['records'] ?? null), 'Declared package privilege did not grant diagnostics');
    curl_close($c);
} finally {
    if ($created) {
        config_set_path('system/user', array_values(array_filter(config_get_path('system/user', []), fn($u) => $u['name'] !== $name)));
        write_config('Remove temporary isolated GUI privilege test identity');
        check(count(array_filter(config_get_path('system/user', []), fn($u) => $u['name'] === $name)) === 0, 'Temporary GUI identity remains');
    }
    unset($password, $entry);
}
echo "PASS: {$checks} native GUI authentication/privilege checks, settings and export protected, declared privilege works, temporary identity removed. Verified TLS throughout.\n";
