<?php

/**
 * Small JSON endpoint behind the notification bell.
 *
 *   GET  ?action=summary&since=<id>   unread count, dropdown HTML, new items for a toast
 *   POST action=mark_read  id=<id>    mark one of MY notifications as read
 *   POST action=mark_all              mark all of MY notifications as read
 *
 * Students only. POST needs the CSRF token (header X-CSRF-Token or field csrf_token).
 * The page works without this file; it only adds live updates and no-reload actions.
 */

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';
require_once dirname(__DIR__) . '/app/helpers/notifications.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function fiNotifyJson(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Authentication and role: a JSON answer, never a redirect to the login page.
if (!isLoggedIn()) {
    fiNotifyJson(401, ['ok' => false, 'error' => 'signed_out']);
}

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);

if (($user['role'] ?? '') !== 'student' || $userId < 1) {
    fiNotifyJson(403, ['ok' => false, 'error' => 'forbidden']);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        // Read what we need from the session, then release it so polling never blocks other pages.
        session_write_close();

        $since = filter_input(INPUT_GET, 'since', FILTER_VALIDATE_INT);
        $since = ($since !== false && $since !== null && $since > 0) ? $since : 0;

        $html = '';
        foreach (FiNotify::recent($pdo, $userId) as $n) {
            $html .= FiNotify::renderItem($n, 'menu');
        }

        $toasts = [];
        foreach (array_reverse(FiNotify::unreadSince($pdo, $userId, $since)) as $n) {
            $toasts[] = [
                'id' => $n['id'],
                'title' => $n['title'],
                'message' => $n['message'],
                'severity' => $n['severity'],
                'icon' => fiIcon($n['icon'], 20),
                'url' => FiNotify::openUrl($n['id']),
            ];
        }

        fiNotifyJson(200, [
            'ok' => true,
            'unread' => FiNotify::unreadCount($pdo, $userId),
            'latest_id' => FiNotify::latestId($pdo, $userId),
            'html' => $html,
            'toasts' => $toasts,
        ]);
    }

    if ($method !== 'POST') {
        header('Allow: GET, POST');
        fiNotifyJson(405, ['ok' => false, 'error' => 'method_not_allowed']);
    }

    // State-changing request: the CSRF token must match this session.
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? '');
    if (!is_string($token) || !verifyCsrfToken($token)) {
        fiNotifyJson(403, ['ok' => false, 'error' => 'csrf']);
    }

    session_write_close();

    $action = $_POST['action'] ?? '';

    if ($action === 'mark_read') {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            fiNotifyJson(400, ['ok' => false, 'error' => 'bad_id']);
        }
        // The owner check lives in the UPDATE: another student's ID simply changes nothing.
        FiNotify::markRead($pdo, $userId, (int) $id);
    } elseif ($action === 'mark_all') {
        FiNotify::markAllRead($pdo, $userId);
    } else {
        fiNotifyJson(400, ['ok' => false, 'error' => 'bad_action']);
    }

    fiNotifyJson(200, ['ok' => true, 'unread' => FiNotify::unreadCount($pdo, $userId)]);
} catch (Throwable $e) {
    // Details stay in the server log; the browser only gets a generic code.
    error_log('[FindIT] Notification API failed: ' . $e->getMessage());
    fiNotifyJson(500, ['ok' => false, 'error' => 'server']);
}
