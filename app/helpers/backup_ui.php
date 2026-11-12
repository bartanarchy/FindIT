<?php

// Small helpers shared by the admin backup pages. The backup system itself lives in
// app/backup and does not need the database, so these pages keep working if it is down.

require_once dirname(__DIR__) . '/backup/bootstrap.php';
require_once __DIR__ . '/csrf.php';

function fiBackupBadge(?string $status): void
{
    $status = (string) $status;
    switch ($status) {
        case 'SUCCESS':
        case 'OK':
        case 'VALID':
        case 'HEALTHY':
            $class = 'fi-badge--success';
            break;
        case 'PARTIAL_SUCCESS':
        case 'RUNNING':
        case 'PENDING':
        case 'NOT_CONFIGURED':
        case 'NOT_RUN':
        case 'SKIPPED':
            $class = 'fi-badge--warning';
            break;
        case 'FAILED':
        case 'INVALID':
        case 'DAMAGED':
            $class = 'fi-badge--danger';
            break;
        default:
            $class = 'fi-badge--neutral';
    }
    $label = $status === '' ? 'Unknown' : str_replace('_', ' ', $status);
    echo '<span class="fi-badge ' . $class . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</span>';
}

function fiBytes($bytes): string
{
    $bytes = (float) $bytes;
    foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
        if ($bytes < 1024 || $unit === 'GB') {
            return ($unit === 'B' ? (string) (int) $bytes : number_format($bytes, 1)) . ' ' . $unit;
        }
        $bytes /= 1024;
    }
    return '';
}

function fiWhen(?string $value): string
{
    if ($value === null || $value === '') {
        return '–';
    }
    $time = strtotime($value);
    return $time === false ? htmlspecialchars($value, ENT_QUOTES, 'UTF-8') : date('d M Y, H:i', $time);
}

/** One-time message that survives a redirect. */
function fiFlash(?string $type = null, ?string $message = null): ?array
{
    if ($type !== null) {
        $_SESSION['fi_flash'] = ['type' => $type, 'message' => (string) $message];
        return null;
    }
    $flash = $_SESSION['fi_flash'] ?? null;
    unset($_SESSION['fi_flash']);
    return is_array($flash) ? $flash : null;
}

function fiAdminLabel(): string
{
    $user = currentUser();
    return 'admin#' . (int) ($user['id'] ?? 0) . ' ' . (string) ($user['name'] ?? '');
}
