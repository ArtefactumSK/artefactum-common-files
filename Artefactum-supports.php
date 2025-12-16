<?php
include_once( ARTEFACTUM_COMMON . 'artefactum-client.php' );

/**
 * Artefactum Support Button - functions.php
 */

function artefactum_add_support_button($wp_admin_bar) {
    // Iba pre prihlásených administrátorov
    if (!current_user_can('manage_options')) {
        return;
    }
    
    $site_url = get_site_url();
    $domain = parse_url($site_url, PHP_URL_HOST);
    
    $domain = preg_replace('/^www\./', '', $domain);
    
	$current_user = wp_get_current_user();
	$user_email   = $current_user->user_email;

	// Vytvorenie support URL
	$support_url = 'https://my.artefactum.sk/support-ticket/?domain=' . urlencode($domain) . '&email=' . urlencode($user_email);

    
    $wp_admin_bar->add_node(array(
        'id'    => 'artefactum-support',
        'title' => '<span class="ab-icon artefactum-support-icon"></span><span class="ab-label">Artefactum Support</span>',
        'href'  => $support_url,
        'meta'  => array(
            'target' => '_blank',
            'title'  => 'Otvoriť Artefactum Support (nové okno)'
        )
    ));
}
add_action('admin_bar_menu', 'artefactum_add_support_button', 100);

function artefactum_support_admin_styles() {
    ?>
    <style>
    #wp-admin-bar-artefactum-support .ab-icon.artefactum-support-icon:before {
        font-size: 18px;
        line-height: 1;
        margin-right: 5px;
    }
    
    #wp-admin-bar-artefactum-support .ab-icon.artefactum-support-icon:before {
        content: "";
        background: url('data:image/svg+xml;base64,PD94bWwgdmVyc2lvbj0iMS4wIiBlbmNvZGluZz0iVVRGLTgiPz48c3ZnIGlkPSJMYXllcl8xIiB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAxNjcuODEgMjAxLjkiPjxwb2x5Z29uIHBvaW50cz0iMTYuMDUgMjAxLjkgODMuOTQgMzEuMjUgMTUxLjgzIDIwMS45IDgzLjk0IDgxLjI1IDE2LjA1IDIwMS45IiBmaWxsPSIjZmZmIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiLz48cGF0aCBkPSJNODMuOTUsMGM0Ni4zNS4wNCw4My45LDM3LjY1LDgzLjg2LDg0LS4wNCw0Ni4zNS0zNy42NSw4My45LTg0LDgzLjg2LTExLjMyLDAtMjIuNTItMi4zMS0zMi45My02Ljc2bDIuNTUtNC41NGM0MC4xNCwxNi45MSw4Ni4zOS0xLjkzLDEwMy4zLTQyLjA3cy0xLjkzLTg2LjM5LTQyLjA3LTEwMy4zQzc0LjUyLTUuNzIsMjguMjcsMTMuMTIsMTEuMzYsNTMuMjZjLTEyLjY1LDMwLjAzLTUuNTMsNjQuNzUsMTcuOTIsODcuMzdsLTIuMDYsNS4xN0MtNi45NSwxMTQuNDgtOS4yNiw2MS4zOSwyMi4wNiwyNy4yMiwzNy45Niw5Ljg3LDYwLjQxLDAsODMuOTQsMGguMDFaIiBmaWxsPSIjNjA1YTVjIiBmaWxsLXJ1bGU9ImV2ZW5vZGQiLz48L3N2Zz4=') no-repeat center center;
        background-size: 16px 16px;
        display: inline-block;
        width: 16px;
        height: 16px;
        margin-right: 0px;
    }
    
		#wp-admin-bar-artefactum-support {
			background-color: #f60 !important;
		}

		/* Text */
		#wp-admin-bar-artefactum-support .ab-label {
			color: #fff !important;
			font-weight: 400;
		}

		/* Ikona SVG – defaultne oranžová */
		#wp-admin-bar-artefactum-support .ab-icon.artefactum-support-icon:before {
			filter: invert(100%) sepia(0%) saturate(0%) hue-rotate(0deg) brightness(0%) contrast(100%);
			/* toto spraví bielu výplň SVG */
		}

		/* Hover efekt */
		#wp-admin-bar-artefactum-support:hover, a.ticketbutton:hover {
			background-color: #000 !important;
		}
		

		#wp-admin-bar-artefactum-support:hover .ab-label {
			color: #fff !important;
		}

		#wp-admin-bar-artefactum-support:hover .ab-icon.artefactum-support-icon:before {
			filter: brightness(0) invert(1); /* ikona biela */
		}

    
    @media screen and (max-width: 782px) {
        #wp-admin-bar-artefactum-support .ab-label {
            display: none;
        }
        #wp-admin-bar-artefactum-support .ab-icon.artefactum-support-icon:before {
            margin-right: 0;
        }
    }
    </style>
    <?php
}
add_action('admin_head', 'artefactum_support_admin_styles');
add_action('wp_head', 'artefactum_support_admin_styles'); // Pre front-end admin bar

function artefactum_support_admin_scripts() {
    ?>
    <script>
    jQuery(document).ready(function($) {
        $('#wp-admin-bar-artefactum-support').attr('title', 'Kliknite pre otvorenie Artefactum Support systému v novom okne');
        
        $('#wp-admin-bar-artefactum-support a').on('click', function(e) {
            // Môžete pridať potvrdenie ak chcete
            // return confirm('Otvoríme Artefactum Support v novom okne. Pokračovať?');
        });
        
        <?php if (WP_DEBUG): ?>
        console.log('Artefactum Support Button loaded');
        console.log('Domain: <?php echo esc_js(preg_replace('/^www\./', '', parse_url(get_site_url(), PHP_URL_HOST))); ?>');
        <?php $current_user = wp_get_current_user(); ?>
		console.log('User email: <?php echo esc_js($current_user->user_email); ?>');

        <?php endif; ?>
    });
    </script>
    <?php
}
add_action('admin_footer', 'artefactum_support_admin_scripts');

function artefactum_support_redirect() {
    $domain = preg_replace('/^www\./', '', parse_url(get_site_url(), PHP_URL_HOST));
    $current_user = wp_get_current_user();
	$user_email   = $current_user->user_email;
    $support_url = 'https://my.artefactum.sk/support-ticket/?domain=' . urlencode($domain) . '&email=' . urlencode($user_email);
    
    ?>
    <div class="wrap">
        <h1>Presmerovanie na Artefactum Support...</h1>
        <div style="text-align: center; padding: 40px;">
            <div style="font-size: 64px; margin-bottom: 20px;">🎫</div>
            <p>Presmerováváme vás na Artefactum Support systém...</p>
            <p><strong>Doména:</strong> <?php echo esc_html($domain); ?></p>
            <p><strong>Email:</strong> <?php echo esc_html($user_email); ?></p>
            <p><a href="<?php echo esc_url($support_url); ?>" target="_blank" class="button button-primary button-large">Otvoriť Support (ak sa neotvoril automaticky)</a></p>
        </div>
    </div>
    <script>
    // Automatické presmerovanie
    setTimeout(function() {
        window.open('<?php echo esc_js($support_url); ?>', '_blank');
    }, 1000);
    </script>
    <?php
}