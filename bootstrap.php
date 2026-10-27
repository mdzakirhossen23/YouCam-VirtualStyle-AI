<?php
declare(strict_types=1);

const VS_ROOT = __DIR__;
const VS_STORAGE = VS_ROOT . DIRECTORY_SEPARATOR . 'storage';

function cfg(?string $key = null) {
    static $config;
    if ($config === null) {
        $config = require VS_ROOT . '/config.php';
    }
    return $key === null ? $config : ($config[$key] ?? null);
}

function ensure_storage(): void {
    foreach ([VS_STORAGE, VS_STORAGE . '/users', VS_STORAGE . '/rate'] as $dir) {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Storage directory is not writable.');
        }
    }
    $deny = VS_STORAGE . '/.htaccess';
    if (!is_file($deny)) {
        @file_put_contents($deny, "Require all denied\nDeny from all\n");
    }
}

function start_secure_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    session_name('virtualstyle_session');
    session_set_cookie_params([
        'lifetime' => (int) cfg('session_lifetime_seconds'),
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (empty($_SESSION['created_at'])) {
        session_regenerate_id(true);
        $_SESSION['created_at'] = time();
    }
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function request_json(): array {
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) json_response(['ok' => false, 'error' => 'Invalid JSON request.'], 400);
    return $data;
}

function random_id(int $bytes = 16): string {
    return bin2hex(random_bytes($bytes));
}

function safe_username(string $value): string {
    return strtolower(trim($value));
}

function users_file(): string { return VS_STORAGE . '/users.json'; }

function locked_json(string $path, callable $callback) {
    $dir = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $fh = fopen($path, 'c+');
    if (!$fh) throw new RuntimeException('Could not open application data.');
    try {
        if (!flock($fh, LOCK_EX)) throw new RuntimeException('Could not lock application data.');
        rewind($fh);
        $raw = stream_get_contents($fh);
        $data = $raw ? json_decode($raw, true) : [];
        if (!is_array($data)) $data = [];
        $result = $callback($data);
        if (is_array($result) && array_key_exists('data', $result)) {
            ftruncate($fh, 0); rewind($fh);
            fwrite($fh, json_encode($result['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            fflush($fh);
            return $result['return'] ?? null;
        }
        return $result;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
        @chmod($path, 0600);
    }
}

function current_user_id(): ?string { return $_SESSION['user_id'] ?? null; }

function require_user(): string {
    $id = current_user_id();
    if (!$id) json_response(['ok' => false, 'error' => 'Authentication required.'], 401);
    return $id;
}

function user_dir(string $userId): string {
    if (!preg_match('/^[a-f0-9]{32}$/', $userId)) throw new RuntimeException('Invalid user.');
    return VS_STORAGE . '/users/' . $userId;
}

function user_db_path(string $userId): string { return user_dir($userId) . '/library.json'; }

function empty_library(): array {
    return ['assets' => [], 'tasks' => [], 'created_at' => gmdate('c')];
}

function read_library(string $userId): array {
    $path = user_db_path($userId);
    if (!is_file($path)) return empty_library();
    $raw = file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : [];
    return is_array($data) ? array_merge(empty_library(), $data) : empty_library();
}

function update_library(string $userId, callable $callback) {
    return locked_json(user_db_path($userId), function(array $data) use ($callback) {
        $data = array_merge(empty_library(), $data);
        $return = $callback($data);
        return ['data' => $data, 'return' => $return];
    });
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(24));
    return $_SESSION['csrf'];
}

function verify_csrf(): void {
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf'] ?? '');
    if (!$provided || !hash_equals(csrf_token(), (string) $provided)) {
        json_response(['ok' => false, 'error' => 'Security token expired. Refresh and try again.'], 419);
    }
}

function client_key(): string {
    return hash('sha256', (string) ($_SERVER['REMOTE_ADDR'] ?? 'local'));
}

function rate_limit(string $bucket, int $max, int $windowSeconds): void {
    $path = VS_STORAGE . '/rate/' . preg_replace('/[^a-z0-9_-]/i', '', $bucket) . '-' . client_key() . '.json';
    $allowed = locked_json($path, function(array $data) use ($max, $windowSeconds) {
        $now = time();
        $hits = array_values(array_filter($data['hits'] ?? [], fn($t) => is_int($t) && $t > $now - $windowSeconds));
        if (count($hits) >= $max) return ['data' => ['hits' => $hits], 'return' => false];
        $hits[] = $now;
        return ['data' => ['hits' => $hits], 'return' => true];
    });
    if (!$allowed) json_response(['ok' => false, 'error' => 'Too many attempts. Please wait and try again.'], 429);
}

function validate_upload(array $file): array {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_response(['ok' => false, 'error' => 'Upload failed.'], 400);
    }
    $size = (int) ($file['size'] ?? 0);
    if ($size < 1 || $size > (int) cfg('max_upload_bytes')) {
        json_response(['ok' => false, 'error' => 'Image must be under 10 MB.'], 400);
    }
    $tmp = (string) ($file['tmp_name'] ?? '');
    $info = @getimagesize($tmp);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    $mime = is_array($info) ? ($info['mime'] ?? '') : '';
    if (!isset($allowed[$mime])) json_response(['ok' => false, 'error' => 'Only genuine JPEG and PNG images are accepted.'], 400);
    $width = (int) ($info[0] ?? 0); $height = (int) ($info[1] ?? 0);
    if ($width < 128 || $height < 128 || max($width, $height) > 4096) {
        json_response(['ok' => false, 'error' => 'Image dimensions must be at least 128×128 and no side over 4096 px.'], 400);
    }
    return ['tmp' => $tmp, 'size' => $size, 'mime' => $mime, 'ext' => $allowed[$mime], 'width' => $width, 'height' => $height];
}

function clean_name(string $name): string {
    $name = preg_replace('/[^\pL\pN._ -]+/u', '_', basename($name));
    $name = $name ?: 'image';
    return function_exists('mb_substr') ? mb_substr($name, 0, 120) : substr($name, 0, 120);
}

function save_uploaded_asset(string $userId, array $file, string $kind, ?string $parentId = null): array {
    if (!in_array($kind, ['person', 'garment'], true)) json_response(['ok' => false, 'error' => 'Invalid image type.'], 400);
    $v = validate_upload($file);
    $id = random_id();
    $dir = user_dir($userId) . '/files';
    if (!is_dir($dir)) mkdir($dir, 0700, true);
    $relative = 'files/' . $id . '.' . $v['ext'];
    $destination = user_dir($userId) . '/' . $relative;
    if (!move_uploaded_file($v['tmp'], $destination)) json_response(['ok' => false, 'error' => 'Could not save image.'], 500);
    @chmod($destination, 0600);
    $asset = [
        'id' => $id, 'kind' => $kind, 'parent_id' => $parentId, 'version' => 1,
        'name' => clean_name((string) ($file['name'] ?? 'image.' . $v['ext'])),
        'path' => $relative, 'mime' => $v['mime'], 'size' => $v['size'],
        'width' => $v['width'], 'height' => $v['height'], 'sha256' => hash_file('sha256', $destination),
        'created_at' => gmdate('c'), 'deleted_at' => null,
    ];
    update_library($userId, function(array &$db) use ($asset) { $db['assets'][$asset['id']] = $asset; });
    return public_asset($asset);
}

function public_asset(array $asset): array {
    return [
        'id' => $asset['id'], 'kind' => $asset['kind'], 'parent_id' => $asset['parent_id'] ?? null,
        'version' => $asset['version'] ?? 1, 'name' => $asset['name'], 'mime' => $asset['mime'],
        'size' => $asset['size'], 'width' => $asset['width'] ?? null, 'height' => $asset['height'] ?? null,
        'created_at' => $asset['created_at'],
        'view_url' => 'api.php?action=file&id=' . rawurlencode($asset['id']),
        'download_url' => 'api.php?action=file&id=' . rawurlencode($asset['id']) . '&download=1',
    ];
}

function get_asset(string $userId, string $id, bool $includeDeleted = false): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $id)) json_response(['ok' => false, 'error' => 'Image not found.'], 404);
    $db = read_library($userId);
    $asset = $db['assets'][$id] ?? null;
    if (!$asset || (!$includeDeleted && !empty($asset['deleted_at']))) json_response(['ok' => false, 'error' => 'Image not found.'], 404);
    $path = user_dir($userId) . '/' . $asset['path'];
    if (!is_file($path)) json_response(['ok' => false, 'error' => 'Stored file is missing.'], 404);
    $asset['_absolute_path'] = $path;
    return $asset;
}

