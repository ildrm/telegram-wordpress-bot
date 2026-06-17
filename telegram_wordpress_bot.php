<?php
/**
 * Telegram WordPress Master Bot v2.1
 * Multisite + Plugins + Themes, with security hardening and completed handlers.
 */

// --- CONFIGURATION ---
// Secrets are loaded from config.local.php (gitignored) with an environment
// variable fallback. Nothing sensitive is hardcoded in this file.
$__cfg = file_exists(__DIR__ . '/config.local.php') ? require __DIR__ . '/config.local.php' : [];

$__admin_ids = $__cfg['admin_ids'] ?? getenv('TGWP_ADMIN_IDS') ?: [];
if (is_string($__admin_ids)) {
    $__admin_ids = array_filter(array_map('trim', explode(',', $__admin_ids)), 'strlen');
}
$__admin_ids = array_values(array_map('intval', (array) $__admin_ids));

define('BOT_TOKEN', $__cfg['bot_token'] ?? getenv('TGWP_BOT_TOKEN') ?: '');
define('ADMIN_IDS', $__admin_ids);
define('WEBHOOK_SECRET', $__cfg['webhook_secret'] ?? getenv('TGWP_WEBHOOK_SECRET') ?: '');
define('DB_FILE', __DIR__ . '/bot.db');
define('LOG_FILE', __DIR__ . '/bot.log');
define('DEBUG', (bool) ($__cfg['debug'] ?? getenv('TGWP_DEBUG') ?: false));

function tgwp_log($msg) {
    if (!DEBUG) return;
    $line = '[' . date('c') . '] ' . (is_string($msg) ? $msg : json_encode($msg)) . "\n";
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

/**
 * Validate a user-supplied site URL before the bot makes a server-side request
 * to it. Blocks SSRF against internal/loopback/link-local hosts and requires
 * HTTPS. Returns the normalized URL or null if rejected.
 */
function tgwp_validate_site_url($url) {
    $url = trim((string) $url);
    if (!filter_var($url, FILTER_VALIDATE_URL)) return null;

    $parts = parse_url($url);
    if (empty($parts['scheme']) || strtolower($parts['scheme']) !== 'https') return null;
    if (empty($parts['host'])) return null;

    $host = $parts['host'];

    // Resolve to IPs and reject anything that is not a public address.
    $ips = [];
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips[] = $host;
    } else {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        foreach ((array) $records as $r) {
            if (!empty($r['ip'])) $ips[] = $r['ip'];
            if (!empty($r['ipv6'])) $ips[] = $r['ipv6'];
        }
        if (!$ips) return null; // unresolvable host
    }
    foreach ($ips as $ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return null; // private, loopback, reserved or link-local
        }
    }
    return $url;
}

// --- FRAMEWORK ---
class DB {
    private static $pdo;
    public static function connect() {
        if (!self::$pdo) {
            $exists = file_exists(DB_FILE);
            self::$pdo = new PDO('sqlite:' . DB_FILE);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            if (!$exists) self::init();
        }
        return self::$pdo;
    }
    private static function init() {
        self::query("CREATE TABLE IF NOT EXISTS users (id INTEGER PRIMARY KEY, tg_id INTEGER UNIQUE, state TEXT, data TEXT, current_site INTEGER)");
        self::query("CREATE TABLE IF NOT EXISTS sites (id INTEGER PRIMARY KEY, user_id INTEGER, name TEXT, url TEXT, token TEXT, multisite INTEGER, extra TEXT, FOREIGN KEY(user_id) REFERENCES users(id))");
    }
    public static function query($sql, $params = []) {
        try {
            $stmt = self::connect()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            tgwp_log('DB error: ' . $e->getMessage() . ' | SQL: ' . $sql);
            throw $e;
        }
    }
    public static function fetch($sql, $params = []) {
        return self::query($sql, $params)->fetch(PDO::FETCH_ASSOC);
    }
    public static function fetchAll($sql, $params = []) {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }
}

