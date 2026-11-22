<?php
/**
 * Database connection handler
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
            return $pdo;
        }
    }

    // Default: SQLite embedded database
    $dataDir = __DIR__ . '/../data';
    if (!is_dir($dataDir)) {
        mkdir($dataDir, 0755, true);
    }

    // Protect data dir via .htaccess if missing
    $htaccess = $dataDir . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Order allow,deny\nDeny from all\n");
    }

    $dbFile = $dataDir . '/database.sqlite';
    $isNew = !file_exists($dbFile);

    $pdo = new PDO('sqlite:' . $dbFile);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Initial table creation if new database
    if ($isNew || filesize($dbFile) === 0) {
        initializeDatabase($pdo);
    }

    return $pdo;
}

function initializeDatabase(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            email VARCHAR(255) NOT NULL UNIQUE,
            username VARCHAR(100) DEFAULT NULL,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(50) DEFAULT 'user',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            last_login DATETIME DEFAULT NULL
        );

        CREATE TABLE IF NOT EXISTS user_keys (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            license_key VARCHAR(255) NOT NULL,
            product VARCHAR(100) NOT NULL,
            status VARCHAR(50) DEFAULT 'active',
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
        CREATE INDEX IF NOT EXISTS idx_user_sessions_token ON user_sessions(session_token);
    ");
}
