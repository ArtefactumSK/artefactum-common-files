<?php
/**
 * Artefactum Licence Client Module
 * Centrálny súbor pre všetky WordPress inštalácie
 * Version: 3.1
 * Last update: 2026-01-11
 * 
 * CHANGELOG:
 * - Pridaná localStorage podpora pre dismiss notices (24h)
 * - Pridaná localStorage podpora pre dismiss dashboard widget (24h)
 * - Odstránené user_meta a AJAX handlery
 * - Zachovaná spätná kompatibilita
 */

// Security check
if (!defined('ABSPATH')) {
    exit('Direct access denied');
}

// Guard proti viacnásobnému načítaniu
if (defined('ARTEFACTUM_CLIENT_LOADED')) {
    return;
}
define('ARTEFACTUM_CLIENT_LOADED', true);

// ============================================================================
// KONFIGURÁCIA
// ============================================================================

define('ARTEFACTUM_API_URL', 'https://my.artefactum.sk/wp-json/artefactum/v1/licence-check');
define('ARTEFACTUM_API_SECRET', 'ART-MH8T-R13N-2938-O9JA-7RD9');
define('ARTEFACTUM_CACHE_HOURS', 4);
define('ARTEFACTUM_GRACE_DAYS', 28);
define('ARTEFACTUM_WARNING_DAYS', 30);
define('ARTEFACTUM_SUPER_ADMIN', 'artefactum');

// ============================================================================
// CORE FUNKCIE
// ============================================================================

function artefactum_generate_token($domain) {
    return hash_hmac('sha256', $domain, ARTEFACTUM_API_SECRET);
}

function artefactum_get_current_domain() {
    $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown';
    return strtolower(preg_replace('/^www\./', '', $domain));
}

function artefactum_is_super_admin() {
    $current_user = wp_get_current_user();
    return $current_user->user_login === ARTEFACTUM_SUPER_ADMIN;
}

function artefactum_check_licence($force_refresh = false) {
    $domain = artefactum_get_current_domain();
    $cache_key = 'artefactum_licence_' . md5($domain);
    
    if (!$force_refresh) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
    }
    
    $current_user = wp_get_current_user();
    $admin_email = $current_user->exists() ? $current_user->user_email : get_option('admin_email');
    
    $response = wp_remote_post(ARTEFACTUM_API_URL, [
        'body' => [
            'domain' => $domain,
            'token' => artefactum_generate_token($domain),
            'admin_email' => $admin_email
        ],
        'timeout' => 10,
        'sslverify' => true,
        'headers' => [
            'User-Agent' => 'Artefactum-Client/3.1 WordPress/' . get_bloginfo('version')
        ]
    ]);
    
    if (is_wp_error($response)) {
        error_log('Artefactum API Error: ' . $response->get_error_message());
        
        $last_state = get_option('artefactum_last_licence_state', [
            'valid' => false,
            'status' => 'error',
            'message' => 'API nedostupné',
            'license_key' => null,
            'expiry_date' => null
        ]);
        
        return $last_state;
    }
    
    $body = wp_remote_retrieve_body($response);
    $http_code = wp_remote_retrieve_response_code($response);
    
    if ($http_code !== 200) {
        error_log("Artefactum API returned HTTP {$http_code}: {$body}");
        return get_option('artefactum_last_licence_state', ['valid' => false, 'status' => 'error']);
    }
    
    $data = json_decode($body, true);
    
    if (!is_array($data)) {
        error_log('Artefactum API: Invalid JSON response');
        return get_option('artefactum_last_licence_state', ['valid' => false, 'status' => 'error']);
    }
    
    if (!isset($data['messages']) || !is_array($data['messages'])) {
        $data['messages'] = [];
    }

    if (isset($data['custom_message']) && is_array($data['custom_message'])) {
        $data['messages'] = $data['custom_message'];
    }
    
    set_transient($cache_key, $data, ARTEFACTUM_CACHE_HOURS * HOUR_IN_SECONDS);
    update_option('artefactum_last_licence_state', $data);
    
    error_log("Artefactum: Licence checked for {$domain} - Status: " . ($data['status'] ?? 'unknown'));
    
    return $data;
}

