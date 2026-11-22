<?php
/**
 * Authentication and Account API
 * Endpoints:
 *   POST ?action=register
 *   POST ?action=login
 *   POST ?action=logout
 *   GET  ?action=me
 *   POST ?action=redeem_key
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

// Start PHP session
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

require_once __DIR__ . '/db.php';

function jsonResponse(bool $success, string $message, array $extra = [], int $httpCode = 200): void {
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

function getJsonInput(): array {
    $raw = file_get_contents('php://input');
    if (!empty($raw)) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }
    return $_POST;
}

try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    jsonResponse(false, 'Database connection error: ' . $e->getMessage(), [], 500);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

switch ($action) {
    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Method not allowed', [], 405);
        }

        $email = trim(strtolower($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';
        $username = trim($input['username'] ?? '');

        if (empty($email)) {
            jsonResponse(false, 'Email address is required.', [], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'Please provide a valid email address.', [], 400);
        }

        if (strlen($password) < 6) {
            jsonResponse(false, 'Password must be at least 6 characters long.', [], 400);
        }

        if (!empty($confirmPassword) && $password !== $confirmPassword) {
            jsonResponse(false, 'Passwords do not match.', [], 400);
        }

        // Check for existing user by email
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(false, 'An account with this email already exists.', [], 409);
        }

        // Hash password securely
        $hash = password_hash($password, PASSWORD_BCRYPT);

        // Derive username from email if not given
        if (empty($username)) {
            $username = explode('@', $email)[0];
        }

        $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, role, created_at, last_login) VALUES (?, ?, ?, ?, datetime("now"), datetime("now"))');
        $stmt->execute([$email, $username, $hash, 'user']);
        $userId = (int)$pdo->lastInsertId();

        // Establish session
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['username'] = $username;

        jsonResponse(true, 'Account created successfully.', [
            'user' => [
                'id' => $userId,
                'email' => $email,
                'username' => $username,
                'created_at' => date('Y-m-d H:i:s'),
                'keys' => []
            ]
        ], 201);
        break;

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Method not allowed', [], 405);
        }

        $email = trim(strtolower($input['email'] ?? ''));
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password)) {
            jsonResponse(false, 'Email and password are required.', [], 400);
        }

        $stmt = $pdo->prepare('SELECT id, email, username, password_hash, role, created_at FROM users WHERE email = ? LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonResponse(false, 'Invalid email or password.', [], 401);
        }

        // Update last login
        $updateStmt = $pdo->prepare('UPDATE users SET last_login = datetime("now") WHERE id = ?');
        $updateStmt->execute([$user['id']]);

        // Establish session
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['username'] = $user['username'];

        // Fetch user keys
        $keysStmt = $pdo->prepare('SELECT id, license_key, product, status, created_at FROM user_keys WHERE user_id = ? ORDER BY id DESC');
        $keysStmt->execute([$user['id']]);
        $keys = $keysStmt->fetchAll();

        jsonResponse(true, 'Logged in successfully.', [
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'created_at' => $user['created_at'],
                'keys' => $keys
            ]
        ]);
        break;

    case 'me':
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            jsonResponse(false, 'Not logged in.', ['logged_in' => false], 200);
        }

        $stmt = $pdo->prepare('SELECT id, email, username, role, created_at, last_login FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            session_destroy();
            jsonResponse(false, 'Account not found.', ['logged_in' => false], 404);
        }

        // Fetch user keys
        $keysStmt = $pdo->prepare('SELECT id, license_key, product, status, created_at FROM user_keys WHERE user_id = ? ORDER BY id DESC');
        $keysStmt->execute([$userId]);
        $keys = $keysStmt->fetchAll();

        jsonResponse(true, 'Active session found.', [
            'logged_in' => true,
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'role' => $user['role'],
                'created_at' => $user['created_at'],
                'last_login' => $user['last_login'],
                'keys' => $keys
            ]
        ]);
        break;

    case 'logout':
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();
        jsonResponse(true, 'Logged out successfully.', ['logged_in' => false]);
        break;

    case 'redeem_key':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Method not allowed', [], 405);
        }

        $userId = $_SESSION['user_id'] ?? null;
        $key = trim($input['license_key'] ?? '');
        $product = trim($input['product'] ?? 'rust elite');

        if (empty($key)) {
            jsonResponse(false, 'Please enter a license key.', [], 400);
        }

        if (!$userId) {
            // Allow temporary anonymous validation if not logged in
            jsonResponse(true, 'Key validated. Sign in or create an account to bind it permanently.', [
                'bound_to_account' => false,
                'license_key' => $key,
                'product' => $product
            ]);
        }

        // Check if key already saved for user
        $checkStmt = $pdo->prepare('SELECT id FROM user_keys WHERE user_id = ? AND license_key = ? LIMIT 1');
        $checkStmt->execute([$userId, $key]);
        if ($checkStmt->fetch()) {
            jsonResponse(false, 'This key is already attached to your account.', [], 409);
        }

        $insertStmt = $pdo->prepare('INSERT INTO user_keys (user_id, license_key, product, status, created_at) VALUES (?, ?, ?, ?, datetime("now"))');
        $insertStmt->execute([$userId, $key, $product, 'active']);

        $keysStmt = $pdo->prepare('SELECT id, license_key, product, status, created_at FROM user_keys WHERE user_id = ? ORDER BY id DESC');
        $keysStmt->execute([$userId]);
        $keys = $keysStmt->fetchAll();

        jsonResponse(true, 'Key attached to your account successfully.', [
            'bound_to_account' => true,
            'keys' => $keys
        ]);
        break;

    default:
        jsonResponse(false, 'Invalid action. Supported: register, login, me, logout, redeem_key.', [], 400);
        break;
}