function cleanup_retention(string $userId): void {
    $cutoff = time() - ((int) cfg('retention_days') * 86400);
    $trashCutoff = time() - ((int) cfg('trash_grace_days') * 86400);
    update_library($userId, function(array &$db) use ($userId, $cutoff, $trashCutoff) {
        foreach ($db['assets'] as $id => &$asset) {
            $created = strtotime((string) ($asset['created_at'] ?? '')) ?: time();
            $deleted = !empty($asset['deleted_at']) ? (strtotime($asset['deleted_at']) ?: time()) : null;
            if (($created < $cutoff && !$deleted) || ($deleted && $deleted < $trashCutoff)) {
                $path = user_dir($userId) . '/' . ($asset['path'] ?? '');
                if (is_file($path)) @unlink($path);
                unset($db['assets'][$id]);
            }
        }
        unset($asset);
    });
}

function http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeout = 60): array {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $headers, CURLOPT_HEADER => true]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        if ($raw === false) { $e = curl_error($ch); curl_close($ch); throw new RuntimeException('Network request failed: ' . $e); }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $responseBody = substr($raw, $headerSize); curl_close($ch);
        return ['status' => $status, 'body' => $responseBody];
    }
    $context = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers),
        'content' => $body ?? '', 'timeout' => $timeout, 'ignore_errors' => true]]);
    $responseBody = @file_get_contents($url, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $line) if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $m)) $status = (int) $m[1];
    if ($responseBody === false) throw new RuntimeException('Network request failed. Enable PHP cURL.');
    return ['status' => $status, 'body' => $responseBody];
}

