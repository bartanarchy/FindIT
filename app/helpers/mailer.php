<?php

/**
 * Lightweight SMTP mailer for FindIT (no external Composer dependencies).
 * Supports plain SMTP (e.g. Laragon Mailpit on 127.0.0.1:1025),
 * STARTTLS (port 587, MAIL_ENCRYPTION=tls), and SMTPS (port 465, MAIL_ENCRYPTION=ssl).
 */
function sendResetPasswordEmail(string $toEmail, string $toName, string $resetUrl): bool
{
    $host = defined('MAIL_HOST') ? MAIL_HOST : '127.0.0.1';
    $port = defined('MAIL_PORT') ? (int) MAIL_PORT : 1025;
    $username = defined('MAIL_USERNAME') ? MAIL_USERNAME : '';
    $password = defined('MAIL_PASSWORD') ? MAIL_PASSWORD : '';
    $encryption = defined('MAIL_ENCRYPTION') ? MAIL_ENCRYPTION : '';
    $fromAddress = defined('MAIL_FROM_ADDRESS') ? MAIL_FROM_ADDRESS : 'noreply@findit.local';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'FindIT';

    $safeName = trim(preg_replace('/[\r\n]+/', ' ', $toName) ?? '');
    $safeFromName = trim(preg_replace('/[\r\n]+/', ' ', $fromName) ?? 'FindIT');
    $safeTo = trim(preg_replace('/[\r\n]+/', '', $toEmail) ?? '');

    if ($safeTo === '' || !filter_var($safeTo, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $subject = 'Reset your FindIT password';
    $escapedName = htmlspecialchars($safeName !== '' ? $safeName : 'Student', ENT_QUOTES, 'UTF-8');
    $escapedUrl = htmlspecialchars($resetUrl, ENT_QUOTES, 'UTF-8');

    $textBody = "Hi " . ($safeName !== '' ? $safeName : 'Student') . ",\r\n\r\n"
        . "We received a request to reset your FindIT account password.\r\n"
        . "Open the link below to set a new password (valid for 30 minutes):\r\n\r\n"
        . $resetUrl . "\r\n\r\n"
        . "If you did not request this, you can safely ignore this email.\r\n";

    $htmlBody = "<!DOCTYPE html><html><body style=\"font-family:Arial,sans-serif;color:#1f2937;line-height:1.6;\">"
        . "<p>Hi {$escapedName},</p>"
        . "<p>We received a request to reset your <strong>FindIT</strong> account password.</p>"
        . "<p><a href=\"{$escapedUrl}\" style=\"display:inline-block;padding:10px 18px;background:#0d6efd;color:#ffffff;text-decoration:none;border-radius:6px;font-weight:600;\">Reset Password</a></p>"
        . "<p>Or copy and paste this link into your browser (valid for 30 minutes):<br><a href=\"{$escapedUrl}\">{$escapedUrl}</a></p>"
        . "<p style=\"color:#6b7280;font-size:13px;\">If you did not request a password reset, you can safely ignore this email.</p>"
        . "</body></html>";

    $boundary = 'findit_' . bin2hex(random_bytes(12));
    $headers = [
        'Date: ' . date('r'),
        'From: ' . sprintf('"%s" <%s>', addslashes($safeFromName), $fromAddress),
        'To: ' . sprintf('"%s" <%s>', addslashes($safeName !== '' ? $safeName : $safeTo), $safeTo),
        'Subject: =?UTF-8?B?' . base64_encode($subject) . '?=',
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
    ];

    $body = "--{$boundary}\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($textBody)) . "\r\n"
        . "--{$boundary}\r\n"
        . "Content-Type: text/html; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: base64\r\n\r\n"
        . chunk_split(base64_encode($htmlBody)) . "\r\n"
        . "--{$boundary}--";

    $message = implode("\r\n", $headers) . "\r\n\r\n" . $body;
    $message = preg_replace('/^\./m', '..', $message);

    $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $errno = 0;
    $errstr = '';
    $socket = @stream_socket_client($remote, $errno, $errstr, 15);

    if (!$socket) {
        error_log("[FindIT] SMTP connection failed ({$remote}): {$errstr} ({$errno})");
        return false;
    }

    stream_set_timeout($socket, 15);

    $readResponse = static function ($sock): array {
        $lines = '';
        $code = 0;
        while (($line = fgets($sock, 515)) !== false) {
            $lines .= $line;
            if (preg_match('/^(\d{3})([ -])/', $line, $m)) {
                $code = (int) $m[1];
                if ($m[2] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }
        return [$code, trim($lines)];
    };

    $sendCmd = static function ($sock, string $cmd, array $expectedCodes) use ($readResponse): bool {
        fwrite($sock, $cmd . "\r\n");
        [$code, $resp] = $readResponse($sock);
        if (!in_array($code, $expectedCodes, true)) {
            error_log("[FindIT] SMTP error after '{$cmd}': [{$code}] {$resp}");
            return false;
        }
        return true;
    };

    try {
        [$code, $resp] = $readResponse($socket);
        if ($code !== 220) {
            error_log("[FindIT] SMTP greeting failed: [{$code}] {$resp}");
            return false;
        }

        $ehloHost = gethostname() ?: 'localhost';
        if (!$sendCmd($socket, 'EHLO ' . $ehloHost, [250])) {
            return false;
        }

        if ($encryption === 'tls') {
            if (!$sendCmd($socket, 'STARTTLS', [220])) {
                return false;
            }
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                error_log('[FindIT] SMTP STARTTLS negotiation failed.');
                return false;
            }
            if (!$sendCmd($socket, 'EHLO ' . $ehloHost, [250])) {
                return false;
            }
        }

        if ($username !== '' && $password !== '') {
            if (!$sendCmd($socket, 'AUTH LOGIN', [334])) {
                return false;
            }
            if (!$sendCmd($socket, base64_encode($username), [334])) {
                return false;
            }
            if (!$sendCmd($socket, base64_encode($password), [235])) {
                return false;
            }
        }

        if (!$sendCmd($socket, 'MAIL FROM:<' . $fromAddress . '>', [250])) {
            return false;
        }
        if (!$sendCmd($socket, 'RCPT TO:<' . $safeTo . '>', [250, 251])) {
            return false;
        }
        if (!$sendCmd($socket, 'DATA', [354])) {
            return false;
        }

        fwrite($socket, $message . "\r\n.\r\n");
        [$dataCode, $dataResp] = $readResponse($socket);
        if ($dataCode !== 250) {
            error_log("[FindIT] SMTP DATA rejected: [{$dataCode}] {$dataResp}");
            return false;
        }

        fwrite($socket, "QUIT\r\n");
        return true;
    } finally {
        fclose($socket);
    }
}