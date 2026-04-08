<?php
/**
 * Ultimate App Launcher v10.5 (Pro Integrated Edition)
 * セキュリティ・管理・UXを極限まで強化した決定版
 * 改修内容：詳細モーダルの起動ボタンを大型化し、説明文の下へ配置
 */

header('Content-Type: text/html; charset=UTF-8');
session_start();
date_default_timezone_set('Asia/Tokyo');
setlocale(LC_ALL, 'ja_JP.UTF-8');

// --- 設定 ---
$ADMIN_PASSWORD_HASH = '$2b$12$9Q55snAzFdQ6.eJ9mn/OW.AzE0x76FZqY5SCWH5jwoKMFOyfkt8bq'; 
$CSV_FILE = "apps.csv";
$COMMENTS_FILE = "comments.csv";
$ACTION_LOG_FILE = "action_log.csv";
$SITE_LOG_FILE = "site_access.csv";
$UPLOAD_DIR = "uploads";

// --- 初期化 & CSRF対策 ---
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$header_definition = ['id', 'title', 'content', 'url', 'thumbnail', 'created_at', 'updated_at', 'launches', 'sort_order', 'likes', 'comments_count', 'operation_method'];
$comment_header = ['comment_id', 'app_id', 'user_name', 'comment_text', 'created_at'];
$action_log_header = ['timestamp', 'ip', 'action', 'target_id', 'user_agent'];
$site_log_header = ['timestamp', 'ip', 'uri', 'referrer', 'user_agent'];

if (!is_dir($UPLOAD_DIR)) mkdir($UPLOAD_DIR, 0755, true);
foreach([$CSV_FILE => $header_definition, $COMMENTS_FILE => $comment_header, $ACTION_LOG_FILE => $action_log_header, $SITE_LOG_FILE => $site_log_header] as $f => $h) {
    if (!file_exists($f)) { $fp = fopen($f, 'w'); fputcsv($fp, $h); fclose($fp); }
}

