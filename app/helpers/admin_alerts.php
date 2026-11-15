<?php

/**
 * Admin alerts for FindIT.
 *
 * Admins are in their own table (public.admin), so alerts are NOT stored as rows.
 * They are worked out live from the current state of the system:
 *
 *   - claims waiting for a decision (and how long the oldest has waited)
 *   - new found-item reports in the last 24 hours
 *   - the latest backup run, when it failed or only partly worked
 *
 * An alert disappears as soon as the problem is fixed, so nothing can go stale and
 * routine actions (such as the admin's own approve/reject) never create noise.
 * Nothing internal (error text, paths, credentials) is ever put in an alert.
 */

require_once __DIR__ . '/ui.php';

final class FiAdminAlerts
{
    /** A pending claim older than this is shown as urgent. */
    public const OVERDUE_HOURS = 72;

    /** @return array<int, array<string, mixed>> most important first */
    public static function collect(PDO $pdo): array
    {
        $alerts = [];

        // 1. Claims waiting for review.
        try {
            $row = $pdo->query(
                "SELECT
                    COUNT(*) AS pending,
                    COUNT(*) FILTER (WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL '24 hours') AS pending_new,
                    COALESCE(EXTRACT(EPOCH FROM (CURRENT_TIMESTAMP - MIN(created_at)))::bigint, 0) AS oldest_seconds,
                    MIN(id_claim) AS first_id
                 FROM public.claim
                 WHERE status_klaim = 'Pending Verification'"
            )->fetch();

            $pending = (int) ($row['pending'] ?? 0);

            if ($pending > 0) {
                $new = (int) $row['pending_new'];
                $oldestHours = (int) floor(((int) $row['oldest_seconds']) / 3600);
                $overdue = $oldestHours >= self::OVERDUE_HOURS;

                $parts = [];
                if ($new > 0) {
                    $parts[] = $new . ' submitted in the last 24 hours';
                }
                if ($oldestHours >= 24) {
                    $parts[] = 'the oldest has waited ' . (int) floor($oldestHours / 24) . ' days';
                }

                $alerts[] = [
                    'key' => 'claims-pending',
                    'count' => $pending,
                    'severity' => $overdue ? 'danger' : 'warning',
                    'icon' => $overdue ? 'alert-triangle' : 'clock',
                    'label' => $overdue ? 'Overdue' : 'Needs review',
                    'title' => $pending === 1 ? '1 claim is waiting for review' : $pending . ' claims are waiting for review',
                    'message' => $parts ? ucfirst(implode(', ', $parts)) . '.' : 'Compare the evidence and approve or reject.',
                    // One waiting claim opens directly; several open the claim list.
                    'link' => $pending === 1 ? 'claim-detail.php?id=' . (int) $row['first_id'] : 'claims.php',
                    'action' => 'Review claims',
                ];
            }
        } catch (Throwable $e) {
            error_log('[FindIT] Admin alert (claims) failed: ' . $e->getMessage());
        }

        // 2. New item reports.
        try {
            $newItems = (int) $pdo->query(
                "SELECT COUNT(*) FROM public.item
                 WHERE created_at >= CURRENT_TIMESTAMP - INTERVAL '24 hours'"
            )->fetchColumn();

            if ($newItems > 0) {
                $alerts[] = [
                    'key' => 'items-new',
                    'count' => $newItems,
                    'severity' => 'info',
                    'icon' => 'package',
                    'label' => 'New reports',
                    'title' => $newItems === 1 ? '1 new found item reported' : $newItems . ' new found items reported',
                    'message' => 'Reported in the last 24 hours. Check the details look right.',
                    'link' => 'items.php',
                    'action' => 'View items',
                ];
            }
        } catch (Throwable $e) {
            error_log('[FindIT] Admin alert (items) failed: ' . $e->getMessage());
        }

        // 3. Backup problems (read from the backup log, not from the database).
        $backup = self::backupAlert();
        if ($backup !== null) {
            $alerts[] = $backup;
        }

        // Most urgent first, keeping the order above inside the same severity.
        $rank = ['danger' => 0, 'warning' => 1, 'info' => 2];
        usort($alerts, static function (array $a, array $b) use ($rank): int {
            return $rank[$a['severity']] <=> $rank[$b['severity']];
        });

        return $alerts;
    }

