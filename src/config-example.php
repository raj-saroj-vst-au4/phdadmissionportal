<?php
// PhD Admissions Portal - config (EXAMPLE)
// Copy this file to `src/config.php` and fill in real credentials.
// `src/config.php` is gitignored; never commit it.

define('DB_HOST', 'localhost');
define('DB_NAME', 'phdadmissions');
define('DB_USER', 'CHANGE_ME');
define('DB_PASS', 'CHANGE_ME');
define('DB_CHARSET', 'utf8mb4');

define('APP_NAME', 'SJMSOM PhD Admissions Portal');
define('APP_BASE', '/phdportal'); // URL prefix; set to '' if served at domain root
define('APP_ROOT', dirname(__DIR__));
define('PUBLIC_ROOT', APP_ROOT . '/public');
define('UPLOAD_EXCEL_DIR', PUBLIC_ROOT . '/uploads/excel');
define('UPLOAD_CBT_DIR', PUBLIC_ROOT . '/uploads/cbt');
define('UPLOAD_APP_DIR', PUBLIC_ROOT . '/uploads/applications');
define('UPLOAD_PHOTO_DIR', PUBLIC_ROOT . '/uploads/photos');

define('PYTHON_BIN', '/usr/bin/python3');
define('EXTRACT_SCRIPT', APP_ROOT . '/scripts/extract_excel.py');

define('FINAL_CATEGORIES', ['TA/RA','SF','EX','CT','TAP','FA','SW']);
define('BIRTH_CATEGORIES', ['GN','OBC-NC','SC','ST','EWS','PWD']);

// SMTP Mailer settings
define('SMTP_HOST',     'smtp.example.com');
define('SMTP_PORT',     587);
define('SMTP_USER',     'CHANGE_ME');
define('SMTP_PASS',     'CHANGE_ME');
define('SMTP_SECURE',   'tls');       // tls | ssl | ''
define('SMTP_FROM',     'noreply@example.com');
define('SMTP_FROM_NAME','SJMSOM PhD Admissions');

// Random delay range between successive emails (in milliseconds, handled client-side)
define('MAIL_DELAY_MIN_MS', 1500);
define('MAIL_DELAY_MAX_MS', 7500);

session_start();
date_default_timezone_set('Asia/Kolkata');
