<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require __DIR__ . '/rejister.php';
    exit;
}

header('Location: frontend/dist/index.html#/signup');
exit;