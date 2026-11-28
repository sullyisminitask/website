<?php
/**
 * Cloudflare Turnstile Server-Side Verification Helper
 */

function getTurnstileConfig(): array {
    $configFile = __DIR__ . '/config.php';
    if (file_exists($configFile)) {
        $config = require $configFile;
        if (!empty($config['turnstile']) && is_array($config['turnstile'])) {
            return $config['turnstile'];
        }
    }
    return [
        'enabled' => false,
        'site_key' => getenv('TURNSTILE_SITE_KEY') ?: '',
        'secret_key' => getenv('TURNSTILE_SECRET_KEY') ?: ''
    ];
}

function isRealTurnstileKey(string $val): bool {
    $v = trim($val);
    if ($v === '') return false;
    if (stripos($v, 'YOUR_CLOUDFLARE_') !== false) return false;
    if (stripos($v, 'YOUR_TURNSTILE_') !== false) return false;
    return true;
}

function isTurnstileEnabled(): bool {
    $cfg = getTurnstileConfig();
    if (isset($cfg['enabled']) && !$cfg['enabled']) {
        return false;
    }
    return isRealTurnstileKey($cfg['site_key'] ?? '') && isRealTurnstileKey($cfg['secret_key'] ?? '');
}

function getTurnstilePublicConfig(): array {
    $cfg = getTurnstileConfig();
    $active = isTurnstileEnabled();
    return [
        'enabled' => $active,
        'site_key' => $active ? trim($cfg['site_key']) : ''
    ];
}

function verifyTurnstileToken(string $token): array {
    if (!isTurnstileEnabled()) {
        return ['ok' => true, 'enforced' => false];
    }

    $token = trim($token);
    if ($token === '') {
        return [
            'ok' => false,
            'enforced' => true,
            'reason' => 'please complete the cloudflare verification check.'
        ];
    }

    $cfg = getTurnstileConfig();
    $secret = trim($cfg['secret_key'] ?? '');
    $remoteIp = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? '');

    $postFields = [
        'secret' => $secret,
        'response' => $token
    ];
    if ($remoteIp !== '') {
        $postFields['remoteip'] = $remoteIp;
    }

    $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode < 200 || $httpCode >= 300 || !is_string($res)) {
        return [
            'ok' => false,
            'enforced' => true,
            'reason' => 'unable to contact cloudflare verification service. please try again.'
        ];
    }

    $data = json_decode($res, true);
    if (!is_array($data) || empty($data['success'])) {
        return [
            'ok' => false,
            'enforced' => true,
            'reason' => 'cloudflare verification failed or expired. please try again.'
        ];
    }

    return ['ok' => true, 'enforced' => true];
}
