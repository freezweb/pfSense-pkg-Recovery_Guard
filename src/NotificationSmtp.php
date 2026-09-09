<?php
declare(strict_types=1);
namespace RecoveryGuard;

/** Native SMTP settings are read only in the sender process, never stored in the outbox. */
final class NotificationSmtp
{
    public function __construct(private \Closure $settings, private ?\Closure $transport = null) {}

    /** A destination binding excludes the password, so rotating credentials can restore retries. */
    public static function target(array $settings): string
    {
        $c = self::compile($settings);
        return hash('sha256', json_encode([$c['to'], $c['from'], $c['params']['host'], $c['params']['port'],
            $c['params']['auth'], $c['params']['username'], $c['params']['socket_options']], JSON_THROW_ON_ERROR));
    }

    /** Must run in an independently bounded worker; PEAR's socket timeout is not a total deadline. */
    public function send(array $job): string
    {
        $settings = ($this->settings)();
        if (($settings['enabled'] ?? null) !== true ||
            isset($settings['smtp']['disable'])) return 'disabled';
        if (($settings['booting'] ?? null) !== false) return 'unknown';
        $config = self::compile($settings);
        if (!is_string($job['target'] ?? null) || !hash_equals(self::target($settings), $job['target'])) return 'target_changed';
        $message = NotificationMessage::render($job);
        $headers = ['From' => $config['from'], 'To' => $config['to'], 'Subject' => $message['subject'],
            'Date' => date('r'), 'Message-ID' => $message['message_id'], 'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8', 'Auto-Submitted' => 'auto-generated'];
        $transport = $this->transport ?? static function (array $params, string $to, array $headers, string $body): mixed {
            require_once 'Mail.php';
            // PEAR's automatic authentication uses opportunistic STARTTLS. Connect
            // without credentials, require encryption, then authenticate explicitly.
            $connection = $params;
            $connection['auth'] = false;
            $connection['username'] = $connection['password'] = '';
            $mailer = \Mail::factory('smtp', $connection);
            if (\PEAR::isError($mailer)) return false;
            try {
                if ($params['auth'] !== false) {
                    $session = @$mailer->getSMTPObject();
                    if (\PEAR::isError($session)) return false;
                    if (!str_starts_with($params['host'], 'ssl://') && @$session->starttls() !== true) return false;
                    if (@$session->auth($params['username'], $params['password'], $params['auth'], false) !== true) return false;
                }
                return @$mailer->send($to, $headers, $body);
            } finally { @$mailer->disconnect(); }
        };
        // A failure can occur after the relay accepted DATA. Only explicit true proves acceptance.
        // Do not persist PEAR errors: they may contain recipient or authentication details.
        try { return $transport($config['params'], $config['to'], $headers, $message['body']) === true ? 'accepted' : 'unknown'; }
        catch (\Throwable) { return 'unknown'; }
    }

    private static function compile(array $settings): array
    {
        $smtp = $settings['smtp'] ?? null;
        if (!is_array($smtp)) throw new \InvalidArgumentException('SMTP configuration unavailable');
        $host = $smtp['ipaddress'] ?? '';
        if (!is_string($host) || strlen($host) > 253 || !preg_match('/\A[a-zA-Z0-9.:[\]-]+\z/D', $host)) throw new \InvalidArgumentException('Invalid SMTP host');
        $to = $smtp['notifyemailaddress'] ?? '';
        if (!is_string($to) || strlen($to) > 4096 || preg_match('/[\r\n\x00]/', $to)) throw new \InvalidArgumentException('Invalid notification recipient');
        $recipients = array_map('trim', explode(',', $to));
        if (count($recipients) > 16) throw new \InvalidArgumentException('Too many notification recipients');
        foreach ($recipients as $recipient) if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Invalid notification recipient');
        $to = implode(', ', $recipients);
        $localhost = ($settings['hostname'] ?? '') . '.' . ($settings['domain'] ?? '');
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9.-]{0,252}\z/D', $localhost)) throw new \InvalidArgumentException('Invalid SMTP greeting name');
        $from = ($smtp['fromaddress'] ?? '') ?: 'pfsense@' . $localhost;
        if (!is_string($from) || !filter_var($from, FILTER_VALIDATE_EMAIL) || preg_match('/[\r\n\x00]/', $from)) throw new \InvalidArgumentException('Invalid SMTP sender');
        $port = filter_var($smtp['port'] ?? 25, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) throw new \InvalidArgumentException('Invalid SMTP port');
        $username = $smtp['username'] ?? ''; $password = $smtp['password'] ?? '';
        if (!is_string($username) || !is_string($password)) throw new \InvalidArgumentException('Invalid SMTP authentication');
        if (($username === '') !== ($password === '')) throw new \InvalidArgumentException('Incomplete SMTP authentication');
        $auth = $username !== '' && $password !== '' ? ($smtp['authentication_mechanism'] ?? 'PLAIN') : false;
        if ($auth !== false && !in_array($auth, ['PLAIN', 'LOGIN', 'CRAM-MD5', 'DIGEST-MD5'], true)) throw new \InvalidArgumentException('Unsupported SMTP authentication');
        $verify = ($smtp['sslvalidate'] ?? '') !== 'disabled';
        return ['to' => $to, 'from' => $from, 'params' => ['host' => (isset($smtp['ssl']) ? 'ssl://' : '') . $host,
            'port' => $port, 'auth' => $auth, 'username' => $auth === false ? '' : $username, 'password' => $auth === false ? '' : $password,
            'localhost' => $localhost, 'timeout' => 10, 'debug' => false, 'persist' => false,
            'socket_options' => ['ssl' => ['verify_peer_name' => $verify, 'verify_peer' => $verify]]]];
    }
}