class TG {
    public static function request($method, $data = []) {
        $ch = curl_init("https://api.telegram.org/bot" . BOT_TOKEN . "/$method");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $res = curl_exec($ch);
        if ($res === false) {
            tgwp_log("Telegram $method failed: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        $decoded = json_decode($res, true);
        if (isset($decoded['ok']) && !$decoded['ok']) {
            tgwp_log("Telegram $method API error: " . ($decoded['description'] ?? 'unknown'));
        }
        return $decoded;
    }
    public static function send($chat_id, $text, $keyboard = null) {
        return self::request('sendMessage', ['chat_id' => $chat_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $keyboard ? json_encode($keyboard) : null]);
    }
    public static function edit($chat_id, $msg_id, $text, $keyboard = null) {
        return self::request('editMessageText', ['chat_id' => $chat_id, 'message_id' => $msg_id, 'text' => $text, 'parse_mode' => 'HTML', 'reply_markup' => $keyboard ? json_encode($keyboard) : null]);
    }
    public static function answer($id, $text = null) {
        return self::request('answerCallbackQuery', ['callback_query_id' => $id, 'text' => $text]);
    }
}

class WP {
    public static function call($site, $endpoint, $data = [], $method = 'GET') {
        $url = rtrim($site['url'], '/') . '/wp-json/tgwp/v1' . $endpoint;
        if (!empty($site['sub_id'])) $url .= (strpos($url, '?') ? '&' : '?') . 'site_id=' . $site['sub_id'];
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $site['token']]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $res = curl_exec($ch);
        if ($res === false) {
            tgwp_log("WP call $endpoint failed: " . curl_error($ch));
            curl_close($ch);
            return null;
        }
        curl_close($ch);
        return json_decode($res, true);
    }
}

// --- LOGIC ---

// Allow the file to be required by unit tests (which exercise the classes and
// helper functions above) without running the webhook handler.
if (php_sapi_name() === 'cli' && getenv('TGWP_TEST')) {
    return;
}

// Fail closed: the bot cannot operate without a token, an admin allowlist,
// and a webhook secret. This prevents an unconfigured deployment from being
// wide open to any Telegram user.
if (BOT_TOKEN === '' || empty(ADMIN_IDS) || WEBHOOK_SECRET === '') {
    http_response_code(500);
    tgwp_log('Refusing to run: bot_token, admin_ids and webhook_secret must all be configured.');
    exit;
}

// Health-check cron: ping every connected site's /stats endpoint and alert the
// owning Telegram user if a site is unreachable. Protected by the same secret
// as the webhook: call as bot.php?cron=<WEBHOOK_SECRET>
if (isset($_GET['cron'])) {
    if (!hash_equals(WEBHOOK_SECRET, (string) $_GET['cron'])) {
        http_response_code(403);
        exit;
    }
    $sites = DB::fetchAll(
        "SELECT s.*, u.tg_id AS owner_tg FROM sites s JOIN users u ON u.id = s.user_id"
    );
    $checked = 0; $down = 0;
    foreach ($sites as $s) {
        $res = WP::call($s, '/stats');
        $checked++;
        if (!is_array($res) || !isset($res['posts'])) {
            $down++;
            TG::send($s['owner_tg'], "🔴 Site unreachable: {$s['name']} ({$s['url']})");
            tgwp_log("Cron: site down id={$s['id']} url={$s['url']}");
        }
    }
    echo "Cron OK: checked $checked, down $down";
    exit;
}

// Verify the request actually came from Telegram. Telegram echoes the secret
// configured via setWebhook in this header on every webhook delivery.
$incoming_secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals(WEBHOOK_SECRET, $incoming_secret)) {
    http_response_code(403);
    tgwp_log('Rejected webhook: bad or missing secret token.');
    exit;
}

$update = json_decode(file_get_contents('php://input'), true);
if (!$update) exit;

$chat_id = $update['message']['chat']['id'] ?? $update['callback_query']['message']['chat']['id'] ?? null;
$tg_id = $update['message']['from']['id'] ?? $update['callback_query']['from']['id'] ?? null;
$text = $update['message']['text'] ?? null;
$callback = $update['callback_query']['data'] ?? null;
$cb_id = $update['callback_query']['id'] ?? null;
$msg_id = $update['message']['message_id'] ?? $update['callback_query']['message']['message_id'] ?? null;

// Authorization: only allow-listed Telegram user IDs.
if (!in_array((int) $tg_id, ADMIN_IDS, true)) {
    tgwp_log('Rejected unauthorized tg_id: ' . $tg_id);
    exit;
}

