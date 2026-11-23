<?php
function fiE($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}
function fiOr($value, string $fallback = 'Not provided'): string
{
    $text = trim((string) ($value ?? ''));
    return $text !== '' ? fiE($text) : fiE($fallback);
}
function fiDate($value, string $fallback = 'Not specified'): string
{
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return fiE($fallback);
    }
    $timestamp = strtotime($text);
    return $timestamp ? fiE(date('d M Y', $timestamp)) : fiE($text);
}
function fiDateTime($value, string $fallback = 'Not available'): string
{
    $text = trim((string) ($value ?? ''));
    if ($text === '') {
        return fiE($fallback);
    }
    $timestamp = strtotime($text);
    return $timestamp ? fiE(date('d M Y, H:i', $timestamp)) : fiE($text);
}
function fiStatusClass(?string $status): string
{
    switch (strtolower(trim((string) $status))) {
        case 'ditemukan':
        case 'disetujui':
        case 'approved':
            return 'fi-badge--success';
        case 'menunggu verifikasi':
        case 'menunggu':
        case 'pending':
            return 'fi-badge--warning';
        case 'ditolak':
        case 'rejected':
            return 'fi-badge--danger';
        case 'diklaim':
        case 'selesai':
        case 'claimed':
            return 'fi-badge--info';
        default:
            return 'fi-badge--neutral';
    }
}
function fiBadge(?string $status, string $fallback = 'Not set'): void
{
    $label = trim((string) $status) !== '' ? (string) $status : $fallback;
    echo '<span class="fi-badge ' . fiStatusClass($status) . '">'
        . fiE($label) . '</span>';
}
function fiHead(string $title, string $assetBase = 'assets', string $bodyClass = ''): void
{
    $bodyClasses = trim('fi-body ' . $bodyClass);
    echo '<!DOCTYPE html>' . "\n";
    echo '<html lang="en">' . "\n";
    echo '<head>' . "\n";
    echo '    <meta charset="UTF-8">' . "\n";
    echo '    <meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '    <meta name="theme-color" content="#F6F3EC">' . "\n";
    echo '    <title>' . fiE($title) . '</title>' . "\n";
     // Browser tab icon. $assetBase keeps the path correct for both public and admin pages.
    // The "?v=1" part forces browsers to reload the icon; raise the number when the logo changes.
    echo '    <link rel="icon" href="' . fiE($assetBase) . '/images/favicon.ico?v=1" sizes="48x48">' . "\n";
    echo '    <link rel="icon" href="' . fiE($assetBase) . '/images/favicon.svg?v=1" type="image/svg+xml">' . "\n";
    echo '    <link rel="stylesheet" href="' . fiE($assetBase) . '/vendor/bootstrap/css/bootstrap.min.css">' . "\n";
    echo '    <link rel="stylesheet" href="' . fiE($assetBase) . '/css/style.css">' . "\n";
    echo '</head>' . "\n";
    echo '<body class="' . fiE($bodyClasses) . '">' . "\n";
    echo '<a class="fi-skip-link" href="#main">Skip to content</a>' . "\n";
}
function fiPublicNav(?array $user, string $active = ''): void
{
    $role = $user['role'] ?? '';
    $link = static function (string $href, string $label, string $key, string $active): string {
        $class = 'nav-link' . ($key === $active ? ' active' : '');
        $current = $key === $active ? ' aria-current="page"' : '';
        return '<li class="nav-item"><a class="' . $class . '" href="' . $href . '"' . $current . '>'
            . $label . '</a></li>';
    };
    echo '<header class="fi-navbar">' . "\n";
    echo '<nav class="navbar navbar-expand-md" aria-label="Main navigation">' . "\n";
    echo '<div class="fi-container">' . "\n";
    echo '<a class="fi-brand" href="index.php">'
    . '<img src="assets/images/findit-logo.png" alt="FindIT Logo" class="fi-brand-logo">'
    . '<span class="fi-brand-name">FindIT</span>'
    . '</a>' . "\n";
    if ($role === 'student') {
        echo fiStudentBell($user, $active) . "\n";
    }
    echo '<button class="navbar-toggler fi-toggler" type="button" data-bs-toggle="collapse" '
        . 'data-bs-target="#fiNav" aria-controls="fiNav" aria-expanded="false" '
        . 'aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>' . "\n";
    echo '<div class="collapse navbar-collapse" id="fiNav">' . "\n";
    echo '<ul class="navbar-nav ms-auto align-items-md-center gap-md-1">' . "\n";
    echo $link('index.php', 'Home', 'home', $active) . "\n";
    if ($role === 'student') {
        echo $link('report-item.php', 'Report found item', 'report', $active) . "\n";
        echo $link('my-claims.php', 'My claims', 'claims', $active) . "\n";
        echo '<li class="nav-item"><a class="fi-nav-profile' . ($active === 'profile' ? ' active' : '') . '" href="profile.php"'
            . ($active === 'profile' ? ' aria-current="page"' : '') . '>'
            . fiNavAvatar($user)
            . '<span class="fi-nav-user">' . fiE($user['name'] ?? 'Student') . '</span></a></li>' . "\n";
        echo '<li class="nav-item ms-md-2"><a class="btn btn-outline-secondary btn-sm" href="logout.php">Sign out</a></li>' . "\n";
    } elseif ($role === 'admin') {
        echo '<li class="nav-item"><a class="nav-link" href="../admin/index.php">Admin dashboard</a></li>' . "\n";
        echo '<li class="nav-item ms-md-2"><a class="btn btn-outline-secondary btn-sm" href="logout.php">Sign out</a></li>' . "\n";
    } else {
        echo $link('login.php', 'Sign in', 'login', $active) . "\n";
        echo '<li class="nav-item ms-md-2"><a class="btn btn-primary btn-sm" href="register.php">Register</a></li>' . "\n";
    }
    echo '</ul>' . "\n";
    echo '</div>' . "\n";
    echo '</div>' . "\n";
    echo '</nav>' . "\n";
    echo '</header>' . "\n";
}
/** Small round profile picture for the navbar. Falls back to the default icon if anything fails. */
function fiNavAvatar(?array $user): string
{
    global $pdo;

    require_once __DIR__ . '/avatar.php';

    $info = ['type' => 'none', 'preset' => null, 'v' => 0];
    $userId = (int) ($user['id'] ?? 0);

    if ($userId > 0 && isset($pdo)) {
        $info = FiAvatar::info($pdo, $userId);
    }

    return FiAvatar::render($info, 30, 'avatar.php');
}

