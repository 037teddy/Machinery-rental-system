<?php
// Configure these environment variables in Apache/PHP before enabling email:
// SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, SMTP_FROM, SMTP_FROM_NAME
function send_app_email(string $to, string $subject, string $html): bool
{
    $host = getenv('SMTP_HOST') ?: 'smtp-relay.brevo.com';
    $port = (int) (getenv('SMTP_PORT') ?: 587);
    $user = getenv('SMTP_USER') ?: '8f3392002@smtp-brevo.com';
    $pass = getenv('SMTP_PASS') ?: 'xsmtpsib-8416c855c731e9c9f2aad1e4a38a0b8fc9df443a592f55579240c33f3c0b6b57-xXGDmxmM1LuDTvBC';
    $from = getenv('SMTP_FROM') ?: 'noreply@annycare.site';
    $from_name = getenv('SMTP_FROM_NAME') ?: 'Machinery Rental';

    if (!$host || !$user || !$pass || !$from) {
        error_log('SMTP is not configured. Set SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, and SMTP_FROM.');
        return false;
    }

    $implicit_tls = $port === 465;
    $transport = $implicit_tls ? 'ssl://' : 'tcp://';
    $socket = stream_socket_client($transport . $host . ':' . $port, $errno, $error, 15);
    if (!$socket) {
        error_log('SMTP connection failed: ' . $error);
        return false;
    }

    $read = static function ($socket, int $expected) {
        $last_code = null;
        do {
            $response = fgets($socket, 512);
            if ($response === false || strlen($response) < 4) {
                return false;
            }
            $last_code = (int) substr($response, 0, 3);
            $is_last_line = $response[3] === ' ';
        } while (!$is_last_line);

        return $last_code === $expected;
    };
    $write = static function ($socket, string $command) { fwrite($socket, $command . "\r\n"); };

    if (!$read($socket, 220)) { fclose($socket); return false; }
    $write($socket, 'EHLO localhost');
    if (!$read($socket, 250)) { fclose($socket); return false; }
    if (!$implicit_tls) {
        $write($socket, 'STARTTLS');
        if (!$read($socket, 220) || !stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($socket); return false; }
    }
    $write($socket, 'EHLO localhost');
    if (!$read($socket, 250)) { fclose($socket); return false; }
    $write($socket, 'AUTH LOGIN');
    if (!$read($socket, 334)) { fclose($socket); return false; }
    $write($socket, base64_encode($user));
    if (!$read($socket, 334)) { fclose($socket); return false; }
    $write($socket, base64_encode($pass));
    if (!$read($socket, 235)) { fclose($socket); return false; }
    $write($socket, 'MAIL FROM:<' . $from . '>');
    if (!$read($socket, 250)) { fclose($socket); return false; }
    $write($socket, 'RCPT TO:<' . $to . '>');
    if (!$read($socket, 250)) { fclose($socket); return false; }
    $write($socket, 'DATA');
    if (!$read($socket, 354)) { fclose($socket); return false; }

    $headers = 'From: ' . $from_name . ' <' . $from . ">\r\n" .
        'To: <' . $to . ">\r\n" .
        'Subject: ' . $subject . "\r\n" .
        "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n";
    fwrite($socket, $headers . $html . "\r\n.\r\n");
    $sent = $read($socket, 250);
    $write($socket, 'QUIT');
    fclose($socket);
    return $sent;
}
