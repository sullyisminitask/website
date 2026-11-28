<?php
/**
 * Live Status, Patch-Alert Subscriptions, Loader Download & Key Verification API
 * Endpoints:
 *   GET  ?action=status
 *   POST ?action=subscribe
 *   POST ?action=verify_key
 *   GET  ?action=download_loader
 */

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

function statusJson(bool $success, string $message, array $extra = [], int $httpCode = 200): void {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code($httpCode);
    echo json_encode(array_merge([
        'success' => $success,
        'message' => $message
    ], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

function getStatusInput(): array {
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
    statusJson(false, 'database connection error.', [], 500);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'status';
$input = getStatusInput();

switch ($action) {
    case 'status':
        $stmt = $pdo->query('SELECT component_key, label, detail, status, badge_class, build, updated_at FROM system_status ORDER BY id ASC');
        $rows = $stmt->fetchAll();

        $allGreen = true;
        $build = '2611.28';
        foreach ($rows as $r) {
            if (($r['badge_class'] ?? 'g') !== 'g') {
                $allGreen = false;
            }
            if (!empty($r['build'])) {
                $build = $r['build'];
            }
        }

        statusJson(true, 'live status', [
            'overall' => $allGreen ? 'all systems operational' : 'partial maintenance',
            'all_operational' => $allGreen,
            'build' => $build,
            'checked_at' => gmdate('Y-m-d H:i:s') . ' utc',
            'components' => $rows
        ]);
        break;

    case 'subscribe':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            statusJson(false, 'method not allowed.', [], 405);
        }

        if (!checkRateLimit($pdo, 'subscribe', 6, 600)) {
            statusJson(false, 'too many requests. please wait a few minutes.', [], 429);
        }

        $email = trim(strtolower($input['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            statusJson(false, 'please enter a valid email address.', [], 400);
        }

        $stmt = $pdo->prepare('INSERT OR IGNORE INTO subscribers (email, created_at) VALUES (?, datetime("now"))');
        $stmt->execute([$email]);

        statusJson(true, 'subscribed! patch alerts will be sent to ' . $email . '.');
        break;

    case 'verify_key':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            statusJson(false, 'method not allowed.', [], 405);
        }

        if (!checkRateLimit($pdo, 'verify_key', 25, 300)) {
            statusJson(false, 'rate limit exceeded.', [], 429);
        }

        $licenseKey = trim($input['license_key'] ?? '');
        $hwid = trim($input['hwid'] ?? '');

        if ($licenseKey === '') {
            statusJson(false, 'license key is required.', ['valid' => false], 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM user_keys WHERE license_key = ? LIMIT 1');
        $stmt->execute([$licenseKey]);
        $keyRow = $stmt->fetch();

        if (!$keyRow) {
            statusJson(false, 'invalid license key.', ['valid' => false], 404);
        }

        if (strtolower($keyRow['status'] ?? '') !== 'active') {
            statusJson(false, 'this license key is not active.', ['valid' => false, 'status' => $keyRow['status']], 403);
        }

        $days = getPlanDurationDays($keyRow['plan'] ?? '7 days');
        $nowTs = time();

        $expiresStr = $keyRow['expires_at'] ?? null;
        if (empty($expiresStr)) {
            $baseTs = !empty($keyRow['created_at']) ? strtotime($keyRow['created_at'] . ' UTC') : $nowTs;
            $expiresTs = $baseTs + ($days * 86400);
            $expiresStr = gmdate('Y-m-d H:i:s', $expiresTs);
            $pdo->prepare('UPDATE user_keys SET expires_at = ? WHERE id = ?')->execute([$expiresStr, $keyRow['id']]);
        } else {
            $expiresTs = strtotime($expiresStr . ' UTC');
        }

        if ($expiresTs !== false && $expiresTs <= $nowTs) {
            $pdo->prepare('UPDATE user_keys SET status = "expired" WHERE id = ?')->execute([$keyRow['id']]);
            statusJson(false, 'license key has expired.', ['valid' => false, 'status' => 'expired'], 403);
        }

        if ($hwid !== '') {
            $existingHwid = trim((string)($keyRow['hwid'] ?? ''));
            if ($existingHwid === '') {
                $upd = $pdo->prepare('UPDATE user_keys SET hwid = ?, activated_at = COALESCE(activated_at, datetime("now")) WHERE id = ?');
                $upd->execute([$hwid, $keyRow['id']]);
                $existingHwid = $hwid;
            } elseif (!hash_equals($existingHwid, $hwid)) {
                statusJson(false, 'key is locked to another pc (hwid mismatch). reset hwid in my keys.', [
                    'valid' => false,
                    'hwid_mismatch' => true
                ], 403);
            }
        }

        statusJson(true, 'key verified.', [
            'valid' => true,
            'product' => $keyRow['product'] ?? 'orbit',
            'plan' => $keyRow['plan'] ?? '7 days',
            'expires_at' => $expiresStr,
            'seconds_remaining' => max(0, (int)$expiresTs - $nowTs),
            'hwid_locked' => !empty($keyRow['hwid']) || $hwid !== ''
        ]);
        break;

    case 'download_loader':
        $userId = $_SESSION['user_id'] ?? null;
        $wantsJson = isset($_GET['format']) && $_GET['format'] === 'json';

        if (!$userId) {
            if ($wantsJson) {
                statusJson(false, 'please sign in to download the loader.', [], 401);
            }
            header('Location: /login.html?next=dashboard.html');
            exit;
        }

        $kStmt = $pdo->prepare('SELECT id, plan, status, expires_at, created_at FROM user_keys WHERE user_id = ? AND status = "active"');
        $kStmt->execute([$userId]);
        $userKeys = $kStmt->fetchAll();

        $hasActive = false;
        foreach ($userKeys as $uk) {
            $days = getPlanDurationDays($uk['plan'] ?? '7 days');
            $expTs = !empty($uk['expires_at'])
                ? strtotime($uk['expires_at'] . ' UTC')
                : (strtotime(($uk['created_at'] ?? gmdate('Y-m-d H:i:s')) . ' UTC') + $days * 86400);
            if ($expTs > time()) {
                $hasActive = true;
                break;
            }
        }

        if (!$hasActive) {
            if ($wantsJson) {
                statusJson(false, 'an active orbit license key is required to download the loader.', [], 403);
            }
            header('Location: /shop.html');
            exit;
        }

        $configFile = __DIR__ . '/config.php';
        $cfg = file_exists($configFile) ? (require $configFile) : [];
        $loaderFile = $cfg['loader']['file_path'] ?? (__DIR__ . '/../data/loader/orbit-loader.exe');
        $loaderUrl = trim($cfg['loader']['download_url'] ?? '');

        if (is_string($loaderFile) && file_exists($loaderFile) && is_readable($loaderFile)) {
            if ($wantsJson) {
                statusJson(true, 'starting loader download.', ['download_url' => 'api/status.php?action=download_loader']);
            }
            $filename = basename($loaderFile);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
            header('Content-Length: ' . filesize($loaderFile));
            header('Cache-Control: no-store, no-cache, must-revalidate');
            readfile($loaderFile);
            exit;
        }

        if ($loaderUrl !== '') {
            if ($wantsJson) {
                statusJson(true, 'redirecting to loader build.', ['download_url' => $loaderUrl]);
            }
            header('Location: ' . $loaderUrl);
            exit;
        }

        if ($wantsJson) {
            statusJson(false, 'loader binary is distributed via #download in discord — opening support server.', [
                'discord_url' => 'https://discord.gg/Et76gjWDX2'
            ], 200);
        }
        header('Location: https://discord.gg/Et76gjWDX2');
        exit;

    default:
        statusJson(false, 'invalid action.', [], 400);
        break;
}