// ============================================================================
// BLOKOVANIE PRÍSTUPU PRI EXPIROVANEJ LICENCII
// ============================================================================

add_action('admin_init', function() {
    if (artefactum_is_super_admin()) {
        return;
    }
    
    if (!is_admin() || !current_user_can('administrator') || !current_user_can('manage_options') || !current_user_can('editor') || !current_user_can('author')) {
        return;
    }
    
    if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    
    $licence = artefactum_check_licence();
    
    if (isset($licence['status']) && $licence['status'] === 'not_found') {
        $domain = artefactum_get_current_domain();
        
        wp_die(
            '<div style="text-align:center; padding:50px; font-family:Arial,sans-serif;">
                <div style="font-size:64px; margin-bottom:20px;">🚫</div>
                <h1 style="color:#FF0F0F; margin-bottom:10px;">Licencia nenájdená</h1>
                <p style="font-size:18px; color:#666; margin-bottom:30px;">
                    Táto webstránka nemá platnú licenciu <b>Artefactum Websuit</b>.
                </p>
                <div style="background:#f8f9fa; padding:20px; border-radius:8px; display:inline-block; text-align:left;">
                    <strong>Doména:</strong> <code>' . esc_html($domain) . '</code><br>
                </div>
                <p style="margin-top:30px;">
                    Kontaktujte <a href="mailto:support@artefactum.sk?subject=' . esc_html($domain) . ' NOT FOUND" style="color:#f60; font-weight:bold;">Artefactum support</a> pre aktiváciu licencie.
                </p>
            </div>',
            'Artefactum - Licencia nenájdená',
            ['response' => 403, 'back_link' => false]
        );
    }
    
    if (isset($licence['status']) && $licence['status'] === 'invalid_subdomain') {
        $domain = artefactum_get_current_domain();
        
        wp_die(
            '<div style="text-align:center; padding:50px; font-family:Arial,sans-serif;">
                <div style="font-size:64px; margin-bottom:20px;">🚫</div>
                <h1 style="color:#FF0F0F; margin-bottom:10px;">Neplatná licencia</h1>
                <p style="font-size:18px; color:#666; margin-bottom:30px;">
                    Subdoména <b>' . esc_html($domain) . '</b> nie je licencovaná.
                </p>
                <div style="background:#f8f9fa; padding:20px; border-radius:8px; display:inline-block; text-align:left;">
                    <strong>Doména:</strong> <code>' . esc_html($domain) . '</code><br>
                    <strong>Status:</strong> <code style="color:#FF0F0F;">INVALID_SUBDOMAIN</code>
                </div>
                <p style="margin-top:30px;">
                    Kontaktujte <a href="mailto:support@artefactum.sk?subject=' . esc_html($domain) . ' SUBDOMAIN" style="color:#f60; font-weight:bold;">Artefactum support</a> pre aktiváciu subdomény.
                </p>
            </div>',
            'Artefactum - Neplatná subdoména',
            ['response' => 403, 'back_link' => false]
        );
    }
    
    if (isset($licence['status']) && $licence['status'] === 'expired') {
        $domain = artefactum_get_current_domain();
        $license_key = $licence['license_key'] ?? 'N/A';
        $mailbody = urlencode("Licenece_ID:\n" . $license_key . "\n\nspráva...");
        
        wp_die(
            '<div style="text-align:center; padding:50px; font-family:Arial,sans-serif;">
                <div style="font-size:64px; margin-bottom:20px;">⚠️</div>
                <h1 style="color:#FF0F0F; margin-bottom:10px;">Licencia expirovala</h1>
                <p style="font-size:18px; color:#666; margin-bottom:30px;">
                    Táto webstránka nemá platnú licenciu <b>Artefactum Websuit</b>.
                </p>
                <div style="background:#f8f9fa; padding:20px; border-radius:8px; display:inline-block; text-align:left;">
                    <strong>Doména:</strong> <code>' . esc_html($domain) . '</code><br>
                    <strong>Licenčný kľúč:</strong> <code>' . esc_html($license_key) . '</code><br>
                </div>
                <p style="margin-top:30px;">
                    Kontaktujte <a href="mailto:support@artefactum.sk?subject=' . esc_html($domain) . ' EXPIRED&body=' . esc_html($mailbody) . '" style="color:#f60; font-weight:bold;">Artefactum support</a> pre obnovenie licencie.
                </p>
            </div>',
            'Artefactum - Licencia expirovala',
            ['response' => 403, 'back_link' => false]
        );
    }

}, 5);