// --- 関数群 ---
function get_csv_data($file, $header_def) {
    $rows = [];
    if (($handle = fopen($file, "r")) !== FALSE) {
        flock($handle, LOCK_SH); fgetcsv($handle); 
        while (($data = fgetcsv($handle)) !== FALSE) {
            while(count($data) < count($header_def)) $data[] = "0";
            $rows[] = array_combine($header_def, array_slice($data, 0, count($header_def)));
        }
        flock($handle, LOCK_UN); fclose($handle);
    }
    return $rows;
}
function save_csv_data($file, $rows, $header_def) {
    $fp = fopen($file, 'w');
    if (flock($fp, LOCK_EX)) {
        fputcsv($fp, $header_def);
        foreach ($rows as $row) {
            $line = []; foreach($header_def as $key) $line[] = $row[$key] ?? "0";
            fputcsv($fp, $line);
        }
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}
function write_log($file, $data) {
    $fp = fopen($file, 'a');
    if (flock($fp, LOCK_EX)) { fputcsv($fp, array_merge([date('Y-m-d H:i:s'), $_SERVER['REMOTE_ADDR']], $data)); flock($fp, LOCK_UN); }
    fclose($fp);
}
function delete_local_image($path) {
    global $UPLOAD_DIR;
    if (strpos($path, $UPLOAD_DIR) === 0 && file_exists($path)) @unlink($path);
}

// 自動PV記録
if (!isset($_GET['ajax'])) write_log($SITE_LOG_FILE, [$_SERVER['REQUEST_URI'], $_SERVER['HTTP_REFERER'] ?? 'Direct', $_SERVER['HTTP_USER_AGENT']]);

// --- アクション制御 ---
$isLoggedIn = $_SESSION['is_admin'] ?? false;
$error = "";

// AJAX系処理
if (isset($_GET['ajax_like'])) {
    $apps = get_csv_data($CSV_FILE, $header_definition);
    foreach ($apps as &$app) { if ($app['id'] == $_GET['ajax_like']) { $app['likes']++; save_csv_data($CSV_FILE, $apps, $header_definition); echo $app['likes']; exit; } }
}
if (isset($_GET['ajax_get_comments'])) {
    $comments = get_csv_data($COMMENTS_FILE, $comment_header);
    header('Content-Type: application/json');
    echo json_encode(array_reverse(array_filter($comments, function($c){ return $c['app_id'] == $_GET['ajax_get_comments']; }))); exit;
}
if (isset($_GET['ajax_get_logs']) && $isLoggedIn) {
    $file = ($_GET['ajax_get_logs'] === 'site') ? $SITE_LOG_FILE : $ACTION_LOG_FILE;
    $head = ($_GET['ajax_get_logs'] === 'site') ? $site_log_header : $action_log_header;
    header('Content-Type: application/json');
    echo json_encode(array_reverse(array_slice(get_csv_data($file, $head), -100))); exit;
}

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] !== 'login' && (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token'])) {
        die('Invalid CSRF Token');
    }
    $action = $_POST['action'];
    if ($action === 'login') {
        if (password_verify($_POST['password'], $ADMIN_PASSWORD_HASH)) { 
            $_SESSION['is_admin'] = true; 
            write_log($ACTION_LOG_FILE, ["login_success", "Admin", ""]); 
            header("Location: index.php"); exit; 
        } else { 
            write_log($ACTION_LOG_FILE, ["login_failure", $_SERVER['REMOTE_ADDR'], ""]); 
            $error = "パスワードがちがうよ！"; 
        }
    }
    if ($action === 'post_comment') {
        $comments = get_csv_data($COMMENTS_FILE, $comment_header);
        $comments[] = ['comment_id'=>time(), 'app_id'=>$_POST['app_id'], 'user_name'=>strip_tags($_POST['user_name'])?:"ななしさん", 'comment_text'=>strip_tags($_POST['comment_text']), 'created_at'=>date('Y/m/d H:i')];
        save_csv_data($COMMENTS_FILE, $comments, $comment_header);
        $apps = get_csv_data($CSV_FILE, $header_definition);
        foreach($apps as &$app){ if($app['id']==$_POST['app_id']) $app['comments_count']++; }
        save_csv_data($CSV_FILE, $apps, $header_definition); echo "ok"; exit;
    }
    if ($action === 'sort' && $isLoggedIn) {
        $apps = get_csv_data($CSV_FILE, $header_definition);
        foreach ($apps as &$app) { $pos = array_search($app['id'], $_POST['order']); $app['sort_order'] = ($pos !== false) ? $pos : 999; }
        save_csv_data($CSV_FILE, $apps, $header_definition);
        write_log($ACTION_LOG_FILE, ["sort_apps", "all", ""]); exit('ok');
    }
    if (in_array($action, ['register', 'update']) && $isLoggedIn) {
        $apps = get_csv_data($CSV_FILE, $header_definition);
        $id = ($action === 'update') ? $_POST['id'] : (string)time(); $now = date('Y/m/d');
        $imgPath = $_POST['current_thumbnail'] ?? 'https://images.unsplash.com/photo-1614332287897-cdc485fa562d?w=800&q=80';
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === 0) {
            $path = $UPLOAD_DIR . '/' . time() . "_" . uniqid() . "." . pathinfo($_FILES['thumbnail']['name'], PATHINFO_EXTENSION);
            if (move_uploaded_file($_FILES['thumbnail']['tmp_name'], $path)) {
                if ($action === 'update') delete_local_image($_POST['current_thumbnail']);
                $imgPath = $path;
            }
        }
        $op_method = $_POST['operation_method'] ?? '未設定';
        if ($action === 'register') {
            $apps[] = [
                'id'=>$id, 'title'=>$_POST['title'], 'content'=>$_POST['content'], 
                'url'=>$_POST['url'], 'thumbnail'=>$imgPath, 'created_at'=>$now, 
                'updated_at'=>$now, 'launches'=>0, 'sort_order'=>count($apps), 
                'likes'=>0, 'comments_count'=>0, 'operation_method'=>$op_method
            ];
            write_log($ACTION_LOG_FILE, ["register_app", $id, $_POST['title']]);
        } else {
            foreach ($apps as &$app) { 
                if ($app['id'] == $id) { 
                    $app['title']=$_POST['title']; $app['content']=$_POST['content']; 
                    $app['url']=$_POST['url']; $app['thumbnail'] = $imgPath; 
                    $app['updated_at']=$now; $app['operation_method']=$op_method;
                } 
            }
            write_log($ACTION_LOG_FILE, ["update_app", $id, $_POST['title']]);
        }
        save_csv_data($CSV_FILE, $apps, $header_definition); header("Location: index.php"); exit;
    }
}

// GETアクション
if (isset($_GET['launch'])) {
    $apps = get_csv_data($CSV_FILE, $header_definition);
    foreach ($apps as &$app) { 
        if ($app['id'] == $_GET['launch']) { 
            $app['launches']++; 
            save_csv_data($CSV_FILE, $apps, $header_definition); 
            write_log($ACTION_LOG_FILE, ["launch", $app['id'], $app['title']]); 
            $target_url = $app['url'];
            $sep = (strpos($target_url, '?') === false) ? '?' : '&';
            header("Location: " . $target_url . $sep . "ref=launcher"); exit; 
        } 
    }
}
if (isset($_GET['delete']) && $isLoggedIn) {
    $apps = get_csv_data($CSV_FILE, $header_definition);
    foreach($apps as $app) { if($app['id'] == $_GET['delete']) delete_local_image($app['thumbnail']); }
    $filtered = array_filter($apps, function($a) { return $a['id'] != $_GET['delete']; });
    save_csv_data($CSV_FILE, $filtered, $header_definition); 
    write_log($ACTION_LOG_FILE, ["delete_app", $_GET['delete'], ""]);
    header("Location: index.php"); exit;
}
if (isset($_GET['logout'])) { session_destroy(); header("Location: index.php"); exit; }

