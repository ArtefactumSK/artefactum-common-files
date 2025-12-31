<?php
/**
 * Artefactum Licence Client Module
 * Centrálny súbor pre všetky WordPress inštalácie
 * Version: 2.0
 * Last update: 2024-10-25
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
// KONFIGURÁCIA - Zmeň tieto hodnoty
// ============================================================================

define('ARTEFACTUM_API_URL', 'https://my.artefactum.sk/wp-json/artefactum/v1/licence-check');
define('ARTEFACTUM_API_SECRET', 'ART-MH8T-R13N-2938-O9JA-7RD9');
define('ARTEFACTUM_CACHE_HOURS', 4); // Cache na 4 hodiny
define('ARTEFACTUM_GRACE_DAYS', 28); // Grace period po expirácii
define('ARTEFACTUM_WARNING_DAYS', 30); // Pre-expiry upozornenie
define('ARTEFACTUM_SUPER_ADMIN', 'artefactum'); // Super admin bez obmedzení
// ============================================================================
// CORE FUNKCIE
// ============================================================================

/**
 * Generovanie bezpečného tokenu
 */
function artefactum_generate_token($domain) {
    return hash_hmac('sha256', $domain, ARTEFACTUM_API_SECRET);
}

/**
 * Získanie domény aktuálnej stránky
 */
function artefactum_get_current_domain() {
    $domain = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'unknown';
    return strtolower(preg_replace('/^www\./', '', $domain));
}

/** * Kontrola či je aktuálny používateľ super admin */
function artefactum_is_super_admin() {
    $current_user = wp_get_current_user();
    return $current_user->user_login === ARTEFACTUM_SUPER_ADMIN;
}

/** * Kontrola licencie cez API s cachovaním */
function artefactum_check_licence($force_refresh = false) {
    $domain = artefactum_get_current_domain();
    $cache_key = 'artefactum_licence_' . md5($domain);
    
    // Vráť cache ak existuje a nie je force refresh
    if (!$force_refresh) {
        $cached = get_transient($cache_key);
        if ($cached !== false) {
            return $cached;
        }
    }
    
    // Priprav API request
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
            'User-Agent' => 'Artefactum-Client/2.0 WordPress/' . get_bloginfo('version')
        ]
    ]);
    
    // Error handling
    if (is_wp_error($response)) {
        error_log('Artefactum API Error: ' . $response->get_error_message());
        
        // Použij posledný známy stav
        $last_state = get_option('artefactum_last_licence_state', [
            'valid' => false,
            'status' => 'error',
            'message' => 'API nedostupné',
            'license_key' => null,
            'expiry_date' => null
        ]);
        
        return $last_state;
    }
    
    // Parse response
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
    // Zabezpečiť že messages je vždy pole
	if (!isset($data['messages']) || !is_array($data['messages'])) {
    $data['messages'] = [];
	}

	// BACKWARD COMPATIBILITY
	if (isset($data['custom_message']) && is_array($data['custom_message'])) {
		$data['messages'] = $data['custom_message'];
	}
    // Cache výsledok
    set_transient($cache_key, $data, ARTEFACTUM_CACHE_HOURS * HOUR_IN_SECONDS);
    update_option('artefactum_last_licence_state', $data);
    
    // Log úspešnú kontrolu
    error_log("Artefactum: Licence checked for {$domain} - Status: " . ($data['status'] ?? 'unknown'));
    
    return $data;
}

// ============================================================================
// BLOKOVANIE PRÍSTUPU PRI EXPIROVANEJ LICENCII
// ============================================================================

add_action('admin_init', function() {
    // Len pre admin area a administrátorov
    if (artefactum_is_super_admin()) {
        return;
    }
    
    // Len pre admin area
    if (!is_admin() || !current_user_can('administrator') || !current_user_can('manage_options') || !current_user_can('editor') || !current_user_can('author')) {
        return;
    }
    
    // Skip pre AJAX, cron, REST API
    if (wp_doing_ajax() || wp_doing_cron() || (defined('REST_REQUEST') && REST_REQUEST)) {
        return;
    }
    
    // Kontrola licencie
    $licence = artefactum_check_licence();
    
    // BLOKOVANIE 1: Licencia nenájdená
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
    
    // BLOKOVANIE 2: Neplatná subdoména
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
    
    // BLOKOVANIE 3: Expirovaná licencia
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

}, 5); // Priorita 5 = skôr ako väčšina iných hookov

// ============================================================================
// ADMIN UPOZORNENIA (GRACE PERIOD & PRE-EXPIRY)
// ============================================================================

