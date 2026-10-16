
<?php

require_once dirname(__DIR__) . '/app/helpers/auth.php';

// only admin can access this dashboard
requireRole('admin');

$admin = currentUser();

function escapeAdminHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | FindIT</title>

    <link rel="stylesheet" href="../public/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="../public/assets/css/style.css">
</head>
<body class="bg-light">

<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold" href="index.php">FindIT Admin</a>

        <div class="d-flex align-items-center gap-3">
            <span class="text-white">
                <?= escapeAdminHtml($admin['name'] ?? 'Administrator') ?>
            </span>
            <a href="../public/logout.php" class="btn btn-outline-light btn-sm">
                Sign Out
            </a>
        </div>
    </div>
</nav>

<main class="container py-5">
    <div class="mb-4">
        <h1 class="fw-bold">Admin Dashboard</h1>
        <p class="text-secondary mb-0">
            Manage reported items and review student claims.
        </p>
    </div>

    <div class="row g-4">
        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold">Manage Items</h2>
                    <p class="text-secondary">
                        Review and manage items reported by students.
                    </p>
                    <a href="items.php" class="btn btn-primary">
                        View Items
                    </a>
                </div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="card border-0 shadow-sm rounded-4 h-100">
                <div class="card-body p-4">
                    <h2 class="h5 fw-bold">Manage Claims</h2>
                    <p class="text-secondary">
                        Review claim requests submitted by students.
                    </p>
                    <a href="claims.php" class="btn btn-primary">
                        View Claims
                    </a>
                </div>
            </div>
        </div>
    </div>
</main>

<script src="../public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
</body>
</html>
