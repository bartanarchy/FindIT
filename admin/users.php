<?php
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/app/helpers/auth.php';
require_once dirname(__DIR__) . '/app/helpers/ui.php';

// Admin only. This page is READ-ONLY: there is no edit or delete here, and the
// password hash is never selected from the database.
requireRole('admin');

const USERS_PER_PAGE = 20;

$page = max(1, (int) ($_GET['page'] ?? 1));
$users = [];
$total = 0;
$errorMessage = '';

try {
    $total = (int) $pdo->query('SELECT COUNT(*) FROM public."User"')->fetchColumn();
    $pages = max(1, (int) ceil($total / USERS_PER_PAGE));
    $page = min($page, $pages);

    $stmt = $pdo->prepare('
        SELECT id_user, name_user, id_nim, email_kampus, role_user, created_at
        FROM public."User"
        ORDER BY id_user ASC
        LIMIT :limit OFFSET :offset
    ');
    $stmt->bindValue(':limit', USERS_PER_PAGE, PDO::PARAM_INT);
    $stmt->bindValue(':offset', ($page - 1) * USERS_PER_PAGE, PDO::PARAM_INT);
    $stmt->execute();
    $users = Crypto::decryptRows($stmt->fetchAll());
} catch (PDOException $e) {
    error_log('[FindIT] User Management error: ' . $e->getMessage());
    $errorMessage = 'Unable to load users. Please try again later.';
    $pages = 1;
}

fiHead('User Management | FindIT Admin', '../public/assets');
fiAdminNav('users');
?>
<main class="fi-main" id="main">
    <div class="fi-container">
        <?php
        fiPageHeader(
            'Campus lost & found / Users',
            'User<br><span>management.</span>',
            'A read-only list of registered accounts.',
            number_format($total),
            'Registered users'
        );
        ?>
        <section aria-labelledby="users-heading">
            <div class="fi-section-head">
                <div>
                    <p class="fi-eyebrow mb-1">Accounts</p>
                    <h2 id="users-heading">Users</h2>
                </div>
            </div>

            <?php if ($errorMessage !== ''): ?>
                <?php fiAlert('danger', $errorMessage); ?>
            <?php elseif ($total === 0): ?>
                <div class="fi-empty-boxed">
                    <?php fiEmptyState('No users yet', 'Registered accounts will appear here.'); ?>
                </div>
            <?php else: ?>
                <div class="fi-table-panel">
                    <div class="fi-table-wrap">
                        <table class="table fi-table">
                            <thead>
                                <tr>
                                    <th scope="col">ID</th>
                                    <th scope="col">Name</th>
                                    <th scope="col">NIM</th>
                                    <th scope="col">Email</th>
                                    <th scope="col">Status</th>
                                    <th scope="col">Registered</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $u): ?>
                                    <tr>
                                        <td data-label="ID"><span class="fi-code">#<?= (int) $u['id_user'] ?></span></td>
                                        <td data-label="Name"><span class="fi-cell-name"><?= fiE((string) $u['name_user']) ?></span></td>
                                        <td data-label="NIM"><?= fiOr((string) ($u['id_nim'] ?? ''), '–') ?></td>
                                        <td data-label="Email"><?= fiE((string) $u['email_kampus']) ?></td>
                                        <td data-label="Status"><?php fiBadge((string) $u['role_user'], 'Unknown'); ?></td>
                                        <td data-label="Registered"><?= fiDate((string) ($u['created_at'] ?? '')) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php if ($pages > 1): ?>
                    <nav class="fi-results-bar" aria-label="Pagination">
                        <p>Page <strong><?= $page ?></strong> of <?= $pages ?></p>
                        <div class="d-flex gap-2">
                            <?php if ($page > 1): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="?page=<?= $page - 1 ?>">Previous</a>
                            <?php endif; ?>
                            <?php if ($page < $pages): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="?page=<?= $page + 1 ?>">Next</a>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php fiFooter('Admin workspace'); ?>
<?php fiScripts('../public/assets'); ?>
</body>
</html>
