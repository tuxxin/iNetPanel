<?php
// FILE: TiCore/DB.php
// TiCore PHP Framework - SQLite PDO Singleton
// Part of iNetPanel | https://github.com/tuxxin/iNetPanel

class DB
{
    private static ?DB $instance = null;
    private PDO $pdo;

    private function __construct()
    {
        $config = App::getInstance()->getConfig();
        $dbPath = $config->get('DB_PATH', '../db/inetpanel.db');

        // Resolve relative path from TiCore/ directory
        if (!str_starts_with($dbPath, '/')) {
            $dbPath = dirname(__DIR__) . '/' . ltrim($dbPath, './');
        }

        $dir = dirname($dbPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $this->pdo = new PDO('sqlite:' . $dbPath);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
    }

    public static function getInstance(): DB
    {
        if (self::$instance === null) {
            self::$instance = new DB();
        }
        return self::$instance;
    }

    public static function get(): PDO
    {
        return self::getInstance()->pdo;
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::get()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetchAll(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function fetchOne(string $sql, array $params = []): array|false
    {
        return self::query($sql, $params)->fetch();
    }

    public static function insert(string $table, array $data): int|false
    {
        $cols = implode(', ', array_keys($data));
        $phs  = implode(', ', array_fill(0, count($data), '?'));
        $stmt = self::get()->prepare("INSERT INTO {$table} ({$cols}) VALUES ({$phs})");
        $stmt->execute(array_values($data));
        return (int) self::get()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $set = implode(', ', array_map(fn($k) => "{$k} = ?", array_keys($data)));
        $stmt = self::get()->prepare("UPDATE {$table} SET {$set} WHERE {$where}");
        $stmt->execute([...array_values($data), ...$whereParams]);
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int
    {
        $stmt = self::get()->prepare("DELETE FROM {$table} WHERE {$where}");
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    public static function setting(string $key, mixed $default = null): mixed
    {
        $row = self::fetchOne('SELECT value FROM settings WHERE key = ?', [$key]);
        return $row ? $row['value'] : $default;
    }

    public static function saveSetting(string $key, mixed $value): void
    {
        self::query(
            'INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value=excluded.value',
            [$key, $value]
        );
    }
}
