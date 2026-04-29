<?php
require __DIR__ . '/../src/auth.php';
$u = current_user();
if (!$u) { header('Location: /phdportal/login.php'); exit; }
header('Location: ' . ($u['role'] === 'admin' ? '/phdportal/dashboard.php' : '/phdportal/panel/dashboard.php'));
