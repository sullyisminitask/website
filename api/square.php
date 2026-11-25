<?php
/**
 * Square Payments Integration & Key Generation Endpoint
 * Handles Square Payment Links, Order Verification, Callback Redirects, and Key Issuance
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

function getSquareConfig(): array {
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        if (!empty($config['square'])) {
            return $config['square'];
        }
    }
    return [
        'environment' => getenv('SQUARE_ENVIRONMENT') ?: 'sandbox',
        'application_id' => getenv('SQUARE_APPLICATION_ID') ?: '',
        'access_token' => getenv('SQUARE_ACCESS_TOKEN') ?: '',
        'location_id' => getenv('SQUARE_LOCATION_ID') ?: '',
        'currency' => getenv('SQUARE_CURRENCY') ?: 'USD'
    ];
}

function isRealCredential(string $val): bool {
    $v = trim($val);
    if ($v === '') return false;
    if (stripos($v, 'YOUR_SQUARE_') !== false) return false;
    return true;
}

function squareApiBase(string $env): string {
    return strtolower($env) === 'production'
        ? 'https://connect.squareup.com'
        : 'https://connect.squareupsandbox.com';
}

function callSquareApi(string $method, string $url, string $accessToken, ?array $payload = null): array {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Square-Version: 2024-11-20',
        "Authorization: Bearer {$accessToken}",
        'Content-Type: application/json'
    ]);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload ?? []));
    }
    $res = curl_exec($ch);
    $curlErr = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = is_string($res) ? json_decode($res, true) : null;
    return [
        'http_code' => $httpCode,
        'error' => $curlErr,
        'data' => is_array($data) ? $data : []
    ];
}

function generateLicenseKey(string $product = 'rust-elite'): string {
    $prefix = strtolower(str_replace(' ', '-', trim($product)));
    $p1 = bin2hex(random_bytes(2));
    $p2 = bin2hex(random_bytes(2));
    $p3 = bin2hex(random_bytes(2));
    return "{$prefix}-{$p1}-{$p2}-{$p3}";
}

function getServerPlanPrice(string $plan): ?float {
    $catalog = [
        '1 day' => 7.99,
        '3 day' => 16.99,
        'week' => 25.99,
        'month' => 54.99,
        '3 month' => 139.99,
    ];
    $key = strtolower(trim($plan));
    return $catalog[$key] ?? null;
}

function issueKeysForOrder(PDO $pdo, array $order): array {
    $items = json_decode($order['items'], true) ?: [];
    $userId = (int)$order['user_id'];
    $orderId = $order['order_id'];
    $generatedKeys = [];

    foreach ($items as $item) {
        $product = $item['product'] ?? $item['name'] ?? 'rust elite';
        $plan = $item['plan'] ?? '1 day';
        $qty = intval($item['quantity'] ?? 1);
        if ($qty < 1) $qty = 1;

        for ($i = 0; $i < $qty; $i++) {
            $key = generateLicenseKey($product);
            $keyStmt = $pdo->prepare('INSERT INTO user_keys (user_id, license_key, product, plan, order_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime("now"))');
            $keyStmt->execute([$userId, $key, $product, $plan, $orderId, 'active']);
            $generatedKeys[] = [
                'license_key' => $key,
                'product' => $product,
                'plan' => $plan,
                'order_id' => $orderId,
                'status' => 'active'
            ];
        }
    }

    $upd = $pdo->prepare('UPDATE orders SET status = "completed" WHERE order_id = ?');
    $upd->execute([$orderId]);

    return $generatedKeys;
}

function verifySquareOrderPaid(array $squareConfig, ?string $squareOrderId): array {
    $accessToken = trim($squareConfig['access_token'] ?? '');
    if (!isRealCredential($accessToken) || empty($squareOrderId)) {
        return ['paid' => false, 'reason' => 'missing square order id or access token'];
    }

    $url = squareApiBase($squareConfig['environment'] ?? 'sandbox') . '/v2/orders/' . urlencode($squareOrderId);
    $resp = callSquareApi('GET', $url, $accessToken);
    if ($resp['http_code'] < 200 || $resp['http_code'] >= 300) {
        return ['paid' => false, 'reason' => 'square order lookup failed'];
    }

    $order = $resp['data']['order'] ?? [];
    $state = strtoupper($order['state'] ?? '');
    $tenders = $order['tenders'] ?? [];
    $netDue = isset($order['net_amount_due_money']['amount']) ? intval($order['net_amount_due_money']['amount']) : null;

    if ($state === 'COMPLETED' || (!empty($tenders) && ($netDue === null || $netDue <= 0))) {
        $paymentId = $tenders[0]['payment_id'] ?? ($tenders[0]['id'] ?? null);
        return ['paid' => true, 'payment_id' => $paymentId];
    }

    return ['paid' => false, 'reason' => 'payment not yet completed on square'];
}

try {
    $pdo = getDatabaseConnection();
} catch (Throwable $e) {
    jsonResponse(false, 'database error: ' . $e->getMessage(), [], 500);
}

try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $input = getJsonInput();
    $squareConfig = getSquareConfig();

    $accessToken = trim($squareConfig['access_token'] ?? '');
    $locationId = trim($squareConfig['location_id'] ?? '');
    $appId = trim($squareConfig['application_id'] ?? '');
    $currency = strtoupper(trim($squareConfig['currency'] ?? 'USD'));
    $isSquareConfigured = isRealCredential($accessToken) && isRealCredential($locationId);

    switch ($action) {
    case 'config':
        jsonResponse(true, 'square configuration', [
            'application_id' => isRealCredential($appId) ? $appId : '',
            'location_id' => isRealCredential($locationId) ? $locationId : '',
            'environment' => $squareConfig['environment'] ?? 'sandbox',
            'currency' => $currency,
            'configured' => $isSquareConfigured
        ]);
        break;

    case 'create_checkout':
        $items = $input['items'] ?? [];
        if (empty($items) || !is_array($items)) {
            jsonResponse(false, 'cart is empty.', [], 400);
        }

        $userId = $_SESSION['user_id'] ?? null;
        $customerEmail = trim(strtolower($input['email'] ?? ($_SESSION['user_email'] ?? '')));

        if (!$userId && empty($customerEmail)) {
            jsonResponse(false, 'please log in or enter an email address so keys can be delivered to your account.', [], 400);
        }

        if (!$userId && !empty($customerEmail)) {
            if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
                jsonResponse(false, 'please provide a valid email address.', [], 400);
            }
            $stmt = $pdo->prepare('SELECT id, email, username FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$customerEmail]);
            $existing = $stmt->fetch();
            if ($existing) {
                $userId = (int)$existing['id'];
            } else {
                $tempPassword = bin2hex(random_bytes(8));
                $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
                $uName = explode('@', $customerEmail)[0];
                $createStmt = $pdo->prepare('INSERT INTO users (email, username, password_hash, role, created_at, last_login) VALUES (?, ?, ?, ?, datetime("now"), datetime("now"))');
                $createStmt->execute([$customerEmail, $uName, $hash, 'user']);
                $userId = (int)$pdo->lastInsertId();
                $_SESSION['user_id'] = $userId;
                $_SESSION['user_email'] = $customerEmail;
                $_SESSION['username'] = $uName;
            }
        }

        $orderId = 'ord-' . bin2hex(random_bytes(4));
        $totalCents = 0;
        $lineItems = [];
        $normalizedItems = [];

        foreach ($items as $item) {
            $name = strtolower(trim($item['product'] ?? $item['name'] ?? 'rust elite'));
            $plan = strtolower(trim($item['plan'] ?? '1 day'));
            $serverPrice = getServerPlanPrice($plan);
            if ($serverPrice === null) {
                jsonResponse(false, "invalid plan selected: {$plan}.", [], 400);
            }
            $qty = intval($item['quantity'] ?? 1);
            if ($qty < 1) $qty = 1;

            $itemCents = intval(round($serverPrice * 100));
            $totalCents += ($itemCents * $qty);

            $normalizedItems[] = [
                'product' => $name,
                'plan' => $plan,
                'price' => $serverPrice,
                'quantity' => $qty
            ];

            $lineItems[] = [
                'name' => "{$name} ({$plan})",
                'quantity' => (string)$qty,
                'base_price_money' => [
                    'amount' => $itemCents,
                    'currency' => $currency
                ]
            ];
        }

        if ($isSquareConfigured) {
            $squareEndpoint = squareApiBase($squareConfig['environment'] ?? 'sandbox') . '/v2/online-checkout/payment-links';
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'chudcheats.xyz';
            $redirectUrl = "{$scheme}{$host}/api/square.php?action=callback&order_id={$orderId}&uid={$userId}";

            $squarePayload = [
                'idempotency_key' => bin2hex(random_bytes(16)),
                'order' => [
                    'location_id' => $locationId,
                    'reference_id' => $orderId,
                    'line_items' => $lineItems
                ],
                'checkout_options' => [
                    'redirect_url' => $redirectUrl,
                    'ask_for_shipping_address' => false
                ],
                'pre_populate_buyer_email' => $customerEmail
            ];

            $resp = callSquareApi('POST', $squareEndpoint, $accessToken, $squarePayload);

            if ($resp['http_code'] >= 200 && $resp['http_code'] < 300 && !empty($resp['data']['payment_link']['url'])) {
                $squareOrderId = $resp['data']['payment_link']['order_id'] ?? null;
                $orderStmt = $pdo->prepare('INSERT INTO orders (user_id, order_id, square_order_id, amount, currency, status, items, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now"))');
                $orderStmt->execute([$userId, $orderId, $squareOrderId, $totalCents / 100, $currency, 'pending', json_encode($normalizedItems)]);

                jsonResponse(true, 'square checkout link created.', [
                    'checkout_url' => $resp['data']['payment_link']['url'],
                    'order_id' => $orderId
                ]);
            }

            $errDetail = $resp['data']['errors'][0]['detail'] ?? ($resp['error'] ?: 'square payment link creation failed.');
            jsonResponse(false, 'square error: ' . strtolower($errDetail), [
                'square_http' => $resp['http_code']
            ], 502);
        }

        // Sandbox demo fallback ONLY when Square credentials have not been set in api/config.php
        $orderStmt = $pdo->prepare('INSERT INTO orders (user_id, order_id, amount, currency, status, items, created_at) VALUES (?, ?, ?, ?, ?, ?, datetime("now"))');
        $orderStmt->execute([$userId, $orderId, $totalCents / 100, $currency, 'pending', json_encode($normalizedItems)]);

        $createdOrder = [
            'user_id' => $userId,
            'order_id' => $orderId,
            'items' => json_encode($normalizedItems)
        ];
        $generatedKeys = issueKeysForOrder($pdo, $createdOrder);

        jsonResponse(true, 'sandbox test order completed! add square credentials in api/config.php for live payments.', [
            'instant_completion' => true,
            'sandbox_demo' => true,
            'order_id' => $orderId,
            'keys' => $generatedKeys,
            'user_id' => $userId
        ]);
        break;

    case 'verify_order':
        $orderId = trim($input['order_id'] ?? $_GET['order_id'] ?? '');
        if (empty($orderId)) {
            jsonResponse(false, 'order id is required.', [], 400);
        }

        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order) {
            jsonResponse(false, 'order not found.', [], 404);
        }

        if ($order['status'] === 'completed') {
            jsonResponse(true, 'order already completed.', ['status' => 'completed']);
        }

        if (!$isSquareConfigured) {
            jsonResponse(false, 'square is not configured.', [], 400);
        }

        $verification = verifySquareOrderPaid($squareConfig, $order['square_order_id'] ?? null);
        if ($verification['paid']) {
            if (!empty($verification['payment_id'])) {
                $pUpd = $pdo->prepare('UPDATE orders SET square_payment_id = ? WHERE order_id = ?');
                $pUpd->execute([$verification['payment_id'], $orderId]);
            }
            $keys = issueKeysForOrder($pdo, $order);
            jsonResponse(true, 'payment verified! license keys issued.', [
                'status' => 'completed',
                'keys' => $keys
            ]);
        }

        jsonResponse(false, $verification['reason'], ['status' => 'pending']);
        break;

    case 'callback':
        $orderId = trim($_GET['order_id'] ?? '');
        $userId = intval($_GET['uid'] ?? ($_SESSION['user_id'] ?? 0));

        if (empty($orderId)) {
            header('Location: /#my-keys');
            exit;
        }

        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            header('Location: /#my-keys');
            exit;
        }

        // Restore session for the purchasing user if missing so keys show right away
        if (empty($_SESSION['user_id']) && !empty($order['user_id'])) {
            $uStmt = $pdo->prepare('SELECT id, email, username FROM users WHERE id = ? LIMIT 1');
            $uStmt->execute([$order['user_id']]);
            $uRow = $uStmt->fetch();
            if ($uRow) {
                $_SESSION['user_id'] = (int)$uRow['id'];
                $_SESSION['user_email'] = $uRow['email'];
                $_SESSION['username'] = $uRow['username'];
            }
        }

        if ($order['status'] === 'completed') {
            header('Location: /?payment_success=1&order=' . urlencode($orderId) . '#my-keys');
            exit;
        }

        if ($isSquareConfigured && !empty($order['square_order_id'])) {
            $verification = verifySquareOrderPaid($squareConfig, $order['square_order_id']);
            if ($verification['paid']) {
                if (!empty($verification['payment_id'])) {
                    $pUpd = $pdo->prepare('UPDATE orders SET square_payment_id = ? WHERE order_id = ?');
                    $pUpd->execute([$verification['payment_id'], $orderId]);
                }
                issueKeysForOrder($pdo, $order);
                header('Location: /?payment_success=1&order=' . urlencode($orderId) . '#my-keys');
                exit;
            }

            header('Location: /?payment_pending=1&order=' . urlencode($orderId) . '#my-keys');
            exit;
        }

        issueKeysForOrder($pdo, $order);
        header('Location: /?payment_success=1&order=' . urlencode($orderId) . '#my-keys');
        exit;

    default:
        jsonResponse(false, 'invalid action. supported: config, create_checkout, verify_order, callback.', [], 400);
        break;
    }
} catch (Throwable $e) {
    jsonResponse(false, 'checkout error: ' . $e->getMessage(), [], 500);
}