function youcam_json(string $method, string $path, ?array $payload = null): array {
    $key = (string) cfg('youcam_api_key');
    if ($key === '') json_response(['ok' => false, 'error' => 'YouCam API key is not configured on the server.'], 503);
    $headers = ['Authorization: Bearer ' . $key, 'Content-Type: application/json', 'Accept: application/json'];
    $response = http_request($method, rtrim((string) cfg('youcam_base_url'), '/') . $path, $headers,
        $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES), 90);
    $data = json_decode($response['body'], true);
    if (!is_array($data)) throw new RuntimeException('YouCam returned an invalid response.');
    if ($response['status'] < 200 || $response['status'] >= 300 || (isset($data['status']) && (int)$data['status'] >= 400)) {
        $message = $data['error'] ?? $data['message'] ?? ('YouCam request failed (' . $response['status'] . ').');
        throw new RuntimeException(is_string($message) ? $message : 'YouCam request failed.');
    }
    return $data;
}

function youcam_upload(array $asset): string {
    $create = youcam_json('POST', '/s2s/v2.0/file', ['files' => [[
        'content_type' => $asset['mime'], 'file_name' => $asset['name'], 'file_size' => $asset['size'],
    ]]]);
    $remote = $create['data']['files'][0] ?? null;
    $request = $remote['requests'][0] ?? null;
    if (!$remote || !$request || empty($remote['file_id']) || empty($request['url'])) throw new RuntimeException('YouCam upload slot was not created.');
    $bytes = file_get_contents($asset['_absolute_path']);
    if ($bytes === false) throw new RuntimeException('Could not read saved image.');
    $headers = [];
    foreach (($request['headers'] ?? []) as $name => $value) $headers[] = $name . ': ' . $value;
    if (!$headers) $headers = ['Content-Type: ' . $asset['mime'], 'Content-Length: ' . strlen($bytes)];
    $uploaded = http_request(strtoupper((string) ($request['method'] ?? 'PUT')), (string) $request['url'], $headers, $bytes, 120);
    if ($uploaded['status'] < 200 || $uploaded['status'] >= 300) throw new RuntimeException('YouCam image upload failed.');
    return (string) $remote['file_id'];
}

function download_result_asset(string $userId, string $url, string $parentId, int $version): array {
    $parts = parse_url($url);
    if (($parts['scheme'] ?? '') !== 'https') throw new RuntimeException('Unsafe result URL.');
    $response = http_request('GET', $url, ['Accept: image/jpeg,image/png'], null, 120);
    if ($response['status'] !== 200 || strlen($response['body']) < 100) throw new RuntimeException('Could not save the generated result.');
    $tmp = tempnam(sys_get_temp_dir(), 'vs-');
    file_put_contents($tmp, $response['body']);
    $info = @getimagesize($tmp); $mime = $info['mime'] ?? '';
    $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/jpeg' ? 'jpg' : '');
    if (!$ext || strlen($response['body']) > (int) cfg('max_upload_bytes') * 2) { @unlink($tmp); throw new RuntimeException('Generated result was not a valid image.'); }
    $id = random_id(); $dir = user_dir($userId) . '/files'; if (!is_dir($dir)) mkdir($dir, 0700, true);
    $relative = 'files/' . $id . '.' . $ext; $destination = user_dir($userId) . '/' . $relative;
    rename($tmp, $destination); @chmod($destination, 0600);
    $asset = ['id' => $id, 'kind' => 'result', 'parent_id' => $parentId, 'version' => $version,
        'name' => 'virtualstyle-result-v' . $version . '.' . $ext, 'path' => $relative, 'mime' => $mime,
        'size' => filesize($destination), 'width' => (int)($info[0] ?? 0), 'height' => (int)($info[1] ?? 0),
        'sha256' => hash_file('sha256', $destination), 'created_at' => gmdate('c'), 'deleted_at' => null];
    update_library($userId, function(array &$db) use ($asset) { $db['assets'][$asset['id']] = $asset; });
    return $asset;
}

ensure_storage();
start_secure_session();
