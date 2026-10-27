<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

$action = (string) ($_GET['action'] ?? 'session');

try {
    if ($action === 'health') {
        json_response(['ok' => true, 'php' => PHP_VERSION, 'storage_writable' => is_writable(VS_STORAGE),
            'curl' => function_exists('curl_init'), 'zip' => class_exists('ZipArchive'),
            'youcam_configured' => (string) cfg('youcam_api_key') !== '']);
    }

    if ($action === 'session') {
        $id = current_user_id();
        json_response(['ok' => true, 'authenticated' => (bool)$id, 'username' => $_SESSION['username'] ?? null,
            'csrf' => csrf_token(), 'allow_registration' => (bool) cfg('allow_registration'),
            'retention_days' => (int) cfg('retention_days')]);
    }

    if ($action === 'register' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        rate_limit('register', 5, 3600); verify_csrf();
        if (!cfg('allow_registration')) json_response(['ok' => false, 'error' => 'Registration is disabled.'], 403);
        $body = request_json(); $username = safe_username((string)($body['username'] ?? '')); $password = (string)($body['password'] ?? '');
        if (!preg_match('/^[a-z0-9_.-]{3,40}$/', $username)) json_response(['ok' => false, 'error' => 'Username must be 3–40 letters, numbers, dots, dashes or underscores.'], 400);
        if (strlen($password) < 10 || strlen($password) > 200) json_response(['ok' => false, 'error' => 'Password must be at least 10 characters.'], 400);
        $created = locked_json(users_file(), function(array $users) use ($username, $password) {
            if (isset($users[$username])) return ['data' => $users, 'return' => null];
            $id = random_id();
            $users[$username] = ['id' => $id, 'password' => password_hash($password, PASSWORD_DEFAULT), 'created_at' => gmdate('c')];
            return ['data' => $users, 'return' => $id];
        });
        if (!$created) json_response(['ok' => false, 'error' => 'Username is already in use.'], 409);
        $dir = user_dir($created); if (!is_dir($dir . '/files')) mkdir($dir . '/files', 0700, true);
        update_library($created, fn(array &$db) => null);
        session_regenerate_id(true); $_SESSION['user_id'] = $created; $_SESSION['username'] = $username; $_SESSION['csrf'] = bin2hex(random_bytes(24));
        json_response(['ok' => true, 'username' => $username, 'csrf' => csrf_token()]);
    }

    if ($action === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        rate_limit('login', 10, 900); verify_csrf();
        $body = request_json(); $username = safe_username((string)($body['username'] ?? '')); $password = (string)($body['password'] ?? '');
        $users = is_file(users_file()) ? json_decode((string)file_get_contents(users_file()), true) : [];
        $record = is_array($users) ? ($users[$username] ?? null) : null;
        if (!$record || !password_verify($password, (string)$record['password'])) json_response(['ok' => false, 'error' => 'Invalid username or password.'], 401);
        session_regenerate_id(true); $_SESSION['user_id'] = $record['id']; $_SESSION['username'] = $username; $_SESSION['csrf'] = bin2hex(random_bytes(24));
        cleanup_retention($record['id']);
        json_response(['ok' => true, 'username' => $username, 'csrf' => csrf_token()]);
    }

    if ($action === 'logout' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf(); $_SESSION = []; if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',time()-42000,$p['path'],$p['domain']??'',(bool)$p['secure'],(bool)$p['httponly']); }
        session_destroy(); json_response(['ok' => true]);
    }

    $userId = require_user();

    if ($action === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        rate_limit('upload', 30, 600); verify_csrf();
        $asset = save_uploaded_asset($userId, $_FILES['image'] ?? [], (string)($_POST['kind'] ?? ''));
        json_response(['ok' => true, 'asset' => $asset], 201);
    }

    if ($action === 'library') {
        cleanup_retention($userId); $db = read_library($userId);
        $assets = [];
        foreach ($db['assets'] as $asset) if (empty($asset['deleted_at'])) $assets[] = public_asset($asset);
        usort($assets, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));
        $tasks = array_values($db['tasks']); usort($tasks, fn($a,$b) => strcmp($b['created_at'], $a['created_at']));
        json_response(['ok' => true, 'assets' => $assets, 'tasks' => $tasks, 'retention_days' => (int)cfg('retention_days')]);
    }

    if ($action === 'file') {
        $asset = get_asset($userId, (string)($_GET['id'] ?? ''));
        header('Content-Type: ' . $asset['mime']); header('Content-Length: ' . filesize($asset['_absolute_path']));
        header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
        if (isset($_GET['download'])) header('Content-Disposition: attachment; filename="' . str_replace('"','', $asset['name']) . '"');
        readfile($asset['_absolute_path']); exit;
    }

    if ($action === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf(); $body = request_json(); $id = (string)($body['id'] ?? ''); get_asset($userId, $id);
        update_library($userId, function(array &$db) use ($id) { $db['assets'][$id]['deleted_at'] = gmdate('c'); });
        json_response(['ok' => true]);
    }

    if ($action === 'start' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        rate_limit('tryon', 10, 600); verify_csrf(); $body = request_json();
        $source = get_asset($userId, (string)($body['source_asset_id'] ?? ''));
        $garment = get_asset($userId, (string)($body['garment_asset_id'] ?? ''));
        if (!in_array($source['kind'], ['person','result'], true) || $garment['kind'] !== 'garment') json_response(['ok'=>false,'error'=>'Choose a person/result and garment image.'],400);
        $category = (string)($body['garment_category'] ?? 'auto');
        if (!in_array($category, ['auto','full_body','upper_body','lower_body','outer','shoes'], true)) $category='auto';
        $srcFileId = youcam_upload($source); $refFileId = youcam_upload($garment);
        $remote = youcam_json('POST', '/s2s/v2.0/task/cloth-v4', ['src_file_id'=>$srcFileId,'ref_file_id'=>$refFileId,'garment_category'=>$category,'filter_multi_person'=>'normal']);
        $taskId = (string)($remote['data']['task_id'] ?? ''); if ($taskId==='') throw new RuntimeException('YouCam did not return a task ID.');
        $localId = random_id();
        $task = ['id'=>$localId,'remote_task_id'=>$taskId,'source_asset_id'=>$source['id'],'garment_asset_id'=>$garment['id'],
            'result_asset_id'=>null,'status'=>'running','category'=>$category,'created_at'=>gmdate('c'),'updated_at'=>gmdate('c'),'error'=>null];
        update_library($userId, function(array &$db) use ($task){$db['tasks'][$task['id']]=$task;});
        json_response(['ok'=>true,'task_id'=>$localId],201);
    }

    if ($action === 'poll') {
        $localId=(string)($_GET['task_id']??''); $db=read_library($userId); $task=$db['tasks'][$localId]??null;
        if(!$task) json_response(['ok'=>false,'error'=>'Task not found.'],404);
        if($task['status']==='success') { $asset=get_asset($userId,(string)$task['result_asset_id']); json_response(['ok'=>true,'status'=>'success','asset'=>public_asset($asset)]); }
        if($task['status']==='error') json_response(['ok'=>true,'status'=>'error','error'=>$task['error']??'Generation failed.']);
        $remote=youcam_json('GET','/s2s/v2.0/task/cloth-v4/'.rawurlencode((string)$task['remote_task_id']));
        $status=(string)($remote['data']['task_status']??'running');
        if($status==='success'){
            $url=(string)($remote['data']['results']['url']??''); if($url==='') throw new RuntimeException('Result URL missing.');
            $source=get_asset($userId,(string)$task['source_asset_id']); $version=(int)($source['version']??1)+1;
            $result=download_result_asset($userId,$url,$source['id'],$version);
            update_library($userId,function(array &$db)use($localId,$result){$db['tasks'][$localId]['status']='success';$db['tasks'][$localId]['result_asset_id']=$result['id'];$db['tasks'][$localId]['updated_at']=gmdate('c');});
            json_response(['ok'=>true,'status'=>'success','asset'=>public_asset($result)]);
        }
        if($status==='error'){
            $err=$remote['data']['error']??'YouCam processing failed.'; if(is_array($err))$err=json_encode($err);
            update_library($userId,function(array &$db)use($localId,$err){$db['tasks'][$localId]['status']='error';$db['tasks'][$localId]['error']=$err;$db['tasks'][$localId]['updated_at']=gmdate('c');});
            json_response(['ok'=>true,'status'=>'error','error'=>$err]);
        }
        json_response(['ok'=>true,'status'=>$status?:'running']);
    }

    if ($action === 'export') {
        $db=read_library($userId); $manifest=['exported_at'=>gmdate('c'),'retention_days'=>(int)cfg('retention_days'),'library'=>$db];
        if(class_exists('ZipArchive')){
            $tmp=tempnam(sys_get_temp_dir(),'vszip-');$zip=new ZipArchive();$zip->open($tmp,ZipArchive::OVERWRITE);
            $zip->addFromString('manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
            foreach($db['assets'] as $a)if(empty($a['deleted_at'])){$p=user_dir($userId).'/'.$a['path'];if(is_file($p))$zip->addFile($p,'images/'.$a['name'].'-'.$a['id'].'.'.pathinfo($p,PATHINFO_EXTENSION));}
            $zip->close();header('Content-Type: application/zip');header('Content-Disposition: attachment; filename="virtualstyle-export-'.gmdate('Ymd').'.zip"');header('Content-Length: '.filesize($tmp));readfile($tmp);@unlink($tmp);exit;
        }
        header('Content-Type: application/json');header('Content-Disposition: attachment; filename="virtualstyle-export-'.gmdate('Ymd').'.json"');echo json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);exit;
    }

    json_response(['ok'=>false,'error'=>'Unknown action.'],404);
} catch (Throwable $e) {
    error_log('VirtualStyle: '.$e->getMessage());
    json_response(['ok'=>false,'error'=>$e->getMessage()],500);
}
