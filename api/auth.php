<?php
/**
 * Authentication, Session Security, Password Recovery & Account Management API
 * Endpoints:
 *   POST ?action=register
 *   POST ?action=login
 *   GET  ?action=me
 *   POST ?action=logout
 *   POST ?action=logout_all
 *   POST ?action=redeem_key
 *   POST ?action=reset_hwid
 *   POST ?action=forgot
 *   POST ?action=reset_password
 *   POST ?action=update_profile
 *   POST ?action=change_password
 *   POST ?action=delete_account
 */

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');

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

function fetchUserKeys(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT id, license_key, product, plan, order_id, status, hwid, hwid_resets_used, hwid_resets_max, activated_at, expires_at, created_at
         FROM user_keys WHERE user_id = ? ORDER BY id DESC'
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        $plan = $r['plan'] ?? '7 days';
        if ((int)($r['hwid_resets_max'] ?? 0) === 0 && getPlanMaxHwidResets($plan) > 0) {
            $r['hwid_resets_max'] = getPlanMaxHwidResets($plan);
        }
        if (empty($r['expires_at']) && !empty($r['created_at'])) {
            $days = getPlanDurationDays($plan);
            $ts = strtotime($r['created_at'] . ' UTC');
            if ($ts !== false) {
                $r['expires_at'] = gmdate('Y-m-d H:i:s', $ts + $days * 86400);
            }
        }
    }
    unset($r);
    return $rows;
}

function fetchUserOrders(PDO $pdo, int $userId): array {
    $stmt = $pdo->prepare(
        'SELECT order_id, amount, currency, status, items, promo_code, created_at
         FROM orders WHERE user_id = ? ORDER BY id DESC'
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function requireActiveUser(PDO $pdo): array {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        jsonResponse(false, 'please sign in first.', ['logged_in' => false], 401);
    }

    $stmt = $pdo->prepare('SELECT id, email, username, role, session_version, created_at, last_login FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        session_destroy();
        jsonResponse(false, 'account not found.', ['logged_in' => false], 401);
    }

    $sessVer = isset($_SESSION['session_version']) ? (int)$_SESSION['session_version'] : (int)($user['session_version'] ?? 1);
    if ($sessVer !== (int)($user['session_version'] ?? 1)) {
        $_SESSION = [];
        session_destroy();
        jsonResponse(false, 'session expired. please sign in again.', ['logged_in' => false], 401);
    }

    $_SESSION['session_version'] = (int)($user['session_version'] ?? 1);
    return $user;
}

function sendResetEmail(string $toEmail, string $username, string $resetUrl): bool {
    $configFile = __DIR__ . '/config.php';
    $cfg = file_exists($configFile) ? (require $configFile) : [];
    $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'chudcheats.xyz') ?: 'chudcheats.xyz';
    $fromEmail = $cfg['mail']['from_email'] ?? ('noreply@' . $host);

    $subject = 'password reset — ' . $host;
    $body = "hi " . ($username ?: 'there') . ",\n\n"
        . "someone requested a password reset for your account on {$host}.\n\n"
        . "use this link within the next 30 minutes to set a new password:\n"
        . "{$resetUrl}\n\n"
        . "if you did not request this, you can safely ignore this email.\n";

    $headers = [
        'From: ' . $host . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'X-Mailer: PHP/' . phpversion()
    ];

    return @mail($toEmail, $subject, $body, implode("\r\n", $headers));
}

try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    jsonResponse(false, 'database connection error.', [], 500);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = getJsonInput();