function fiAdminNav(string $active = ''): void
{
    $link = static function (string $href, string $label, string $key, string $active): string {
        $class = 'nav-link' . ($key === $active ? ' active' : '');
        $current = $key === $active ? ' aria-current="page"' : '';
        return '<li class="nav-item"><a class="' . $class . '" href="' . $href . '"' . $current . '>'
            . $label . '</a></li>';
    };
    echo '<header class="fi-navbar fi-navbar--admin">' . "\n";
    echo '<nav class="navbar navbar-expand-md" aria-label="Admin navigation">' . "\n";
    echo '<div class="fi-container">' . "\n";
    echo '<a class="fi-brand" href="index.php">'
    . '<img src="../public/assets/images/findit-logo.png" alt="FindIT Logo" class="fi-brand-logo">'
    . '<span class="fi-brand-name">FindIT</span>'
    . '<small class="fi-brand-tag">Admin</small>'
    . '</a>' . "\n";
    echo fiAdminBell() . "\n";
    echo '<button class="navbar-toggler fi-toggler" type="button" data-bs-toggle="collapse" '
        . 'data-bs-target="#fiNav" aria-controls="fiNav" aria-expanded="false" '
        . 'aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>' . "\n";
    echo '<div class="collapse navbar-collapse" id="fiNav">' . "\n";
    echo '<ul class="navbar-nav ms-auto align-items-md-center gap-md-1">' . "\n";
    echo $link('index.php', 'Dashboard', 'dashboard', $active) . "\n";
    echo $link('items.php', 'Item', 'items', $active) . "\n";
    echo $link('claims.php', 'Claim', 'claims', $active) . "\n";
    echo $link('users.php', 'User Management', 'users', $active) . "\n";
    echo $link('backups.php', 'Backup', 'backups', $active) . "\n";
    echo '<li class="nav-item"><a class="nav-link" href="../public/index.php">View site</a></li>' . "\n";
    echo '<li class="nav-item ms-md-2"><a class="btn btn-outline-secondary btn-sm" href="../public/logout.php">Sign out</a></li>' . "\n";
    echo '</ul>' . "\n";
    echo '</div>' . "\n";
    echo '</div>' . "\n";
    echo '</nav>' . "\n";
    echo '</header>' . "\n";
}
function fiPageHeader(
    string $eyebrow,
    string $titleHtml,
    string $lead = '',
    ?string $count = null,
    string $countLabel = ''
): void {
    echo '<header class="fi-hero">' . "\n";
    echo '<div class="fi-hero-text">' . "\n";
    echo '<p class="fi-eyebrow">' . fiE($eyebrow) . '</p>' . "\n";
    echo '<h1 class="fi-title">' . $titleHtml . '</h1>' . "\n";
    if ($lead !== '') {
        echo '<p class="fi-lead">' . fiE($lead) . '</p>' . "\n";
    }
    echo '</div>' . "\n";
    if ($count !== null) {
        echo '<div class="fi-count" role="group" aria-label="' . fiE($countLabel) . '">' . "\n";
        echo '<span class="fi-count-number">' . fiE($count) . '</span>' . "\n";
        echo '<span class="fi-count-label">' . fiE($countLabel) . '</span>' . "\n";
        echo '</div>' . "\n";
    }
    echo '</header>' . "\n";
}
function fiAlert(string $type, string $message, bool $autoHide = false): void
{
    $allowed = ['success', 'danger', 'warning', 'info'];
    $type = in_array($type, $allowed, true) ? $type : 'info';
    $role = $type === 'danger' ? 'alert' : 'status';
    $icons = ['success' => 'check-circle', 'danger' => 'x-circle', 'warning' => 'alert-triangle', 'info' => 'info'];
    $labels = ['success' => 'Done', 'danger' => 'Something went wrong', 'warning' => 'Heads up', 'info' => 'Note'];
    $hide = $autoHide ? ' data-autohide="6000"' : '';
    echo '<div class="alert alert-' . $type . ' fi-alert fi-alert--rich" role="' . $role . '"' . $hide . '>'
        . '<span class="fi-alert-icon" aria-hidden="true">' . fiIcon($icons[$type], 20) . '</span>'
        . '<div class="fi-alert-text"><strong class="fi-alert-label">' . fiE($labels[$type]) . '</strong>'
        . '<span class="fi-alert-message">' . fiE($message) . '</span></div>'
        . '<button type="button" class="fi-alert-close" data-bs-dismiss="alert" aria-label="Dismiss message">'
        . fiIcon('x', 16) . '</button>'
        . ($autoHide ? '<span class="fi-alert-timer" aria-hidden="true"></span>' : '')
        . '</div>' . "\n";
}
function fiEmptyState(string $title, string $text, string $actionHtml = '', string $mark = '+'): void
{
    echo '<div class="fi-empty">' . "\n";
    echo '<div class="fi-empty-mark" aria-hidden="true">' . fiE($mark) . '</div>' . "\n";
    echo '<h2 class="fi-empty-title">' . fiE($title) . '</h2>' . "\n";
    echo '<p class="fi-empty-text">' . fiE($text) . '</p>' . "\n";
    if ($actionHtml !== '') {
        echo '<div class="mt-3">' . $actionHtml . '</div>' . "\n";
    }
    echo '</div>' . "\n";
}
function fiFooter(string $note = 'Helping students reconnect with their lost belongings.'): void
{
    echo '<footer class="fi-footer">' . "\n";
    echo '<div class="fi-container fi-footer-inner">' . "\n";
    echo '<span>FindIT &middot; Campus Lost &amp; Found</span>' . "\n";
    echo '<span>' . fiE($note) . '</span>' . "\n";
    echo '</div>' . "\n";
    echo '</footer>' . "\n";
}
function fiScripts(string $assetBase = 'assets'): void
{
    echo '<script src="' . fiE($assetBase) . '/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>' . "\n";
    echo '<script src="' . fiE($assetBase) . '/js/app.js"></script>' . "\n";
}

