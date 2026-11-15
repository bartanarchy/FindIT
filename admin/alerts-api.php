<?php

/**
 * JSON endpoint behind the admin bell (read only).
 * Returns the live alerts: claims waiting, new item reports, backup problems.
 * Admins only; students get 403 and signed-out visitors get 401.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';
require_once dirname(__DIR__) . '/app/helpers/admin_alerts.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function fiAlertsJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if (!isLoggedIn()) {
    fiAlertsJson(401, ['ok' => false, 'error' => 'signed_out']);
}

if ((currentUser()['role'] ?? '') !== 'admin') {
    fiAlertsJson(403, ['ok' => false, 'error' => 'forbidden']);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    fiAlertsJson(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

// Nothing is written to the session here, so release it and never block other pages.
session_write_close();

try {
    $alerts = FiAdminAlerts::collect($pdo);
    $summary = FiAdminAlerts::summary($alerts);

    $html = '';
    $items = [];
    foreach ($alerts as $a) {
        $html .= FiAdminAlerts::renderItem($a, 'menu');
        $items[] = [
            'key' => $a['key'],
            'count' => (int) $a['count'],
            'title' => $a['title'],
            'message' => $a['message'],
            'severity' => $a['severity'],
            'icon' => fiIcon($a['icon'], 20),
            'url' => $a['link'],
        ];
    }

    fiAlertsJson(200, [
        'ok' => true,
        'unread' => $summary['count'],
        'severity' => $summary['severity'] === 'danger' ? 'danger' : 'warning',
        'html' => $html,
        'toasts' => $items,
    ]);
} catch (Throwable $e) {
    error_log('[FindIT] Admin alerts API failed: ' . $e->getMessage());
    fiAlertsJson(500, ['ok' => false, 'error' => 'server']);
}
