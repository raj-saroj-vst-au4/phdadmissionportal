<?php
require __DIR__ . '/../src/auth.php';
logout();
header('Location: /phdportal/login.php');