$all_apps = get_csv_data($CSV_FILE, $header_definition);
usort($all_apps, function($a, $b) { return (int)($a['sort_order'] ?? 0) - (int)($b['sort_order'] ?? 0); });

// --- 合計計算 ---
$total_apps_count = count($all_apps);
$total_launches_count = array_sum(array_column($all_apps, 'launches'));

// --- OGPメタデータ生成 ---
$protocol = empty($_SERVER['HTTPS']) ? 'http://' : 'https://';
$base_url = $protocol . $_SERVER['HTTP_HOST'] . strtok($_SERVER["REQUEST_URI"], '?');
$ogp = [
    'title' => 'EyeMoT Simple Games',
    'desc'  => '手軽に療育ゲームを楽しもう! 視線入力やスイッチ操作に対応したWebアプリランチャーです。',
    'image' => $base_url . '/ogp-default.jpg',
    'url'   => $base_url,
    'type'  => 'website'
];

$target_app_id = $_GET['app_id'] ?? null;
if ($target_app_id) {
    foreach ($all_apps as $app) {
        if ($app['id'] == $target_app_id) {
            $ogp['title'] = $app['title'] . ' | EyeMoT Simple Games';
            $ogp['desc']  = mb_strimwidth($app['content'], 0, 100, "...");
            if (preg_match('/^https?:\/\//', $app['thumbnail'])) {
                $ogp['image'] = $app['thumbnail'];
            } else {
                $ogp['image'] = $base_url . '/' . $app['thumbnail'];
            }
            $ogp['url'] = $base_url . '?app_id=' . $app['id'];
            $ogp['type'] = 'article';
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($ogp['title']) ?></title>
    
    <meta property="og:title" content="<?= htmlspecialchars($ogp['title']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($ogp['desc']) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogp['image']) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($ogp['url']) ?>">
    <meta property="og:type" content="<?= htmlspecialchars($ogp['type']) ?>">
    <meta property="og:site_name" content="EyeMoT Simple Games">
    
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($ogp['title']) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($ogp['desc']) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogp['image']) ?>">

    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Fredoka:wght@400;700&display=swap');
        body { font-family: 'Fredoka', sans-serif; background: #FFD600; color: #333; }
        .energetic-card { background: #fff; border: 4px solid #000; box-shadow: 8px 8px 0px #000; transition: all 0.2s; position: relative; }
        .energetic-card:hover { transform: translate(-2px, -2px); box-shadow: 12px 12px 0px #000; }
        .btn-pop { border: 3px solid #000; box-shadow: 4px 4px 0px #000; transition: all 0.1s; display: inline-flex; align-items: center; justify-content: center; font-weight: 900; cursor: pointer; }
        .btn-pop:active { transform: translate(2px, 2px); box-shadow: 0px 0px 0px #000; }
        .thick-input { border: 4px solid #000 !important; border-radius: 1rem; padding: 0.8rem; width: 100%; font-weight: bold; outline: none; }
        .modal-label { display: inline-block; background: #000; color: #fff; padding: 2px 12px; border-radius: 8px 8px 0 0; font-size: 0.75rem; font-weight: 900; }
        .stat-badge { border: 2px solid #000; font-size: 0.7rem; font-weight: 900; padding: 2px 8px; border-radius: 99px; box-shadow: 2px 2px 0px #000; background: #fff; }
        .custom-scrollbar::-webkit-scrollbar { width: 8px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #333; border-radius: 10px; }
        .sortable-ghost { opacity: 0.3; }
        .new-label { 
            position: absolute; top: -15px; right: -15px; background: #ff006e; color: #fff; 
            padding: 4px 14px; border: 4px solid #000; font-weight: 900; transform: rotate(12deg); 
            box-shadow: 6px 6px 0px #000; z-index: 20; font-size: 0.9rem; pointer-events: none;
        }
        .share-btn { width: 40px; height: 40px; display: flex; align-items: center; justify-content: center; border-radius: 50%; border: 2px solid #000; color: #fff; transition: transform 0.1s; }
        .share-btn:hover { transform: scale(1.1); }
        .share-twitter { background-color: #000; }
        .share-facebook { background-color: #1877F2; }
        .share-line { background-color: #06C755; }
        .share-copy { background-color: #64748b; }
    </style>
</head>
<body class="<?= $isLoggedIn ? 'is-admin' : '' ?>">

<div class="absolute top-4 right-4 z-[60] flex flex-col gap-2 items-end pointer-events-none md:pointer-events-auto">
    <div class="energetic-card bg-white px-4 py-2 rounded-2xl flex gap-4 items-center scale-90 md:scale-100 origin-top-right">
        <div class="text-center">
            <p class="text-[10px] font-black text-slate-500 leading-none mb-1 uppercase">Apps</p>
            <p class="text-xl font-black leading-none"><?= $total_apps_count ?></p>
        </div>
        <div class="w-[2px] h-8 bg-black"></div>
        <div class="text-center">
            <p class="text-[10px] font-black text-slate-500 leading-none mb-1 uppercase">Total Plays</p>
            <p class="text-xl font-black leading-none"><?= number_format($total_launches_count) ?></p>
        </div>
    </div>
</div>

<div class="container mx-auto px-4 max-w-6xl pb-20">
    <header class="py-12 text-left transform -rotate-1 relative">
        <h1 class="text-4xl md:text-6xl font-black bg-white border-4 border-black inline-block px-6 py-2 shadow-[8px_8px_0px_#000]">EyeMoT Simple Games</h1>
        <p class="text-lg md:text-xl font-bold mt-4 bg-pink-500 text-white inline-block px-4 py-1 border-2 border-black shadow-[4px_4px_0px_#000]">手軽に療育ゲームを楽しもう!</p>
    </header>

    <section class="mb-8">
        <div class="energetic-card rounded-[1.5rem] p-4 bg-blue-50 border-blue-400 max-w-4xl mx-auto flex flex-row gap-4 items-center">
            <div class="bg-blue-500 text-white w-10 h-10 flex items-center justify-center rounded-xl shadow-[2px_2px_0px_#000] border-2 border-black shrink-0 font-black text-sm">★</div>
            <div>
                <h2 class="text-lg font-black italic uppercase mb-1 leading-tight">はじめにお読みください</h2>
                <ul class="list-disc list-inside space-y-0.5 font-bold text-slate-700 text-xs md:text-sm">
                    <li>視線入力で操作するには、<a href="https://www.poran.net/ito/download/eyemot-mouse" target="_blank" class="text-pink-600 hover:text-pink-500 transition-colors decoration-2 underline-offset-4">視線マウス EyeMoT Mouse</a> が必要です。</li>
                    <li>スイッチの信号は、クリック・スペース・エンターおよびゲームパッドに対応しています。</li>
                    <li>できるだけ、ブラウザを最大化してお使いください。</li>
                    <li>スマホやタブレットでは、正常に動作しないことがあります。</li>
                </ul>
            </div>
        </div>
    </section>

    <div id="app-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-10">
        <?php foreach ($all_apps as $app): 
            $is_new = (strtotime($app['created_at']) > strtotime('-3 days'));
        ?>
        <div class="draggable-card energetic-card rounded-[3.5rem] group flex flex-col relative" data-id="<?= htmlspecialchars($app['id']) ?>">
            <?php if($is_new): ?><div class="new-label">NEW!</div><?php endif; ?>

            <div class="aspect-[16/9] relative overflow-hidden bg-slate-200 border-b-4 border-black cursor-pointer rounded-t-[3.2rem]" onclick='openDetailModal(<?= json_encode($app) ?>)'>
                <img src="<?= htmlspecialchars($app['thumbnail']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition duration-500">
                <?php
                $methodText = $app['operation_method'] ?? '未設定';
                $badgeColorClass = match(true) {
                    str_contains($methodText, '視線') && str_contains($methodText, 'スイッチ') => 'from-purple-500 to-pink-500',
                    str_contains($methodText, '視線') => 'from-cyan-400 to-blue-600',
                    str_contains($methodText, 'スイッチ') => 'from-orange-400 to-rose-500',
                    default => 'from-slate-500 to-slate-700',
                };
                ?>
                <div class="absolute top-5 left-5 z-10">
                    <span class="inline-block bg-gradient-to-r <?= $badgeColorClass ?> text-white text-base font-black italic tracking-wider px-5 py-2 rounded-full border-[3px] border-white shadow-[3px_3px_6px_rgba(0,0,0,0.3)] transform -rotate-3">
                        <?= htmlspecialchars($methodText) ?>
                    </span>
                </div>
                <div class="absolute bottom-3 left-5 flex flex-wrap gap-2 z-10">
                    <span class="stat-badge bg-yellow-400 text-[10px] shadow-sm border border-white">▶ <?= (int)$app['launches'] ?></span>
                    <span id="card-likes-<?= $app['id'] ?>" class="stat-badge bg-pink-400 text-white text-[10px] shadow-sm border border-white">❤ <?= (int)$app['likes'] ?></span>
                    <span class="stat-badge bg-cyan-400 text-[10px] shadow-sm border border-white">💬 <?= (int)$app['comments_count'] ?></span>
                </div>
            </div>

			<div class="p-6 text-left flex-grow flex flex-col">
                <h2 class="text-xl font-black italic mb-1 uppercase truncate"><?= htmlspecialchars($app['title']) ?></h2>
                <p class="text-[10px] font-bold text-slate-400 mb-2 italic uppercase">Updated: <?= htmlspecialchars($app['updated_at']) ?></p>
                <p class="text-xs font-bold text-slate-600 mb-4 line-clamp-2 min-h-[2.5rem]"><?= htmlspecialchars($app['content']) ?></p>
                <div class="grid grid-cols-2 gap-3 mt-auto">
                    <button onclick='openDetailModal(<?= json_encode($app) ?>)' class="btn-pop bg-white py-3 rounded-2xl text-xs">詳しくみる</button>
                    <a href="?launch=<?= htmlspecialchars($app['id']) ?>" class="btn-pop bg-indigo-600 text-white py-3 rounded-2xl text-xs" target="_blank">起動</a>
                </div>
            </div>
            <?php if ($isLoggedIn): ?>
            <div class="absolute top-3 right-3 flex gap-2">
                <button onclick='openEditModal(<?= json_encode($app) ?>)' class="btn-pop bg-emerald-400 p-2 rounded-xl shadow-[2px_2px_0px_#000]"><svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg></button>
                <button onclick='if(confirm("このアプリと画像を削除しますか？")) location.href="?delete=<?= $app["id"] ?>"' class="btn-pop bg-red-500 p-2 rounded-xl shadow-[2px_2px_0px_#000]"><svg class="w-4 h-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="4" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg></button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php if ($isLoggedIn): ?>
        <button onclick="openModal('register-modal')" class="energetic-card rounded-[3.5rem] flex flex-col items-center justify-center p-8 bg-white/50 border-dashed hover:bg-white transition min-h-[300px]">
            <span class="text-5xl mb-4">★</span>
            <span class="font-black uppercase tracking-widest text-sm">アプリを追加！</span>
        </button>
        <?php endif; ?>
    </div>
</div>

<footer class="bg-white border-t-8 border-black pt-12 pb-16 text-center">
    <div class="mb-10">
        <p class="text-sm font-black mb-4 uppercase text-slate-500">Share this site</p>
        <div class="flex justify-center gap-4">
            <a href="https://twitter.com/intent/tweet?url=<?= urlencode($base_url) ?>&text=<?= urlencode('EyeMoT Simple Games - 手軽に療育ゲームを楽しもう!') ?>" target="_blank" class="share-btn share-twitter" title="Share on X"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></a>
            <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($base_url) ?>" target="_blank" class="share-btn share-facebook" title="Share on Facebook"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.791-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg></a>
            <a href="https://social-plugins.line.me/lineit/share?url=<?= urlencode($base_url) ?>" target="_blank" class="share-btn share-line" title="Share on LINE"><svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M24 10.304c0-5.369-5.383-9.738-12-9.738S0 4.935 0 10.304c0 4.76 3.966 8.765 9.385 9.547.41.088.948.27 1.085.617.118.3.078.706.036 1.088-.06.574-.356 2.148-.396 2.378-.057.34.195.946.865.516 4.79-3.06 6.81-4.85 9.073-7.513 1.258-1.48 1.952-3.435 1.952-5.631"/></svg></a>
        </div>
    </div>

    <p class="text-lg font-black italic mb-8">
        このサイトは「<a href="https://www.poran.net/" target="_blank" class="text-pink-600 hover:text-pink-500 transition-colors decoration-2 underline-offset-4">ポランの広場</a>」が運営しています。
    </p>
    <div class="flex justify-center gap-4 flex-wrap px-4">
        <?php if ($isLoggedIn): ?>
            <button onclick="openLogModal()" class="btn-pop bg-yellow-400 px-8 py-3 rounded-full text-sm relative">
                ログを表示
                <?php 
                $coms = get_csv_data($COMMENTS_FILE, $comment_header);
                $new_com_count = count(array_filter($coms, function($c){ return strtotime($c['created_at']) > strtotime('-24 hours'); }));
                if($new_com_count > 0): ?><span class="absolute -top-2 -right-2 bg-red-600 text-white text-[10px] w-6 h-6 rounded-full flex items-center justify-center border-2 border-black"><?= $new_com_count ?></span><?php endif; ?>
            </button>
            <a href="?logout=1" class="btn-pop bg-white px-8 py-3 rounded-full text-sm">ログアウト</a>
        <?php else: ?>
            <button onclick="openModal('login-modal')" class="btn-pop bg-blue-400 text-white px-8 py-3 rounded-full text-sm">管理者ログイン</button>
        <?php endif; ?>
    </div>
</footer>

<!-- モーダル類 -->
<div id="register-modal" class="hidden fixed inset-0 z-[80] flex items-center justify-center p-4 bg-indigo-900/80 backdrop-blur-md overflow-y-auto">
    <div class="energetic-card w-full max-w-xl p-8 rounded-[3rem] bg-white relative">
        <h2 id="modal-title" class="text-2xl font-black mb-8 italic bg-yellow-400 border-4 border-black inline-block px-4 py-1 uppercase">App Setup</h2>
        <form action="" method="POST" enctype="multipart/form-data" class="space-y-6">
            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="action" id="form-action" value="register"><input type="hidden" name="id" id="form-id"><input type="hidden" name="current_thumbnail" id="form-current-thumbnail">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div><label class="modal-label">アプリ名</label><input type="text" name="title" id="form-title" class="thick-input" required></div>
                <div><label class="modal-label">URL</label><input type="url" name="url" id="form-url" class="thick-input" required></div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="modal-label">操作方法</label>
                    <select name="operation_method" id="form-operation-method" class="thick-input appearance-none bg-white">
                        <option value="視線">視線</option>
                        <option value="スイッチ">スイッチ</option>
                        <option value="視線＆スイッチ">視線＆スイッチ</option>
                        <option value="未設定">未設定</option>
                    </select>
                </div>
                <div><label class="modal-label">画像 (16:9)</label><input type="file" name="thumbnail" accept="image/*" class="thick-input bg-slate-50 text-xs"></div>
            </div>
            <div><label class="modal-label">せつめい</label><textarea name="content" id="form-content" rows="2" class="thick-input"></textarea></div>
            <div class="flex gap-4 pt-4"><button type="button" onclick="closeModal('register-modal')" class="flex-1 font-black text-slate-400 uppercase">Cancel</button><button type="submit" class="btn-pop flex-1 bg-pink-500 text-white py-3 rounded-2xl">SAVE!</button></div>
        </form>
    </div>
</div>

<div id="detail-modal" class="hidden fixed inset-0 z-[70] flex items-center justify-center p-4 bg-black/60 backdrop-blur-sm">
    <div class="energetic-card w-full max-w-2xl max-h-[90vh] flex flex-col rounded-[2.5rem] bg-white overflow-hidden">
        <div class="flex-grow overflow-y-auto custom-scrollbar p-6">
            <div class="aspect-[16/9] rounded-2xl overflow-hidden border-4 border-black mb-6 shadow-[6px_6px_0px_#000]"><img id="detail-img" src="" class="w-full h-full object-cover"></div>
            <h2 id="detail-title" class="text-2xl font-black mb-2 italic uppercase"></h2>
            
            <div class="flex gap-2 mb-4 justify-end">
                <p class="text-[10px] font-black uppercase text-slate-400 self-center mr-1">Share App:</p>
                <a id="share-app-tw" href="#" target="_blank" class="share-btn share-twitter w-8 h-8"><svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></a>
                <a id="share-app-fb" href="#" target="_blank" class="share-btn share-facebook w-8 h-8"><svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.791-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg></a>
                <a id="share-app-ln" href="#" target="_blank" class="share-btn share-line w-8 h-8"><svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M24 10.304c0-5.369-5.383-9.738-12-9.738S0 4.935 0 10.304c0 4.76 3.966 8.765 9.385 9.547.41.088.948.27 1.085.617.118.3.078.706.036 1.088-.06.574-.356 2.148-.396 2.378-.057.34.195.946.865.516 4.79-3.06 6.81-4.85 9.073-7.513 1.258-1.48 1.952-3.435 1.952-5.631"/></svg></a>
                <button id="share-app-cp" onclick="copyPageUrl()" class="share-btn share-copy w-8 h-8" title="Copy Link"><svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5H6a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2v-1M8 5a2 2 0 002 2h2a2 2 0 002-2M8 5a2 2 0 012-2h2a2 2 0 012 2m0 0h2a2 2 0 012 2v3m2 4H10m0 0l3-3m-3 3l3 3"/></svg></button>
            </div>

            <div class="flex flex-wrap gap-4 mb-6 items-center">
                <span id="detail-method" class="stat-badge bg-black text-white"></span>
                <span id="detail-date" class="stat-badge"></span>
                <button onclick="addLike()" class="stat-badge bg-pink-500 text-white">❤ <span id="detail-likes"></span> いいね！</button>
            </div>
            
            <!-- 説明文 -->
            <p id="detail-content" class="text-slate-700 font-bold mb-6 bg-slate-50 p-6 rounded-2xl border-2 border-black border-dashed"></p>
            
            <!-- 大きな起動ボタン (説明文の下に配置) -->
            <a id="detail-launch-btn" href="#" target="_blank" class="btn-pop bg-indigo-600 text-white w-full py-4 rounded-2xl text-xl mb-8">アプリを起動する</a>

            <div class="border-t-4 border-black pt-8"><h3 class="text-xl font-black mb-6 italic">💬 コメント</h3><div id="comment-list" class="space-y-4 mb-8"></div>
            <div class="bg-yellow-100 p-6 rounded-2xl border-4 border-black">
                <input type="text" id="comm-name" class="thick-input mb-4 text-sm" placeholder="お名前（任意）">
                <textarea id="comm-text" class="thick-input mb-4 text-sm" rows="2" placeholder="メッセージ"></textarea>
                <button onclick="postComment()" class="btn-pop bg-indigo-600 text-white w-full py-3 rounded-xl">送信！</button>
            </div></div>
        </div>
        <button onclick="closeModal('detail-modal')" class="p-4 border-t-4 border-black font-black bg-slate-100 uppercase">Close</button>
    </div>
</div>

<div id="login-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4 bg-indigo-900/80 backdrop-blur-md">
    <div class="energetic-card w-full max-sm p-10 rounded-[3rem] text-center bg-white">
        <form action="" method="POST" class="space-y-6">
            <input type="hidden" name="action" value="login">
            <input type="password" name="password" class="thick-input text-center uppercase" placeholder="Password" required autofocus>
            <?php if($error): ?><p class="text-pink-600 font-bold"><?= $error ?></p><?php endif; ?>
            <button type="submit" class="btn-pop w-full bg-indigo-600 text-white py-4 rounded-2xl">LOGIN</button>
            <button type="button" onclick="closeModal('login-modal')" class="block w-full mt-4 text-slate-400 underline font-black">CLOSE</button>
        </form>
    </div>
</div>

<div id="log-modal" class="hidden fixed inset-0 z-[100] flex items-center justify-center p-4 bg-black/80 backdrop-blur-sm">
    <div class="energetic-card w-full max-w-5xl max-h-[85vh] flex flex-col rounded-[2.5rem] bg-white overflow-hidden">
        <div class="bg-yellow-400 border-b-4 border-black flex items-center"><button class="px-6 py-4 font-black border-r-4 border-black" onclick="loadLogs('action')">操作ログ</button><button class="px-6 py-4 font-black" onclick="loadLogs('site')">アクセスログ</button><button onclick="closeModal('log-modal')" class="ml-auto px-6 text-2xl font-black">×</button></div>
        <div class="flex-grow overflow-auto p-4 custom-scrollbar"><table class="w-full text-left text-[10px] font-bold border-collapse"><thead class="sticky top-0 bg-white border-b-2 border-black"><tr><th class="p-2">日時</th><th class="p-2">IP</th><th class="p-2">情報1</th><th class="p-2">情報2</th><th class="p-2">端末</th></tr></thead><tbody id="log-table-body"></tbody></table></div>
    </div>
</div>

<div id="toast" class="fixed bottom-10 left-1/2 transform -translate-x-1/2 bg-black text-white px-6 py-3 rounded-full font-black opacity-0 transition-opacity pointer-events-none z-[110] shadow-lg">Link Copied!</div>

<script>
    let currentAppId = null;
    const csrfToken = '<?= $_SESSION['csrf_token'] ?>';
    const baseUrl = '<?= $base_url ?>';

    function openModal(id) { document.getElementById(id).classList.remove('hidden'); }
    function closeModal(id) { 
        document.getElementById(id).classList.add('hidden'); 
        if(id === 'detail-modal') {
            const newUrl = window.location.pathname;
            window.history.replaceState({}, '', newUrl);
        }
    }
    
    function openDetailModal(app) {
        currentAppId = app.id;
        document.getElementById('detail-img').src = app.thumbnail;
        document.getElementById('detail-title').innerText = app.title;
        document.getElementById('detail-content').innerText = app.content;
        document.getElementById('detail-date').innerText = "登録日: " + app.created_at;
        document.getElementById('detail-method').innerText = "操作方法: " + (app.operation_method || '未設定');
        document.getElementById('detail-likes').innerText = app.likes;
        
        // 起動ボタンのリンクを設定
        document.getElementById('detail-launch-btn').href = '?launch=' + app.id;
        
        const permalink = baseUrl + '?app_id=' + app.id;
        window.history.pushState({app_id: app.id}, app.title, '?app_id=' + app.id);

        const text = encodeURIComponent(app.title + " | EyeMoT Simple Games");
        const url = encodeURIComponent(permalink);
        document.getElementById('share-app-tw').href = `https://twitter.com/intent/tweet?url=${url}&text=${text}`;
        document.getElementById('share-app-fb').href = `https://www.facebook.com/sharer/sharer.php?u=${url}`;
        document.getElementById('share-app-ln').href = `https://social-plugins.line.me/lineit/share?url=${url}`;

        loadComments(app.id); 
        openModal('detail-modal');
    }

    function copyPageUrl() {
        const url = window.location.href;
        navigator.clipboard.writeText(url).then(() => {
            const toast = document.getElementById('toast');
            toast.classList.remove('opacity-0');
            setTimeout(() => toast.classList.add('opacity-0'), 2000);
        });
    }

    window.addEventListener('DOMContentLoaded', () => {
        const urlParams = new URLSearchParams(window.location.search);
        const appId = urlParams.get('app_id');
        if(appId) {
            const card = document.querySelector(`.draggable-card[data-id="${appId}"]`);
            if(card) card.querySelector('img').click();
        }
    });

    function addLike() {
        fetch('?ajax_like=' + currentAppId).then(res => res.text()).then(num => {
            document.getElementById('detail-likes').innerText = num;
            const cardBadge = document.getElementById('card-likes-' + currentAppId);
            if(cardBadge) cardBadge.innerText = '❤ ' + num;
        });
    }
    function loadComments(appId) {
        const container = document.getElementById('comment-list');
        fetch('?ajax_get_comments=' + appId).then(res => res.json()).then(data => {
            container.innerHTML = data.length ? "" : "<p class='text-slate-400 italic font-black'>No comments yet.</p>";
            data.forEach(c => {
                const div = document.createElement('div');
                div.className = "bg-white border-2 border-black p-4 rounded-xl shadow-[4px_4px_0px_#000]";
                div.innerHTML = `<div class="flex justify-between text-[10px] font-black text-pink-500 mb-1"><span>@${c.user_name}</span><span>${c.created_at}</span></div><p class="font-bold text-sm break-all">${c.comment_text}</p>`;
                container.appendChild(div);
            });
        });
    }
    function postComment() {
        const name = document.getElementById('comm-name').value;
        const text = document.getElementById('comm-text').value;
        if(!text) return;
        const fd = new FormData(); 
        fd.append('action', 'post_comment'); 
        fd.append('app_id', currentAppId); 
        fd.append('user_name', name); 
        fd.append('comment_text', text);
        fd.append('csrf_token', csrfToken);
        fetch('', { method: 'POST', body: fd }).then(() => { 
            document.getElementById('comm-text').value = ""; 
            loadComments(currentAppId);
            location.reload(); 
        });
    }
    function openEditModal(app) {
        document.getElementById('modal-title').innerText = "EDIT APP";
        document.getElementById('form-action').value = "update";
        document.getElementById('form-id').value = app.id;
        document.getElementById('form-title').value = app.title;
        document.getElementById('form-url').value = app.url;
        document.getElementById('form-content').value = app.content;
        document.getElementById('form-operation-method').value = app.operation_method || '未設定';
        document.getElementById('form-current-thumbnail').value = app.thumbnail;
        openModal('register-modal');
    }
    function loadLogs(type) {
        const body = document.getElementById('log-table-body');
        body.innerHTML = '<tr><td colspan="5" class="p-8 text-center italic">Loading...</td></tr>';
        fetch('?ajax_get_logs=' + type).then(res => res.json()).then(data => {
            body.innerHTML = '';
            data.forEach(log => {
                const tr = document.createElement('tr');
                tr.className = "border-b border-slate-100 hover:bg-slate-50";
                tr.innerHTML = `<td class="p-2 whitespace-nowrap">${log.timestamp}</td><td class="p-2">${log.ip}</td><td class="p-2 truncate max-w-[100px]">${log.action || log.uri}</td><td class="p-2 truncate max-w-[100px]">${log.target_id || log.referrer}</td><td class="p-2 text-slate-300 truncate max-w-[100px]">${log.user_agent}</td>`;
                body.appendChild(tr);
            });
        });
    }
    function openLogModal() { openModal('log-modal'); loadLogs('action'); }

    <?php if ($isLoggedIn): ?>
    new Sortable(document.getElementById('app-grid'), {
        draggable: '.draggable-card', animation: 250,
        onEnd: () => {
            const order = Array.from(document.querySelectorAll('.draggable-card')).map(el => el.dataset.id);
            const fd = new FormData(); 
            fd.append('action', 'sort');
            fd.append('csrf_token', csrfToken);
            order.forEach(id => fd.append('order[]', id)); fetch('', { method: 'POST', body: fd });
        }
    });
    <?php endif; ?>
</script>
</body>
</html>