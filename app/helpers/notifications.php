<?php

/**
 * Student notifications for FindIT.
 *
 * - Rows live in public.notification (see schema.sql + migrations 001 and 006).
 * - Title and message are encrypted like the rest of the app (Crypto, column names
 *   "title" and "message"), so everything read here is decrypted before it is shown.
 * - Every query is prepared and always filtered by the owner (id_user).
 * - A notification is created from a real event only, with a dedupe key such as
 *   "claim:12:approved". The unique index makes a repeated event do nothing.
 *
 * Admin alerts are different: they are calculated live (app/helpers/admin_alerts.php).
 */

require_once __DIR__ . '/crypto.php';
require_once __DIR__ . '/ui.php';

final class FiNotify
{
    public const PAGE_SIZE = 10;
    public const DROPDOWN_SIZE = 6;

    public const FILTERS = ['all', 'claims', 'items'];

    /** Known notification types: label, severity and icon. Text is always shown too. */
    private const TYPES = [
        'claim_approved' => ['label' => 'Claim approved', 'severity' => 'success', 'icon' => 'check-circle'],
        'claim_rejected' => ['label' => 'Claim rejected', 'severity' => 'danger', 'icon' => 'x-circle'],
        'claim_review'   => ['label' => 'Under review', 'severity' => 'warning', 'icon' => 'clock'],
        'item_claimed'   => ['label' => 'Item returned', 'severity' => 'success', 'icon' => 'package'],
        'general'        => ['label' => 'Update', 'severity' => 'info', 'icon' => 'info'],
    ];

    // ------------------------------------------------------------- helpers

    public static function meta(string $type): array
    {
        return self::TYPES[$type] ?? self::TYPES['general'];
    }

    /**
     * Only plain internal pages are allowed as a destination, for example
     * "my-claims.php#claim-12" or "item-detail.php?id=5". Anything else
     * (other sites, javascript:, paths with "//" or "..") is rejected.
     */
    public static function safeLink(?string $link): ?string
    {
        $link = trim((string) $link);

        if ($link === '' || strlen($link) > 255) {
            return null;
        }

        $pattern = '/\A[a-z][a-z0-9_-]*\.php(\?[A-Za-z0-9_=&-]*)?(#[A-Za-z0-9_-]+)?\z/';

        return preg_match($pattern, $link) === 1 ? $link : null;
    }

    /** "Just now", "5 min ago", "3 h ago", "2 d ago", or a date for older items. */
    public static function ago(int $seconds, string $fallbackDate = ''): string
    {
        if ($seconds < 60) {
            return 'Just now';
        }
        if ($seconds < 3600) {
            return (int) floor($seconds / 60) . ' min ago';
        }
        if ($seconds < 86400) {
            return (int) floor($seconds / 3600) . ' h ago';
        }
        if ($seconds < 7 * 86400) {
            return (int) floor($seconds / 86400) . ' d ago';
        }

        return $fallbackDate !== '' ? $fallbackDate : (int) floor($seconds / 86400) . ' d ago';
    }