// User Session
$user = DB::fetch("SELECT * FROM users WHERE tg_id = ?", [$tg_id]);
if (!$user) {
    DB::query("INSERT INTO users (tg_id, state) VALUES (?, ?)", [$tg_id, 'start']);
    $user = ['tg_id' => $tg_id, 'state' => 'start', 'data' => '{}'];
}
$user_data = json_decode($user['data'] ?: '{}', true);

// Context
$current_site = null;
if ($user['current_site']) {
    $current_site = DB::fetch("SELECT * FROM sites WHERE id = ?", [$user['current_site']]);
    if ($current_site && isset($user_data['sub_id'])) $current_site['sub_id'] = $user_data['sub_id'];
}

// Route: Text
if ($text) {
    if ($text === '/start') {
        TG::send($chat_id, "Welcome!", ['inline_keyboard' => [[['text' => 'My Sites', 'callback_data' => 'home']]]]);
        exit;
    }
    if ($user['state'] === 'await_url' || $user['state'] === 'await_token') {
       // ... Connect logic (simplified for brevity, assume v1 logic exists) ...
       // Re-implemeting vital connect logic for completeness
       if ($user['state'] === 'await_url') {
           $clean_url = tgwp_validate_site_url($text);
           if (!$clean_url) {
               TG::send($chat_id, "❌ Invalid URL. Use a public HTTPS address (e.g. https://example.com).");
               exit;
           }
           $data = json_decode($user['data'], true); $data['conn_url'] = $clean_url;
           DB::query("UPDATE users SET data = ?, state = 'await_token' WHERE tg_id = ?", [json_encode($data), $tg_id]);
           TG::send($chat_id, "URL Saved. Now paste Token:");
           exit;
       }
       if ($user['state'] === 'await_token') {
           // Verify
           $data = json_decode($user['data'], true);
           $url = tgwp_validate_site_url($data['conn_url'] ?? '');
           if (!$url) {
               TG::send($chat_id, "❌ Stored URL is invalid. Please start over with Connect.");
               DB::query("UPDATE users SET state='idle' WHERE tg_id=?", [$tg_id]);
               exit;
           }
           $res = WP::call(['url'=>$url,'token'=>$text], '/connect'); // Temp obj
           if(isset($res['name'])) {
               DB::query("INSERT INTO sites (user_id,name,url,token,multisite) VALUES (?,?,?,?,?)", [$user['id'], $res['name'], $url, $text, $res['multisite']?1:0]);
               TG::send($chat_id, "Connected: {$res['name']}");
               DB::query("UPDATE users SET state='idle' WHERE tg_id=?", [$tg_id]);
           } else TG::send($chat_id, "Failed.");
           exit;
       }
    }
    if ($user['state'] === 'search_plugin' || $user['state'] === 'search_theme') {
        $type = ($user['state'] === 'search_plugin') ? 'plugin' : 'theme';
        $res = WP::call($current_site, '/search?type='.$type.'&q='.urlencode($text));
        $kb = [];
        if(!empty($res) && !isset($res['code'])) {
            foreach($res as $item) {
                // Determine action based on type
                $slug = $item['slug'];
                $act = "inst_{$type}_{$slug}";
                $kb[] = [['text' => "⬇️ {$item['name']} ({$item['version']})", 'callback_data' => $act]];
            }
        }
        $kb[] = [['text' => '🔙 Back', 'callback_data' => 'feat_installers']];
        TG::send($chat_id, "Search Results for '$text':", ['inline_keyboard' => $kb]);
        DB::query("UPDATE users SET state='idle' WHERE tg_id=?", [$tg_id]);
        exit;
    }

    // New-post compose flow
    if ($user['state'] === 'post_title') {
        $user_data['draft']['title'] = $text;
        DB::query("UPDATE users SET state='post_body', data=? WHERE tg_id=?", [json_encode($user_data), $tg_id]);
        TG::send($chat_id, "Now send the post <b>body</b> (HTML allowed):");
        exit;
    }
    if ($user['state'] === 'post_body') {
        $user_data['draft']['content'] = $text;
        DB::query("UPDATE users SET state='idle', data=? WHERE tg_id=?", [json_encode($user_data), $tg_id]);
        TG::send($chat_id, "How should I save it?", ['inline_keyboard' => [
            [['text' => '🟢 Publish now', 'callback_data' => 'pub_publish']],
            [['text' => '🕒 Schedule', 'callback_data' => 'pub_future']],
            [['text' => '📝 Save draft', 'callback_data' => 'pub_draft']],
        ]]);
        exit;
    }
    if ($user['state'] === 'post_schedule') {
        if (!$current_site) { TG::send($chat_id, "⚠️ No site selected."); exit; }
        $draft = $user_data['draft'] ?? [];
        $res = WP::call($current_site, '/posts', [
            'action' => 'create', 'status' => 'future',
            'title' => $draft['title'] ?? '', 'content' => $draft['content'] ?? '',
            'date' => $text,
        ], 'POST');
        $ok = isset($res['success']) && $res['success'];
        unset($user_data['draft']);
        DB::query("UPDATE users SET state='idle', data=? WHERE tg_id=?", [json_encode($user_data), $tg_id]);
        TG::send($chat_id, $ok ? "✅ Scheduled. " . ($res['link'] ?? '') : "❌ " . ($res['message'] ?? 'Could not schedule (check date format/future).'));
        exit;
    }
}

