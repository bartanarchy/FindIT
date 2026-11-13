<?php

// Only Polinema student accounts may register or sign in:  <digits>@student.polinema.ac.id

const CAMPUS_EMAIL_DOMAIN = 'student.polinema.ac.id';

function normalizeCampusEmail(string $email): string
{
    // Emails are compared in lowercase everywhere (storage, lookup, login).
    return strtolower(trim($email));
}

function isCampusStudentEmail(string $email): bool
{
    // \A and \z (not ^ and $) so a trailing newline can't sneak through.
    // [0-9] keeps it to plain ASCII digits.
    return preg_match(
        '/\A[0-9]{1,30}@student\.polinema\.ac\.id\z/',
        normalizeCampusEmail($email)
    ) === 1;
}

const CAMPUS_EMAIL_ERROR =
    'Please use your student email in the format NIM@student.polinema.ac.id.';