// ============================================================================
// ADMIN UPOZORNENIA (GRACE PERIOD & PRE-EXPIRY) - s localStorage
// ============================================================================

add_action('admin_notices', function() {
    if (!is_admin()) {return;}
    
    $licence = artefactum_check_licence();
    
    if (!empty($licence['messages']) && is_array($licence['messages'])) {
        foreach ($licence['messages'] as $index => $msg) {
            if (empty($msg['message'])) {
                continue;
            }
            
            $priority = $msg['priority'] ?? 'info';
            
            $priority_styles = [
                'info' => ['bg' => '#E0FEFE', 'border' => '#0000FF', 'color' => '#0c4a6e', 'icon' => '💬'],
                'warning' => ['bg' => '#FCF8F7', 'border' => '#f60', 'color' => '#f60', 'icon' => '⚠️'],
                'critical' => ['bg' => '#fff1f1', 'border' => '#ff0f0f', 'color' => '#ff0f0f', 'icon' => '🚨']
            ];
            
            $style = $priority_styles[$priority] ?? $priority_styles['info'];
            $notice_id = 'arte_notice_' . md5($msg['message'] . $priority);

            echo '<div id="' . esc_attr($notice_id) . '" class="notice arte-dismissible-notice" style="background:' . $style['bg'] . '; border-left:4px solid ' . $style['border'] . ' !important; padding:12px 40px 12px 15px; position:relative;">';
            echo '<button type="button" class="notice-dismiss arte-notice-dismiss" data-notice-id="' . esc_attr($notice_id) . '" style="position:absolute; top:0; right:0; padding:9px; background:none; border:none; color:inherit; cursor:pointer;"><span class="screen-reader-text">Dismiss</span></button>';
            echo '<p style="margin:0; color:' . $style['color'] . '; font-weight:500;">';
            echo $style['icon'] . ' <strong style="text-transform:uppercase">ARTEFACTUM ' . strtoupper($priority) . ':</strong></p>';
            echo '<p style="margin:8px 0 0 0; color:' . $style['color'] . ';">' . wp_kses_post($msg['message']) . '</p>';
            echo '</div>';
        }
        ?>
        <script>
        (function() {
            var dismissButtons = document.querySelectorAll('.arte-notice-dismiss');
            dismissButtons.forEach(function(btn) {
                btn.addEventListener('click', function(e) {
                    e.preventDefault();
                    var noticeId = this.getAttribute('data-notice-id');
                    var notice = document.getElementById(noticeId);
                    
                    var dismissed = JSON.parse(localStorage.getItem('arte_dismissed_notices') || '{}');
                    dismissed[noticeId] = Date.now();
                    localStorage.setItem('arte_dismissed_notices', JSON.stringify(dismissed));
                    
                    if (notice) {
                        notice.style.display = 'none';
                    }
                });
            });
            
            var dismissed = JSON.parse(localStorage.getItem('arte_dismissed_notices') || '{}');
            var now = Date.now();
            var dayInMs = 24 * 60 * 60 * 1000;
            
            Object.keys(dismissed).forEach(function(noticeId) {
                if ((now - dismissed[noticeId]) < dayInMs) {
                    var notice = document.getElementById(noticeId);
                    if (notice) {
                        notice.style.display = 'none';
                    }
                } else {
                    delete dismissed[noticeId];
                }
            });
            
            localStorage.setItem('arte_dismissed_notices', JSON.stringify(dismissed));
        })();
        </script>
        <?php
    }
    
    if (!isset($licence['status']) || $licence['status'] === 'error') {
        return;
    }
    
    if (!$licence['valid'] && $licence['status'] !== 'expired') {
        return;
    }
    
    $show_notice = false;
    $notice_class = 'notice-warning';
    $message = '';
    $icon = '⚠️';
    
    if (!empty($licence['grace_period'])) {
        $show_notice = true;
        $notice_class = 'notice-error';
        $icon = '🚨';
        $days = intval($licence['days_remaining'] ?? 0);
        $days_text = $days === 1 ? 'deň' : ($days <= 4 ? 'dni' : 'dní');
        $message = sprintf(
            '<strong style="color:#FF0F0F;">KRITICKÉ UPOZORNENIE:</strong> Licencia expirovala! Zostáva <strong style="color:#FF0F0F;">%d %s</strong> na obnovenie.',
            $days,
            $days_text
        );
    } elseif (!empty($licence['pre_warning'])) {
        $show_notice = true;
        $notice_class = 'notice-warning';
        $icon = '⏰';
        $days = intval($licence['days_remaining'] ?? 0);
        $days_text = $days === 1 ? 'deň' : ($days <= 4 ? 'dni' : 'dní');
        $message = sprintf(
            '<strong style="color:#FF5F15;">UPOZORNENIE:</strong> Licencia vyprší o <strong style="color:#FF5F15;">%d %s</strong>.',
            $days,
            $days_text
        );
    }
    
    if ($show_notice) {
        $expiry_formatted = !empty($licence['expiry_date']) 
            ? date('d.m.Y', strtotime($licence['expiry_date']))
            : 'N/A';
        
        $domain = artefactum_get_current_domain();
        
        echo '<div class="notice ' . $notice_class . ' is-dismissible" style="border-left-width:4px; border-left-color:#f60 !important;">';
        echo '<p style="margin:10px 0;">';
        echo $icon . ' <strong style="color:#f60; font-size:14px;">ARTEFACTUM WEBSUITE LICENCE</strong>';
        echo '</p>';
        echo '<p style="margin:5px 0;"><strong>Doména:</strong> ' . esc_html($domain) . '</p>';
        echo '<p style="margin:5px 0;"><strong>Platnosť do:</strong> ' . esc_html($expiry_formatted) . '</p>';
        echo '<p style="margin:5px 0;"><strong>License Key:</strong> <code>' . esc_html($licence['license_key'] ?? 'N/A') . '</code></p>';
        echo '<p style="margin:10px 0 5px 0;">' . $message . '</p>';
        echo '<p style="margin:5px 0;"><a href="mailto:support@artefactum.sk" style="color:#f60; font-weight:bold;">✉ Kontaktovať podporu &raquo;</a></p>';
        echo '</div>';
    }
});

