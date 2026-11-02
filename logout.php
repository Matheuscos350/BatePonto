<?php
declare(strict_types=1);
require __DIR__ . '/config.php';
$_SESSION = [];
session_destroy();
header('Location: index.php');