// Route: File Upload (ZIP)
if (isset($update['message']['document']) && $current_site) {
    if ($user['state'] === 'upload_plugin' || $user['state'] === 'upload_theme') {
        $doc = $update['message']['document'];
        if (strpos($doc['file_name'], '.zip') !== false) {
             $file_res = TG::request('getFile', ['file_id' => $doc['file_id']]);
             $dl = "https://api.telegram.org/file/bot" . BOT_TOKEN . "/" . $file_res['result']['file_path'];
             $type = ($user['state'] === 'upload_plugin') ? 'plugin' : 'theme';
             TG::send($chat_id, "⏳ Installing $type...");
             
             // Install Request
             $res = WP::call($current_site, "/{$type}s", ['action' => 'install', 'zip_url' => $dl], 'POST'); // /plugins or /themes
             
             if (isset($res['success']) && $res['success']) TG::send($chat_id, "✅ Installed Successfully!\n" . implode("\n", $res['log'] ?? []));
             else TG::send($chat_id, "❌ Failed: " . ($res['message'] ?? 'Unknown error'));
             
             DB::query("UPDATE users SET state='idle' WHERE tg_id=?", [$tg_id]);
        } else {
            TG::send($chat_id, "Please upload a ZIP file.");
        }
        exit;
    }
}