// ============================================================================
// DASHBOARD WIDGET - s localStorage
// ============================================================================

add_action('wp_dashboard_setup', function() {
    if (current_user_can('administrator') || current_user_can('manage_options') || current_user_can('editor') || current_user_can('author')) {
        wp_add_dashboard_widget(
            'artefactum-a',
            'Artefactum Support 🎫',
            'artefactum_render_dashboard_widget'
        );
    }
});

add_action('wp_dashboard_setup', 'artefactum_move_support_widget_to_top', 999);
function artefactum_move_support_widget_to_top() {
    global $wp_meta_boxes;
    $widget_id = 'artefactum-a';
    if (isset($wp_meta_boxes['dashboard']['normal']['core'][$widget_id])) {
        $widget = $wp_meta_boxes['dashboard']['normal']['core'][$widget_id];
        unset($wp_meta_boxes['dashboard']['normal']['core'][$widget_id]);
        $wp_meta_boxes['dashboard']['normal']['core'] = 
            array_merge([$widget_id => $widget], $wp_meta_boxes['dashboard']['normal']['core']);
    }
}

function artefactum_render_dashboard_widget() {
    $domain = artefactum_get_current_domain();
    $user_id = get_current_user_id();
    $meta_key = 'arte_dismissed_widget_' . md5($domain);
    
    $widget_id = 'arte_widget_' . md5($domain);
    $current_user = wp_get_current_user();
    $email = $current_user->user_email ?: get_option('admin_email');
    $licence = artefactum_check_licence();
    
    $status_colors = [
        'active' => '#10b981',
        'warning' => '#FF5F15',
        'grace' => '#ff0f0f',
        'expired' => '#FF0F0F',
        'error' => '#6b7280',
        'not_found' => '#9ca3af'
    ];
    
    $status = $licence['status'] ?? 'unknown';
    $status_color = $status_colors[$status] ?? '#6b7280';
    $status_label = strtoupper($status);
    
    $license_key = $licence['license_key'] ?? 'N/A';
    $expiry_date = !empty($licence['expiry_date']) 
        ? date('d.m.Y', strtotime($licence['expiry_date']))
        : '<em style="color:#10b981;">Neobmedzená ∞</em>';
    
    $support_url = 'https://my.artefactum.sk/support-ticket/?domain=' . urlencode($domain) . '&email=' . urlencode($email);
    ?>
    
    <style>
    .arte-widget-wrapper { position: relative; }    
    .button-primary:hover {
        background: #000 !important;
        border-color: #000 !important;
    }
    </style>
        
        <div style="text-align:center; padding:20px;">
            <div style="font-size:48px; margin-bottom:15px;">🎫</div>
            <h3 style="margin-bottom:15px; color:#f60; font-weight:600; font-size:1.5em;">
                Potrebujete pomoc?
            </h3>
            <p style="margin-bottom:20px; color:#666; line-height:1.8;">
                Kontaktujte <strong>Artefactum SUPPORT tím</strong><br>pre riešenie problémov s vašou webstránkou.
            </p>
            
            <div style="background:#f8f9fa; padding:15px; border-radius:8px; margin:20px 0; text-align:left; font-size:0.9em;">
                <strong style="color:#333;">Vaše údaje:</strong><br>
                <table style="width:100%; margin-top:10px; border-spacing:0;">
                    <tr>
                        <td style="padding:3px 0;"><strong>Doména:</strong></td>
                        <td style="padding:3px 0;"><code><?php echo esc_html($domain); ?></code></td>
                    </tr>
                    <tr>
                        <td style="padding:3px 0;"><strong>Email:</strong></td>
                        <td style="padding:3px 0;"><code><?php echo esc_html($email); ?></code></td>
                    </tr>
                    <tr>
                        <td style="padding:3px 0;"><strong>Partner ID:</strong></td>
                        <td style="padding:3px 0;">
                            <code style="color:#f60; font-weight:bold;"><?php echo esc_html($license_key); ?></code>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:3px 0;"><strong>Platnosť licencie:</strong></td>
                        <td style="padding:3px 0;"><?php echo $expiry_date; ?></td>
                    </tr>
                    <tr>
                        <td style="padding:3px 0;"><strong>Status:</strong></td>
                        <td style="padding:3px 0;">
                            <span style="color:<?php echo $status_color; ?>; font-weight:bold;">
                                <?php echo $status_label; ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            
            <a href="<?php echo esc_url($support_url); ?>" 
               target="_blank" 
               class="button button-primary" 
               style="background:#f60; border-color:#f60; box-shadow:none; text-shadow:none; padding:10px 20px; font-weight:bold; transition:all 0.3s;">
                Otvoriť SUPPORT TICKET
            </a>
            
            <div style="margin-top:15px;">
                <small style="color:#999;">Otvorí sa v novom okne</small>
            </div>
        </div>
    </div>
    <?php
}