    private static function backupAlert(): ?array
    {
        // Loading the backup system sets the PHP timezone; keep this page's timezone as it was.
        $zone = date_default_timezone_get();

        try {
            require_once dirname(__DIR__) . '/backup/bootstrap.php';
            $runs = BackupStore::listRuns(1);
        } catch (Throwable $e) {
            // No backup folder or settings yet: nothing reliable to report.
            return null;
        } finally {
            @date_default_timezone_set($zone);
        }

        $run = $runs[0] ?? null;
        if (!is_array($run)) {
            return null;
        }

        $status = (string) ($run['status'] ?? '');

        if ($status === 'FAILED') {
            return [
                'key' => 'backup-failed',
                'count' => 1,
                'severity' => 'danger',
                'icon' => 'database',
                'label' => 'Backup failed',
                'title' => 'The latest backup failed',
                'message' => 'No new backup was saved. Open the backup page to see what happened and run it again.',
                'link' => 'backups.php',
                'action' => 'Open backups',
            ];
        }

        // A local-only backup is normal when no remote copy is configured; only warn on a real failure.
        if ($status === 'PARTIAL_SUCCESS' && !in_array((string) ($run['remote']['status'] ?? ''), ['NOT_CONFIGURED', 'SKIPPED'], true)) {
            return [
                'key' => 'backup-partial',
                'count' => 1,
                'severity' => 'warning',
                'icon' => 'database',
                'label' => 'Backup incomplete',
                'title' => 'The latest backup has only one copy',
                'message' => 'It was saved locally, but the second copy was not confirmed.',
                'link' => 'backups.php',
                'action' => 'Open backups',
            ];
        }

        return null;
    }

    /** HTML for one alert in the bell dropdown or on the dashboard. All values are escaped. */
    public static function renderItem(array $a, string $mode = 'menu'): string
    {
        $sev = fiE($a['severity']);

        $html = '<li class="fi-notif fi-notif--' . $sev . ($mode === 'card' ? ' fi-notif--page' : '')
            . ' is-alert" data-alert-key="' . fiE($a['key']) . '">';
        $html .= '<a class="fi-notif-link" href="' . fiE($a['link']) . '">';
        $html .= '<span class="fi-notif-icon" aria-hidden="true">' . fiIcon($a['icon'], 20) . '</span>';
        $html .= '<span class="fi-notif-body">';
        $html .= '<span class="fi-notif-meta"><span class="fi-notif-type">' . fiE($a['label']) . '</span></span>';
        $html .= '<span class="fi-notif-title">' . fiE($a['title']) . '</span>';
        $html .= '<span class="fi-notif-msg">' . fiE($a['message']) . '</span>';
        $html .= '</span></a>';

        if ($mode === 'card') {
            $html .= '<div class="fi-notif-actions"><a class="btn btn-outline-secondary btn-sm" href="'
                . fiE($a['link']) . '">' . fiE($a['action']) . '</a></div>';
        }

        return $html . '</li>';
    }

    /** Short summary used for the bell badge colour and screen readers. */
    public static function summary(array $alerts): array
    {
        $severity = 'info';
        foreach ($alerts as $a) {
            if ($a['severity'] === 'danger') {
                $severity = 'danger';
                break;
            }
            if ($a['severity'] === 'warning') {
                $severity = 'warning';
            }
        }

        // Only alerts that need an action count towards the badge.
        $actionable = count(array_filter($alerts, static fn(array $a): bool => $a['severity'] !== 'info'));

        return ['count' => $actionable, 'severity' => $severity];
    }
}
