<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/bootstrap.php';
$users = is_file(users_file()) ? json_decode((string) file_get_contents(users_file()), true) : [];
$count = 0;
foreach (is_array($users) ? $users : [] as $record) {
    $id = (string) ($record['id'] ?? '');
    if (preg_match('/^[a-f0-9]{32}$/', $id)) {
        cleanup_retention($id);
        $count++;
    }
}
fwrite(STDOUT, "VirtualStyle retention cleanup completed for {$count} users.\n");
