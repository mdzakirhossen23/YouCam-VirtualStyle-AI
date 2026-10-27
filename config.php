<?php
declare(strict_types=1);

/*
 * Keep secrets on the server. Prefer setting YOUCAM_API_KEY in cPanel's
 * environment/secret manager. If that is unavailable, replace the empty
 * fallback below after deployment and never commit the edited file.
 * Existing VirtualStyle installs keep the key in /home/ACCOUNT/youcam-config.php;
 * this compatibility lookup preserves that secure, outside-web-root setup.
 */
$legacyKey = '';
$legacyFile = dirname(__DIR__, 2) . '/youcam-config.php';
if (is_file($legacyFile)) {
    $legacyValue = require $legacyFile;
    $legacyKey = is_array($legacyValue) ? (string) ($legacyValue['api_key'] ?? '') : (is_string($legacyValue) ? $legacyValue : '');
}

return [
    'app_name' => 'YouCam VirtualStyle AI',
    'youcam_api_key' => getenv('YOUCAM_API_KEY') ?: $legacyKey,
    'youcam_base_url' => 'https://yce-api-01.makeupar.com',
    'allow_registration' => true,
    'retention_days' => 90,
    'trash_grace_days' => 7,
    'max_upload_bytes' => 10 * 1024 * 1024,
    'session_lifetime_seconds' => 60 * 60 * 12,
];
