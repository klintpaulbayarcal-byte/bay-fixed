<?php
require_once __DIR__ . '/app_bootstrap.php';
start_app_session();
session_destroy();

header('Location: frontend/dist/index.html#/');
exit;