/**
 * Pickup QR code as a PNG image generated by PHP (no JavaScript or extra files needed),
 * with a download link. Used on the student's My claims page and the admin claim page.
 */
function fiQrBlock(string $token, string $fileName, string $caption, string $note = ''): void
{
    require_once __DIR__ . '/qr.php';

    echo '<span class="fi-field-label">Pickup QR code</span>' . "\n";

    try {
        $uri = FiQr::pngDataUri($token);
    } catch (Throwable $e) {
        error_log('[FindIT] QR generation failed: ' . $e->getMessage());
        echo '<p class="text-danger small">The QR code could not be generated.</p>' . "\n";
        return;
    }

    $safeName = preg_replace('/[^A-Za-z0-9._-]+/', '-', $fileName) ?: 'FindIT-pickup-qr';

    echo '<div style="display:flex;flex-wrap:wrap;align-items:center;gap:18px;margin-top:6px;">' . "\n";
    echo '<img src="' . $uri . '" width="220" height="220" alt="Pickup QR code for ' . fiE($caption) . '" '
        . 'style="width:220px;height:220px;max-width:100%;background:#fff;border:1px solid #d9d4c3;'
        . 'border-radius:12px;padding:6px;image-rendering:pixelated;">' . "\n";
    echo '<div style="flex:1 1 220px;">' . "\n";
    if ($note !== '') {
        echo '<p class="fi-field-value mb-2">' . fiE($note) . '</p>' . "\n";
    }
    echo '<a class="btn btn-primary btn-sm" href="' . $uri . '" download="' . fiE($safeName) . '.png">'
        . 'Download QR Code</a>' . "\n";
    echo '</div>' . "\n";
    echo '</div>' . "\n";
}