add_action('admin_notices', function() {
    if (!is_admin()) {return;}
    
    $licence = artefactum_check_licence();
    
	// ZOBRAZ VŠETKY SPRÁVY Z POĽA 'messages'
	if (!empty($licence['messages']) && is_array($licence['messages'])) {
		foreach ($licence['messages'] as $msg) {
			// Skip ak nie je text správy
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

			echo '<div class="notice is-dismissible" style="background:' . $style['bg'] . '; border-left:4px solid ' . $style['border'] . ' !important; padding:12px 15px;">';
			echo '<p style="margin:0; color:' . $style['color'] . '; font-weight:500;">';
			echo $style['icon'] . ' <strong style="text-transform:uppercase">ARTEFACTUM ' . strtoupper($priority) . ':</strong></p>';
			echo '<p style="margin:8px 0 0 0; color:' . $style['color'] . ';">' . wp_kses_post($msg['message']) . '</p>';
			echo '</div>';
		}
	}
    
    // Nič nezobrazuj ak je error alebo aktívna bez upozornenia
    if (!isset($licence['status']) || $licence['status'] === 'error') {
        return;
    }
    
    if (!$licence['valid'] && $licence['status'] !== 'expired') {
        return;
    }
    
    // Zobraz upozornenie len pre grace period alebo pre-warning
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
// DASHBOARD WIDGET
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

// Posunie Artefactum Support widget na prvé miesto
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
    
    // Skontroluj či používateľ dismissol widget (platnosť 1 deň)
    $dismissed_time = get_user_meta($user_id, $meta_key, true);
    if ($dismissed_time && (time() - $dismissed_time < DAY_IN_SECONDS)) {
        ?>
        <div style="text-align:center; padding:20px;">
            <p style="color:#999;font-size:13px;margin:0;">
                Widget skrytý na 24 hodín. 
                <a href="#" class="arte-show-widget" style="color:#f60;">Zobraziť znovu</a>
            </p>
        </div>
        <script>
        jQuery(function($) {
            $('.arte-show-widget').on('click', function(e) {
                e.preventDefault();
                $.post(ajaxurl, {
                    action: 'arte_undismiss_widget',
                    nonce: '<?php echo wp_create_nonce('arte_widget_dismiss'); ?>'
                }, function() {
                    location.reload();
                });
            });
        });
        </script>
        <?php
        return;
    }
    
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
    
    $widget_id = 'arte_widget_' . md5($domain);
    ?>
    
    <style>
    .arte-widget-wrapper { position: relative; }
    .arte-widget-dismiss {
        position: absolute;
        top: 10px;
        right: 10px;
        background: rgba(0,0,0,0.1);
        border: none;
        border-radius: 3px;
        padding: 5px 10px;
        cursor: pointer;
        font-size: 18px;
        line-height: 1;
        color: #666;
        z-index: 10;
    }
    .arte-widget-dismiss:hover { background: rgba(0,0,0,0.2); }
    .button-primary:hover {
        background: #000 !important;
        border-color: #000 !important;
    }
    </style>
    
    <script>
    jQuery(function($) {
        $('#<?php echo esc_js($widget_id); ?> .arte-widget-dismiss').on('click', function(e) {
            e.preventDefault();
            
            var btn = $(this);
            btn.prop('disabled', true).text('...');
            
            $.post(ajaxurl, {
                action: 'arte_dismiss_widget',
                nonce: '<?php echo wp_create_nonce('arte_widget_dismiss'); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Chyba pri skrývaní widgetu');
                    btn.prop('disabled', false).text('✕');
                }
            });
        });
    });
    </script>
    
    <div id="<?php echo esc_attr($widget_id); ?>" class="arte-widget-wrapper">
        <button class="arte-widget-dismiss" title="Skryť na 24 hodín">✕</button>
        
        <?php 
        if (!empty($licence['messages']) && is_array($licence['messages'])): 
            foreach ($licence['messages'] as $msg):
                $priority = $msg['priority'] ?? 'info';
                $message_text = $msg['message'] ?? '';
                
                if (empty($message_text)) {
                    continue;
                }
                
                $priority_styles = [
                    'info' => ['bg' => '#E0FEFE', 'border' => '#0000FF', 'color' => '#0c4a6e', 'icon' => '💬'],
                    'warning' => ['bg' => '#FCF8F7', 'border' => '#f60', 'color' => '#f60', 'icon' => '⚠️'],
                    'critical' => ['bg' => '#fff1f1', 'border' => '#ff0f0f', 'color' => '#ff0f0f', 'icon' => '🚨']
                ];
                $style = $priority_styles[$priority] ?? $priority_styles['info'];
        ?>
        <div style="background:<?php echo $style['bg']; ?>; border-left:4px solid <?php echo $style['border']; ?>; padding:15px; margin-bottom:20px; border-radius:4px; text-align:left;">
            <strong style="color:<?php echo $style['color']; ?>;text-transform:uppercase">
                <?php echo $style['icon']; ?> ARTEFACTUM <?php echo strtoupper($priority); ?>:
            </strong>
            <p style="margin:10px 0 0 0; color:<?php echo $style['color']; ?>;">
                <?php echo wp_kses_post($message_text); ?>
            </p>
        </div>
        <?php 
            endforeach;
        endif; 
        ?>
        
        <div style="text-align:center; padding:20px;">
            <div style="font-size:48px; margin-bottom:15px;">🎫</div>
            <h3 style="margin-bottom:15px; color:#f60; font-weight:600; font-size:1.5em;">
                Potrebujete pomoc?
            </h3>
            <p style="margin-bottom:20px; color:#666; line-height:1.8;">
                Kontaktujte <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 675.59 201.9" style="height:29px;width:auto;display:inline-block;margin:-5px 6px -9px 3px"><polygon points="523.83 201.9 591.72 31.25 659.61 201.9 591.72 81.25 523.83 201.9" fill="#f60" fill-rule="evenodd"/><path d="M471.17,71.35c.03-2.84-.45-5.65-1.42-8.32-.85-2.35-2.16-4.51-3.86-6.35-1.6-1.71-3.54-3.07-5.7-4-2.19-.95-4.56-1.44-6.95-1.43-2.66-.07-5.32.27-7.88,1-1.86.53-3.57,1.5-5,2.81-1.3,1.29-2.36,2.81-3.11,4.48-.7-2.45-2.22-4.58-4.3-6.05-2-1.47-4.89-2.2-8.53-2.23-2.18-.05-4.34.4-6.32,1.31-1.78.87-3.3,2.18-4.43,3.8l-2.76-4h-8v84.27h12.14v-65c0-3,.71-5.22,2.09-6.72,1.55-1.57,3.71-2.39,5.91-2.25,2.16-.1,4.25.74,5.74,2.3,1.38,1.54,2.09,3.75,2.12,6.67v65h12.27v-65c0-3,.72-5.22,2.07-6.72,1.51-1.56,3.63-2.38,5.8-2.25,2.18-.12,4.32.7,5.86,2.25,1.4,1.5,2.11,3.75,2.14,6.72v65h12.13v-65.29Z" fill="#605a5c"/><path d="M393.66,52.32h-12.14v65.24c.06,1.74-.34,3.46-1.15,5-.68,1.25-1.72,2.26-3,2.89-1.36.66-2.85.98-4.36.95-1.53.04-3.05-.3-4.42-1-1.27-.69-2.31-1.73-3-3-.75-1.49-1.13-3.13-1.11-4.8V52.32h-12.2v63.86c-.09,4.16.65,8.29,2.18,12.16,1.26,3.1,3.42,5.74,6.2,7.59,2.83,1.77,6.11,2.67,9.45,2.59,2.21.05,4.42-.29,6.51-1,1.47-.52,2.83-1.31,4-2.34.86-.76,1.63-1.63,2.28-2.58l2.76,4h8V52.32Z" fill="#605a5c"/><path d="M332.97,126.39c-1.84.11-3.6-.73-4.67-2.23-1.14-1.94-1.68-4.18-1.54-6.43v-54.65h16v-10.76h-16v-19.45l-12.1,4.14v15.31h-7.72v10.76h7.72v54.65c0,6.78,1.56,11.94,4.69,15.47,3.13,3.53,7.86,5.32,14.21,5.35,1.66-.01,3.32-.25,4.92-.71,1.63-.47,3.18-1.14,4.64-2,1.42-.83,2.71-1.88,3.82-3.1l-8.42-9.1c-.5.86-1.24,1.56-2.13,2-1.06.53-2.24.79-3.42.75Z" fill="#605a5c"/><path d="M279.45,62.66c1.54-.03,3.06.31,4.44,1,1.33.67,2.43,1.71,3.17,3,.84,1.53,1.27,3.25,1.22,5v3.58h12.13v-3.61c.06-3.22-.5-6.42-1.65-9.43-.98-2.44-2.54-4.6-4.53-6.32-1.96-1.63-4.23-2.84-6.67-3.56-2.64-.76-5.37-1.13-8.11-1.11-2.8-.04-5.59.32-8.28,1.08-2.44.67-4.71,1.86-6.64,3.5-1.98,1.7-3.51,3.86-4.44,6.3-1.12,3.05-1.67,6.29-1.61,9.54v45.93c-.03,3.07.52,6.13,1.61,9,.98,2.48,2.5,4.72,4.44,6.55,1.91,1.78,4.17,3.14,6.64,4,2.66.94,5.46,1.41,8.28,1.38,2.76.02,5.49-.41,8.11-1.28,2.46-.82,4.73-2.14,6.67-3.87,1.98-1.81,3.53-4.05,4.53-6.55,1.14-2.95,1.7-6.1,1.65-9.26v-5.38l-12.13,4v1.38c.04,1.7-.38,3.39-1.22,4.87-.74,1.28-1.84,2.3-3.17,2.94-2.84,1.39-6.16,1.39-9,0-1.31-.68-2.39-1.72-3.12-3-.82-1.47-1.23-3.12-1.2-4.8v-45.91c-.05-1.74.37-3.47,1.2-5,.72-1.29,1.8-2.33,3.12-3,1.42-.69,2.98-1.02,4.56-.97Z" fill="#605a5c"/><path d="M249.93,71.63c.09-3.81-.74-7.58-2.43-11-1.48-2.94-3.8-5.38-6.67-7-3.09-1.66-6.56-2.5-10.07-2.44-3.12-.07-6.22.42-9.17,1.45-2.23.85-4.27,2.14-6,3.79-1.57,1.58-2.95,3.35-4.11,5.25l10.18,6.64c.54-1.79,1.7-3.33,3.28-4.33,1.73-.96,3.7-1.42,5.68-1.33,1.44-.06,2.87.34,4.07,1.15,1.08.79,1.9,1.89,2.35,3.15.52,1.5.78,3.08.75,4.67v4.1c-1.59,1.66-3.33,3.17-5.19,4.51l-6.39,4.67c-2.31,1.67-4.53,3.47-6.65,5.38-2.22,1.99-4.21,4.23-5.93,6.66-1.85,2.61-3.29,5.49-4.27,8.53-1.11,3.54-1.67,7.23-1.63,10.94-.06,3.43.48,6.85,1.6,10.1.89,2.55,2.33,4.88,4.21,6.82,1.66,1.71,3.67,3.04,5.89,3.91,4.08,1.55,8.57,1.65,12.71.28,1.56-.54,3.01-1.37,4.27-2.44,1.1-.96,2.03-2.1,2.76-3.36l2.76,4.83h8v-65,.07ZM238.07,115.35c.02,1.86-.32,3.7-1,5.43-.63,1.59-1.7,2.98-3.08,4-1.56,1.07-3.42,1.62-5.31,1.57-2.58.14-5.09-.92-6.78-2.88-1.54-1.91-2.32-4.62-2.32-8.16,0-2.35.44-4.69,1.33-6.87.85-2.08,2.01-4.03,3.43-5.77,1.37-1.72,2.91-3.31,4.59-4.74,1.56-1.32,3.19-2.56,4.88-3.7,1.61-1.08,3-2,4.25-2.78v23.9Z" fill="#605a5c"/><path d="M201.38,24.18c-2.77-.02-5.53.45-8.14,1.4-2.49.9-4.77,2.28-6.71,4.07-1.97,1.84-3.53,4.08-4.57,6.57-1.15,2.83-1.72,5.86-1.68,8.92v7.18h-5.38v10.76h5.38v73.51h12.13V63.08h9v-10.76h-9v-7.18c-.02-1.7.41-3.38,1.25-4.87.76-1.29,1.88-2.33,3.22-3,1.41-.68,2.96-1.02,4.53-1,.92-.02,1.83.12,2.71.39.77.24,1.49.62,2.12,1.12l4.83-11.72c-1.54-.59-3.14-1.04-4.77-1.33-1.62-.35-3.27-.54-4.92-.55Z" fill="#605a5c"/><path d="M591.73,0c46.35.04,83.9,37.65,83.86,84-.04,46.35-37.65,83.9-84,83.86-11.32,0-22.52-2.31-32.93-6.76l2.55-4.54c40.14,16.91,86.38-1.93,103.29-42.07,16.91-40.14-1.93-86.38-42.07-103.29s-86.38,1.93-103.29,42.07c-12.65,30.02-5.53,64.73,17.92,87.36l-2.06,5.17c-34.17-31.32-36.48-84.41-5.16-118.58C545.74,9.87,568.19,0,591.72,0h.01Z" fill="#605a5c" fill-rule="evenodd"/><path d="M59.36,52.32h-7.91v10.63c2.52-4.8,5.21-8.42,7.91-10.63Z" fill="#605a5c"/><path d="M76.66,51.81c-1.36-.42-2.78-.62-4.21-.6-.44,0-.86,0-1.27.08-.42.04-.83.11-1.24.2h-.18c-2.9.72-5.49,2.81-7.51,6.16-3.9,6.43-7,16-10.76,25.34v53.55h12.14v-63.67c-.01-1.71.38-3.39,1.15-4.92.69-1.36,1.73-2.52,3-3.36,1.18-.79,2.57-1.23,4-1.24.76-.02,1.53.06,2.27.23.56.14,1.06.44,1.45.87l4.14-10.89c-.84-.81-1.86-1.41-2.98-1.75Z" fill="#605a5c"/><path d="M16,111.73l6.35-47.18,4.06,32.06c1.65,12.44,6.82,6.54,11.3-1.76L27.71,28.59h-11.16L0,136.59h13l1.66-13.38c5.22.17,10.37-1.22,14.79-4l.52,4,1.8,13.38h12.27l-4.86-32.25c-5.66,5.88-12.98,9.18-23.18,7.39Z" fill="#605a5c"/><path d="M86.66,63.08h4v6.55c3.77,6.42,8,14.26,12.14,22.62v-29.17h16v-10.76h-16v-19.45l-12.14,4.14v15.31h-7.72v5.12c1.04,1.52,2.31,3.42,3.72,5.64Z" fill="#605a5c"/><path d="M112.66,125.45l-.3.18c-1.05.53-2.21.79-3.38.76-1.84.11-3.6-.73-4.67-2.23-1.15-1.94-1.69-4.18-1.55-6.43v-12.82c-3.59-7.13-7.63-14.02-12.1-20.64v33.46c0,6.78,1.56,11.94,4.69,15.47s7.86,5.32,14.21,5.35c1.66-.01,3.32-.25,4.92-.71,1.63-.47,3.18-1.14,4.64-2,1.42-.83,2.71-1.88,3.82-3.1h0c-4.82-.08-7.57-2.55-10.28-7.29Z" fill="#605a5c"/><path d="M154.9,117.56c.05,1.66-.33,3.31-1.08,4.8-.65,1.27-1.66,2.31-2.9,3-1.32.7-2.8,1.04-4.3,1-2.26.09-4.47-.74-6.11-2.3-1.61-1.78-2.43-4.13-2.3-6.53v-3.62c-3.77,5.15-7.47,9.79-10.29,13.17.25.6.54,1.19.86,1.76,1.71,3.04,4.24,5.52,7.31,7.17,3.26,1.69,6.89,2.55,10.57,2.51,2.76.02,5.5-.46,8.09-1.42,2.44-.89,4.66-2.28,6.53-4.09,1.9-1.86,3.38-4.10,4.35-6.58,1.07-2.83,1.61-5.84,1.58-8.87v-5.38l-12.27,4-.04,1.38Z" fill="#605a5c"/><path d="M138.21,98.43v-26.8c-.05-1.74.36-3.47,1.19-5,.72-1.29,1.81-2.33,3.13-3,1.4-.69,2.95-1.04,4.51-1,1.54-.04,3.07.33,4.43,1.06,1.19.68,2.16,1.69,2.8,2.91,0,0,.07,0,.08.09.05.09.09.19.13.29.06.09.11.19.16.29.49,1.03.83,2.13,1,3.26.18.96.27,1.93.27,2.9v3.02c0,.49,0,1-.08,1.44-.08,1.11-.19,2.16-.34,3.16,0,.17,0,.33-.07.5s-.09.52-.14.79v.11h0c-1.16,8-8.8,20.21-16.35,30.61,3.93-2,7.46-3.95,10.54-5.8,2.98-1.77,5.79-3.82,8.39-6.12,2.3-2.02,4.26-4.41,5.79-7.06,1.6-2.8,2.74-5.84,3.38-9,.76-3.92,1.13-7.9,1.08-11.89.04-3.23-.52-6.43-1.65-9.45-1.01-2.6-2.56-4.95-4.53-6.92-1.92-1.85-4.18-3.30-6.67-4.25-2.59-.97-5.34-1.47-8.11-1.45-2.82-.03-5.62.42-8.28,1.34-2.46.82-4.72,2.15-6.64,3.9-1.94,1.78-3.45,3.96-4.44,6.40-1.10,2.79-1.65,5.78-1.61,8.78v46.88c6.48-2.53,12.03-11.49,12.03-19.99Z" fill="#605a5c"/></svg><strong>SUPPORT tím</strong><br>pre riešenie problémov s vašou webstránkou.
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

