<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/lagin.php';
    exit;
}

header('Location: frontend/dist/index.html#/');
exit;