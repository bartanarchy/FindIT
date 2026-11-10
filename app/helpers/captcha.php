<?php

// this function ask the google reCAPTCHA server whether the token is valid or not. 
function verifyRecaptcha(string $token): bool
{
    // if the token or secret key is empty, we assume failure. 
    // The secret key is defined in config/config.php and comes from .env.
    if ($token === '' || RECAPTCHA_SECRET_KEY === '') {
        return false;
    }

    // curl is a PHP library that can send HTTP requests.
    $ch = curl_init('https://www.google.com/recaptcha/api/siteverify');

    curl_setopt_array($ch, [
        CURLOPT_POST => true, // send with POST method

        // data to send to Google, including the secret key, the token from the user,
        // and the user's IP address 
        CURLOPT_POSTFIELDS => http_build_query([
            'secret'   => RECAPTCHA_SECRET_KEY,
            'response' => $token,
            'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
        ]),

        CURLOPT_RETURNTRANSFER => true, // save the response from Google as a string instead of printing it
        CURLOPT_TIMEOUT => 5,           // wait at most 5 seconds for a response from Google
    ]);

    $response = curl_exec($ch);

    // if the request failed, we log the error on the server and return false.
    if ($response === false) {
        // error_log is a PHP function that writes messages to the server's error log.
        error_log('[FindIT] reCAPTCHA request failed: ' . curl_error($ch));
        return false;
    }

    // google's response is a JSON string, 
    // so we decode it into an associative array for easier access.
    $data = json_decode($response, true);

    // the 'success' field in the response indicates whether the verification was successful.
    return is_array($data) && ($data['success'] ?? false) === true;
}