// ============================================================================
// ADMIN BAR BUTTON
// ============================================================================

add_action('admin_bar_menu', function($wp_admin_bar) {
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $domain = artefactum_get_current_domain();
    $current_user = wp_get_current_user();
    $email = $current_user->user_email ?: get_option('admin_email');
    $support_url = 'https://my.artefactum.sk/support-ticket/?domain=' . urlencode($domain) . '&email=' . urlencode($email);
    
    $wp_admin_bar->add_node([
        'id' => 'artefactum-support',
        'title' => '<span class="dashicons dashicons-sos" style="font-size:20px; line-height:28px;"></span> <span class="ab-label">Artefactum Support</span>',
        'href' => $support_url,
        'meta' => [
            'target' => '_blank',
            'title' => 'Otvoriť Artefactum Support (nové okno)'
        ]
    ]);
}, 100);

add_action('admin_head', 'artefactum_admin_bar_styles');
add_action('wp_head', 'artefactum_admin_bar_styles');

function artefactum_admin_bar_styles() {
    ?>
    <style>
    #wp-admin-bar-artefactum-support {
        background-color: #f60 !important;
    }
    #wp-admin-bar-artefactum-support:hover {
        background-color: #000 !important;
    }
    #wp-admin-bar-artefactum-support a,
    #wp-admin-bar-artefactum-support .ab-label {
        color: #fff !important;
        font-weight: 500;
    }
    #wp-admin-bar-artefactum-support .dashicons {
        color: #fff !important;
    }
    @media screen and (max-width: 782px) {
        #wp-admin-bar-artefactum-support .ab-label {
            display: none;
        }
    }
    </style>
    <?php
}

