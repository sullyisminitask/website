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

function fetchLiveUsdToAudRate(): ?array {
    $endpoints = [
        'https://open.er-api.com/v6/latest/USD',
        'https://api.frankfurter.dev/v1/latest?base=USD&symbols=AUD'
    ];

    foreach ($endpoints as $url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 4);
        curl_setopt($ch, CURLOPT_USERAGENT, 'chudcheats-fx/1.0');
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300 && is_string($res)) {
            $json = json_decode($res, true);
            $rate = $json['rates']['AUD'] ?? null;
            if (is_numeric($rate) && (float)$rate > 0.5 && (float)$rate < 5.0) {
                return [
                    'rate' => (float)$rate,
                    'source' => 'live',
                    'updated_at' => gmdate('Y-m-d H:i:s') . ' utc'
                ];
            }
        }
    }
    return null;
}

function getUsdToAudRate(bool $forceRefresh = false): array {
    $cacheFile = __DIR__ . '/../data/fx_cache.json';
    $ttlSeconds = 900;

    if (!$forceRefresh && file_exists($cacheFile)) {
        $cached = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($cached) && !empty($cached['rate']) && !empty($cached['fetched_at'])) {
            if ((time() - (int)$cached['fetched_at']) < $ttlSeconds) {
                return [
                    'rate' => (float)$cached['rate'],
                    'source' => 'live',
                    'updated_at' => $cached['updated_at'] ?? ''
                ];
            }
        }
    }

    $live = fetchLiveUsdToAudRate();
    if ($live !== null) {
        $cacheData = [
            'rate' => $live['rate'],
            'fetched_at' => time(),
            'updated_at' => $live['updated_at']
        ];
        @file_put_contents($cacheFile, json_encode($cacheData));
        return $live;
    }

    if (file_exists($cacheFile)) {
        $stale = json_decode((string)@file_get_contents($cacheFile), true);
        if (is_array($stale) && !empty($stale['rate'])) {
            return [
                'rate' => (float)$stale['rate'],
                'source' => 'cached',
                'updated_at' => $stale['updated_at'] ?? ''
            ];
        }
    }

    return [
        'rate' => 1.51,
        'source' => 'fallback',
        'updated_at' => gmdate('Y-m-d H:i:s') . ' utc'
    ];
}

function generateLicenseKey(string $product = 'orbit'): string {
    $prefix = strtolower(str_replace(' ', '-', trim($product)));
    $p1 = bin2hex(random_bytes(2));
    $p2 = bin2hex(random_bytes(2));
    $p3 = bin2hex(random_bytes(2));
    return "{$prefix}-{$p1}-{$p2}-{$p3}";
}

