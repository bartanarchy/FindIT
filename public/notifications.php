<?php

require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/csrf.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';

// Only signed-in students can access their notifications.
requireRole('student');

$user = currentUser();
$userId = (int) ($user['id'] ?? 0);

if ($userId < 1) {
    header('Location: login.php');
    exit;
}

$errorMessage = '';
$notifications = [];
$csrfToken = generateCsrfToken();

// Mark a notification as read only when it belongs to this student.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $notificationId = filter_input(INPUT_POST, 'notification_id', FILTER_VALIDATE_INT);

    if (!is_string($submittedToken) || !verifyCsrfToken($submittedToken)) {
        $errorMessage = 'Your form session has expired. Refresh the page and try again.';
    } elseif (!$notificationId || $notificationId < 1) {
        $errorMessage = 'Invalid notification ID.';
    } else {
        try {
            $updateStmt = $pdo->prepare(
                'UPDATE public.notification
                 SET is_read = TRUE
                 WHERE id_notification = :notification_id
                   AND id_user = :user_id'
            );

            $updateStmt->execute([
                'notification_id' => $notificationId,
                'user_id' => $userId,
            ]);

            // Redirect after saving to prevent the form from being submitted again.
            header('Location: notifications.php');
            exit;
        } catch (PDOException $e) {
            error_log('[FindIT] Failed to update notification: ' . $e->getMessage());
            $errorMessage = 'Unable to update this notification. Please try again.';
        }
    }
}

// Load only this student's notifications, newest first.
try {
    $notificationStmt = $pdo->prepare(
        'SELECT id_notification, id_claim, title, message, is_read, created_at
         FROM public.notification
         WHERE id_user = :user_id
         ORDER BY created_at DESC, id_notification DESC'
    );
    $notificationStmt->execute(['user_id' => $userId]);
    $notifications = $notificationStmt->fetchAll();
} catch (PDOException $e) {
    error_log('[FindIT] Failed to load notifications: ' . $e->getMessage());
    $errorMessage = 'Unable to load notifications. Please try again later.';
}

// Count unread notifications for the page header.
$unreadCount = count(array_filter(
    $notifications,
    static fn(array $notification): bool => !$notification['is_read']
));

// Opening this page counts as reading: mark the notifications that were just loaded as read.
$unreadIds = [];
foreach ($notifications as $notification) {
    if (!$notification['is_read']) {
        $unreadIds[] = (int) $notification['id_notification'];
    }
}

if ($unreadIds !== []) {
    try {
        // One "?" placeholder for each unread notification ID.
        $placeholders = implode(',', array_fill(0, count($unreadIds), '?'));

        $markStmt = $pdo->prepare(
            'UPDATE public.notification
            SET is_read = TRUE
            WHERE id_user = ?
                AND id_notification IN (' . $placeholders . ')'
        );

        $markStmt->execute(array_merge([$userId], $unreadIds));
    } catch (PDOException $e) {
        // The list can still be shown even if marking fails.
        error_log('[FindIT] Failed to mark notifications as read: ' . $e->getMessage());
    }
}

fiHead('Notifications | FindIT');
fiPublicNav($user, 'notifications');

?>

<main class="fi-main" id="main">
    <div class="fi-container">
        <?php
        fiPageHeader(
            'Campus lost & found / Updates',
            'Your <span>notifications.</span>',
            'Stay up to date with decisions about your item claims.',
            (string) $unreadCount,
            'Unread notifications'
        );
        ?>

        <?php if ($errorMessage !== ''): ?>
            <?php fiAlert('danger', $errorMessage); ?>
        <?php endif; ?>

        <?php if (empty($notifications) && $errorMessage === ''): ?>
            <section class="fi-panel">
                <?php
                fiEmptyState(
                    'You are all caught up',
                    'Updates about your claim decisions will appear here.'
                );
                ?>
            </section>
        <?php elseif (!empty($notifications)): ?>
            <section aria-label="Notification list">
                <?php foreach ($notifications as $notification): ?>
                    <article class="fi-panel mb-3">
                        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
                            <h2 class="h5 mb-0"><?= fiE($notification['title']) ?></h2>

                            <?php if (!$notification['is_read']): ?>
                                <span class="badge text-bg-primary">Unread</span>
                            <?php else: ?>
                                <span class="badge text-bg-secondary">Read</span>
                            <?php endif; ?>
                        </div>

                        <p class="mb-2"><?= nl2br(fiE($notification['message'])) ?></p>

                        <p class="small text-secondary mb-3">
                            <?= fiDateTime($notification['created_at']) ?>
                        </p>

                        <div class="d-flex flex-wrap gap-2">
                            <?php if (!empty($notification['id_claim'])): ?>
                                <a class="btn btn-outline-secondary btn-sm" href="my-claims.php">
                                    View my claims
                                </a>
                            <?php endif; ?>

                            <?php if (!$notification['is_read']): ?>
                                <form method="POST" action="notifications.php">
                                    <input type="hidden" name="csrf_token" value="<?= fiE($csrfToken) ?>">
                                    <input type="hidden" name="notification_id" value="<?= (int) $notification['id_notification'] ?>">
                                    <button class="btn btn-primary btn-sm" type="submit">Mark as read</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
    </div>
</main>

<?php fiFooter(); ?>
<?php fiScripts(); ?>

</body>
</html>
