<?php
require_once __DIR__ . '/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function one(string $sql, array $params = []) {
    return q($sql, $params)->fetch();
}

function all(string $sql, array $params = []): array {
    return q($sql, $params)->fetchAll();
}

function setting(string $key, $default = null) {
    $r = one('SELECT `value` FROM settings WHERE `key` = ?', [$key]);
    return $r ? $r['value'] : $default;
}

function set_setting(string $key, $value): void {
    q('INSERT INTO settings(`key`,`value`) VALUES(?,?) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`)',
        [$key, (string)$value]);
}

function active_intake() {
    return one('SELECT * FROM intakes WHERE is_active = 1 ORDER BY id DESC LIMIT 1');
}
