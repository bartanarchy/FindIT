<?php

require_once dirname(__DIR__) . '/app/controllers/AuthController.php';
require_once dirname(__DIR__) . '/app/helpers/recovery.php';

// While the database is down the controller cannot be created (it needs the database),
// so the session is cleared directly and the person returns to the emergency sign-in.
if (defined('FINDIT_DB_DOWN')) {
    fiClearSession();
    header('Location: recovery-login.php');
    exit;
}

$authController = new AuthController();
$authController->logout();

header('Location: login.php');
exit;
