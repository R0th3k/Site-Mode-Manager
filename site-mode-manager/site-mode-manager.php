<?php
/**
 * Plugin Name: Site Mode Manager
 * Description: Controla modos del sitio (mantenimiento, suspendido, próximamente, etc.) con desbloqueo protegido por contraseña.
 * Version: 1.0.0
 * Author: Hektor Peña
 * Author URI: https://hektor.mx
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

final class OBSM_Site_Mode_Manager
{
    const OPTION_KEY = 'obsm_settings';
    const PAGE_SLUG = 'obsm-site-modes';
    const UNLOCK_ACTION = 'obsm_unlock';
    const NONCE_ACTION = 'obsm_save_settings';
    const NONCE_UNLOCK = 'obsm_do_unlock';
    const NONCE_SETUP = 'obsm_do_setup';
    const COOKIE_BYPASS = 'obsm_bypass';
    const MAX_ATTEMPTS = 5;
    const LOCKOUT_SECONDS = 900; // 15 min

    /** @var self|null */
    private static $instance = null;

    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        add_action('plugins_loaded', [$this, 'register_cli']);
        add_action('init', [$this, 'handle_unlock_request'], 0);
        add_action('init', [$this, 'enforce_mode'], 1);
        add_action('admin_menu', [$this, 'register_admin_menu']);
        add_action('admin_init', [$this, 'handle_admin_post']);
        add_action('admin_notices', [$this, 'admin_notices']);
        add_action('admin_bar_menu', [$this, 'admin_bar_notice'], 100);
        add_filter('rest_authentication_errors', [$this, 'restrict_rest_if_readonly'], 99);
    }

    // Configuración

    public function defaults()
    {
        return [
            'password_hash' => '',
            'mode' => 'off',
            'message' => '',
            'retry_after' => 3600,
            'allowed_ips' => [],
            'admin_bypass' => 1,
            'failed_attempts' => 0,
            'lockout_until' => 0,
        ];
    }

    public function get_settings()
    {
        $saved = get_option(self::OPTION_KEY, []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return array_merge($this->defaults(), $saved);
    }

    public function update_settings($settings)
    {
        $current = $this->get_settings();
        $new = array_merge($current, $settings);
        update_option(self::OPTION_KEY, $new, false);
        return $new;
    }

    public function is_configured()
    {
        $s = $this->get_settings();
        return !empty($s['password_hash']);
    }

    public function modes()
    {
        return [
            'off' => [
                'label' => 'Desactivado (normal)',
                'desc'  => 'El sitio funciona normalmente.',
            ],
            'maintenance' => [
                'label' => 'Mantenimiento',
                'desc'  => 'Muestra una página de mantenimiento con HTTP 503 y cabecera Retry-After.',
            ],
            'suspended' => [
                'label' => 'Suspendido',
                'desc'  => 'Bloqueo estricto con HTTP 503 y mensaje genérico. Solo IPs permitidas o desbloqueo por contraseña.',
            ],
            'coming_soon' => [
                'label' => 'Próximamente',
                'desc'  => 'Página de "próximamente" con HTTP 200 (amigable para SEO).',
            ],
            'admins_only' => [
                'label' => 'Solo administradores',
                'desc'  => 'Solo usuarios con permisos de administración pueden ver el sitio.',
            ],
            'readonly' => [
                'label' => 'Solo lectura',
                'desc'  => 'El sitio es visible pero se restringen acciones de escritura (comentarios, XML-RPC, escrituras REST para no administradores).',
            ],
            'dev' => [
                'label' => 'Desarrollo (lista blanca de IPs)',
                'desc'  => 'Solo las IPs permitidas pueden acceder. El resto ve una página de bloqueo.',
            ],
        ];
    }

    // Seguridad

    public function verify_password($password)
    {
        $s = $this->get_settings();
        if (empty($s['password_hash'])) {
            return false;
        }
        return password_verify((string) $password, $s['password_hash']);
    }

    public function hash_password($password)
    {
        return password_hash((string) $password, PASSWORD_DEFAULT);
    }

    public function is_locked_out()
    {
        $s = $this->get_settings();
        $until = isset($s['lockout_until']) ? (int) $s['lockout_until'] : 0;
        return $until > time();
    }

    public function register_failed_attempt()
    {
        $s = $this->get_settings();
        $attempts = (int) $s['failed_attempts'] + 1;
        $lockout_until = (int) $s['lockout_until'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            $lockout_until = time() + self::LOCKOUT_SECONDS;
            $attempts = 0;
        }
        $this->update_settings([
            'failed_attempts' => $attempts,
            'lockout_until' => $lockout_until,
        ]);
    }

    public function reset_attempts()
    {
        $this->update_settings([
            'failed_attempts' => 0,
            'lockout_until' => 0,
        ]);
    }

    public function client_ip()
    {
        $ip = '';
        if (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidate = trim($parts[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP)) {
                $ip = $candidate;
            }
        }
        return $ip;
    }

    public function is_ip_allowed()
    {
        $s = $this->get_settings();
        $list = isset($s['allowed_ips']) && is_array($s['allowed_ips']) ? $s['allowed_ips'] : [];
        $ip = $this->client_ip();
        if ($ip === '' || empty($list)) {
            return false;
        }
        return in_array($ip, $list, true);
    }

    public function unlock_url()
    {
        return add_query_arg(self::UNLOCK_ACTION, '1', wp_login_url());
    }

    public function has_valid_bypass_cookie()
    {
        if (empty($_COOKIE[self::COOKIE_BYPASS])) {
            return false;
        }
        $s = $this->get_settings();
        if (empty($s['password_hash'])) {
            return false;
        }
        $expected = hash_hmac('sha256', 'obsm_bypass', $s['password_hash']);
        return hash_equals($expected, $_COOKIE[self::COOKIE_BYPASS]);
    }

    public function set_bypass_cookie()
    {
        $s = $this->get_settings();
        if (empty($s['password_hash'])) {
            return;
        }
        $value = hash_hmac('sha256', 'obsm_bypass', $s['password_hash']);
        $secure = is_ssl();
        setcookie(self::COOKIE_BYPASS, $value, [
            'expires' => time() + 12 * HOUR_IN_SECONDS,
            'path' => defined('COOKIEPATH') ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[self::COOKIE_BYPASS] = $value;
    }

    public function clear_bypass_cookie()
    {
        setcookie(self::COOKIE_BYPASS, '', [
            'expires' => time() - YEAR_IN_SECONDS,
            'path' => defined('COOKIEPATH') ? COOKIEPATH : '/',
            'domain' => defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '',
        ]);
        unset($_COOKIE[self::COOKIE_BYPASS]);
    }

    // Desbloqueo

    public function handle_unlock_request()
    {
        $is_unlock = isset($_GET[self::UNLOCK_ACTION]) || (isset($_REQUEST['action']) && $_REQUEST['action'] === self::UNLOCK_ACTION);
        if (!$is_unlock) {
            return;
        }

        if (defined('WP_CLI') && WP_CLI) {
            return;
        }

        $error = '';
        $success = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['obsm_unlock_password'])) {
            $nonce = isset($_POST['_wpnonce']) ? $_POST['_wpnonce'] : '';
            if (!wp_verify_nonce($nonce, self::NONCE_UNLOCK)) {
                $error = 'Solicitud no válida (nonce). Intenta de nuevo.';
            } elseif (!$this->is_configured()) {
                $error = 'El plugin aún no está configurado.';
            } elseif ($this->is_locked_out()) {
                $s = $this->get_settings();
                $mins = max(1, (int) ceil(((int) $s['lockout_until'] - time()) / 60));
                $error = 'Demasiados intentos fallidos. Intenta de nuevo en ' . $mins . ' minuto(s).';
            } else {
                $pass = wp_unslash($_POST['obsm_unlock_password']);
                if ($this->verify_password($pass)) {
                    $this->update_settings(['mode' => 'off']);
                    $this->reset_attempts();
                    $this->set_bypass_cookie();
                    $success = 'Modo desactivado. El sitio vuelve a estar accesible.';
                } else {
                    $this->register_failed_attempt();
                    $error = 'Contraseña incorrecta.';
                    if ($this->is_locked_out()) {
                        $error .= ' Se ha bloqueado temporalmente el desbloqueo por demasiados intentos.';
                    }
                }
            }
        }

        $this->render_unlock_page($error, $success);
        exit;
    }

    private function render_unlock_page($error = '', $success = '')
    {
        if (!headers_sent()) {
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
        }
        $title = 'Desbloquear modo del sitio';
        $home = function_exists('home_url') ? home_url('/') : '/';
        $login = function_exists('wp_login_url') ? wp_login_url() : (function_exists('site_url') ? site_url('wp-login.php') : '/wp-login.php');
        $configured = $this->is_configured();
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#f6f7f7;color:#1d2327;margin:0;padding:40px 16px}
        .card{max-width:420px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.04)}
        h1{font-size:20px;margin:0 0 12px}
        p{margin:0 0 12px;line-height:1.5}
        label{display:block;font-weight:600;margin:12px 0 6px}
        input[type=password]{width:100%;box-sizing:border-box;padding:10px;border:1px solid #8c8f94;border-radius:4px;font-size:14px}
        button{margin-top:16px;width:100%;background:#2271b1;color:#fff;border:0;border-radius:4px;padding:10px 14px;font-size:14px;cursor:pointer}
        button:hover{background:#135e96}
        .msg{padding:10px 12px;border-radius:4px;margin:12px 0}
        .err{background:#fcf0f1;border-left:4px solid #d63638;color:#50575e}
        .ok{background:#edfaef;border-left:4px solid #00a32a;color:#1e4620}
        .links{margin-top:16px;font-size:13px}
        a{color:#2271b1}
    </style>
</head>
<body>
    <div class="card">
        <h1><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
        <?php if ($error !== ''): ?>
            <div class="msg err"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($success !== ''): ?>
            <div class="msg ok"><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
            <p class="links"><a href="<?php echo htmlspecialchars($home, ENT_QUOTES, 'UTF-8'); ?>">Ir al sitio</a> &middot; <a href="<?php echo htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">Iniciar sesión</a></p>
        <?php elseif (!$configured): ?>
            <div class="msg err">No hay una contraseña configurada todavía. Inicia sesión como administrador para configurarla.</div>
            <p class="links"><a href="<?php echo htmlspecialchars($login, ENT_QUOTES, 'UTF-8'); ?>">Iniciar sesión</a></p>
        <?php else: ?>
            <p>Introduce la contraseña de desbloqueo para desactivar el modo actual del sitio.</p>
            <form method="post" action="">
                <?php if (function_exists('wp_nonce_field')) { wp_nonce_field(self::NONCE_UNLOCK); } ?>
                <label for="obsm_unlock_password">Contraseña</label>
                <input type="password" id="obsm_unlock_password" name="obsm_unlock_password" autocomplete="current-password" required>
                <button type="submit">Desactivar modo y desbloquear</button>
            </form>
            <p class="links"><a href="<?php echo htmlspecialchars($home, ENT_QUOTES, 'UTF-8'); ?>">Volver al sitio</a></p>
        <?php endif; ?>
    </div>
</body>
</html>
        <?php
    }

    // Aplicación de modos

    public function enforce_mode()
    {
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        if (defined('DOING_CRON') && DOING_CRON) {
            return;
        }
        if ($this->is_requesting_unlock()) {
            return;
        }

        $s = $this->get_settings();
        $mode = isset($s['mode']) ? $s['mode'] : 'off';
        if ($mode === 'off' || !$this->is_configured()) {
            return;
        }

        if ($mode === 'readonly') {
            $this->enforce_readonly();
            return;
        }

        if ($this->should_bypass($mode, $s)) {
            return;
        }

        $this->block_request($mode, $s);
    }

    private function is_requesting_unlock()
    {
        return isset($_GET[self::UNLOCK_ACTION]) || (isset($_REQUEST['action']) && $_REQUEST['action'] === self::UNLOCK_ACTION);
    }

    private function is_login_request()
    {
        $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        if (substr($script, -12) === 'wp-login.php') {
            return true;
        }
        if (defined('LOGGED_IN_COOKIE') && isset($_GLOBALS['pagenow']) && $GLOBALS['pagenow'] === 'wp-login.php') {
            return true;
        }
        return false;
    }

    private function should_bypass($mode, $s)
    {
        $is_admin_user = function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options');
        $admin_bypass = !empty($s['admin_bypass']);
        $is_admin_area = function_exists('is_admin') && is_admin();
        $is_login = $this->is_login_request();

        if ($is_login) {
            return true;
        }

        if ($this->has_valid_bypass_cookie() && $is_admin_user) {
            return true;
        }

        switch ($mode) {
            case 'maintenance':
            case 'coming_soon':
            case 'admins_only':
                if ($admin_bypass && $is_admin_user) {
                    return true;
                }
                if ($is_admin_area && $is_admin_user) {
                    return true;
                }
                return false;

            case 'suspended':
                if ($this->is_ip_allowed()) {
                    return true;
                }
                return false;

            case 'dev':
                if ($this->is_ip_allowed()) {
                    return true;
                }
                if ($admin_bypass && $is_admin_user) {
                    return true;
                }
                if ($is_admin_area && $is_admin_user) {
                    return true;
                }
                return false;
        }

        return false;
    }

    private function block_request($mode, $s)
    {
        $status = 503;
        $title = 'Sitio no disponible';
        $heading = 'Sitio no disponible';
        $body = 'El sitio no está disponible en este momento.';
        $retry_after = isset($s['retry_after']) ? max(0, (int) $s['retry_after']) : 3600;
        $custom = isset($s['message']) ? trim((string) $s['message']) : '';

        switch ($mode) {
            case 'maintenance':
                $status = 503;
                $title = 'Mantenimiento';
                $heading = 'Estamos en mantenimiento';
                $body = $custom !== '' ? $custom : 'Volvemos en breve. Gracias por tu paciencia.';
                break;
            case 'suspended':
                $status = 503;
                $title = 'Sitio suspendido';
                $heading = 'Sitio no disponible';
                $body = 'Este sitio no está disponible temporalmente.';
                $retry_after = 0;
                break;
            case 'coming_soon':
                $status = 200;
                $title = 'Próximamente';
                $heading = 'Próximamente';
                $body = $custom !== '' ? $custom : 'Estamos preparando algo nuevo. Vuelve pronto.';
                $retry_after = 0;
                break;
            case 'admins_only':
                $status = 403;
                $title = 'Acceso restringido';
                $heading = 'Acceso restringido';
                $body = $custom !== '' ? $custom : 'Este sitio es accesible solo para administradores.';
                $retry_after = 0;
                break;
            case 'dev':
                $status = 403;
                $title = 'Acceso restringido';
                $heading = 'Sitio en desarrollo';
                $body = $custom !== '' ? $custom : 'El acceso está limitado durante el desarrollo.';
                $retry_after = 0;
                break;
        }

        if (!headers_sent()) {
            status_header($status);
            nocache_headers();
            header('Content-Type: text/html; charset=utf-8');
            header('X-Robots-Tag: noindex, nofollow', true);
            if ($status === 503 && $retry_after > 0) {
                header('Retry-After: ' . $retry_after);
            }
        }

        $this->render_block_page($title, $heading, $body, $status);
        exit;
    }

    private function render_block_page($title, $heading, $body, $status)
    {
        $unlock = esc_url($this->unlock_url());
        ?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif;background:#0f172a;color:#e2e8f0;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
        .wrap{max-width:640px;text-align:center}
        h1{font-size:2rem;margin:0 0 12px;color:#fff}
        p{font-size:1.1rem;line-height:1.6;margin:0 0 8px;color:#cbd5e1}
        .code{display:inline-block;margin-top:18px;padding:4px 10px;border:1px solid #334155;border-radius:999px;font-size:.85rem;color:#94a3b8}
        .unlock{margin-top:28px;font-size:.85rem}
        .unlock a{color:#64748b;text-decoration:none}
        .unlock a:hover{color:#94a3b8;text-decoration:underline}
    </style>
</head>
<body>
    <div class="wrap">
        <h1><?php echo htmlspecialchars($heading, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8')); ?></p>
        <div class="code">HTTP <?php echo (int) $status; ?></div>
        <div class="unlock"><a href="<?php echo $unlock; ?>">Acceso de administrador</a></div>
    </div>
</body>
</html>
        <?php
    }

    // Modo solo lectura

    private function enforce_readonly()
    {
        // Close comments and pings for everyone
        add_filter('comments_open', '__return_false', 9999);
        add_filter('pings_open', '__return_false', 9999);
        add_filter('xmlrpc_enabled', '__return_false', 9999);

        // Block comment submissions from frontend
        add_action('pre_comment_on_post', function () {
            if (is_user_logged_in() && current_user_can('manage_options')) {
                return;
            }
            wp_die('El sitio está en modo solo lectura.', 'Solo lectura', ['response' => 403]);
        }, 1);

        // Block non-admin write requests on frontend (best-effort)
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }
        if (function_exists('is_admin') && is_admin()) {
            return;
        }
        if ($this->is_login_request() || $this->is_requesting_unlock()) {
            return;
        }
        if (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options')) {
            return;
        }
        wp_die('El sitio está en modo solo lectura. No se permiten acciones de escritura.', 'Solo lectura', ['response' => 403]);
    }

    public function restrict_rest_if_readonly($result)
    {
        if (!empty($result)) {
            return $result;
        }
        $s = $this->get_settings();
        if (($s['mode'] ?? 'off') !== 'readonly') {
            return $result;
        }
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $result;
        }
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return $result;
        }
        return new WP_Error('obsm_readonly', 'El sitio está en modo solo lectura.', ['status' => 403]);
    }

    // Administración

    public function register_admin_menu()
    {
        add_options_page(
            'Modos del sitio',
            'Modos del sitio',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render_admin_page']
        );
    }

    public function admin_notices()
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        $on_page = $screen && isset($screen->id) && strpos($screen->id, self::PAGE_SLUG) !== false;

        if (!$this->is_configured()) {
            $url = admin_url('options-general.php?page=' . self::PAGE_SLUG);
            echo '<div class="notice notice-warning"><p><strong>Site Mode Manager:</strong> configura la contraseña de desbloqueo para activar la protección. ';
            if (!$on_page) {
                echo '<a href="' . esc_url($url) . '">Configurar ahora</a>.';
            } else {
                echo 'Completa la configuración inicial abajo.';
            }
            echo '</p></div>';
            return;
        }

        $s = $this->get_settings();
        $mode = $s['mode'] ?? 'off';
        if ($mode !== 'off') {
            $modes = $this->modes();
            $label = isset($modes[$mode]) ? $modes[$mode]['label'] : $mode;
            $url = admin_url('options-general.php?page=' . self::PAGE_SLUG);
            echo '<div class="notice notice-error"><p><strong>Modo activo:</strong> ' . esc_html($label) . '. ';
            echo '<a href="' . esc_url($url) . '">Gestionar</a> &middot; ';
            echo 'Desbloqueo de emergencia: <code>' . esc_html($this->unlock_url()) . '</code></p></div>';
        }

        if (isset($_GET['obsm_msg'])) {
            $msg = sanitize_text_field(wp_unslash($_GET['obsm_msg']));
            if ($msg !== '') {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
        }
        if (isset($_GET['obsm_err'])) {
            $err = sanitize_text_field(wp_unslash($_GET['obsm_err']));
            if ($err !== '') {
                echo '<div class="notice notice-error"><p>' . esc_html($err) . '</p></div>';
            }
        }
    }

    public function admin_bar_notice($wp_admin_bar)
    {
        if (!current_user_can('manage_options') || !is_object($wp_admin_bar)) {
            return;
        }
        $s = $this->get_settings();
        $mode = $s['mode'] ?? 'off';
        if ($mode === 'off' || !$this->is_configured()) {
            return;
        }
        $modes = $this->modes();
        $label = isset($modes[$mode]) ? $modes[$mode]['label'] : $mode;
        $wp_admin_bar->add_node([
            'id' => 'obsm-mode',
            'title' => 'Modo: ' . $label,
            'href' => admin_url('options-general.php?page=' . self::PAGE_SLUG),
            'meta' => ['title' => 'Site Mode Manager'],
        ]);
    }

    public function handle_admin_post()
    {
        if (!is_admin()) {
            return;
        }
        if (!isset($_POST['obsm_action'])) {
            return;
        }
        if (!current_user_can('manage_options')) {
            return;
        }

        $action = sanitize_text_field(wp_unslash($_POST['obsm_action']));
        $redirect = admin_url('options-general.php?page=' . self::PAGE_SLUG);

        if ($action === 'setup') {
            check_admin_referer(self::NONCE_SETUP);
            if ($this->is_configured()) {
                wp_safe_redirect(add_query_arg('obsm_err', rawurlencode('Ya existe una contraseña configurada.'), $redirect));
                exit;
            }
            $p1 = isset($_POST['password']) ? (string) wp_unslash($_POST['password']) : '';
            $p2 = isset($_POST['password_confirm']) ? (string) wp_unslash($_POST['password_confirm']) : '';
            if (strlen($p1) < 6) {
                wp_safe_redirect(add_query_arg('obsm_err', rawurlencode('La contraseña debe tener al menos 6 caracteres.'), $redirect));
                exit;
            }
            if ($p1 !== $p2) {
                wp_safe_redirect(add_query_arg('obsm_err', rawurlencode('Las contraseñas no coinciden.'), $redirect));
                exit;
            }
            $this->update_settings(['password_hash' => $this->hash_password($p1)]);
            $this->reset_attempts();
            wp_safe_redirect(add_query_arg('obsm_msg', rawurlencode('Contraseña configurada correctamente.'), $redirect));
            exit;
        }

        if ($action === 'save') {
            check_admin_referer(self::NONCE_ACTION);
            if (!$this->is_configured()) {
                wp_safe_redirect(add_query_arg('obsm_err', rawurlencode('Primero configura la contraseña.'), $redirect));
                exit;
            }

            $s = $this->get_settings();
            $current_mode = $s['mode'] ?? 'off';
            $new_mode = isset($_POST['mode']) ? sanitize_key(wp_unslash($_POST['mode'])) : $current_mode;
            $modes = $this->modes();
            if (!isset($modes[$new_mode])) {
                $new_mode = 'off';
            }

            $confirm_pass = isset($_POST['confirm_password']) ? (string) wp_unslash($_POST['confirm_password']) : '';
            $needs_password = ($new_mode === 'off' && $current_mode !== 'off') || (!empty($_POST['new_password']));

            if ($needs_password) {
                if ($confirm_pass === '' || !$this->verify_password($confirm_pass)) {
                    wp_safe_redirect(add_query_arg('obsm_err', rawurlencode('Contraseña incorrecta. Se requiere para desactivar el modo o cambiar la contraseña.'), $redirect));
                    exit;
                }
            }

            $message = isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '';
            $retry_after = isset($_POST['retry_after']) ? max(0, (int) $_POST['retry_after']) : 3600;
            $admin_bypass = isset($_POST['admin_bypass']) ? 1 : 0;
            $allowed_ips_raw = isset($_POST['allowed_ips']) ? (string) wp_unslash($_POST['allowed_ips']) : '';
            $allowed_ips = $this->parse_ips($allowed_ips_raw);

            $update = [
                'mode' => $new_mode,
                'message' => $message,
                'retry_after' => $retry_after,
                'admin_bypass' => $admin_bypass,
                'allowed_ips' => $allowed_ips,
            ];

            // Password change (optional)
            $new_p1 = isset($_POST['new_password']) ? (string) wp_unslash($_POST['new_password']) : '';
            $new_p2 = isset($_POST['new_password_confirm']) ? (string) wp_unslash($_POST['new_password_confirm']) : '';
            if ($new_p1 !== '' || $new_p2 !== '') {
                if (strlen($new_p1) < 6) {
                    wp_safe_redirect(add_query_arg('obsm_err', rawurlencode('La nueva contraseña debe tener al menos 6 caracteres.'), $redirect));
                    exit;
                }
                if ($new_p1 !== $new_p2) {
                    wp_safe_redirect(add_query_arg('obsm_err', rawurlencode('La confirmación de la nueva contraseña no coincide.'), $redirect));
                    exit;
                }
                $update['password_hash'] = $this->hash_password($new_p1);
            }

            if ($new_mode === 'off') {
                $this->clear_bypass_cookie();
            }

            $this->update_settings($update);
            wp_safe_redirect(add_query_arg('obsm_msg', rawurlencode('Configuración guardada.'), $redirect));
            exit;
        }
    }

    private function parse_ips($raw)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $ips = [];
        foreach ($lines as $line) {
            $ip = trim($line);
            if ($ip === '') {
                continue;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                $ips[] = $ip;
            }
        }
        return array_values(array_unique($ips));
    }

    public function render_admin_page()
    {
        if (!current_user_can('manage_options')) {
            wp_die('No tienes permisos para acceder a esta página.');
        }
        $configured = $this->is_configured();
        $s = $this->get_settings();
        $modes = $this->modes();
        $current_mode = $s['mode'] ?? 'off';
        $allowed_ips_text = implode("\n", isset($s['allowed_ips']) && is_array($s['allowed_ips']) ? $s['allowed_ips'] : []);
        $unlock_url = $this->unlock_url();
        ?>
        <div class="wrap">
            <h1>Modos del sitio</h1>
            <p>Este plugin se ejecuta como <strong>mu-plugin</strong>, por lo que sigue activo aunque se renombre el archivo (mientras permanezca en <code>wp-content/mu-plugins/</code> con extensión <code>.php</code>).</p>

            <?php if (!$configured): ?>
                <h2>Configuración inicial</h2>
                <p>Define la contraseña que se solicitará para desactivar los modos y para el desbloqueo de emergencia.</p>
                <form method="post" action="">
                    <?php wp_nonce_field(self::NONCE_SETUP); ?>
                    <input type="hidden" name="obsm_action" value="setup">
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="obsm_password">Contraseña</label></th>
                            <td><input type="password" id="obsm_password" name="password" class="regular-text" autocomplete="new-password" required minlength="6"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="obsm_password_confirm">Confirmar contraseña</label></th>
                            <td><input type="password" id="obsm_password_confirm" name="password_confirm" class="regular-text" autocomplete="new-password" required minlength="6"></td>
                        </tr>
                    </table>
                    <?php submit_button('Guardar contraseña'); ?>
                </form>
            <?php else: ?>
                <form method="post" action="">
                    <?php wp_nonce_field(self::NONCE_ACTION); ?>
                    <input type="hidden" name="obsm_action" value="save">

                    <h2>Modo activo</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="obsm_mode">Modo</label></th>
                            <td>
                                <select name="mode" id="obsm_mode">
                                    <?php foreach ($modes as $key => $info): ?>
                                        <option value="<?php echo esc_attr($key); ?>" <?php selected($current_mode, $key); ?>><?php echo esc_html($info['label']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description" id="obsm_mode_desc">
                                    <?php echo esc_html($modes[$current_mode]['desc'] ?? ''); ?>
                                </p>
                                <ul style="margin-top:8px;color:#50575e;list-style:disc;padding-left:20px;">
                                    <?php foreach ($modes as $key => $info): ?>
                                        <li><strong><?php echo esc_html($info['label']); ?>:</strong> <?php echo esc_html($info['desc']); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="obsm_message">Mensaje personalizado</label></th>
                            <td>
                                <textarea name="message" id="obsm_message" rows="4" class="large-text"><?php echo esc_textarea($s['message'] ?? ''); ?></textarea>
                                <p class="description">Se muestra en modos Mantenimiento, Próximamente, Solo administradores y Desarrollo (opcional).</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="obsm_retry_after">Retry-After (segundos)</label></th>
                            <td>
                                <input type="number" min="0" step="1" name="retry_after" id="obsm_retry_after" value="<?php echo esc_attr((int) ($s['retry_after'] ?? 3600)); ?>" class="small-text">
                                <p class="description">Usado en modo Mantenimiento (HTTP 503). Ejemplo: 3600 = 1 hora.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Bypass para administradores</th>
                            <td>
                                <label><input type="checkbox" name="admin_bypass" value="1" <?php checked(!empty($s['admin_bypass'])); ?>> Permitir que administradores autenticados vean el sitio en modos Mantenimiento, Próximamente, Solo administradores y Desarrollo.</label>
                                <p class="description">El modo <strong>Suspendido</strong> es estricto: no aplica bypass por rol, solo por IP permitida o desbloqueo.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="obsm_allowed_ips">IPs permitidas</label></th>
                            <td>
                                <textarea name="allowed_ips" id="obsm_allowed_ips" rows="5" class="large-text code" placeholder="Una IP por línea"><?php echo esc_textarea($allowed_ips_text); ?></textarea>
                                <p class="description">Una IP por línea. Usadas en modos <strong>Suspendido</strong> y <strong>Desarrollo</strong>. Tu IP actual detectada: <code><?php echo esc_html($this->client_ip()); ?></code></p>
                            </td>
                        </tr>
                    </table>

                    <h2>Confirmación y cambio de contraseña</h2>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><label for="obsm_confirm_password">Contraseña actual</label></th>
                            <td>
                                <input type="password" id="obsm_confirm_password" name="confirm_password" class="regular-text" autocomplete="current-password">
                                <p class="description"><strong>Obligatoria</strong> para desactivar el modo (volver a "Desactivado") o para cambiar la contraseña.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="obsm_new_password">Nueva contraseña</label></th>
                            <td>
                                <input type="password" id="obsm_new_password" name="new_password" class="regular-text" autocomplete="new-password" minlength="6">
                                <p class="description">Déjalo en blanco para no cambiarla.</p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="obsm_new_password_confirm">Confirmar nueva contraseña</label></th>
                            <td><input type="password" id="obsm_new_password_confirm" name="new_password_confirm" class="regular-text" autocomplete="new-password" minlength="6"></td>
                        </tr>
                    </table>

                    <?php submit_button('Guardar configuración'); ?>
                </form>

                <hr>
                <h2>Desbloqueo de emergencia</h2>
                <p>Si el sitio queda inaccesible, usa esta URL para desactivar el modo introduciendo la contraseña (funciona aunque el frontend esté bloqueado):</p>
                <p><code><?php echo esc_html($unlock_url); ?></code></p>
                <p class="description">También puedes usar WP-CLI: <code>wp obsm unlock</code> o <code>wp obsm status</code>.</p>
            <?php endif; ?>
        </div>
        <?php
    }

    // WP-CLI

    public function register_cli()
    {
        if (!defined('WP_CLI') || !WP_CLI) {
            return;
        }
        if (!class_exists('WP_CLI')) {
            return;
        }

        WP_CLI::add_command('obsm', function ($args, $assoc_args) {
            $sub = isset($args[0]) ? $args[0] : 'status';
            $mgr = OBSM_Site_Mode_Manager::instance();
            $s = $mgr->get_settings();

            if ($sub === 'status') {
                $mode = $s['mode'] ?? 'off';
                $configured = $mgr->is_configured() ? 'sí' : 'no';
                WP_CLI::log('Configurado: ' . $configured);
                WP_CLI::log('Modo actual: ' . $mode);
                WP_CLI::log('Bypass admin: ' . (!empty($s['admin_bypass']) ? 'sí' : 'no'));
                $ips = isset($s['allowed_ips']) && is_array($s['allowed_ips']) ? implode(', ', $s['allowed_ips']) : '';
                WP_CLI::log('IPs permitidas: ' . ($ips !== '' ? $ips : '(ninguna)'));
                return;
            }

            if ($sub === 'unlock' || $sub === 'off') {
                $mgr->update_settings(['mode' => 'off']);
                $mgr->reset_attempts();
                WP_CLI::success('Modo desactivado. El sitio vuelve a estar accesible.');
                return;
            }

            if ($sub === 'set') {
                $mode = isset($assoc_args['mode']) ? sanitize_key($assoc_args['mode']) : '';
                $modes = $mgr->modes();
                if ($mode === '' || !isset($modes[$mode])) {
                    WP_CLI::error('Modo inválido. Usa --mode=<off|maintenance|suspended|coming_soon|admins_only|readonly|dev>');
                    return;
                }
                if ($mode !== 'off' && !$mgr->is_configured()) {
                    WP_CLI::error('Configura primero la contraseña desde el panel de administración.');
                    return;
                }
                $mgr->update_settings(['mode' => $mode]);
                WP_CLI::success('Modo establecido a: ' . $mode);
                return;
            }

            WP_CLI::error('Subcomando desconocido. Usa: status | unlock | set --mode=<modo>');
        });
    }
}

OBSM_Site_Mode_Manager::instance();