// ============================================================================
// WP-CLI PODPORA
// ============================================================================

if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('artefactum', function($args, $assoc_args) {
        $subcommand = $args[0] ?? 'status';
        
        switch ($subcommand) {
            case 'refresh':
            case 'check':
                WP_CLI::line('Kontrolujem licenciu...');
                $result = artefactum_check_licence(true);
                
                WP_CLI::success('Licencia obnovená');
                WP_CLI::line('');
                WP_CLI::line('Doména: ' . artefactum_get_current_domain());
                WP_CLI::line('License Key: ' . ($result['license_key'] ?? 'N/A'));
                WP_CLI::line('Status: ' . ($result['status'] ?? 'unknown'));
                WP_CLI::line('Platná: ' . ($result['valid'] ? 'Áno' : 'Nie'));
                WP_CLI::line('Správa: ' . ($result['message'] ?? 'N/A'));
                
                if (!empty($result['expiry_date'])) {
                    WP_CLI::line('Expirácia: ' . date('d.m.Y', strtotime($result['expiry_date'])));
                }
                if (!empty($result['days_remaining'])) {
                    WP_CLI::line('Zostáva dní: ' . $result['days_remaining']);
                }
                break;
                
            case 'clear-cache':
                $domain = artefactum_get_current_domain();
                delete_transient('artefactum_licence_' . md5($domain));
                WP_CLI::success('Cache vymazaná');
                break;
                
            case 'status':
            default:
                $result = artefactum_check_licence();
                WP_CLI::line('Status: ' . ($result['status'] ?? 'unknown'));
                WP_CLI::line('Valid: ' . ($result['valid'] ? 'Yes' : 'No'));
                break;
        }
    });
}

// ============================================================================
// DEBUG INFO (iba pre WP_DEBUG)
// ============================================================================

if (defined('WP_DEBUG') && WP_DEBUG) {
    add_action('admin_footer', function() {
        $licence = artefactum_check_licence();
        $domain = artefactum_get_current_domain();
        
        echo '<!-- Artefactum Debug Info -->';
        echo '<!-- Domain: ' . esc_html($domain) . ' -->';
        echo '<!-- Status: ' . esc_html($licence['status'] ?? 'unknown') . ' -->';
        echo '<!-- Valid: ' . ($licence['valid'] ? 'Yes' : 'No') . ' -->';
        echo '<!-- License Key: ' . esc_html($licence['license_key'] ?? 'N/A') . ' -->';
    });
}

// ============================================================================
// CACHE MANAGEMENT
// ============================================================================

add_action('admin_init', function() {
    if (isset($_GET['clear_arte_cache'])) {
        $domain = artefactum_get_current_domain();
        delete_transient('artefactum_licence_' . md5($domain));
        delete_option('artefactum_last_licence_state');
        wp_redirect(admin_url());
        exit;
    }
}, 1);