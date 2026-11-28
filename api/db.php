<?php
/**
 * Database connection, schema migrations, and rate-limit helper
 * Supports SQLite (default, zero-configuration) and MySQL/MariaDB
 */

function getDatabaseConnection(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        if (!empty($config['use_mysql'])) {
            $host = $config['db_host'] ?? '127.0.0.1';
            $port = $config['db_port'] ?? 3306;
            $dbname = $config['db_name'] ?? '';
            $user = $config['db_user'] ?? '';
            $pass = $config['db_pass'] ?? '';
            $charset = $config['db_charset'] ?? 'utf8mb4';

            $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $pdo = new PDO($dsn, $user, $pass, $options);
            ensureSchemaUpdated($pdo);
            return $pdo;
        }
    }

    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    $htaccess = $dataDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order allow,deny\nDeny from all\n");
    }

    $dbFile = $dataDir . '/database.sqlite';
    $isNew = !file_exists($dbFile);

    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 5000;');
    $pdo->exec('PRAGMA foreign_keys = ON;');

    if ($isNew || filesize($dbFile) === 0) {
        initializeDatabase($pdo);
    } else {
        ensureSchemaUpdated($pdo);
    }

    return $pdo;
}

function getPlanDurationDays(string $plan): int {
    $p = strtolower(trim($plan));
    if ($p === '1d' || $p === '1 day') return 1;
    if ($p === '3d' || $p === '3 day' || $p === '3 days') return 3;
    if ($p === '7d' || $p === '7 day' || $p === '7 days' || $p === 'week') return 7;
    if ($p === '30d' || $p === '30 day' || $p === '30 days' || $p === 'month') return 30;
    if ($p === '3 month' || $p === '90d' || $p === '90 days') return 90;
    return 7;
}

function getPlanMaxHwidResets(string $plan): int {
    $days = getPlanDurationDays($plan);
    if ($days >= 30) return 2;
    if ($days >= 7) return 1;
    return 0;
}

function getClientIpHash(): string {
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP']
        ?? $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['REMOTE_ADDR']
        ?? '0.0.0.0';
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return hash('sha256', 'cc_rl_' . $ip);
}

function checkRateLimit(PDO $pdo, string $actionKey, int $maxAttempts = 10, int $windowSeconds = 300): bool {
    $ipHash = getClientIpHash();
    $now = time();

    try {
        $pdo->prepare('DELETE FROM rate_limits WHERE window_expires < ?')->execute([$now]);

        $stmt = $pdo->prepare('SELECT id, attempts, window_expires FROM rate_limits WHERE ip_hash = ? AND action_key = ? LIMIT 1');
        $stmt->execute([$ipHash, $actionKey]);
        $row = $stmt->fetch();

        if (!$row) {
            $ins = $pdo->prepare('INSERT INTO rate_limits (ip_hash, action_key, attempts, window_expires) VALUES (?, ?, 1, ?)');
            $ins->execute([$ipHash, $actionKey, $now + $windowSeconds]);
            return true;
        }

        if ((int)$row['attempts'] >= $maxAttempts) {
            return false;
        }

        $upd = $pdo->prepare('UPDATE rate_limits SET attempts = attempts + 1 WHERE id = ?');
        $upd->execute([$row['id']]);
        return true;
    } catch (Throwable $e) {
        return true;
    }
}

