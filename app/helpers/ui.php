<?php



/**
 * Escape any value for safe output inside HTML.
 */
function fiE($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

/**
 * Escape a value, or return a fallback text when the value is empty.
 */
function fiOr($value, string $fallback = 'Not provided'): string
{
    $text = trim((string) ($value ?? ''));

    return $text !== '' ? fiE($text) : fiE($fallback);
}

/**
 * Format a date such as 2026-10-17 as "17 Oct 2026".
 */
function fiDate($value, string $fallback = 'Not specified'): string
{
    $text = trim((string) ($value ?? ''));

    if ($text === '') {
        return fiE($fallback);
    }

    $timestamp = strtotime($text);

    return $timestamp ? fiE(date('d M Y', $timestamp)) : fiE($text);
}

/**
 * Format a timestamp such as 2026-10-17 09:39:00 as "17 Oct 2026, 09:39".
 */
function fiDateTime($value, string $fallback = 'Not available'): string
{
    $text = trim((string) ($value ?? ''));

    if ($text === '') {
        return fiE($fallback);
    }

    $timestamp = strtotime($text);

    return $timestamp ? fiE(date('d M Y, H:i', $timestamp)) : fiE($text);
}

/**
 * Pick the badge color for an item or claim status.
 */
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

/**
 * Print a status badge.
 */
function fiBadge(?string $status, string $fallback = 'Not set'): void
{
    $label = trim((string) $status) !== '' ? (string) $status : $fallback;

    echo '<span class="fi-badge ' . fiStatusClass($status) . '">'
        . fiE($label) . '</span>';
}

/**
 * Print the document start: doctype, head, and the opening body tag.
 * $assetBase is "assets" for public pages and "../public/assets" for admin pages.
 */
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
    echo '    <link rel="stylesheet" href="' . fiE($assetBase) . '/vendor/bootstrap/css/bootstrap.min.css">' . "\n";
    echo '    <link rel="stylesheet" href="' . fiE($assetBase) . '/css/style.css">' . "\n";
    echo '</head>' . "\n";
    echo '<body class="' . fiE($bodyClasses) . '">' . "\n";
    echo '<a class="fi-skip-link" href="#main">Skip to content</a>' . "\n";
}

/**
 * Print the top navigation used on every student-facing page.
 * $active can be: home, report, claims.
 */
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
    echo '<a class="fi-brand" href="index.php">FindIT<span>.</span></a>' . "\n";
    echo '<button class="navbar-toggler fi-toggler" type="button" data-bs-toggle="collapse" '
        . 'data-bs-target="#fiNav" aria-controls="fiNav" aria-expanded="false" '
        . 'aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>' . "\n";
    echo '<div class="collapse navbar-collapse" id="fiNav">' . "\n";
    echo '<ul class="navbar-nav ms-auto align-items-md-center gap-md-1">' . "\n";

    echo $link('index.php', 'Home', 'home', $active) . "\n";

    if ($role === 'student') {
        echo $link('report-item.php', 'Report found item', 'report', $active) . "\n";
        echo $link('my-claims.php', 'My claims', 'claims', $active) . "\n";
        echo '<li class="nav-item d-none d-lg-block"><span class="fi-nav-user">'
            . fiE($user['name'] ?? 'Student') . '</span></li>' . "\n";
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

/**
 * Print the top navigation used on every admin page.
 * $active can be: dashboard, items, claims.
 */
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
    echo '<a class="fi-brand" href="index.php">FindIT<span>.</span>'
        . '<small class="fi-brand-tag">Admin</small></a>' . "\n";
    echo '<button class="navbar-toggler fi-toggler" type="button" data-bs-toggle="collapse" '
        . 'data-bs-target="#fiNav" aria-controls="fiNav" aria-expanded="false" '
        . 'aria-label="Toggle navigation"><span class="navbar-toggler-icon"></span></button>' . "\n";
    echo '<div class="collapse navbar-collapse" id="fiNav">' . "\n";
    echo '<ul class="navbar-nav ms-auto align-items-md-center gap-md-1">' . "\n";
    echo $link('index.php', 'Dashboard', 'dashboard', $active) . "\n";
    echo $link('items.php', 'Items', 'items', $active) . "\n";
    echo $link('claims.php', 'Claims', 'claims', $active) . "\n";
    echo '<li class="nav-item"><a class="nav-link" href="../public/index.php">View site</a></li>' . "\n";
    echo '<li class="nav-item ms-md-2"><a class="btn btn-outline-secondary btn-sm" href="../public/logout.php">Sign out</a></li>' . "\n";
    echo '</ul>' . "\n";
    echo '</div>' . "\n";
    echo '</div>' . "\n";
    echo '</nav>' . "\n";
    echo '</header>' . "\n";
}

/**
 * Print the large page title with an optional count card.
 * $titleHtml may contain trusted markup such as <span>...</span>.
 */
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

/**
 * Print a consistent alert message.
 * $type can be: success, danger, warning, info.
 */
function fiAlert(string $type, string $message, bool $autoHide = false): void
{
    $allowed = ['success', 'danger', 'warning', 'info'];
    $type = in_array($type, $allowed, true) ? $type : 'info';
    $role = $type === 'danger' ? 'alert' : 'status';
    $hide = $autoHide ? ' data-autohide="6000"' : '';

    echo '<div class="alert alert-' . $type . ' fi-alert" role="' . $role . '"' . $hide . '>'
        . '<span class="fi-alert-dot" aria-hidden="true"></span>'
        . '<div class="fi-alert-text">' . fiE($message) . '</div>'
        . '</div>' . "\n";
}

/**
 * Print an empty state card. $actionHtml may contain a trusted button or link.
 */
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

/**
 * Print the page footer.
 */
function fiFooter(string $note = 'Helping students reconnect with their lost belongings.'): void
{
    echo '<footer class="fi-footer">' . "\n";
    echo '<div class="fi-container fi-footer-inner">' . "\n";
    echo '<span>FindIT &middot; Campus Lost &amp; Found</span>' . "\n";
    echo '<span>' . fiE($note) . '</span>' . "\n";
    echo '</div>' . "\n";
    echo '</footer>' . "\n";
}

/**
 * Print the shared script tags. Close body and html in the page itself.
 */
function fiScripts(string $assetBase = 'assets'): void
{
    echo '<script src="' . fiE($assetBase) . '/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>' . "\n";
    echo '<script src="' . fiE($assetBase) . '/js/app.js"></script>' . "\n";
}
