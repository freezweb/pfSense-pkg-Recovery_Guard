<?php
declare(strict_types=1);
if (PHP_OS !== 'FreeBSD' || getenv('RECOVERY_GUARD_ISOLATED_LAB') !== '1' || !is_file('/root/RECOVERY_GUARD_ISOLATED_LAB')) throw new RuntimeException('Isolated FreeBSD laboratory required');
$vendor = $argv[1] ?? '';
if (!is_file($vendor . '/Mail.php') || !is_file($vendor . '/SOURCES.txt')) throw new RuntimeException('Pinned private PEAR fixture required');
set_include_path($vendor . PATH_SEPARATOR . get_include_path());
foreach (['NotificationOutbox', 'NotificationMessage', 'NotificationSmtp'] as $name) require __DIR__ . '/../src/' . $name . '.php';
use RecoveryGuard\NotificationSmtp;
umask(0077);
if (($argv[2] ?? '') === '--client') {
    $settings = json_decode(file_get_contents($argv[3]), true, 32, JSON_THROW_ON_ERROR);
    $job = ['id' => str_repeat('a', 64), 'target' => NotificationSmtp::target($settings),
        'event' => ['action_id' => 'fixture:1000', 'kind' => 'reboot', 'mode' => 'recover', 'result' => 'handoff_pending', 'time' => 1000]];
    echo (new NotificationSmtp(fn() => $settings))->send($job);
    exit;
}
$dir = '/root/recovery-guard-smtp-tls-' . bin2hex(random_bytes(6)); mkdir($dir, 0700);
$trusted = '';
foreach (['valid' => 'IP:127.0.0.1', 'wrong-name' => 'DNS:wrong.example.invalid', 'untrusted' => 'IP:127.0.0.1'] as $name => $san) {
    $config = $dir . '/' . $name . '.cnf';
    file_put_contents($config, "[req]\ndistinguished_name=dn\nx509_extensions=extensions\n[dn]\n[extensions]\nsubjectAltName={$san}\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n");
    $options = ['config' => $config, 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'x509_extensions' => 'extensions'];
    $key = openssl_pkey_new($options);
    $csr = openssl_csr_new(['commonName' => 'fixture.example.invalid'], $key, $options);
    $cert = openssl_csr_sign($csr, null, $key, 1, $options, random_int(1, 100000000));
    if (!$cert || !openssl_x509_export($cert, $public) || !openssl_pkey_export($key, $private, null, $options)) throw new RuntimeException('Private certificate generation failed');
    file_put_contents($dir . '/' . $name . '.pem', $public . $private);
    if ($name !== 'untrusted') $trusted .= $public;
}
file_put_contents($dir . '/trust.pem', $trusted);
$cases = [
    'no-starttls' => ['tls' => 'none', 'cert' => 'valid', 'auth' => 'PLAIN', 'result' => 'unknown', 'auth_seen' => false],
    'no-starttls-validation-disabled' => ['tls' => 'none', 'cert' => 'valid', 'auth' => 'LOGIN', 'result' => 'unknown', 'auth_seen' => false],
    'implicit-plain' => ['tls' => 'implicit', 'cert' => 'valid', 'auth' => 'PLAIN', 'result' => 'accepted', 'auth_seen' => true],
    'starttls-login' => ['tls' => 'starttls', 'cert' => 'valid', 'auth' => 'LOGIN', 'result' => 'accepted', 'auth_seen' => true],
    'bad-password' => ['tls' => 'starttls', 'cert' => 'valid', 'auth' => 'PLAIN', 'result' => 'unknown', 'auth_seen' => true],
    'wrong-name' => ['tls' => 'starttls', 'cert' => 'wrong-name', 'auth' => 'LOGIN', 'result' => 'unknown', 'auth_seen' => false],
    'untrusted' => ['tls' => 'implicit', 'cert' => 'untrusted', 'auth' => 'PLAIN', 'result' => 'unknown', 'auth_seen' => false],
    'explicit-validation-disabled' => ['tls' => 'implicit', 'cert' => 'untrusted', 'auth' => 'PLAIN', 'result' => 'accepted', 'auth_seen' => true],
    'starttls-refused' => ['tls' => 'starttls', 'cert' => 'valid', 'auth' => 'LOGIN', 'result' => 'unknown', 'auth_seen' => false],
];
$checks = 0;
foreach ($cases as $name => $case) {
    $context = stream_context_create(['ssl' => ['local_cert' => $dir . '/' . $case['cert'] . '.pem', 'verify_peer' => false]]);
    $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
    if (!$listener) throw new RuntimeException('Private listener failed');
    $port = (int) explode(':', stream_socket_get_name($listener, false))[1];
    $evidence = $dir . '/' . $name . '.json';
    $pid = pcntl_fork();
    if ($pid < 0) throw new RuntimeException('Private fixture fork failed');
    if ($pid === 0) {
        pcntl_alarm(15);
        $seen = ['tls' => false, 'auth' => false, 'plaintext_auth' => false, 'data' => false];
        $socket = stream_socket_accept($listener, 5); fclose($listener);
        if (!$socket) exit(2);
        stream_set_timeout($socket, 3);
        try {
            if ($case['tls'] === 'implicit') {
                if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
                    file_put_contents($evidence, json_encode($seen, JSON_THROW_ON_ERROR)); fclose($socket); exit(0);
                }
                $seen['tls'] = true;
            }
            fwrite($socket, "220 fixture ESMTP\r\n");
            $data = false; $authStep = ''; $authenticated = false; $userValid = false;
            while (($line = fgets($socket, 8192)) !== false) {
                if ($data) {
                    if ($line === ".\r\n") { $data = false; fwrite($socket, "250 accepted\r\n"); }
                } elseif ($authStep !== '') {
                    if ($authStep === 'user') { $userValid = base64_decode(trim($line), true) === 'fixture-user'; $authStep = 'password'; fwrite($socket, "334 UGFzc3dvcmQ6\r\n"); }
                    else {
                        $expected = $authStep === 'plain' ? "\0fixture-user\0fixture-password" : 'fixture-password';
                        $authenticated = ($authStep === 'plain' || $userValid) && base64_decode(trim($line), true) === $expected;
                        $authStep = ''; fwrite($socket, $authenticated ? "235 authenticated\r\n" : "535 rejected\r\n");
                    }
                } elseif (str_starts_with($line, 'EHLO ') || str_starts_with($line, 'HELO ')) {
                    fwrite($socket, "250-fixture\r\n" . (!$seen['tls'] && $case['tls'] === 'starttls' ? "250-STARTTLS\r\n" : '') . "250 AUTH PLAIN LOGIN\r\n");
                } elseif ($line === "STARTTLS\r\n") {
                    if ($name === 'starttls-refused') { fwrite($socket, "454 TLS unavailable\r\n"); continue; }
                    fwrite($socket, "220 ready\r\n");
                    if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) break;
                    $seen['tls'] = true;
                } elseif (str_starts_with($line, 'AUTH ')) {
                    $seen['auth'] = true; $seen['plaintext_auth'] = !$seen['tls'];
                    $authStep = trim($line) === 'AUTH PLAIN' ? 'plain' : 'user';
                    fwrite($socket, $authStep === 'plain' ? "334 \r\n" : "334 VXNlcm5hbWU6\r\n");
                } elseif (str_starts_with($line, 'MAIL FROM:') || str_starts_with($line, 'RCPT TO:')) fwrite($socket, $authenticated ? "250 accepted\r\n" : "530 authentication required\r\n");
                elseif ($line === "DATA\r\n") {
                    $seen['data'] = true; $data = true; fwrite($socket, "354 continue\r\n");
                } elseif ($line === "RSET\r\n") fwrite($socket, "250 reset\r\n");
                elseif ($line === "QUIT\r\n") { fwrite($socket, "221 bye\r\n"); break; }
                else throw new RuntimeException('Unexpected SMTP command');
            }
        } finally { file_put_contents($evidence, json_encode($seen, JSON_THROW_ON_ERROR)); fclose($socket); }
        exit(0);
    }
    fclose($listener);
    $settings = ['enabled' => true, 'booting' => false, 'hostname' => 'fixture', 'domain' => 'example.invalid',
        'smtp' => ['ipaddress' => '127.0.0.1', 'port' => $port, 'notifyemailaddress' => 'fixture@example.invalid',
            'username' => 'fixture-user', 'password' => 'fixture-password', 'authentication_mechanism' => $case['auth']]];
    if ($case['tls'] === 'implicit') $settings['smtp']['ssl'] = '';
    if (str_ends_with($name, 'validation-disabled')) $settings['smtp']['sslvalidate'] = 'disabled';
    if ($name === 'bad-password') $settings['smtp']['password'] = 'incorrect-fixture-password';
    file_put_contents($dir . '/settings.json', json_encode($settings, JSON_THROW_ON_ERROR));
    try {
        $process = proc_open(['/usr/bin/timeout', '-k', '0.5', '12', PHP_BINARY, '-d', 'openssl.cafile=' . $dir . '/trust.pem', __FILE__, $vendor, '--client', $dir . '/settings.json'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['file', $dir . '/' . $name . '.stderr', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Private client failed');
        $result = stream_get_contents($pipes[1]); fclose($pipes[1]); $exit = proc_close($process);
        pcntl_waitpid($pid, $status);
        $seen = is_file($evidence) ? json_decode(file_get_contents($evidence), true, 8, JSON_THROW_ON_ERROR) : null;
        if ($exit !== 0 || !pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0 || $result !== $case['result'] ||
            !is_array($seen) || $seen['auth'] !== $case['auth_seen'] || $seen['plaintext_auth'] || $seen['data'] !== ($case['result'] === 'accepted')) {
            throw new RuntimeException('TLS/authentication mismatch: ' . $name . '; result=' . $result . '; evidence=' . json_encode($seen));
        }
        $checks++; echo "PASS: {$name}\n";
    } finally { if (posix_kill($pid, 0)) { posix_kill($pid, SIGTERM); pcntl_waitpid($pid, $status); } }
}
echo "PASS: {$checks} actual PEAR TLS/authentication cases; loopback only, fixture credentials only.\nEvidence directory: {$dir}\n";