switch ($action) {
    case 'register':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        if (!checkRateLimit($pdo, 'register', 8, 600)) {
            jsonResponse(false, 'too many registration attempts. please wait a few minutes.', [], 429);
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

        $stmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, role, session_version, created_at, last_login) VALUES (?, ?, ?, ?, 1, datetime("now"), datetime("now"))');
        $stmt->execute([$email, $username, $hash, 'user']);
        $userId = (int)$pdo->lastInsertId();

        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['user_email'] = $email;
        $_SESSION['username'] = $username;
        $_SESSION['session_version'] = 1;

        jsonResponse(true, 'account created successfully.', [
            'user' => [
                'id' => $userId,
                'email' => $email,
                'username' => $username,
                'created_at' => gmdate('Y-m-d H:i:s'),
                'keys' => [],
                'orders' => []
            ]
        ], 201);
        break;

    case 'login':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        if (!checkRateLimit($pdo, 'login', 12, 300)) {
            jsonResponse(false, 'too many sign-in attempts. please wait 5 minutes.', [], 429);
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

        $stmt = $pdo->prepare('SELECT id, email, username, password_hash, role, session_version, created_at FROM users WHERE LOWER(email) = ? OR LOWER(username) = ? LIMIT 1');
        $stmt->execute([$identifier, $identifier]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            jsonResponse(false, 'wrong email, username or password.', [], 401);
        }

        $pdo->prepare('UPDATE users SET last_login = datetime("now") WHERE id = ?')->execute([$user['id']]);

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['session_version'] = (int)($user['session_version'] ?? 1);

        $uid = (int)$user['id'];
        jsonResponse(true, 'logged in successfully.', [
            'user' => [
                'id' => $uid,
                'email' => $user['email'],
                'username' => $user['username'],
                'created_at' => $user['created_at'],
                'keys' => fetchUserKeys($pdo, $uid),
                'orders' => fetchUserOrders($pdo, $uid)
            ]
        ]);
        break;

    case 'me':
        $userId = $_SESSION['user_id'] ?? null;
        if (!$userId) {
            jsonResponse(false, 'not logged in.', ['logged_in' => false], 200);
        }

        $stmt = $pdo->prepare('SELECT id, email, username, role, session_version, created_at, last_login FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user) {
            $_SESSION = [];
            session_destroy();
            jsonResponse(false, 'account not found.', ['logged_in' => false], 200);
        }

        $sessVer = isset($_SESSION['session_version']) ? (int)$_SESSION['session_version'] : (int)($user['session_version'] ?? 1);
        if ($sessVer !== (int)($user['session_version'] ?? 1)) {
            $_SESSION = [];
            session_destroy();
            jsonResponse(false, 'session expired.', ['logged_in' => false], 200);
        }

        $_SESSION['session_version'] = (int)($user['session_version'] ?? 1);
        $uid = (int)$user['id'];

        jsonResponse(true, 'active session found.', [
            'logged_in' => true,
            'user' => [
                'id' => $uid,
                'email' => $user['email'],
                'username' => $user['username'],
                'role' => $user['role'],
                'created_at' => $user['created_at'],
                'last_login' => $user['last_login'],
                'keys' => fetchUserKeys($pdo, $uid),
                'orders' => fetchUserOrders($pdo, $uid)
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
        jsonResponse(true, 'logged out successfully.', ['logged_in' => false]);
        break;

    case 'logout_all':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        $user = requireActiveUser($pdo);
        $newVer = ((int)($user['session_version'] ?? 1)) + 1;
        $pdo->prepare('UPDATE users SET session_version = ? WHERE id = ?')->execute([$newVer, $user['id']]);
        $pdo->prepare('DELETE FROM user_sessions WHERE user_id = ?')->execute([$user['id']]);

        session_regenerate_id(true);
        $_SESSION['session_version'] = $newVer;

        jsonResponse(true, 'signed out on every other device.');
        break;

    case 'redeem_key':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        $user = requireActiveUser($pdo);
        $uid = (int)$user['id'];
        $key = trim($input['license_key'] ?? '');
        $product = strtolower(trim($input['product'] ?? 'orbit'));
        $plan = strtolower(trim($input['plan'] ?? '7 days'));

        if ($key === '') {
            jsonResponse(false, 'please enter a license key.', [], 400);
        }

        $existingStmt = $pdo->prepare('SELECT id, user_id FROM user_keys WHERE license_key = ? LIMIT 1');
        $existingStmt->execute([$key]);
        $existingRow = $existingStmt->fetch();

        if ($existingRow) {
            if ((int)$existingRow['user_id'] === $uid) {
                jsonResponse(false, 'this key is already attached to your account.', [], 409);
            }
            jsonResponse(false, 'this key is already bound to another account.', [], 409);
        }

        $days = getPlanDurationDays($plan);
        $maxResets = getPlanMaxHwidResets($plan);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $days * 86400);

        $insertStmt = $pdo->prepare(
            'INSERT INTO user_keys (user_id, license_key, product, plan, status, hwid_resets_used, hwid_resets_max, expires_at, created_at)
             VALUES (?, ?, ?, ?, "active", 0, ?, ?, datetime("now"))'
        );
        $insertStmt->execute([$uid, $key, $product, $plan, $maxResets, $expiresAt]);

        jsonResponse(true, 'key attached to your account.', [
            'bound_to_account' => true,
            'keys' => fetchUserKeys($pdo, $uid)
        ]);
        break;

    case 'reset_hwid':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        $user = requireActiveUser($pdo);
        $uid = (int)$user['id'];
        $keyId = isset($input['key_id']) ? (int)$input['key_id'] : 0;
        $licenseKey = trim($input['license_key'] ?? '');

        if ($keyId > 0) {
            $kStmt = $pdo->prepare('SELECT * FROM user_keys WHERE id = ? AND user_id = ? LIMIT 1');
            $kStmt->execute([$keyId, $uid]);
        } else {
            $kStmt = $pdo->prepare('SELECT * FROM user_keys WHERE license_key = ? AND user_id = ? LIMIT 1');
            $kStmt->execute([$licenseKey, $uid]);
        }

        $keyRow = $kStmt->fetch();
        if (!$keyRow) {
            jsonResponse(false, 'key not found on your account.', [], 404);
        }

        $plan = $keyRow['plan'] ?? '7 days';
        $maxResets = (int)($keyRow['hwid_resets_max'] ?? 0);
        if ($maxResets === 0 && getPlanMaxHwidResets($plan) > 0) {
            $maxResets = getPlanMaxHwidResets($plan);
        }
        $usedResets = (int)($keyRow['hwid_resets_used'] ?? 0);

        if (empty($keyRow['hwid'])) {
            jsonResponse(false, 'this key does not have an hwid locked yet.', [], 400);
        }

        if ($usedResets >= $maxResets) {
            jsonResponse(false, 'no hwid resets left on this key. open a ticket on discord for assistance.', [], 400);
        }

        $upd = $pdo->prepare('UPDATE user_keys SET hwid = NULL, hwid_resets_used = hwid_resets_used + 1, hwid_resets_max = ? WHERE id = ? AND user_id = ?');
        $upd->execute([$maxResets, $keyRow['id'], $uid]);

        jsonResponse(true, 'hwid reset. it will lock again on next launch.', [
            'keys' => fetchUserKeys($pdo, $uid)
        ]);
        break;

    case 'forgot':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        if (!checkRateLimit($pdo, 'forgot', 5, 900)) {
            jsonResponse(false, 'too many reset requests. please wait a few minutes.', [], 429);
        }

        $turnstileResult = verifyTurnstileToken((string)($input['cf_turnstile_response'] ?? $input['turnstile_token'] ?? ''));
        if (!$turnstileResult['ok']) {
            jsonResponse(false, 'please complete cloudflare verification first.', ['turnstile_reset' => true], 400);
        }

        $email = trim(strtolower($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonResponse(false, 'please provide a valid email address.', [], 400);
        }

        $uStmt = $pdo->prepare('SELECT id, email, username FROM users WHERE LOWER(email) = ? LIMIT 1');
        $uStmt->execute([$email]);
        $found = $uStmt->fetch();

        if ($found) {
            $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ? AND used = 0')->execute([$found['id']]);
            $rawToken = bin2hex(random_bytes(24));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = gmdate('Y-m-d H:i:s', time() + 1800);

            $ins = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at, used, created_at) VALUES (?, ?, ?, 0, datetime("now"))');
            $ins->execute([$found['id'], $tokenHash, $expiresAt]);

            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'chudcheats.xyz';
            $resetUrl = "{$scheme}{$host}/forgot.html?token=" . urlencode($rawToken);
            sendResetEmail($found['email'], $found['username'] ?? '', $resetUrl);
        }

        jsonResponse(true, 'if that email has an account, a reset link is on its way.', [
            'sent' => true
        ]);
        break;

    case 'reset_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        if (!checkRateLimit($pdo, 'reset_password', 8, 600)) {
            jsonResponse(false, 'too many attempts. please wait a few minutes.', [], 429);
        }

        $rawToken = trim($input['token'] ?? '');
        $newPw = $input['new_password'] ?? '';
        $confirmPw = $input['confirm_password'] ?? '';

        if ($rawToken === '' || strlen($rawToken) < 20) {
            jsonResponse(false, 'invalid or expired reset link.', [], 400);
        }

        if (strlen($newPw) < 8) {
            jsonResponse(false, 'password must be at least 8 characters.', [], 400);
        }

        if ($confirmPw !== '' && $newPw !== $confirmPw) {
            jsonResponse(false, 'passwords do not match.', [], 400);
        }

        $tokenHash = hash('sha256', $rawToken);
        $nowUtc = gmdate('Y-m-d H:i:s');

        $rStmt = $pdo->prepare('SELECT id, user_id, expires_at, used FROM password_resets WHERE token_hash = ? LIMIT 1');
        $rStmt->execute([$tokenHash]);
        $resetRow = $rStmt->fetch();

        if (!$resetRow || (int)$resetRow['used'] === 1 || $resetRow['expires_at'] <= $nowUtc) {
            jsonResponse(false, 'this reset link is invalid or has expired. request a new one.', [], 400);
        }

        $uid = (int)$resetRow['user_id'];
        $uStmt = $pdo->prepare('SELECT id, email, username, session_version FROM users WHERE id = ? LIMIT 1');
        $uStmt->execute([$uid]);
        $user = $uStmt->fetch();

        if (!$user) {
            jsonResponse(false, 'account not found.', [], 404);
        }

        $newHash = password_hash($newPw, PASSWORD_BCRYPT);
        $newVer = ((int)($user['session_version'] ?? 1)) + 1;

        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = ?, session_version = ?, last_login = datetime("now") WHERE id = ?')
                ->execute([$newHash, $newVer, $uid]);
            $pdo->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')->execute([$uid]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = $uid;
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['session_version'] = $newVer;

        jsonResponse(true, 'password updated. signing you in…', [
            'user' => [
                'id' => $uid,
                'email' => $user['email'],
                'username' => $user['username']
            ]
        ]);
        break;

    case 'update_profile':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        $user = requireActiveUser($pdo);
        $userId = (int)$user['id'];

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
                'id' => $userId,
                'email' => $email,
                'username' => $username
            ]
        ]);
        break;

    case 'change_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        $user = requireActiveUser($pdo);
        $userId = (int)$user['id'];

        $current = $input['current_password'] ?? '';
        $nextPw = $input['new_password'] ?? '';

        if (strlen($nextPw) < 8) {
            jsonResponse(false, 'new password must be at least 8 characters.', [], 400);
        }

        $stmt = $pdo->prepare('SELECT password_hash, session_version FROM users WHERE id = ? LIMIT 1');
        $stmt->execute([$userId]);
        $row = $stmt->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            jsonResponse(false, 'current password is incorrect.', [], 401);
        }

        $newHash = password_hash($nextPw, PASSWORD_BCRYPT);
        $newVer = ((int)($row['session_version'] ?? 1)) + 1;
        $upd = $pdo->prepare('UPDATE users SET password_hash = ?, session_version = ? WHERE id = ?');
        $upd->execute([$newHash, $newVer, $userId]);

        session_regenerate_id(true);
        $_SESSION['session_version'] = $newVer;

        jsonResponse(true, 'password updated.');
        break;

    case 'delete_account':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            jsonResponse(false, 'method not allowed.', [], 405);
        }

        $user = requireActiveUser($pdo);
        $userId = (int)$user['id'];

        $confirm = trim($input['confirm'] ?? '');
        if ($confirm !== 'DELETE') {
            jsonResponse(false, 'confirmation text must match DELETE.', [], 400);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM password_resets WHERE user_id = ?')->execute([$userId]);
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
