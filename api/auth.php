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
require_once __DIR__ . '/turnstile.php';

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

        $turnstileResult = verifyTurnstileToken((string)($input['cf_turnstile_response'] ?? $input['turnstile_token'] ?? ''));
        if (!$turnstileResult['ok']) {
            jsonResponse(false, 'please complete cloudflare verification before registering.', ['turnstile_reset' => true], 400);
        }

        $email = trim(strtolower($input['email'] ?? ''));
        $password = $input['password'] ?? '';
        $confirmPassword = $input['confirm_password'] ?? '';
        $username = trim($input['username'] ?? '');

        if (empty($email)) {
            jsonResponse(false, 'email address is required.', [], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'please provide a valid email address.', [], 400);
        }

        if ($username !== '' && !preg_match('/^[a-zA-Z0-9_]{3,16}$/', $username)) {
            jsonResponse(false, 'username must be 3 to 16 letters, numbers or underscores.', [], 400);
        }

        if (strlen($password) < 6) {
            jsonResponse(false, 'password must be at least 6 characters long.', [], 400);
        }

        if (!empty($confirmPassword) && $password !== $confirmPassword) {
            jsonResponse(false, 'passwords do not match.', [], 400);
        }

        $stmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? LIMIT 1');
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            jsonResponse(false, 'an account with this email already exists.', [], 409);
        }

        if ($username !== '') {
            $uStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = ? LIMIT 1');
            $uStmt->execute([strtolower($username)]);
            if ($uStmt->fetch()) {
                jsonResponse(false, 'that username is already taken.', [], 409);
            }
        } else {
            $base = preg_replace('/[^a-zA-Z0-9_]/', '_', explode('@', $email)[0]) ?: 'user';
            $base = substr($base, 0, 12);
            $candidate = $base;
            $uStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = ? LIMIT 1');
            $uStmt->execute([strtolower($candidate)]);
            if ($uStmt->fetch()) {
                $candidate = substr($base, 0, 11) . '_' . bin2hex(random_bytes(2));
            }
            $username = $candidate;
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, role, created_at, last_login) VALUES (?, ?, ?, ?, datetime("now"), datetime("now"))');
        $stmt->execute([$email, $username, $hash, 'user']);
        $userId = (int)$pdo->lastInsertId();

        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['username'] = $username;

        jsonResponse(true, 'account created successfully.', [
            'user' => [
                'id' => $userId,
                'email' => $email,
                'username' => $username,
                'created_at' => date('Y-m-d H:i:s'),
                'keys' => [],
                'orders' => []
            ]
        ], 201);
        break;

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Method not allowed', [], 405);
        }

        $turnstileResult = verifyTurnstileToken((string)($input['cf_turnstile_response'] ?? $input['turnstile_token'] ?? ''));
        if (!$turnstileResult['ok']) {
            jsonResponse(false, 'please complete cloudflare verification before logging in.', ['turnstile_reset' => true], 400);
        }

        $identifier = trim(strtolower($input['email'] ?? $input['id'] ?? ''));
        $password = $input['password'] ?? '';

        if (empty($identifier) || empty($password)) {
            jsonResponse(false, 'email or username and password are required.', [], 400);
        }

        $stmt = $pdo->prepare('SELECT id, email, username, password_hash, role, created_at FROM users WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonResponse(false, 'wrong email, username or password.', [], 401);
        }

        $updateStmt = $pdo->prepare('UPDATE users SET last_login = datetime("now") WHERE id = ?');
        $updateStmt->execute([$user['id']]);

        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['username'] = $user['username'];

        $keysStmt = $pdo->prepare('SELECT id, license_key, product, plan, order_id, status, created_at FROM user_keys WHERE user_id = ? ORDER BY id DESC');
        $keysStmt->execute([$user['id']]);
        $keys = $keysStmt->fetchAll();

        $ordersStmt = $pdo->prepare('SELECT order_id, amount, currency, status, items, created_at FROM orders WHERE user_id = ? ORDER BY id DESC');
        $ordersStmt->execute([$user['id']]);
        $orders = $ordersStmt->fetchAll();

        jsonResponse(true, 'logged in successfully.', [
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'created_at' => $user['created_at'],
                'keys' => $keys,
                'orders' => $orders
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
        $keysStmt = $pdo->prepare('SELECT id, license_key, product, plan, order_id, status, created_at FROM user_keys WHERE user_id = ? ORDER BY id DESC');
        $keysStmt->execute([$userId]);
        $keys = $keysStmt->fetchAll();

        // Fetch user orders
        $ordersStmt = $pdo->prepare('SELECT order_id, amount, currency, status, items, created_at FROM orders WHERE user_id = ? ORDER BY id DESC');
        $ordersStmt->execute([$userId]);
        $orders = $ordersStmt->fetchAll();

        jsonResponse(true, 'Active session found.', [
            'logged_in' => true,
            'user' => [
                'id' => (int)$user['id'],
                'email' => $user['email'],
                'username' => $user['username'],
                'role' => $user['role'],
                'created_at' => $user['created_at'],
                'last_login' => $user['last_login'],
                'keys' => $keys,
                'orders' => $orders
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
        $product = trim($input['product'] ?? 'rust orbit');

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

    case 'forgot':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Method not allowed', [], 405);
        }

        $turnstileResult = verifyTurnstileToken((string)($input['cf_turnstile_response'] ?? $input['turnstile_token'] ?? ''));
        if (!$turnstileResult['ok']) {
            jsonResponse(false, 'please complete cloudflare verification first.', ['turnstile_reset' => true], 400);
        }

        $email = trim(strtolower($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'please provide a valid email address.', [], 400);
        }

        jsonResponse(false, 'automated password reset emails are not enabled yet. open a support ticket on discord for manual recovery.', [
            'code' => 'no_smtp',
            'discord_url' => 'https://discord.gg/Et76gjWDX2'
        ], 200);
        break;

    case 'update_profile':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Method not allowed', [], 405);
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            jsonResponse(false, 'please sign in first.', [], 401);
        }

        $username = trim($input['username'] ?? '');
        $email = trim(strtolower($input['email'] ?? ''));

        if (!preg_match('/^[a-zA-Z0-9_]{3,16}$/', $username)) {
            jsonResponse(false, 'username must be 3 to 16 letters, numbers or underscores.', [], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'please provide a valid email address.', [], 400);
        }

        $eStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(email) = ? AND id != ? LIMIT 1');
        $eStmt->execute([$email, $userId]);
        if ($eStmt->fetch()) {
            jsonResponse(false, 'that email is already used by another account.', [], 409);
        }

        $uStmt = $pdo->prepare('SELECT id FROM users WHERE LOWER(username) = ? AND id != ? LIMIT 1');
        $uStmt->execute([strtolower($username), $userId]);
        if ($uStmt->fetch()) {
            jsonResponse(false, 'that username is already taken.', [], 409);
        }

        $upd = $pdo->prepare('UPDATE users SET username = ?, email = ? WHERE id = ?');
        $upd->execute([$username, $email, $userId]);

        $_SESSION['user_email'] = $email;
        $_SESSION['username'] = $username;

        jsonResponse(true, 'profile saved.', [
            'user' => [
                'id' => (int)$userId,
                'email' => $email,
                'username' => $username
            ]
        ]);
        break;

    case 'change_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Method not allowed', [], 405);
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            jsonResponse(false, 'please sign in first.', [], 401);
        }

        $current = $input['current_password'] ?? '';
        $nextPw = $input['new_password'] ?? '';

        if (strlen($nextPw) < 8) {
            jsonResponse(false, 'new password must be at least 8 characters.', [], 400);
        }

        $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            jsonResponse(false, 'current password is incorrect.', [], 401);
        }

        $newHash = password_hash($nextPw, PASSWORD_BCRYPT);
        $upd = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $upd->execute([$newHash, $userId]);

        jsonResponse(true, 'password updated.');
        break;

    case 'delete_account':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'Method not allowed', [], 405);
        }

        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            jsonResponse(false, 'please sign in first.', [], 401);
        }

        $confirm = trim($input['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            jsonResponse(false, 'confirmation text must match DELETE.', [], 400);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM promo_redemptions WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM user_keys WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM orders WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$userId]);
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$userId]);
            $pdo->commit();
        } catch (Throwable $txErr) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $txErr;
        }

        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }
        session_destroy();

        jsonResponse(true, 'account deleted.');
        break;

    default:
        jsonResponse(false, 'invalid action.', [], 400);
        break;
}