/**
 * Small inline SVG icons (stroke style, they take the text colour).
 * Only names from this list are drawn; anything else falls back to "info".
 */
function fiIcon(string $name, int $size = 18): string
{
    $paths = [
        'bell' => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
        'check' => '<path d="M20 6 9 17l-5-5"/>',
        'check-circle' => '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
        'x-circle' => '<circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/>',
        'x' => '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
        'clock' => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'package' => '<path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5"/><path d="M12 22V12"/>',
        'alert-triangle' => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'info' => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'database' => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/>',
        'inbox' => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
        'lock' => '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
        'mail' => '<rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/>',
        'shield-check' => '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
    ];
    $body = $paths[$name] ?? $paths['info'];
    $size = max(12, min(48, $size));

    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size
        . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" focusable="false">' . $body . '</svg>';
}

/**
 * The bell + dropdown shared by students (their notifications) and admins (live alerts).
 * Options: mode, endpoint, csrf, count, severity, itemsHtml, extra (data attributes),
 * title, footerHref, footerLabel, emptyTitle, emptyText, active.
 */
function fiBellMarkup(array $o): string
{
    $count = (int) $o['count'];
    $badge = $count > 99 ? '99+' : (string) $count;
    $noun = $o['mode'] === 'admin' ? 'alerts' : 'unread notifications';
    $label = $o['title'] . ($count > 0 ? ', ' . $count . ' ' . $noun : ', nothing new');
    $hasItems = $o['itemsHtml'] !== '';

    $html = '<div class="fi-bell dropdown' . (!empty($o['active']) ? ' is-current' : '') . '" data-fi-bell'
        . ' data-mode="' . fiE($o['mode']) . '" data-endpoint="' . fiE($o['endpoint']) . '"'
        . ' data-csrf="' . fiE($o['csrf']) . '" data-poll="45" data-title="' . fiE($o['title']) . '"'
        . $o['extra'] . '>';

    $html .= '<a href="' . fiE($o['footerHref']) . '" class="fi-bell-btn" id="fiBellBtn-' . fiE($o['mode']) . '"'
        . ' role="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false"'
        . ' aria-haspopup="true" aria-label="' . fiE($label) . '" data-bell-button>'
        . fiIcon('bell', 22)
        . '<span class="fi-bell-badge fi-bell-badge--' . fiE($o['severity']) . '" data-bell-badge'
        . ($count > 0 ? '' : ' hidden') . '>' . fiE($badge) . '</span></a>';

    $html .= '<div class="dropdown-menu dropdown-menu-end fi-bell-menu" aria-labelledby="fiBellBtn-' . fiE($o['mode']) . '">';
    $html .= '<div class="fi-bell-head"><h2 class="fi-bell-title">' . fiE($o['title']) . '</h2>';
    $html .= '</div>';

    $html .= '<ul class="fi-notif-list" data-bell-list' . ($hasItems ? '' : ' hidden') . '>' . $o['itemsHtml'] . '</ul>';
    $html .= '<div class="fi-bell-empty" data-bell-empty' . ($hasItems ? ' hidden' : '') . '>'
        . '<span class="fi-bell-empty-icon" aria-hidden="true">' . fiIcon($o['emptyIcon'], 24) . '</span>'
        . '<strong>' . fiE($o['emptyTitle']) . '</strong><span>' . fiE($o['emptyText']) . '</span></div>';
    $html .= '<div class="fi-bell-foot"><a href="' . fiE($o['footerHref']) . '">' . fiE($o['footerLabel']) . '</a></div>';
    $html .= '</div>';
    $html .= '<span class="visually-hidden" role="status" aria-live="polite" data-bell-live></span>';

    return $html . '</div>';
}