    private const SELECT = '
        SELECT id_notification, id_claim, id_item, type, severity, title, message,
               link, is_read, created_at,
               GREATEST(0, EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - created_at))::bigint) AS age_seconds
        FROM public.notification';

    // -------------------------------------------------------------- create

    /**
     * Save one notification. Call it inside the same transaction as the event.
     * Returns true when a new row was created, false when the same event was
     * already notified (duplicate) - which is not an error.
     */
    public static function create(PDO $pdo, array $data): bool
    {
        // A deleted student has no inbox: skip quietly so the admin's decision still saves.
        if ((int) ($data['user_id'] ?? 0) < 1) {
            return false;
        }

        $type = isset(self::TYPES[$data['type'] ?? '']) ? $data['type'] : 'general';
        $meta = self::meta($type);

        // Inside a transaction a failed statement would cancel everything (PostgreSQL rule).
        // A savepoint keeps a notification problem (for example migration 006 not applied yet)
        // from undoing the claim or the admin's decision it belongs to.
        $inTransaction = $pdo->inTransaction();

        try {
            if ($inTransaction) {
                $pdo->exec('SAVEPOINT fi_notify');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO public.notification
                    (id_user, id_claim, id_item, type, severity, title, message, link, dedupe_key)
                 VALUES
                    (:user_id, :claim_id, :item_id, :type, :severity, :title, :message, :link, :dedupe_key)
                 ON CONFLICT (id_user, dedupe_key) WHERE dedupe_key IS NOT NULL
                 DO NOTHING'
            );

            $stmt->execute([
                'user_id' => (int) $data['user_id'],
                'claim_id' => !empty($data['claim_id']) ? (int) $data['claim_id'] : null,
                'item_id' => !empty($data['item_id']) ? (int) $data['item_id'] : null,
                'type' => $type,
                'severity' => $meta['severity'],
                'title' => Crypto::protect('title', mb_substr((string) $data['title'], 0, 150)),
                'message' => Crypto::protect('message', mb_substr((string) $data['message'], 0, 1000)),
                'link' => self::safeLink($data['link'] ?? null),
                'dedupe_key' => isset($data['dedupe_key']) ? substr((string) $data['dedupe_key'], 0, 120) : null,
            ]);

            $created = $stmt->rowCount() === 1;

            if ($inTransaction) {
                $pdo->exec('RELEASE SAVEPOINT fi_notify');
            }

            return $created;
        } catch (Throwable $e) {
            if ($inTransaction) {
                try {
                    $pdo->exec('ROLLBACK TO SAVEPOINT fi_notify');
                } catch (Throwable $inner) {
                    // The outer transaction is already unusable; the caller will roll it back.
                }
            }
            error_log('[FindIT] Notification could not be saved: ' . $e->getMessage());

            return false;
        }
    }

    // ---------------------------------------------------------------- read

    public static function unreadCount(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM public.notification WHERE id_user = :u AND is_read = FALSE'
        );
        $stmt->execute(['u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    public static function latestId(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare(
            'SELECT COALESCE(MAX(id_notification), 0) FROM public.notification WHERE id_user = :u'
        );
        $stmt->execute(['u' => $userId]);

        return (int) $stmt->fetchColumn();
    }

    /** Newest notifications for the bell dropdown (read and unread). */
    public static function recent(PDO $pdo, int $userId, int $limit = self::DROPDOWN_SIZE): array
    {
        $stmt = $pdo->prepare(
            self::SELECT . '
             WHERE id_user = :u
             ORDER BY created_at DESC, id_notification DESC
             LIMIT ' . max(1, min(20, $limit))
        );
        $stmt->execute(['u' => $userId]);

        return array_map([self::class, 'present'], Crypto::decryptRows($stmt->fetchAll()));
    }

    /** Unread notifications newer than $sinceId (used by polling to show a toast). */
    public static function unreadSince(PDO $pdo, int $userId, int $sinceId, int $limit = 3): array
    {
        $stmt = $pdo->prepare(
            self::SELECT . '
             WHERE id_user = :u AND is_read = FALSE AND id_notification > :since
             ORDER BY id_notification DESC
             LIMIT ' . max(1, min(5, $limit))
        );
        $stmt->execute(['u' => $userId, 'since' => $sinceId]);

        return array_map([self::class, 'present'], Crypto::decryptRows($stmt->fetchAll()));
    }

    private static function filterSql(string $filter): string
    {
        switch ($filter) {
            case 'unread':
                return ' AND is_read = FALSE';
            case 'claims':
                // Older rows (before migration 006) have type "general" but belong to a claim.
                return " AND (type LIKE 'claim\\_%' OR (type = 'general' AND id_claim IS NOT NULL))";
            case 'items':
                return " AND type LIKE 'item\\_%'";
            default:
                return '';
        }
    }

    /** One page of notifications with server-side filtering. */
    public static function page(PDO $pdo, int $userId, string $filter, int $page): array
    {
        $filter = in_array($filter, self::FILTERS, true) ? $filter : 'all';
        $where = ' WHERE id_user = :u' . self::filterSql($filter);

        $countStmt = $pdo->prepare('SELECT COUNT(*) FROM public.notification' . $where);
        $countStmt->execute(['u' => $userId]);
        $total = (int) $countStmt->fetchColumn();

        $pages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * self::PAGE_SIZE;

        $stmt = $pdo->prepare(
            self::SELECT . $where . '
             ORDER BY created_at DESC, id_notification DESC
             LIMIT ' . self::PAGE_SIZE . ' OFFSET ' . $offset
        );
        $stmt->execute(['u' => $userId]);

        return [
            'rows' => array_map([self::class, 'present'], Crypto::decryptRows($stmt->fetchAll())),
            'total' => $total,
            'pages' => $pages,
            'page' => $page,
            'filter' => $filter,
        ];
    }

    /** Numbers for the filter tabs. */
    public static function counts(PDO $pdo, int $userId): array
    {
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS total,
                    COUNT(*) FILTER (WHERE is_read = FALSE) AS unread,
                    COUNT(*) FILTER (WHERE type LIKE 'claim\\_%'
                                     OR (type = 'general' AND id_claim IS NOT NULL)) AS claims,
                    COUNT(*) FILTER (WHERE type LIKE 'item\\_%') AS items
             FROM public.notification
             WHERE id_user = :u"
        );
        $stmt->execute(['u' => $userId]);
        $row = $stmt->fetch() ?: [];

        return [
            'all' => (int) ($row['total'] ?? 0),
            'unread' => (int) ($row['unread'] ?? 0),
            'claims' => (int) ($row['claims'] ?? 0),
            'items' => (int) ($row['items'] ?? 0),
        ];
    }

    /** One notification, only if it belongs to this student. */
    public static function findOwn(PDO $pdo, int $userId, int $id): ?array
    {
        $stmt = $pdo->prepare(self::SELECT . ' WHERE id_notification = :id AND id_user = :u');
        $stmt->execute(['id' => $id, 'u' => $userId]);
        $row = $stmt->fetch();

        return $row ? self::present(Crypto::decryptRow($row)) : null;
    }

    // --------------------------------------------------------------- write

    /** The owner check is part of the UPDATE, so another student's ID changes nothing. */
    public static function markRead(PDO $pdo, int $userId, int $id): bool
    {
        $stmt = $pdo->prepare(
            'UPDATE public.notification
             SET is_read = TRUE, read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE id_notification = :id AND id_user = :u AND is_read = FALSE'
        );
        $stmt->execute(['id' => $id, 'u' => $userId]);

        return $stmt->rowCount() === 1;
    }

    public static function markAllRead(PDO $pdo, int $userId): int
    {
        $stmt = $pdo->prepare(
            'UPDATE public.notification
             SET is_read = TRUE, read_at = COALESCE(read_at, CURRENT_TIMESTAMP)
             WHERE id_user = :u AND is_read = FALSE'
        );
        $stmt->execute(['u' => $userId]);

        return $stmt->rowCount();
    }

    // -------------------------------------------------------- presentation

    /** Turn a decrypted database row into a clean array for output. */
    public static function present(array $row): array
    {
        $type = (string) ($row['type'] ?? 'general');
        $title = (string) ($row['title'] ?? '');

        // Rows created before migration 006 have no type: work it out from the title.
        if ($type === 'general') {
            if (stripos($title, 'approved') !== false) {
                $type = 'claim_approved';
            } elseif (stripos($title, 'rejected') !== false) {
                $type = 'claim_rejected';
            }
        }

        $meta = self::meta($type);
        $claimId = (int) ($row['id_claim'] ?? 0);
        $itemId = (int) ($row['id_item'] ?? 0);

        $link = self::safeLink($row['link'] ?? null);
        if ($link === null && $claimId > 0) {
            $link = 'my-claims.php#claim-' . $claimId;
        } elseif ($link === null && $itemId > 0) {
            $link = 'item-detail.php?id=' . $itemId;
        }

        $created = (string) ($row['created_at'] ?? '');
        $stamp = $created !== '' ? strtotime($created) : false;
        $dateLabel = $stamp ? date('d M Y', $stamp) : '';

        return [
            'id' => (int) $row['id_notification'],
            'type' => $type,
            'type_label' => $meta['label'],
            'severity' => $meta['severity'],
            'icon' => $meta['icon'],
            'title' => $title,
            'message' => (string) ($row['message'] ?? ''),
            'link' => $link,
            'is_read' => (bool) $row['is_read'],
            'ago' => self::ago((int) ($row['age_seconds'] ?? 0), $dateLabel),
            'created' => $created !== '' ? fiDateTime($created) : '',
        ];
    }

    /** Where clicking a notification goes: marks it as read, then opens the target. */
    public static function openUrl(int $id): string
    {
        return 'notifications.php?open=' . $id;
    }

    /**
     * HTML for one notification. Used by the page, the dropdown and the polling
     * endpoint, so all three look the same. Every value is escaped here.
     *
     * $mode "menu" = compact (bell dropdown), "page" = full (notification page).
     */
    public static function renderItem(array $n, string $mode = 'menu'): string
    {
        $id = (int) $n['id'];
        $unread = !$n['is_read'];
        $sev = fiE($n['severity']);

        $html = '<li class="fi-notif fi-notif--' . $sev . ($unread ? ' is-unread' : '')
            . ($mode === 'page' ? ' fi-notif--page' : '') . '" data-notif-id="' . $id . '">';

        $html .= '<a class="fi-notif-link" href="' . fiE(self::openUrl($id)) . '">';
        $html .= '<span class="fi-notif-icon" aria-hidden="true">' . fiIcon($n['icon'], 20) . '</span>';
        $html .= '<span class="fi-notif-body">';
        $html .= '<span class="fi-notif-meta">';
        $html .= '<span class="fi-notif-type">' . fiE($n['type_label']) . '</span>';
        $html .= '<time class="fi-notif-time" title="' . $n['created'] . '">' . fiE($n['ago']) . '</time>';
        $html .= '</span>';
        $html .= '<span class="fi-notif-title">' . fiE($n['title']) . '</span>';
        $html .= '<span class="fi-notif-msg">' . fiE($n['message']) . '</span>';
        if ($unread) {
            $html .= '<span class="visually-hidden">Unread</span>';
        }
        $html .= '</span>';
        $html .= '</a>';

        // No manual "mark as read": opening the bell or the notification page marks everything as read.
        if ($mode === 'page' && $n['link'] !== null) {
            $html .= '<div class="fi-notif-actions">';
            $html .= '<a class="btn btn-outline-secondary btn-sm" href="' . fiE(self::openUrl($id)) . '">Open</a>';
            $html .= '</div>';
        }

        return $html . '</li>';
    }
}