function getServerPlanPrice(string $plan): ?float {
    $catalog = [
        '1d' => 7.99,
        '1 day' => 7.99,
        '3d' => 16.99,
        '3 day' => 16.99,
        '3 days' => 16.99,
        '7d' => 19.99,
        '7 day' => 19.99,
        '7 days' => 19.99,
        'week' => 19.99,
        '30d' => 39.99,
        '30 day' => 39.99,
        '30 days' => 39.99,
        'month' => 39.99,
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
        $product = $item['product'] ?? $item['name'] ?? 'orbit';
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
    case 'rate':
        $fx = getUsdToAudRate(!empty($_GET['refresh']));
        $turnstilePublic = getTurnstilePublicConfig();
        jsonResponse(true, 'square configuration', [
            'application_id' => isRealCredential($appId) ? $appId : '',
            'location_id' => isRealCredential($locationId) ? $locationId : '',
            'environment' => $squareConfig['environment'] ?? 'sandbox',
            'display_currency' => 'USD',
            'currency' => $currency,
            'usd_to_aud' => $fx['rate'],
            'rate_source' => $fx['source'],
            'rate_updated_at' => $fx['updated_at'],
            'configured' => $isSquareConfigured,
            'turnstile_enabled' => $turnstilePublic['enabled'],
            'turnstile_site_key' => $turnstilePublic['site_key']
        ]);
        break;

    case 'check_promo':
        $code = trim(strtolower($input['code'] ?? $_GET['code'] ?? ''));
        if ($code === '') {
            jsonResponse(false, 'please enter a promo code.', [], 400);
        }

        $pStmt = $pdo->prepare('SELECT id, code, discount_percent, max_uses, used_count, active FROM promo_codes WHERE LOWER(code) = ? LIMIT 1');
        $pStmt->execute([$code]);
        $promo = $pStmt->fetch();

        if (!$promo || !(int)$promo['active']) {
            jsonResponse(false, 'invalid promo code.', [], 404);
        }

        $maxUses = (int)$promo['max_uses'];
        $usedCount = (int)$promo['used_count'];
        $remaining = max(0, $maxUses - $usedCount);

        if ($remaining <= 0) {
            jsonResponse(false, "promo code \"{$promo['code']}\" has reached its {$maxUses}-use limit.", [
                'uses_remaining' => 0,
                'max_uses' => $maxUses
            ], 400);
        }

        jsonResponse(true, "promo \"{$promo['code']}\" applied ({$promo['discount_percent']}% off — {$remaining} use" . ($remaining === 1 ? '' : 's') . " left).", [
            'code' => strtolower($promo['code']),
            'discount_percent' => (int)$promo['discount_percent'],
            'max_uses' => $maxUses,
            'used_count' => $usedCount,
            'uses_remaining' => $remaining
        ]);
        break;

    case 'create_checkout':
        $items = $input['items'] ?? [];
        if (empty($items) || !is_array($items)) {
            jsonResponse(false, 'cart is empty.', [], 400);
        }

        $tosAccepted = !empty($input['tos_accepted']);
        if (!$tosAccepted) {
            jsonResponse(false, 'you must accept the terms of service before checking out.', [], 400);
        }

        $turnstileResult = verifyTurnstileToken((string)($input['cf_turnstile_response'] ?? $input['turnstile_token'] ?? ''));
        if (!$turnstileResult['ok']) {
            jsonResponse(false, 'please complete cloudflare verification before checking out.', ['turnstile_reset' => true], 400);
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

        $fx = getUsdToAudRate();
        $fxRate = ($currency === 'AUD') ? (float)$fx['rate'] : 1.0;

        $promoCodeInput = trim(strtolower($input['promo_code'] ?? ''));
        $appliedPromo = null;
        $discountPercent = 0;

        if ($promoCodeInput !== '') {
            // Validate promo before hitting Square; lock/consume atomically below
            $pStmt = $pdo->prepare('SELECT id, code, discount_percent, max_uses, used_count, active FROM promo_codes WHERE LOWER(code) = ? LIMIT 1');
            $pStmt->execute([$promoCodeInput]);
            $promoRow = $pStmt->fetch();

            if (!$promoRow || !(int)$promoRow['active']) {
                jsonResponse(false, 'invalid promo code.', [], 400);
            }
            if ((int)$promoRow['used_count'] >= (int)$promoRow['max_uses']) {
                jsonResponse(false, "promo code \"{$promoRow['code']}\" has reached its {$promoRow['max_uses']}-use limit.", [], 400);
            }
            $appliedPromo = $promoRow;
            $discountPercent = max(0, min(100, (int)$promoRow['discount_percent']));
        }

        $orderId = 'ord-' . bin2hex(random_bytes(4));
        $totalUsdCents = 0;
        $totalCents = 0;
        $lineItems = [];
        $normalizedItems = [];

        foreach ($items as $item) {
            $name = strtolower(trim($item['product'] ?? $item['name'] ?? 'orbit'));
            $plan = strtolower(trim($item['plan'] ?? '1 day'));
            $serverPriceUsd = getServerPlanPrice($plan);
            if ($serverPriceUsd === null) {
                jsonResponse(false, "invalid plan selected: {$plan}.", [], 400);
            }
            $qty = intval($item['quantity'] ?? 1);
            if ($qty < 1) $qty = 1;

            $discountedUsd = round($serverPriceUsd * ((100 - $discountPercent) / 100), 2);
            $unitChargeAmount = ($currency === 'AUD')
                ? round($discountedUsd * $fxRate, 2)
                : $discountedUsd;
            $itemCents = intval(round($unitChargeAmount * 100));
            $totalUsdCents += intval(round($discountedUsd * 100)) * $qty;
            $totalCents += ($itemCents * $qty);

            $normalizedItems[] = [
                'product' => $name,
                'plan' => $plan,
                'price' => $discountedUsd,
                'price_usd' => $discountedUsd,
                'original_price_usd' => $serverPriceUsd,
                'price_charged' => $unitChargeAmount,
                'charged_currency' => $currency,
                'fx_rate' => $fxRate,
                'promo_code' => $appliedPromo ? strtolower($appliedPromo['code']) : null,
                'quantity' => $qty
            ];

            $usdLabel = number_format($discountedUsd, 2);
            $lineItems[] = [
                'name' => "{$name} ({$plan} - \${$usdLabel} usd)",
                'quantity' => (string)$qty,
                'base_price_money' => [
                    'amount' => $itemCents,
                    'currency' => $currency
                ]
            ];
        }

        // Free order via 100% promo code (atomically consume 1 use and issue keys)
        if ($appliedPromo !== null && $totalCents === 0) {
            $isSqlite = ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite');
            if ($isSqlite) {
                $pdo->exec('BEGIN IMMEDIATE');
            } else {
                $pdo->beginTransaction();
            }

            try {
                $consumeStmt = $pdo->prepare(
                    'UPDATE promo_codes SET used_count = used_count + 1 WHERE id = ? AND active = 1 AND used_count < max_uses'
                );
                $consumeStmt->execute([$appliedPromo['id']]);

                if ($consumeStmt->rowCount() !== 1) {
                    $pdo->rollBack();
                    jsonResponse(false, "promo code \"{$appliedPromo['code']}\" has reached its {$appliedPromo['max_uses']}-use limit.", [], 400);
                }

                $orderStmt = $pdo->prepare(
                    'INSERT INTO orders (user_id, order_id, amount, currency, status, items, promo_code, tos_accepted, created_at) VALUES (?, ?, 0, ?, ?, ?, ?, 1, datetime("now"))'
                );
                $orderStmt->execute([
                    $userId,
                    $orderId,
                    $currency,
                    'completed',
                    json_encode($normalizedItems),
                    strtolower($appliedPromo['code'])
                ]);

                $redempStmt = $pdo->prepare(
                    'INSERT INTO promo_redemptions (promo_id, user_id, order_id, created_at) VALUES (?, ?, ?, datetime("now"))'
                );
                $redempStmt->execute([$appliedPromo['id'], $userId, $orderId]);

                $generatedKeys = issueKeysForOrder($pdo, [
                    'user_id' => $userId,
                    'order_id' => $orderId,
                    'items' => json_encode($normalizedItems)
                ]);

                $pdo->commit();
            } catch (Throwable $txErr) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $txErr;
            }

            $usesRemaining = max(0, (int)$appliedPromo['max_uses'] - ((int)$appliedPromo['used_count'] + 1));
            jsonResponse(true, "promo \"{$appliedPromo['code']}\" applied! free order completed ({$usesRemaining} use" . ($usesRemaining === 1 ? '' : 's') . " remaining).", [
                'instant_completion' => true,
                'promo_applied' => strtolower($appliedPromo['code']),
                'uses_remaining' => $usesRemaining,
                'order_id' => $orderId,
                'usd_total' => 0,
                'charged_total' => 0,
                'charged_currency' => $currency,
                'fx_rate' => $fxRate,
                'keys' => $generatedKeys,
                'user_id' => $userId
            ]);
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
                $orderStmt = $pdo->prepare('INSERT INTO orders (user_id, order_id, square_order_id, amount, currency, status, items, promo_code, tos_accepted, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, datetime("now"))');
                $orderStmt->execute([$userId, $orderId, $squareOrderId, $totalCents / 100, $currency, 'pending', json_encode($normalizedItems), $appliedPromo ? strtolower($appliedPromo['code']) : null]);

                jsonResponse(true, 'square checkout link created.', [
                    'checkout_url' => $resp['data']['payment_link']['url'],
                    'order_id' => $orderId,
                    'usd_total' => $totalUsdCents / 100,
                    'charged_total' => $totalCents / 100,
                    'charged_currency' => $currency,
                    'fx_rate' => $fxRate
                ]);
            }

            $errDetail = $resp['data']['errors'][0]['detail'] ?? ($resp['error'] ?: 'square payment link creation failed.');
            jsonResponse(false, 'square error: ' . strtolower($errDetail), [
                'square_http' => $resp['http_code']
            ], 502);
        }

        // Sandbox demo fallback ONLY when Square credentials have not been set in api/config.php
        $orderStmt = $pdo->prepare('INSERT INTO orders (user_id, order_id, amount, currency, status, items, promo_code, tos_accepted, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, datetime("now"))');
        $orderStmt->execute([$userId, $orderId, $totalCents / 100, $currency, 'pending', json_encode($normalizedItems), $appliedPromo ? strtolower($appliedPromo['code']) : null]);

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
            'usd_total' => $totalUsdCents / 100,
            'charged_total' => $totalCents / 100,
            'charged_currency' => $currency,
            'fx_rate' => $fxRate,
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
            header('Location: /dashboard.html');
            exit;
        }

        $stmt = $pdo->prepare('SELECT * FROM orders WHERE order_id = ? LIMIT 1');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            header('Location: /dashboard.html');
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
            header('Location: /dashboard.html?payment_success=1&order=' . urlencode($orderId));
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
                header('Location: /dashboard.html?payment_success=1&order=' . urlencode($orderId));
                exit;
            }

            header('Location: /dashboard.html?payment_pending=1&order=' . urlencode($orderId));
            exit;
        }

        issueKeysForOrder($pdo, $order);
        header('Location: /dashboard.html?payment_success=1&order=' . urlencode($orderId));
        exit;

    default:
        jsonResponse(false, 'invalid action. supported: config, rate, check_promo, create_checkout, verify_order, callback.', [], 400);
        break;
    }
} catch (Throwable $e) {
    jsonResponse(false, 'checkout error: ' . $e->getMessage(), [], 500);
}