/** Bell for a signed-in student. If anything fails the navbar still renders (with no badge). */
function fiStudentBell(?array $user, string $active = ''): string
{
    global $pdo;

    $studentId = (int) ($user['id'] ?? 0);
    $unread = 0;
    $latest = 0;
    $itemsHtml = '';

    if ($studentId > 0 && isset($pdo)) {
        try {
            require_once __DIR__ . '/notifications.php';
            require_once __DIR__ . '/csrf.php';
            $unread = FiNotify::unreadCount($pdo, $studentId);
            $latest = FiNotify::latestId($pdo, $studentId);
            foreach (FiNotify::recent($pdo, $studentId) as $n) {
                $itemsHtml .= FiNotify::renderItem($n, 'menu');
            }
        } catch (Throwable $e) {
            error_log('[FindIT] Failed to load the notification bell: ' . $e->getMessage());
        }
    }

    require_once __DIR__ . '/csrf.php';

    return fiBellMarkup([
        'mode' => 'student',
        'endpoint' => 'notifications-api.php',
        'csrf' => generateCsrfToken(),
        'count' => $unread,
        'severity' => 'danger',
        'itemsHtml' => $itemsHtml,
        'extra' => ' data-latest-id="' . $latest . '"',
        'title' => 'Notifications',
        'footerHref' => 'notifications.php',
        'footerLabel' => 'View all notifications',
        'emptyIcon' => 'inbox',
        'emptyTitle' => 'You are all caught up',
        'emptyText' => 'Decisions about your claims will show up here.',
        'active' => $active === 'notifications',
    ]);
}

/** Bell for admins: live alerts (claims waiting, new reports, backup problems). */
function fiAdminBell(): string
{
    global $pdo;

    $alerts = [];

    if (isset($pdo)) {
        try {
            require_once __DIR__ . '/admin_alerts.php';
            $alerts = FiAdminAlerts::collect($pdo);
        } catch (Throwable $e) {
            error_log('[FindIT] Failed to load admin alerts: ' . $e->getMessage());
        }
    }

    require_once __DIR__ . '/admin_alerts.php';
    $summary = FiAdminAlerts::summary($alerts);

    $itemsHtml = '';
    $counts = [];
    foreach ($alerts as $a) {
        $itemsHtml .= FiAdminAlerts::renderItem($a, 'menu');
        $counts[$a['key']] = (int) $a['count'];
    }

    return fiBellMarkup([
        'mode' => 'admin',
        'endpoint' => 'alerts-api.php',
        'csrf' => '',
        'count' => $summary['count'],
        'severity' => $summary['severity'] === 'danger' ? 'danger' : 'warning',
        'itemsHtml' => $itemsHtml,
        'extra' => ' data-counts="' . fiE(json_encode($counts, JSON_FORCE_OBJECT)) . '"',
        'title' => 'Alerts',
        'footerHref' => 'index.php',
        'footerLabel' => 'Open dashboard',
        'emptyIcon' => 'shield-check',
        'emptyTitle' => 'All clear',
        'emptyText' => 'Nothing needs your attention right now.',
        'active' => false,
    ]);
}