/* function artefactum_render_dashboard_widget() {
    $domain = artefactum_get_current_domain();
    $current_user = wp_get_current_user();
    $email = $current_user->user_email ?: get_option('admin_email');
    $licence = artefactum_check_licence();
    
    // Status farby
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
	<?php 
    if (!empty($licence['messages']) && is_array($licence['messages'])): 
        foreach ($licence['messages'] as $msg):
            $priority = $msg['priority'] ?? 'info';
            $message_text = $msg['message'] ?? '';
            
            if (empty($message_text)) {
                continue;
            }
            
            $priority_styles = [
                'info' => ['bg' => '#E0FEFE', 'border' => '#0000FF', 'color' => '#0c4a6e', 'icon' => '💬'],
                'warning' => ['bg' => '#FCF8F7', 'border' => '#f60', 'color' => '#f60', 'icon' => '⚠️'],
                'critical' => ['bg' => '#fff1f1', 'border' => '#ff0f0f', 'color' => '#ff0f0f', 'icon' => '🚨']
            ];
            $style = $priority_styles[$priority] ?? $priority_styles['info'];
    ?>
	<div style="background:<?php echo $style['bg']; ?>; border-left:4px solid <?php echo $style['border']; ?>; padding:15px; margin-bottom:20px; border-radius:4px; text-align:left;">
        <strong style="color:<?php echo $style['color']; ?>;text-transform:uppercase">
            <?php echo $style['icon']; ?> ARTEFACTUM <?php echo strtoupper($priority); ?>:
        </strong>
        <p style="margin:10px 0 0 0; color:<?php echo $style['color']; ?>;">
            <?php echo wp_kses_post($message_text); ?>
        </p>
    </div>
    <?php 
        endforeach;
    endif; 
    ?>
    <div style="text-align:center; padding:20px;">
        <div style="font-size:48px; margin-bottom:15px;">🎫</div>
        <h3 style="margin-bottom:15px; color:#f60; font-weight:600; font-size:1.5em;">
            Potrebujete pomoc?
        </h3>
        <p style="margin-bottom:20px; color:#666; line-height:1.8;">
            Kontaktujte <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 675.59 201.9" style="height:29px;width:auto;display:inline-block;margin:-5px 6px -9px 3px"><polygon points="523.83 201.9 591.72 31.25 659.61 201.9 591.72 81.25 523.83 201.9" fill="#f60" fill-rule="evenodd"/><path d="M471.17,71.35c.03-2.84-.45-5.65-1.42-8.32-.85-2.35-2.16-4.51-3.86-6.35-1.6-1.71-3.54-3.07-5.7-4-2.19-.95-4.56-1.44-6.95-1.43-2.66-.07-5.32.27-7.88,1-1.86.53-3.57,1.5-5,2.81-1.3,1.29-2.36,2.81-3.11,4.48-.7-2.45-2.22-4.58-4.3-6.05-2-1.47-4.89-2.2-8.53-2.23-2.18-.05-4.34.4-6.32,1.31-1.78.87-3.3,2.18-4.43,3.8l-2.76-4h-8v84.27h12.14v-65c0-3,.71-5.22,2.09-6.72,1.55-1.57,3.71-2.39,5.91-2.25,2.16-.1,4.25.74,5.74,2.3,1.38,1.54,2.09,3.75,2.12,6.67v65h12.27v-65c0-3,.72-5.22,2.07-6.72,1.51-1.56,3.63-2.38,5.8-2.25,2.18-.12,4.32.7,5.86,2.25,1.4,1.5,2.11,3.75,2.14,6.72v65h12.13v-65.29Z" fill="#605a5c"/><path d="M393.66,52.32h-12.14v65.24c.06,1.74-.34,3.46-1.15,5-.68,1.25-1.72,2.26-3,2.89-1.36.66-2.85.98-4.36.95-1.53.04-3.05-.3-4.42-1-1.27-.69-2.31-1.73-3-3-.75-1.49-1.13-3.13-1.11-4.8V52.32h-12.2v63.86c-.09,4.16.65,8.29,2.18,12.16,1.26,3.1,3.42,5.74,6.2,7.59,2.83,1.77,6.11,2.67,9.45,2.59,2.21.05,4.42-.29,6.51-1,1.47-.52,2.83-1.31,4-2.34.86-.76,1.63-1.63,2.28-2.58l2.76,4h8V52.32Z" fill="#605a5c"/><path d="M332.97,126.39c-1.84.11-3.6-.73-4.67-2.23-1.14-1.94-1.68-4.18-1.54-6.43v-54.65h16v-10.76h-16v-19.45l-12.1,4.14v15.31h-7.72v10.76h7.72v54.65c0,6.78,1.56,11.94,4.69,15.47,3.13,3.53,7.86,5.32,14.21,5.35,1.66-.01,3.32-.25,4.92-.71,1.63-.47,3.18-1.14,4.64-2,1.42-.83,2.71-1.88,3.82-3.1l-8.42-9.1c-.5.86-1.24,1.56-2.13,2-1.06.53-2.24.79-3.42.75Z" fill="#605a5c"/><path d="M279.45,62.66c1.54-.03,3.06.31,4.44,1,1.33.67,2.43,1.71,3.17,3,.84,1.53,1.27,3.25,1.22,5v3.58h12.13v-3.61c.06-3.22-.5-6.42-1.65-9.43-.98-2.44-2.54-4.6-4.53-6.32-1.96-1.63-4.23-2.84-6.67-3.56-2.64-.76-5.37-1.13-8.11-1.11-2.8-.04-5.59.32-8.28,1.08-2.44.67-4.71,1.86-6.64,3.5-1.98,1.7-3.51,3.86-4.44,6.3-1.12,3.05-1.67,6.29-1.61,9.54v45.93c-.03,3.07.52,6.13,1.61,9,.98,2.48,2.5,4.72,4.44,6.55,1.91,1.78,4.17,3.14,6.64,4,2.66.94,5.46,1.41,8.28,1.38,2.76.02,5.49-.41,8.11-1.28,2.46-.82,4.73-2.14,6.67-3.87,1.98-1.81,3.53-4.05,4.53-6.55,1.14-2.95,1.7-6.1,1.65-9.26v-5.38l-12.13,4v1.38c.04,1.7-.38,3.39-1.22,4.87-.74,1.28-1.84,2.3-3.17,2.94-2.84,1.39-6.16,1.39-9,0-1.31-.68-2.39-1.72-3.12-3-.82-1.47-1.23-3.12-1.2-4.8v-45.91c-.05-1.74.37-3.47,1.2-5,.72-1.29,1.8-2.33,3.12-3,1.42-.69,2.98-1.02,4.56-.97Z" fill="#605a5c"/><path d="M249.93,71.63c.09-3.81-.74-7.58-2.43-11-1.48-2.94-3.8-5.38-6.67-7-3.09-1.66-6.56-2.5-10.07-2.44-3.12-.07-6.22.42-9.17,1.45-2.23.85-4.27,2.14-6,3.79-1.57,1.58-2.95,3.35-4.11,5.25l10.18,6.64c.54-1.79,1.7-3.33,3.28-4.33,1.73-.96,3.7-1.42,5.68-1.33,1.44-.06,2.87.34,4.07,1.15,1.08.79,1.9,1.89,2.35,3.15.52,1.5.78,3.08.75,4.67v4.1c-1.59,1.66-3.33,3.17-5.19,4.51l-6.39,4.67c-2.31,1.67-4.53,3.47-6.65,5.38-2.22,1.99-4.21,4.23-5.93,6.66-1.85,2.61-3.29,5.49-4.27,8.53-1.11,3.54-1.67,7.23-1.63,10.94-.06,3.43.48,6.85,1.6,10.1.89,2.55,2.33,4.88,4.21,6.82,1.66,1.71,3.67,3.04,5.89,3.91,4.08,1.55,8.57,1.65,12.71.28,1.56-.54,3.01-1.37,4.27-2.44,1.1-.96,2.03-2.1,2.76-3.36l2.76,4.83h8v-65,.07ZM238.07,115.35c.02,1.86-.32,3.7-1,5.43-.63,1.59-1.7,2.98-3.08,4-1.56,1.07-3.42,1.62-5.31,1.57-2.58.14-5.09-.92-6.78-2.88-1.54-1.91-2.32-4.62-2.32-8.16,0-2.35.44-4.69,1.33-6.87.85-2.08,2.01-4.03,3.43-5.77,1.37-1.72,2.91-3.31,4.59-4.74,1.56-1.32,3.19-2.56,4.88-3.7,1.61-1.08,3-2,4.25-2.78v23.9Z" fill="#605a5c"/><path d="M201.38,24.18c-2.77-.02-5.53.45-8.14,1.4-2.49.9-4.77,2.28-6.71,4.07-1.97,1.84-3.53,4.08-4.57,6.57-1.15,2.83-1.72,5.86-1.68,8.92v7.18h-5.38v10.76h5.38v73.51h12.13V63.08h9v-10.76h-9v-7.18c-.02-1.7.41-3.38,1.25-4.87.76-1.29,1.88-2.33,3.22-3,1.41-.68,2.96-1.02,4.53-1,.92-.02,1.83.12,2.71.39.77.24,1.49.62,2.12,1.12l4.83-11.72c-1.54-.59-3.14-1.04-4.77-1.33-1.62-.35-3.27-.54-4.92-.55Z" fill="#605a5c"/><path d="M591.73,0c46.35.04,83.9,37.65,83.86,84-.04,46.35-37.65,83.9-84,83.86-11.32,0-22.52-2.31-32.93-6.76l2.55-4.54c40.14,16.91,86.38-1.93,103.29-42.07,16.91-40.14-1.93-86.38-42.07-103.29s-86.38,1.93-103.29,42.07c-12.65,30.02-5.53,64.73,17.92,87.36l-2.06,5.17c-34.17-31.32-36.48-84.41-5.16-118.58C545.74,9.87,568.19,0,591.72,0h.01Z" fill="#605a5c" fill-rule="evenodd"/><path d="M59.36,52.32h-7.91v10.63c2.52-4.8,5.21-8.42,7.91-10.63Z" fill="#605a5c"/><path d="M76.66,51.81c-1.36-.42-2.78-.62-4.21-.6-.44,0-.86,0-1.27.08-.42.04-.83.11-1.24.2h-.18c-2.9.72-5.49,2.81-7.51,6.16-3.9,6.43-7,16-10.76,25.34v53.55h12.14v-63.67c-.01-1.71.38-3.39,1.15-4.92.69-1.36,1.73-2.52,3-3.36,1.18-.79,2.57-1.23,4-1.24.76-.02,1.53.06,2.27.23.56.14,1.06.44,1.45.87l4.14-10.89c-.84-.81-1.86-1.41-2.98-1.75Z" fill="#605a5c"/><path d="M16,111.73l6.35-47.18,4.06,32.06c1.65,12.44,6.82,6.54,11.3-1.76L27.71,28.59h-11.16L0,136.59h13l1.66-13.38c5.22.17,10.37-1.22,14.79-4l.52,4,1.8,13.38h12.27l-4.86-32.25c-5.66,5.88-12.98,9.18-23.18,7.39Z" fill="#605a5c"/><path d="M86.66,63.08h4v6.55c3.77,6.42,8,14.26,12.14,22.62v-29.17h16v-10.76h-16v-19.45l-12.14,4.14v15.31h-7.72v5.12c1.04,1.52,2.31,3.42,3.72,5.64Z" fill="#605a5c"/><path d="M112.66,125.45l-.3.18c-1.05.53-2.21.79-3.38.76-1.84.11-3.6-.73-4.67-2.23-1.15-1.94-1.69-4.18-1.55-6.43v-12.82c-3.59-7.13-7.63-14.02-12.1-20.64v33.46c0,6.78,1.56,11.94,4.69,15.47s7.86,5.32,14.21,5.35c1.66-.01,3.32-.25,4.92-.71,1.63-.47,3.18-1.14,4.64-2,1.42-.83,2.71-1.88,3.82-3.1h0c-4.82-.08-7.57-2.55-10.28-7.29Z" fill="#605a5c"/><path d="M154.9,117.56c.05,1.66-.33,3.31-1.08,4.8-.65,1.27-1.66,2.31-2.9,3-1.32.7-2.8,1.04-4.3,1-2.26.09-4.47-.74-6.11-2.3-1.61-1.78-2.43-4.13-2.3-6.53v-3.62c-3.77,5.15-7.47,9.79-10.29,13.17.25.6.54,1.19.86,1.76,1.71,3.04,4.24,5.52,7.31,7.17,3.26,1.69,6.89,2.55,10.57,2.51,2.76.02,5.5-.46,8.09-1.42,2.44-.89,4.66-2.28,6.53-4.09,1.9-1.86,3.38-4.1,4.35-6.58,1.07-2.83,1.61-5.84,1.58-8.87v-5.38l-12.27,4-.04,1.38Z" fill="#605a5c"/><path d="M138.21,98.43v-26.8c-.05-1.74.36-3.47,1.19-5,.72-1.29,1.81-2.33,3.13-3,1.4-.69,2.95-1.04,4.51-1,1.54-.04,3.07.33,4.43,1.06,1.19.68,2.16,1.69,2.8,2.91,0,0,.07,0,.08.09.05.09.09.19.13.29.06.09.11.19.16.29.49,1.03.83,2.13,1,3.26.18.96.27,1.93.27,2.9v3.02c0,.49,0,1-.08,1.44-.08,1.11-.19,2.16-.34,3.16,0,.17,0,.33-.07.5s-.09.52-.14.79v.11h0c-1.16,8-8.8,20.21-16.35,30.61,3.93-2,7.46-3.95,10.54-5.8,2.98-1.77,5.79-3.82,8.39-6.12,2.3-2.02,4.26-4.41,5.79-7.06,1.6-2.8,2.74-5.84,3.38-9,.76-3.92,1.13-7.9,1.08-11.89.04-3.23-.52-6.43-1.65-9.45-1.01-2.6-2.56-4.95-4.53-6.92-1.92-1.85-4.18-3.3-6.67-4.25-2.59-.97-5.34-1.47-8.11-1.45-2.82-.03-5.62.42-8.28,1.34-2.46.82-4.72,2.15-6.64,3.9-1.94,1.78-3.45,3.96-4.44,6.4-1.1,2.79-1.65,5.78-1.61,8.78v46.88c6.48-2.53,12.03-11.49,12.03-19.99Z" fill="#605a5c"/></svg><strong>SUPPORT tím</strong><br>pre riešenie problémov s vašou webstránkou.
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
    
    <style>
    .button-primary:hover {
        background: #000 !important;
        border-color: #000 !important;
    }
    </style>
    <?php
} */

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

