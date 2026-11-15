<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';
require_once dirname(__DIR__) . '/app/helpers/notifications.php';

// Only signed-in students can access their notifications.
requireRole('student');

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);

if ($userId < 1) {
    header('Location: login.php');
    exit;
}

// Filter and page come from the URL; anything unexpected falls back to the defaults.
$filter = $_GET['f'] ?? 'all';
$filter = is_string($filter) && in_array($filter, FiNotify::FILTERS, true) ? $filter : 'all';
$pageNumber = filter_input(INPUT_GET, 'p', FILTER_VALIDATE_INT);
$pageNumber = ($pageNumber && $pageNumber > 0) ? $pageNumber : 1;

$query = static function (string $f, int $p): string {
    $parts = [];
    if ($f !== 'all') {
        $parts['f'] = $f;
    }
    if ($p > 1) {
        $parts['p'] = $p;
    }
    return $parts ? '?' . http_build_query($parts) : '';
};

// Clicking a notification: mark it as read, then open the claim or item it is about.
// Only the owner's own notification can be opened, so another ID just returns to the list.
$openId = filter_input(INPUT_GET, 'open', FILTER_VALIDATE_INT);
if ($openId && $openId > 0) {
    $target = 'notifications.php';

    try {
        $own = FiNotify::findOwn($pdo, $userId, $openId);
        if ($own) {
            FiNotify::markRead($pdo, $userId, $openId);
            // present() already validated the link; check once more before redirecting.
            $target = FiNotify::safeLink($own['link']) ?? $target;
        }
    } catch (Throwable $e) {
        error_log('[FindIT] Failed to open a notification: ' . $e->getMessage());
    }

    header('Location: ' . $target);
    exit;
}

$errorMessage = '';
$result = ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'filter' => $filter];
$counts = ['all' => 0, 'unread' => 0, 'claims' => 0, 'items' => 0];

try {
    $result = FiNotify::page($pdo, $userId, $filter, $pageNumber);
    $counts = FiNotify::counts($pdo, $userId);

    // Opening this page means the student has seen their notifications: mark them all as read.
    // The rows above were loaded first, so unread ones still look "new" on this visit only.
    if ($counts['unread'] > 0) {
        FiNotify::markAllRead($pdo, $userId);
    }
} catch (Throwable $e) {
    error_log('[FindIT] Failed to load notifications: ' . $e->getMessage());
    $errorMessage = 'Unable to load notifications. Please try again later.';
}

$tabs = [
    'all' => 'All',
    'claims' => 'Claims',
    'items' => 'Items',
];

$emptyText = [
    'all' => ['You are all caught up', 'Updates about your claims and reported items will appear here.'],
    'claims' => ['No claim updates yet', 'When an admin reviews one of your claims, you will see it here.'],
    'items' => ['No item updates yet', 'You will be told here when an item you reported is returned to its owner.'],
];

$current = $result['filter'];

fiHead('Notifications | FindIT');
fiPublicNav($user, 'notifications');

?>

<main class="fi-main" id="main">
    <div class="fi-container">
        <?php
        fiPageHeader(
            'Campus lost & found / Updates',
            'Your <span>notifications.</span>',
            'Stay up to date with decisions about your claims and the items you reported.',
            (string) $counts['unread'],
            'New this visit'
        );
        ?>

        <?php if ($errorMessage !== ''): ?>
            <?php fiAlert('danger', $errorMessage); ?>
        <?php endif; ?>

        <section class="fi-notif-toolbar" aria-label="Filter notifications">
            <nav class="fi-notif-tabs" aria-label="Notification filters">
                <?php foreach ($tabs as $key => $label): ?>
                    <a class="fi-notif-tab<?= $current === $key ? ' is-active' : '' ?>"
                       href="notifications.php<?= fiE($query($key, 1)) ?>"
                       <?= $current === $key ? 'aria-current="page"' : '' ?>>
                        <?= fiE($label) ?>
                        <span class="fi-notif-tab-count"><?= (int) $counts[$key] ?></span>
                    </a>
                <?php endforeach; ?>
            </nav>

        </section>

        <?php if (empty($result['rows']) && $errorMessage === ''): ?>
            <section class="fi-panel">
                <?php
                fiEmptyState(
                    $emptyText[$current][0],
                    $emptyText[$current][1],
                    $current !== 'all' ? '<a class="btn btn-outline-secondary btn-sm" href="notifications.php">Show all notifications</a>' : ''
                );
                ?>
            </section>
        <?php elseif (!empty($result['rows'])): ?>
            <section aria-label="Notification list">
                <ul class="fi-notif-list fi-notif-list--page">
                    <?php foreach ($result['rows'] as $n): ?>
                        <?= FiNotify::renderItem($n, 'page') ?>
                    <?php endforeach; ?>
                </ul>
            </section>

            <?php if ($result['pages'] > 1): ?>
                <?php
                $from = max(1, $result['page'] - 2);
                $to = min($result['pages'], $result['page'] + 2);
                ?>
                <nav class="fi-pager" aria-label="Notification pages">
                    <?php if ($result['page'] > 1): ?>
                        <a class="fi-pager-link" href="notifications.php<?= fiE($query($current, $result['page'] - 1)) ?>" rel="prev">Previous</a>
                    <?php endif; ?>
                    <?php for ($i = $from; $i <= $to; $i++): ?>
                        <a class="fi-pager-link<?= $i === $result['page'] ? ' is-active' : '' ?>"
                           href="notifications.php<?= fiE($query($current, $i)) ?>"
                           <?= $i === $result['page'] ? 'aria-current="page"' : '' ?>><?= $i ?></a>
                    <?php endfor; ?>
                    <?php if ($result['page'] < $result['pages']): ?>
                        <a class="fi-pager-link" href="notifications.php<?= fiE($query($current, $result['page'] + 1)) ?>" rel="next">Next</a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts(); ?>

</body>
</html>
