<?php

// Captcha answer is stored in session, on the server
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// code length is 5 char, and the code is valid for 5 mnt
const CAPTCHA_LENGTH = 5;
const CAPTCHA_LIFETIME = 300;

// make random captcha code, store it in session, and return it.
function generateCaptchaCode(): string
{
    // similar letters is intentionally removed to avoid confusion (0 and O, 1 and I).
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $maxIndex = strlen($alphabet) - 1;

    $code = '';
    for ($i = 0; $i < CAPTCHA_LENGTH; $i++) {
        // random_int chooses random number that is safe for security purposes.
        $code .= $alphabet[random_int(0, $maxIndex)];
    }

    // save the code along with its creation time.
    $_SESSION['captcha'] = [
        'code' => $code,
        'created_at' => time(),
    ];

    return $code;
}

// check the visitor's answer. Return true if correct.
function verifyCaptcha(string $answer): bool
{
    $stored = $_SESSION['captcha'] ?? null;

    // code is immediately removed so it can only be used once.
    unset($_SESSION['captcha']);

    if (!is_array($stored) || empty($stored['code'])) {
        return false;
    }

    // if more than lifetime, the code is considered expired.
    if (time() - (int) ($stored['created_at'] ?? 0) > CAPTCHA_LIFETIME) {
        return false;
    }

    // not case sensitive
    $answer = strtoupper(trim($answer));

    return $answer !== '' && hash_equals($stored['code'], $answer);
}