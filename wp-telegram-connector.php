<?php
/**
 * Plugin Name: Telegram Bot Connector
 * Description: Securely connects your WordPress site to the Telegram Management Bot.
 * Version: 2.1.0
 * Author: Shahin Ilderemi
 * License: MIT
 */

if (!defined('ABSPATH')) exit;

class WP_Telegram_Connector {

    const OPTION_KEY = 'tgwp_api_token';
    const NAMESPACE = 'tgwp/v1';

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('network_admin_menu', [$this, 'add_admin_menu']);
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function add_admin_menu() {
        add_menu_page('Telegram Bot', 'Telegram Connect', 'manage_options', 'tgwp-connect', [$this, 'render_admin_page'], 'dashicons-smartphone', 100);
    }

    public function render_admin_page() {
        if (!current_user_can('manage_options')) return;
        
        if (isset($_POST['tgwp_generate_token']) && check_admin_referer('tgwp_gen_token')) {
            $token = bin2hex(random_bytes(32));
            update_option(self::OPTION_KEY, $token);
            echo '<div class="notice notice-success"><p>New secure token generated!</p></div>';
        }

        $token = get_option(self::OPTION_KEY);
        ?>
        <div class="wrap">
            <h1>Connect to Telegram Bot</h1>
            <p>Use this token to connect your site to the Telegram Management Bot.</p>
            
            <?php if ($token): ?>
                <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; max-width:600px;">
                    <h3>Your API Token</h3>
                    <code style="display:block; padding:15px; background:#f0f0f1; margin:10px 0; font-size:1.2em; word-break:break-all;">
                        <?php echo esc_html($token); ?>
                    </code>
                </div>
            <?php else: ?>
                <div class="notice notice-warning"><p>No token found. Generate one below.</p></div>
            <?php endif; ?>

            <form method="post" style="margin-top:20px;">
                <?php wp_nonce_field('tgwp_gen_token'); ?>
                <button type="submit" name="tgwp_generate_token" class="button button-primary button-large">
                    <?php echo $token ? 'Regenerate Token' : 'Generate Token'; ?>
                </button>
            </form>
        </div>
        <?php
    }