// Admin bar styling
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
        //if (!current_user_can('manage_options')) return;
        
        $licence = artefactum_check_licence();
        $domain = artefactum_get_current_domain();
        
        echo '<!-- Artefactum Debug Info -->';
        echo '<!-- Domain: ' . esc_html($domain) . ' -->';
        echo '<!-- Status: ' . esc_html($licence['status'] ?? 'unknown') . ' -->';
        echo '<!-- Valid: ' . ($licence['valid'] ? 'Yes' : 'No') . ' -->';
        echo '<!-- License Key: ' . esc_html($licence['license_key'] ?? 'N/A') . ' -->';
    });
}

// DOČASNE - Vymazať cache
add_action('admin_init', function() {
    if (isset($_GET['clear_arte_cache'])) {
        $domain = artefactum_get_current_domain();
        delete_transient('artefactum_licence_' . md5($domain));
        delete_option('artefactum_last_licence_state');
        wp_redirect(admin_url());
        exit;
    }
}, 1);



// DOČASNE - Vymazať cache  - /wp-admin/?clear_arte_cache
add_action('admin_init', function() {
    if (isset($_GET['clear_arte_cache'])) {
        $domain = artefactum_get_current_domain();
        delete_transient('artefactum_licence_' . md5($domain));
        delete_option('artefactum_last_licence_state');
        wp_redirect(admin_url());
        exit;
    }
}, 1);