function ensureSchemaUpdated(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            order_id VARCHAR(100) UNIQUE NOT NULL,
            square_order_id VARCHAR(100) DEFAULT NULL,
            square_payment_id VARCHAR(100) DEFAULT NULL,
            amount DECIMAL(10, 2) NOT NULL,
            currency VARCHAR(10) DEFAULT 'USD',
            status VARCHAR(50) DEFAULT 'completed',
            items TEXT NOT NULL,
            promo_code VARCHAR(64) DEFAULT NULL,
            tos_accepted INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS promo_codes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            code VARCHAR(64) NOT NULL UNIQUE,
            discount_percent INTEGER NOT NULL DEFAULT 100,
            max_uses INTEGER NOT NULL DEFAULT 6,
            used_count INTEGER NOT NULL DEFAULT 0,
            active INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS promo_redemptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            promo_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            order_id VARCHAR(100) NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (promo_id) REFERENCES promo_codes(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS password_resets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            expires_at DATETIME NOT NULL,
            used INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS subscribers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(255) NOT NULL UNIQUE,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS system_status (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            component_key VARCHAR(64) NOT NULL UNIQUE,
            label VARCHAR(100) NOT NULL,
            detail VARCHAR(120) NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'operational',
            badge_class VARCHAR(16) NOT NULL DEFAULT 'g',
            build VARCHAR(32) DEFAULT NULL,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS rate_limits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            ip_hash VARCHAR(64) NOT NULL,
            action_key VARCHAR(64) NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 1,
            window_expires INTEGER NOT NULL
        );

        CREATE INDEX IF NOT EXISTS idx_promo_codes_code ON promo_codes(code);
        CREATE INDEX IF NOT EXISTS idx_promo_redemptions_promoid ON promo_redemptions(promo_id);
        CREATE INDEX IF NOT EXISTS idx_password_resets_token ON password_resets(token_hash);
        CREATE INDEX IF NOT EXISTS idx_rate_limits_lookup ON rate_limits(ip_hash, action_key);
    ");

    $migrations = [
        "ALTER TABLE users ADD COLUMN session_version INTEGER NOT NULL DEFAULT 1",
        "ALTER TABLE user_keys ADD COLUMN plan VARCHAR(100) DEFAULT '1 day'",
        "ALTER TABLE user_keys ADD COLUMN order_id VARCHAR(100) DEFAULT NULL",
        "ALTER TABLE user_keys ADD COLUMN hwid VARCHAR(128) DEFAULT NULL",
        "ALTER TABLE user_keys ADD COLUMN hwid_resets_used INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE user_keys ADD COLUMN hwid_resets_max INTEGER NOT NULL DEFAULT 0",
        "ALTER TABLE user_keys ADD COLUMN activated_at DATETIME DEFAULT NULL",
        "ALTER TABLE user_keys ADD COLUMN expires_at DATETIME DEFAULT NULL",
        "ALTER TABLE orders ADD COLUMN promo_code VARCHAR(64) DEFAULT NULL",
        "ALTER TABLE orders ADD COLUMN tos_accepted INTEGER DEFAULT 0",
    ];
    foreach ($migrations as $sql) {
        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {}
    }

    // Seed promo code tester144 (100% off, maximum 6 uses total)
    $seedStmt = $pdo->prepare(
        "INSERT OR IGNORE INTO promo_codes (code, discount_percent, max_uses, used_count, active) VALUES (?, ?, ?, 0, 1)"
    );
    $seedStmt->execute(['tester144', 100, 6]);

    // Seed live system status rows
    $statusSeed = $pdo->prepare(
        "INSERT OR IGNORE INTO system_status (component_key, label, detail, status, badge_class, build) VALUES (?, ?, ?, ?, ?, ?)"
    );
    $statusSeed->execute(['orbit', 'orbit', 'external · build 2611.28', 'undetected', 'g', '2611.28']);
    $statusSeed->execute(['loader', 'loader', 'windows 10 / 11', 'operational', 'g', '2611.28']);
    $statusSeed->execute(['delivery', 'key delivery', 'checkout → my keys', 'operational', 'g', null]);
    $statusSeed->execute(['support', 'support', 'discord tickets', 'online', 'g', null]);
}

function initializeDatabase(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(255) NOT NULL UNIQUE,
            username VARCHAR(100) DEFAULT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'user',
            session_version INTEGER NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS user_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            license_key VARCHAR(255) NOT NULL,
            product VARCHAR(100) NOT NULL,
            plan VARCHAR(100) DEFAULT '1 day',
            order_id VARCHAR(100) DEFAULT NULL,
            status VARCHAR(50) DEFAULT 'active',
            hwid VARCHAR(128) DEFAULT NULL,
            hwid_resets_used INTEGER NOT NULL DEFAULT 0,
            hwid_resets_max INTEGER NOT NULL DEFAULT 0,
            activated_at DATETIME DEFAULT NULL,
            expires_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS user_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            session_token VARCHAR(64) NOT NULL UNIQUE,
            ip_address VARCHAR(45) DEFAULT NULL,
            user_agent TEXT DEFAULT NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_users_email ON users(email);
        CREATE INDEX IF NOT EXISTS idx_user_keys_userid ON user_keys(user_id);
        CREATE INDEX IF NOT EXISTS idx_user_keys_key ON user_keys(license_key);
        CREATE INDEX IF NOT EXISTS idx_user_sessions_token ON user_sessions(session_token);
    ");

    ensureSchemaUpdated($pdo);

    $pdo->exec("
        CREATE INDEX IF NOT EXISTS idx_orders_userid ON orders(user_id);
        CREATE INDEX IF NOT EXISTS idx_orders_orderid ON orders(order_id);
    ");
}