// Route: Callbacks
if ($callback) {
    TG::answer($cb_id);
    
    // Global Navigation
    if ($callback === 'home') {
        $sites = DB::fetchAll("SELECT * FROM sites WHERE user_id = ?", [$user['id']]);
        $kb = []; foreach($sites as $s) $kb[] = [['text' => $s['name'], 'callback_data' => 'site_'.$s['id']]];
        $kb[] = [['text' => '➕ Connect', 'callback_data' => 'cmd_connect']];
        TG::edit($chat_id, $msg_id, "Your Sites:", ['inline_keyboard' => $kb]);
    }
    
    // Command Connect Trigger
    if ($callback === 'cmd_connect') {
        TG::send($chat_id, "Send Site URL:");
        DB::query("UPDATE users SET state='await_url' WHERE tg_id=?", [$tg_id]);
    }

    // Site Dashboard
    if (strpos($callback, 'site_') === 0) {
        $sid = (int) substr($callback, strlen('site_'));
        // Ownership check: only switch to a site that belongs to this user.
        $s = DB::fetch("SELECT * FROM sites WHERE id=? AND user_id=?", [$sid, $user['id']]);
        if (!$s) {
            TG::send($chat_id, "❌ Site not found.");
            exit;
        }
        DB::query("UPDATE users SET current_site=?,data='{}' WHERE tg_id=?", [$sid, $tg_id]);

        $is_net_root = ($s['multisite']); // If this IS the network root
        
        $kb = [];
        if ($is_net_root) {
             // Network Admin View
             $kb[] = [['text' => '🌐 Network Sites', 'callback_data' => 'net_sites']];
             $kb[] = [['text' => '🔌 Net Plugins', 'callback_data' => 'feat_plugins_net'], ['text' => '🎨 Net Themes', 'callback_data' => 'feat_themes_net']];
        } else {
             // Standard View
             $kb[] = [['text' => '🔌 Plugins', 'callback_data' => 'feat_plugins'], ['text' => '🎨 Themes', 'callback_data' => 'feat_themes']];
        }
        $kb[] = [['text' => '📝 Posts', 'callback_data' => 'feat_posts'], ['text' => '💬 Comments', 'callback_data' => 'feat_comments']];
        $kb[] = [['text' => '⚙️ System', 'callback_data' => 'feat_system'], ['text' => '➕ Install New', 'callback_data' => 'feat_installers']];
        $kb[] = [['text' => '🔙 Home', 'callback_data' => 'home']];
        
        TG::edit($chat_id, $msg_id, "Dashboard: {$s['name']}", ['inline_keyboard' => $kb]);
        exit;
    }

    // Every callback below operates on the currently-selected site.
    $site_prefixes = ['net_sites', 'visit_', 'feat_', 'pact_', 'pactok_', 'find_', 'inst_', 'ask_upload', 'set_up_', 'child_',
        'post_new', 'pub_', 'cmt_', 'cmod_', 'thsw_', 'sys_'];
    $needs_site = false;
    foreach ($site_prefixes as $p) { if (strpos($callback, $p) === 0) { $needs_site = true; break; } }
    if ($needs_site && !$current_site) {
        TG::send($chat_id, "⚠️ Please select a site first (/start → My Sites).");
        exit;
    }

    // Network Sites List
    if ($callback === 'net_sites') {
        $res = WP::call($current_site, '/sites');
        $kb = [];
        if(!empty($res) && !isset($res['code'])) {
            foreach($res as $sub) {
                // Action: Visit Dashboard of Child
                $kb[] = [['text' => "📄 {$sub['domain']}{$sub['path']}", 'callback_data' => 'visit_' . $sub['id']]];
            }
        }
        $kb[] = [['text' => '➕ Create Site', 'callback_data' => 'child_create']];
        $kb[] = [['text' => '🔙 Back', 'callback_data' => 'site_' . $current_site['id']]];
        TG::edit($chat_id, $msg_id, "Network Sites:", ['inline_keyboard' => $kb]);
    }
    
    // Visit Child Site (Set Context)
    if (strpos($callback, 'visit_') === 0) {
        $sub = str_replace('visit_', '', $callback);
        // Store sub_id in session
        $d = json_decode($user['data'],true); $d['sub_id'] = $sub;
        DB::query("UPDATE users SET data=? WHERE tg_id=?", [json_encode($d), $tg_id]);
        
        // Show Standard Dashboard for this sub-site
        $kb = [
            [['text' => '🔌 Plugins', 'callback_data' => 'feat_plugins'], ['text' => '🎨 Themes', 'callback_data' => 'feat_themes']],
            [['text' => '📝 Posts', 'callback_data' => 'feat_posts'], ['text' => '👥 Users', 'callback_data' => 'feat_users_ms']], // Child users
            [['text' => '🔙 Network Admin', 'callback_data' => 'site_' . $current_site['id']]] // Clears sub ID effectively if we reload root
        ];
        TG::edit($chat_id, $msg_id, "Child Site #$sub Control:", ['inline_keyboard' => $kb]);
    }
    
    // Plugins List (Single or Network or Child)
    if (strpos($callback, 'feat_plugins') === 0) {
        $net = (strpos($callback, '_net') !== false) ? 1 : 0;
        $res = WP::call($current_site, '/plugins' . ($net ? '?network=1' : ''));

        // Telegram limits callback_data to 64 bytes, so we cannot inline the
        // plugin path. Store an index->path map in the user's session and
        // reference plugins by short integer index instead.
        $kb = [];
        $plugmap = [];
        if (!empty($res) && is_array($res)) {
            $count = 0;
            foreach ($res as $p) {
                if ($count > 15) break;
                $idx = $count++;
                $plugmap[$idx] = $p['path'];
                $status = $p['active'] ? '✅' : '⚫️';
                $action = $p['active'] ? 'deactivate' : 'activate';
                $short = substr(basename($p['path']), 0, 20);
                $kb[] = [['text' => "$status $short", 'callback_data' => "pact_{$action}_{$net}_{$idx}"]];
            }
        }
        // Persist the map (preserving any existing session data such as sub_id).
        $user_data['plugmap'] = $plugmap;
        DB::query("UPDATE users SET data=? WHERE tg_id=?", [json_encode($user_data), $tg_id]);

        $kb[] = [['text' => '🔙 Back', 'callback_data' => 'site_' . $current_site['id']]];
        TG::edit($chat_id, $msg_id, "Plugins:", ['inline_keyboard' => $kb]);
        exit;
    }

    // Plugin Action — pact_ACTION_NET_IDX (idx resolves via session plugmap).
    // Destructive actions are confirmed first via a pactok_ callback.
    if (strpos($callback, 'pact_') === 0 || strpos($callback, 'pactok_') === 0) {
        $confirmed = strpos($callback, 'pactok_') === 0;
        $parts = explode('_', $callback);
        if (count($parts) !== 4 || !in_array($parts[1], ['activate', 'deactivate', 'delete'], true)) {
            TG::answer($cb_id, "Invalid action.");
            exit;
        }
        $act = $parts[1];
        $net = (int) $parts[2];
        $idx = (int) $parts[3];
        $path = $user_data['plugmap'][$idx] ?? null;
        if ($path === null) {
            TG::answer($cb_id, "Stale list — please reopen Plugins.");
            exit;
        }

        // Confirmation gate for the irreversible delete action.
        if ($act === 'delete' && !$confirmed) {
            TG::edit($chat_id, $msg_id, "⚠️ Delete plugin " . basename($path) . "? This cannot be undone.", ['inline_keyboard' => [
                [['text' => '🗑 Yes, delete', 'callback_data' => "pactok_delete_{$net}_{$idx}"]],
                [['text' => '🔙 Cancel', 'callback_data' => 'feat_plugins' . ($net ? '_net' : '')]],
            ]]);
            exit;
        }

        $res = WP::call($current_site, '/plugins', ['action' => $act, 'plugin' => $path, 'network' => $net], 'POST');
        $ok = isset($res['success']) && $res['success'];
        TG::answer($cb_id, $ok ? "Done: $act" : "Failed: $act");
        TG::send($chat_id, ($ok ? "✅" : "❌") . " $act: " . basename($path) . "\nReopen Plugins to refresh.");
        exit;
    }
    
    // Posts: list recent + compose new
    if ($callback === 'feat_posts') {
        $res = WP::call($current_site, '/posts');
        $kb = [];
        if (!empty($res) && is_array($res) && !isset($res['code'])) {
            foreach ($res as $p) {
                $icon = $p['status'] === 'publish' ? '🟢' : ($p['status'] === 'future' ? '🕒' : '📝');
                $kb[] = [['text' => "$icon " . mb_substr($p['title'] ?: '(untitled)', 0, 30), 'callback_data' => 'noop']];
            }
        }
        $kb[] = [['text' => '✍️ New Post', 'callback_data' => 'post_new']];
        $kb[] = [['text' => '🔙 Back', 'callback_data' => 'site_' . $current_site['id']]];
        TG::edit($chat_id, $msg_id, "Recent Posts:", ['inline_keyboard' => $kb]);
        exit;
    }

    // Start the new-post compose flow (title, then body, then publish choice)
    if ($callback === 'post_new') {
        $user_data['draft'] = [];
        DB::query("UPDATE users SET state='post_title', data=? WHERE tg_id=?", [json_encode($user_data), $tg_id]);
        TG::send($chat_id, "✍️ Send the post <b>title</b>:");
        exit;
    }

    // Publish-mode choice for a composed draft
    if (in_array($callback, ['pub_publish', 'pub_draft', 'pub_future'], true)) {
        $draft = $user_data['draft'] ?? null;
        if (!$draft || !isset($draft['title'])) {
            TG::answer($cb_id, "No draft in progress.");
            exit;
        }
        if ($callback === 'pub_future') {
            DB::query("UPDATE users SET state='post_schedule' WHERE tg_id=?", [$tg_id]);
            TG::send($chat_id, "🕒 Send publish time (YYYY-MM-DD HH:MM, site timezone):");
            exit;
        }
        $status = $callback === 'pub_publish' ? 'publish' : 'draft';
        $res = WP::call($current_site, '/posts', [
            'action' => 'create', 'status' => $status,
            'title' => $draft['title'], 'content' => $draft['content'] ?? '',
        ], 'POST');
        $ok = isset($res['success']) && $res['success'];
        unset($user_data['draft']);
        DB::query("UPDATE users SET state='idle', data=? WHERE tg_id=?", [json_encode($user_data), $tg_id]);
        TG::send($chat_id, $ok ? "✅ Post saved ($status). " . ($res['link'] ?? '') : "❌ Failed to save post.");
        exit;
    }

    // Comments moderation queue
    if ($callback === 'feat_comments') {
        $res = WP::call($current_site, '/comments?status=hold');
        $kb = [];
        $cmtmap = [];
        if (!empty($res) && is_array($res) && !isset($res['code'])) {
            $i = 0;
            foreach ($res as $c) {
                $cmtmap[$i] = $c['id'];
                $kb[] = [['text' => "💬 {$c['author']}: " . mb_substr($c['content'], 0, 25), 'callback_data' => "cmt_$i"]];
                $i++;
            }
        }
        $user_data['cmtmap'] = $cmtmap;
        DB::query("UPDATE users SET data=? WHERE tg_id=?", [json_encode($user_data), $tg_id]);
        $kb[] = [['text' => '🔙 Back', 'callback_data' => 'site_' . $current_site['id']]];
        TG::edit($chat_id, $msg_id, empty($cmtmap) ? "No pending comments 🎉" : "Pending Comments:", ['inline_keyboard' => $kb]);
        exit;
    }

    // Single comment moderation actions
    if (strpos($callback, 'cmt_') === 0) {
        $idx = (int) substr($callback, 4);
        $cid = $user_data['cmtmap'][$idx] ?? null;
        if ($cid === null) { TG::answer($cb_id, "Stale — reopen Comments."); exit; }
        TG::edit($chat_id, $msg_id, "Comment #$cid — choose action:", ['inline_keyboard' => [
            [['text' => '✅ Approve', 'callback_data' => "cmod_approve_$idx"], ['text' => '🚫 Spam', 'callback_data' => "cmod_spam_$idx"]],
            [['text' => '🗑 Trash', 'callback_data' => "cmod_trash_$idx"]],
            [['text' => '🔙 Back', 'callback_data' => 'feat_comments']],
        ]]);
        exit;
    }
    if (strpos($callback, 'cmod_') === 0) {
        list($_d, $act, $idx) = explode('_', $callback) + [null, null, null];
        $cid = $user_data['cmtmap'][(int) $idx] ?? null;
        if ($cid === null || !in_array($act, ['approve', 'spam', 'trash'], true)) { TG::answer($cb_id, "Invalid."); exit; }
        $res = WP::call($current_site, '/comments', ['id' => $cid, 'action' => $act], 'POST');
        TG::answer($cb_id, isset($res['success']) && $res['success'] ? "Done: $act" : "Failed");
        TG::send($chat_id, "Comment #$cid: $act. Reopen Comments to refresh.");
        exit;
    }

    // Themes list + switch
    if (strpos($callback, 'feat_themes') === 0) {
        $res = WP::call($current_site, '/themes');
        $kb = [];
        if (!empty($res) && is_array($res) && !isset($res['code'])) {
            foreach ($res as $t) {
                $icon = !empty($t['active']) ? '✅' : '🎨';
                $cb = !empty($t['active']) ? 'noop' : 'thsw_' . rawurlencode($t['slug']);
                $kb[] = [['text' => "$icon {$t['name']}", 'callback_data' => $cb]];
            }
        }
        $kb[] = [['text' => '🔙 Back', 'callback_data' => 'site_' . $current_site['id']]];
        TG::edit($chat_id, $msg_id, "Themes:", ['inline_keyboard' => $kb]);
        exit;
    }
    if (strpos($callback, 'thsw_') === 0) {
        $slug = rawurldecode(substr($callback, 5));
        $res = WP::call($current_site, '/themes', ['action' => 'switch', 'slug' => $slug], 'POST');
        $ok = isset($res['success']) && $res['success'];
        TG::answer($cb_id, $ok ? "Theme switched" : "Failed");
        TG::send($chat_id, ($ok ? "✅ Activated: " : "❌ Failed: ") . $slug);
        exit;
    }

    // System maintenance menu
    if ($callback === 'feat_system') {
        TG::edit($chat_id, $msg_id, "⚙️ System Maintenance:", ['inline_keyboard' => [
            [['text' => '🧹 Flush Cache', 'callback_data' => 'sys_flush_cache'], ['text' => '🗜 Optimize DB', 'callback_data' => 'sys_optimize_db']],
            [['text' => '⬆️ Update All', 'callback_data' => 'sys_update_all'], ['text' => '🔑 Magic Login', 'callback_data' => 'sys_magic']],
            [['text' => '🔙 Back', 'callback_data' => 'site_' . $current_site['id']]],
        ]]);
        exit;
    }
    if ($callback === 'sys_flush_cache' || $callback === 'sys_optimize_db') {
        $act = $callback === 'sys_flush_cache' ? 'flush_cache' : 'optimize_db';
        $res = WP::call($current_site, '/system', ['action' => $act], 'POST');
        TG::send($chat_id, isset($res['success']) && $res['success'] ? "✅ " . ($res['message'] ?? 'Done') : "❌ Failed");
        exit;
    }
    if ($callback === 'sys_update_all') {
        TG::send($chat_id, "⏳ Updating all plugins & themes...");
        $res = WP::call($current_site, '/updates', ['type' => 'all'], 'POST');
        $ok = isset($res['success']) && $res['success'];
        TG::send($chat_id, ($ok ? "✅ " : "❌ ") . implode("\n", $res['log'] ?? ['Update failed']));
        exit;
    }
    if ($callback === 'sys_magic') {
        $res = WP::call($current_site, '/system', ['action' => 'magic_login'], 'POST');
        if (isset($res['url'])) {
            TG::send($chat_id, "🔑 One-time login (valid 5 min):\n" . $res['url']);
        } else {
            TG::send($chat_id, "❌ Could not create login link.");
        }
        exit;
    }

    // Installers Menu
    if ($callback === 'feat_installers') {
        TG::edit($chat_id, $msg_id, "Select Type to Install:", ['inline_keyboard' => [
            [['text' => '🔌 Find Plugin', 'callback_data' => 'find_plugin'], ['text' => '🎨 Find Theme', 'callback_data' => 'find_theme']],
            [['text' => '📤 Upload ZIP', 'callback_data' => 'ask_upload']],
            [['text' => '🔙 Back', 'callback_data' => 'site_' . $current_site['id']]]
        ]]);
    }
    
    if ($callback === 'find_plugin' || $callback === 'find_theme') {
        $type = ($callback === 'find_plugin') ? 'search_plugin' : 'search_theme';
        DB::query("UPDATE users SET state=? WHERE tg_id=?", [$type, $tg_id]);
        TG::send($chat_id, "Enter search term:");
    }
    
    if (strpos($callback, 'inst_') === 0) {
        // inst_plugin_slug or inst_theme_slug
        list($dummy, $type, $slug) = explode('_', $callback, 3);
        TG::send($chat_id, "⏳ Installing $slug...");
        $res = WP::call($current_site, "/{$type}s", ['action' => 'install', 'slug' => $slug], 'POST'); // e.g. /plugins
        if(isset($res['success']) && $res['success']) TG::send($chat_id, "✅ Installed!");
        else TG::send($chat_id, "❌ Error");
    }
    
    if ($callback === 'ask_upload') {
        TG::edit($chat_id, $msg_id, "Upload wot?", ['inline_keyboard' => [
            [['text' => 'Plugin ZIP', 'callback_data' => 'set_up_plug'], ['text' => 'Theme ZIP', 'callback_data' => 'set_up_theme']]
        ]]);
    }
    
    if ($callback === 'set_up_plug' || $callback === 'set_up_theme') {
        $s = ($callback === 'set_up_plug') ? 'upload_plugin' : 'upload_theme';
        DB::query("UPDATE users SET state=? WHERE tg_id=?", [$s, $tg_id]);
        TG::send($chat_id, "📂 Send the .zip file now.");
    }
}
