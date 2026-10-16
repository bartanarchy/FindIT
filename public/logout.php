<?php

require_once dirname(__DIR__) . '/app/controllers/AuthController.php';

$authController = new AuthController();
$authController->logout();

header('Location: login.php');
exit;