    public function register_routes() {
        // ... Previous routes ...
        register_rest_route(self::NAMESPACE, '/connect', ['methods' => 'GET', 'callback' => [$this, 'get_site_info'], 'permission_callback' => [$this, 'check_permission']]);
        register_rest_route(self::NAMESPACE, '/stats', ['methods' => 'GET', 'callback' => [$this, 'get_stats'], 'permission_callback' => [$this, 'check_permission']]);
        register_rest_route(self::NAMESPACE, '/posts', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'handle_posts'], 'permission_callback' => [$this, 'check_permission']]);
        register_rest_route(self::NAMESPACE, '/posts/(?P<id>\d+)', ['methods' => ['DELETE'], 'callback' => [$this, 'delete_post'], 'permission_callback' => [$this, 'check_permission']]);
        register_rest_route(self::NAMESPACE, '/media', ['methods' => 'POST', 'callback' => [$this, 'upload_media'], 'permission_callback' => [$this, 'check_permission']]);
        register_rest_route(self::NAMESPACE, '/comments', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'handle_comments'], 'permission_callback' => [$this, 'check_permission']]);
        register_rest_route(self::NAMESPACE, '/updates', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'handle_updates'], 'permission_callback' => [$this, 'check_permission']]);
        register_rest_route(self::NAMESPACE, '/users', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'handle_users'], 'permission_callback' => [$this, 'check_permission']]);
        register_rest_route(self::NAMESPACE, '/system', ['methods' => 'POST', 'callback' => [$this, 'handle_system'], 'permission_callback' => [$this, 'check_permission']]);
        if (class_exists('WooCommerce')) {
            register_rest_route(self::NAMESPACE, '/woo/orders', ['methods' => ['GET', 'POST'], 'callback' => [$this, 'handle_woo'], 'permission_callback' => [$this, 'check_permission']]);
        }

        // --- NEW v2.0 ROUTES ---
        
        // Multisite Sites Management
        register_rest_route(self::NAMESPACE, '/sites', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle_multisite_sites'],
            'permission_callback' => [$this, 'check_permission']
        ]);

        // Plugins Management
        register_rest_route(self::NAMESPACE, '/plugins', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle_plugins'],
            'permission_callback' => [$this, 'check_permission']
        ]);

        // Themes Management
        register_rest_route(self::NAMESPACE, '/themes', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle_themes'],
            'permission_callback' => [$this, 'check_permission']
        ]);

        // WP Repository Search
        register_rest_route(self::NAMESPACE, '/search', [
            'methods' => 'GET',
            'callback' => [$this, 'handle_search'],
            'permission_callback' => [$this, 'check_permission']
        ]);
        
        // Users Multisite Specific
         register_rest_route(self::NAMESPACE, '/users_ms', [
            'methods' => ['GET', 'POST'],
            'callback' => [$this, 'handle_users_ms'],
            'permission_callback' => [$this, 'check_permission']
        ]);
    }

    public function check_permission($request) {
        $header = $request->get_header('authorization');
        if (!$header) return false;
        $token = trim(str_replace('Bearer ', '', $header));
        $saved_token = get_option(self::OPTION_KEY);

        // Authenticate FIRST — never act on request params before the token is
        // verified.
        if (!$saved_token || !hash_equals($saved_token, $token)) {
            return false;
        }

        // Only after auth: switch to the requested blog, and only if it exists.
        if (is_multisite()) {
            $sid = (int) $request->get_param('site_id');
            if ($sid > 0 && get_site($sid)) {
                switch_to_blog($sid);
            }
        }

        return true;
    }

    // --- EXISTING METHODS (Shortened for brevity, full logic assumed present) ---
    // [Previous get_site_info, get_stats, handle_posts, delete_post, upload_media, handle_comments, handle_updates, handle_users, handle_system, handle_woo kept as is] 
    // To save space, I will re-include the ESSENTIAL parts and the NEW parts.
    
    public function get_site_info() {
        $sites = [];
        if (is_multisite()) {
            $blogs = get_sites(['number' => 100]); 
            foreach($blogs as $b) {
                $sites[] = ['id' => $b->blog_id, 'url' => get_site_url($b->blog_id), 'name' => get_blog_option($b->blog_id, 'blogname')];
            }
        }
        return ['name' => get_bloginfo('name'), 'url' => home_url(), 'version' => get_bloginfo('version'), 'multisite' => is_multisite(), 'subsites' => $sites];
    }
    public function get_stats() {
        $updates = 0;
        if (function_exists('get_plugin_updates')) {
            require_once ABSPATH . 'wp-admin/includes/update.php';
            $updates = count((array) get_plugin_updates()) + count((array) get_theme_updates());
        }
        return [
            'name'             => get_bloginfo('name'),
            'posts'            => (int) wp_count_posts()->publish,
            'drafts'           => (int) wp_count_posts()->draft,
            'comments_pending' => (int) wp_count_comments()->moderated,
            'updates'          => $updates,
        ];
    }

    public function handle_posts($request) {
        if ($request->get_method() === 'GET') {
            $posts = get_posts([
                'numberposts' => 15,
                'offset'      => max(0, (int) $request->get_param('page')) * 15,
                'post_status' => ['publish', 'draft', 'future', 'pending'],
            ]);
            $list = [];
            foreach ($posts as $p) {
                $list[] = [
                    'id'     => $p->ID,
                    'title'  => $p->post_title,
                    'status' => $p->post_status,
                    'date'   => $p->post_date,
                    'link'   => get_permalink($p->ID),
                ];
            }
            return array_values($list);
        }

        // POST: create or edit
        $action = $request->get_param('action');
        $title  = sanitize_text_field((string) $request->get_param('title'));
        $content = wp_kses_post((string) $request->get_param('content'));

        if ($action === 'edit') {
            $id = (int) $request->get_param('id');
            if (!$id || !get_post($id)) return new WP_Error('not_found', 'Post not found', ['status' => 404]);
            $update = ['ID' => $id];
            if ($title !== '')   $update['post_title'] = $title;
            if ($content !== '') $update['post_content'] = $content;
            $res = wp_update_post($update, true);
            if (is_wp_error($res)) return $res;
            return ['success' => true, 'id' => $id];
        }

        // create (supports scheduling via future status + post_date)
        $status = in_array($request->get_param('status'), ['publish', 'draft', 'future', 'pending'], true)
            ? $request->get_param('status') : 'draft';
        $postarr = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_status'  => $status,
        ];
        $when = $request->get_param('date'); // ISO 8601, site timezone, for scheduling
        if ($status === 'future' && $when) {
            $ts = strtotime($when);
            if ($ts && $ts > current_time('timestamp')) {
                $postarr['post_date']     = date('Y-m-d H:i:s', $ts);
                $postarr['post_date_gmt'] = get_gmt_from_date($postarr['post_date']);
            } else {
                return new WP_Error('bad_date', 'Schedule date must be in the future', ['status' => 400]);
            }
        }
        $id = wp_insert_post($postarr, true);
        if (is_wp_error($id)) return $id;
        return ['success' => true, 'id' => $id, 'status' => $status, 'link' => get_permalink($id)];
    }

    public function delete_post($request) {
        $id = (int) $request['id'];
        if (!$id || !get_post($id)) return new WP_Error('not_found', 'Post not found', ['status' => 404]);
        $res = wp_trash_post($id);
        return ['success' => (bool) $res];
    }

    public function upload_media($request) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $url = esc_url_raw((string) $request->get_param('url'));
        if (!$url) return new WP_Error('no_url', 'Missing media url', ['status' => 400]);

        $tmp = download_url($url);
        if (is_wp_error($tmp)) return $tmp;

        $filename = sanitize_file_name($request->get_param('filename') ?: basename(parse_url($url, PHP_URL_PATH)));
        $file = ['name' => $filename, 'tmp_name' => $tmp];
        $id = media_handle_sideload($file, 0);
        if (is_wp_error($id)) { @unlink($tmp); return $id; }

        return ['success' => true, 'id' => $id, 'url' => wp_get_attachment_url($id)];
    }

    public function handle_comments($request) {
        if ($request->get_method() === 'GET') {
            $comments = get_comments([
                'status' => $request->get_param('status') ?: 'hold',
                'number' => 15,
                'offset' => max(0, (int) $request->get_param('page')) * 15,
            ]);
            $list = [];
            foreach ($comments as $c) {
                $list[] = [
                    'id'      => $c->comment_ID,
                    'author'  => $c->comment_author,
                    'content' => wp_trim_words($c->comment_content, 25),
                    'post'    => get_the_title($c->comment_post_ID),
                ];
            }
            return array_values($list);
        }

        $id = (int) $request->get_param('id');
        $action = $request->get_param('action');
        if (!$id || !get_comment($id)) return new WP_Error('not_found', 'Comment not found', ['status' => 404]);

        switch ($action) {
            case 'approve': wp_set_comment_status($id, 'approve'); break;
            case 'spam':    wp_spam_comment($id); break;
            case 'trash':   wp_trash_comment($id); break;
            case 'reply':
                $text = wp_kses_post((string) $request->get_param('content'));
                $parent = get_comment($id);
                $cid = wp_insert_comment([
                    'comment_post_ID'  => $parent->comment_post_ID,
                    'comment_content'  => $text,
                    'comment_parent'   => $id,
                    'user_id'          => get_current_user_id(),
                    'comment_approved' => 1,
                ]);
                return ['success' => (bool) $cid, 'id' => $cid];
            default: return new WP_Error('bad_action', 'Unknown action', ['status' => 400]);
        }
        return ['success' => true];
    }

    public function handle_updates($request) {
        require_once ABSPATH . 'wp-admin/includes/update.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        if ($request->get_method() === 'GET') {
            wp_update_plugins();
            wp_update_themes();
            return [
                'plugins' => array_keys((array) get_plugin_updates()),
                'themes'  => array_keys((array) get_theme_updates()),
            ];
        }

        // POST: run updates. type=plugin|theme|all
        $type = $request->get_param('type') ?: 'all';
        $skin = new WP_Ajax_Upgrader_Skin();
        $log = [];

        if ($type === 'plugin' || $type === 'all') {
            $plugins = array_keys((array) get_plugin_updates());
            if ($plugins) {
                $upgrader = new Plugin_Upgrader($skin);
                $upgrader->bulk_upgrade($plugins);
                $log[] = 'Plugins updated: ' . count($plugins);
            }
        }
        if ($type === 'theme' || $type === 'all') {
            $themes = array_keys((array) get_theme_updates());
            if ($themes) {
                $upgrader = new Theme_Upgrader($skin);
                $upgrader->bulk_upgrade($themes);
                $log[] = 'Themes updated: ' . count($themes);
            }
        }
        return ['success' => true, 'log' => $log ?: ['Nothing to update']];
    }

    public function handle_users($request) {
        if ($request->get_method() === 'GET') {
            $users = get_users(['number' => 20, 'offset' => max(0, (int) $request->get_param('page')) * 20]);
            $list = [];
            foreach ($users as $u) {
                $list[] = [
                    'id'    => $u->ID,
                    'login' => $u->user_login,
                    'email' => $u->user_email,
                    'roles' => $u->roles,
                ];
            }
            return array_values($list);
        }

        $action = $request->get_param('action');
        if ($action === 'create') {
            $login = sanitize_user((string) $request->get_param('login'));
            $email = sanitize_email((string) $request->get_param('email'));
            $role  = $request->get_param('role') ?: 'subscriber';
            if (!$login || !is_email($email)) return new WP_Error('bad_input', 'Invalid login or email', ['status' => 400]);
            $id = wp_insert_user([
                'user_login' => $login,
                'user_email' => $email,
                'user_pass'  => wp_generate_password(20),
                'role'       => $role,
            ]);
            if (is_wp_error($id)) return $id;
            return ['success' => true, 'id' => $id];
        }
        if ($action === 'delete') {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            $id = (int) $request->get_param('id');
            if ($id === get_current_user_id()) return new WP_Error('self', 'Refusing to delete current user', ['status' => 400]);
            return ['success' => (bool) wp_delete_user($id)];
        }
        return new WP_Error('bad_action', 'Unknown action', ['status' => 400]);
    }

    public function handle_system($request) {
        $action = $request->get_param('action');
        switch ($action) {
            case 'flush_cache':
                wp_cache_flush();
                if (function_exists('w3tc_flush_all')) w3tc_flush_all();
                if (function_exists('wp_cache_clear_cache')) wp_cache_clear_cache();
                return ['success' => true, 'message' => 'Cache flushed'];
            case 'optimize_db':
                global $wpdb;
                $tables = $wpdb->get_col('SHOW TABLES');
                foreach ($tables as $t) {
                    $wpdb->query('OPTIMIZE TABLE `' . esc_sql($t) . '`');
                }
                return ['success' => true, 'message' => count($tables) . ' tables optimized'];
            case 'magic_login':
                // Issue a single-use, time-limited passwordless login link.
                $uid = get_current_user_id();
                $key = bin2hex(random_bytes(32));
                set_transient('tgwp_magic_' . $uid, $key, 5 * MINUTE_IN_SECONDS);
                $link = add_query_arg(['tgwp_magic' => $uid, 'key' => $key], admin_url());
                return ['success' => true, 'url' => $link, 'expires_in' => 300];
            default:
                return new WP_Error('bad_action', 'Unknown action', ['status' => 400]);
        }
    }

    public function handle_woo($request) {
        if (!class_exists('WooCommerce')) return new WP_Error('no_woo', 'WooCommerce not active', ['status' => 400]);

        if ($request->get_method() === 'GET') {
            $orders = wc_get_orders(['limit' => 15, 'paginate' => false, 'orderby' => 'date', 'order' => 'DESC']);
            $list = [];
            foreach ($orders as $o) {
                $list[] = [
                    'id'     => $o->get_id(),
                    'status' => $o->get_status(),
                    'total'  => $o->get_total(),
                    'email'  => $o->get_billing_email(),
                ];
            }
            return array_values($list);
        }

        $id = (int) $request->get_param('id');
        $status = sanitize_text_field((string) $request->get_param('status'));
        $order = wc_get_order($id);
        if (!$order) return new WP_Error('not_found', 'Order not found', ['status' => 404]);
        $order->update_status($status);
        return ['success' => true];
    }
    
    // --- NEW FEATURES ---

    public function handle_multisite_sites($request) {
        if (!is_multisite()) return new WP_Error('no_multisite', 'Not a multisite installation');
        
        if ($request->get_method() === 'GET') {
            $sites = get_sites(['number' => 50, 'offset' => ($request->get_param('page') ?: 0 ) * 50]);
            $list = [];
            foreach($sites as $s) {
                $list[] = [
                    'id' => $s->blog_id,
                    'domain' => $s->domain,
                    'path' => $s->path,
                    'registered' => $s->registered,
                    'last_updated' => $s->last_updated
                ];
            }
            return $list;
        }
        
        // POST: Create/Delete
        $action = $request->get_param('action');
        
        if ($action === 'create') {
            $domain = $request->get_param('domain');
            $title = $request->get_param('title');
            $email = $request->get_param('email'); // Admin email
            
            $id = wpmu_create_blog($domain, '/', $title, get_current_user_id(), ['admin_email' => $email]);
            if (is_wp_error($id)) return $id;
            return ['success' => true, 'id' => $id];
        }
        
        if ($action === 'delete') {
            // Irreversible!
            $id = $request->get_param('id');
            if ($id == 1) return new WP_Error('protect', 'Cannot delete main site');
            wpmu_delete_blog($id, true);
            return ['success' => true];
        }
    }
    
    public function handle_users_ms($request) {
        if (!is_multisite()) return new WP_Error('no_ms', 'Not multisite');
        // Managed via switch_to_blog in check_permission so regular WP functions work on child site
        // If we need to add existing user to blog:
        if ($request->get_method() === 'POST' && $request->get_param('action') === 'add_existing') {
            $res = add_user_to_blog(get_current_blog_id(), $request->get_param('user_id'), $request->get_param('role'));
            return ['success' => !is_wp_error($res)];
        }
        return $this->handle_users($request); // Reuse single site logic
    }

    public function handle_plugins($request) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        
        $network = $request->get_param('network'); // If operating on Network level
        
        if ($request->get_method() === 'GET') {
            if (!function_exists('get_plugins')) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            $all = get_plugins();
            $active = get_option('active_plugins', []);
            $network_active = is_multisite() ? get_site_option('active_sitewide_plugins', []) : [];
            
            $list = [];
            foreach($all as $path => $data) {
                $isActive = in_array($path, $active) || isset($network_active[$path]);
                $list[] = [
                    'path' => $path,
                    'name' => $data['Name'],
                    'version' => $data['Version'],
                    'active' => $isActive,
                    'network_active' => isset($network_active[$path])
                ];
            }
            return array_values($list);
        }
        
        $action = $request->get_param('action');
        $plugin = $request->get_param('plugin'); // Path, e.g. 'akismet/akismet.php'

        if ($action === 'activate') {
            $res = $network ? activate_plugin($plugin, '', true) : activate_plugin($plugin);
            return ['success' => !is_wp_error($res)];
        }
        
        if ($action === 'deactivate') {
            $network ? deactivate_plugins($plugin, true) : deactivate_plugins($plugin);
            return ['success' => true];
        }
        
        if ($action === 'delete') {
            $res = delete_plugins([$plugin]);
            return ['success' => !is_wp_error($res)];
        }
        
        if ($action === 'install') {
            $slug = $request->get_param('slug');
            $zip = $request->get_param('zip_url');
            
            $skin = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            
            if ($zip) {
                // Download tmp
                $tmp = download_url($zip);
                if (is_wp_error($tmp)) return $tmp;
                $res = $upgrader->install($tmp);
                @unlink($tmp);
            } else {
                // From Repo
                include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
                $api = plugins_api('plugin_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
                if (is_wp_error($api)) return $api;
                $res = $upgrader->install($api->download_link);
            }
            
            if ($res) {
                 // Activate if requested? User can do it in next step
                 return ['success' => true, 'log' => $skin->get_upgrade_messages()];
            }
            return new WP_Error('install_failed', 'Install failed');
        }
    }

    public function handle_themes($request) {
        require_once ABSPATH . 'wp-admin/includes/theme.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        
        if ($request->get_method() === 'GET') {
            $themes = wp_get_themes();
            $current = get_stylesheet();
            $list = [];
            foreach($themes as $slug => $t) {
                $list[] = [
                    'slug' => $slug,
                    'name' => $t->get('Name'),
                    'version' => $t->get('Version'),
                    'active' => ($slug === $current)
                ];
            }
            return array_values($list);
        }
        
        $action = $request->get_param('action');
        $slug = $request->get_param('slug');
        
        if ($action === 'switch') {
            switch_theme($slug);
            return ['success' => true];
        }
        
        if ($action === 'delete') {
            $res = delete_theme($slug);
            return ['success' => !is_wp_error($res)];
        }
        
        if ($action === 'install') {
            $zip = $request->get_param('zip_url');
            $skin = new WP_Ajax_Upgrader_Skin();
            $upgrader = new Theme_Upgrader($skin);
            
             if ($zip) {
                $tmp = download_url($zip);
                if (is_wp_error($tmp)) return $tmp;
                $res = $upgrader->install($tmp);
                @unlink($tmp);
            } else {
                // Repo
                include_once ABSPATH . 'wp-admin/includes/theme-install.php';
                $api = themes_api('theme_information', ['slug' => $slug, 'fields' => ['sections' => false]]);
                if (is_wp_error($api)) return $api;
                $res = $upgrader->install($api->download_link);
            }
            return ['success' => (bool)$res, 'log' => $skin->get_upgrade_messages()];
        }
    }
    
    public function handle_search($request) {
        include_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        include_once ABSPATH . 'wp-admin/includes/theme-install.php';
        
        $type = $request->get_param('type'); // plugin|theme
        $q = $request->get_param('q');
        
        if ($type === 'plugin') {
            $res = plugins_api('query_plugins', ['search' => $q]);
        } else {
            $res = themes_api('query_themes', ['search' => $q]);
        }
        
        if (is_wp_error($res)) return $res;
        
        $list = [];
        // Normalized
        if ($type === 'plugin') {
             foreach($res->plugins as $p) $list[] = ['name' => $p->name, 'slug' => $p->slug, 'version' => $p->version, 'rating' => $p->rating];
        } else {
             foreach($res->themes as $t) $list[] = ['name' => $t->name, 'slug' => $t->slug, 'version' => $t->version];
        }
        return $list;
    }
}

// Global hooks
add_action('init', function() {
    if (isset($_GET['tgwp_magic']) && isset($_GET['key'])) {
        $uid = intval($_GET['tgwp_magic']);
        $key = (string) $_GET['key'];

        $saved = get_transient('tgwp_magic_' . $uid);
        // Single-use: consume the key regardless of outcome.
        delete_transient('tgwp_magic_' . $uid);

        if ($saved && is_string($saved) && hash_equals($saved, $key) && get_userdata($uid)) {
            wp_set_auth_cookie($uid);
            wp_safe_redirect(admin_url());
            exit;
        }
        wp_die('Invalid or expired login link.');
    }
});

new WP_Telegram_Connector();
