<?php
/**
 * Plugin Name: Global Helper Functions
 * Description: A collection of helper functions that all plugins/themes can use.
 * Version:     1.0
 * Author:      Osman Senhanli
 */

// In functions.php or an MU-plugin:

/**
 * Temporary diagnostic: force cacheable headers on public pages.
 * Remove this once we identify the real source of "private" headers.
 */
add_action('send_headers', function () {

  // Don't interfere with wp-admin/admin-ajax.
  if (is_admin()) return;

  // Don't cache Woo sensitive pages.
  if (function_exists('is_cart') && is_cart()) return;
  if (function_exists('is_checkout') && is_checkout()) return;
  if (function_exists('is_account_page') && is_account_page()) return;

  // Remove any existing cache-control coming from elsewhere.
  header_remove('Cache-Control');
  header_remove('Pragma');
  header_remove('Expires');
  header_remove('cf-edge-cache');

  // Allow proxy caching (Varnish/Cloudflare). Browser can still revalidate.
  header('Cache-Control: public, max-age=0, s-maxage=3600');
}, 0);

/**************** PAGE REDIRECTS ****************************************/

// Redirect product category pages to the /books page
add_action( 'template_redirect', function() {
    if ( is_tax( 'product_cat' ) ) {
        wp_redirect( home_url( '/books' ), 301 );
        exit;
    }
});

/*************************************************************************
 * ******************   MAUTIC TRACKING  *********************************
 * **********************************************************************/
/**
 * Plugin Name: iFun Mautic Tracker
 * Description: Injects the Mautic tracking script on every public page.
 */

if (!defined('ABSPATH')) exit;

// === CONFIG: set your Mautic base URL (no trailing slash) ===
if (!defined('IFUN_MAUTIC_BASE')) {
  define('IFUN_MAUTIC_BASE', 'https://ifunlearning.com.au/mautic');
}

// Optional: don’t track logged-in admins/editors
function ifun_should_track_mautic(){
  if (is_user_logged_in() && current_user_can('edit_pages')) return false;
  return true;
}

// Optional: require a consent cookie (rename to your cookie if needed)
// return true to always track
/*
function ifun_has_consent(){
  // Example: only fire if "ifun_consent=1"
  return (isset($_COOKIE['ifun_consent']) && $_COOKIE['ifun_consent'] === '1');
}
*/

add_action('wp_head', function () {
  if (!ifun_should_track_mautic()) return;
  // If you don’t use consent gating, change to: if (!ifun_has_consent()) return;
  // Or simply comment the next line out.
//  if (!ifun_has_consent()) return;

  $base = esc_url(IFUN_MAUTIC_BASE);
  ?>
  <!-- Mautic Tracking -->
  <script>
  (function(w,d,t,u,n,a,m){w['MauticTrackingObject']=n;w[n]=w[n]||function(){
    (w[n].q=w[n].q||[]).push(arguments)};a=d.createElement(t),m=d.getElementsByTagName(t)[0];
    a.async=1;a.src=u;m.parentNode.insertBefore(a,m)
  })(window,document,'script','<?php echo $base; ?>/mtc.js','mt');
  mt('send','pageview');
  </script>
  <noscript>
    <img src="<?php echo $base; ?>/mtracking.gif" style="display:none;" alt="" />
  </noscript>
  <!-- /Mautic Tracking -->
  <?php
}, 99);



/*************************************************************************
 * ******************   GLOBAL FUNCTIONS *********************************
 * **********************************************************************/

if ( ! function_exists( 'get_book_type' ) ) {
    function get_book_type() {
        // default
        $type = 'us-book';

        $cookie_country = $_COOKIE['ifun_geo_country'] ?? '';
        if ($cookie_country === 'AU') {
            return 'au-book';
        }
        if ($cookie_country === 'US') {
            return 'us-book';
        }

        $cf_country = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
        if ($cf_country === 'AU') {
            return 'au-book';
        }
        if ($cf_country === 'US') {
            return 'us-book';
        }

        if ( function_exists( 'geoip_detect2_get_info_from_current_ip' ) ) {
            $info = geoip_detect2_get_info_from_current_ip();
            if ( ! empty( $info->country->isoCode ) && 'AU' === $info->country->isoCode ) {
                $type = 'au-book';
            }
        }

        return $type;
    }
}

// Simple GeoIP-based WooCommerce currency switch (AU -> AUD, else USD).
add_filter('woocommerce_currency', function($currency){
    if (is_admin() && !(defined('DOING_AJAX') && DOING_AJAX)) {
        return $currency;
    }

    $book_type = function_exists('get_book_type') ? get_book_type() : '';
    if ($book_type === 'au-book') {
        return 'AUD';
    }
    if ($book_type === 'us-book') {
        return 'USD';
    }

    return $currency;
}, 1, 1);

// Cloudflare Geo cookie helper: read CF-IPCountry and set ifun_geo_country cookie.
add_action('init', function(){
    if (is_admin()) {
        return;
    }

    $header = $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '';
    if (!$header || $header === 'XX') {
        return;
    }

    $country = strtoupper(trim($header));
    if (!preg_match('/^[A-Z]{2}$/', $country)) {
        return;
    }

    $cookie_name = 'ifun_geo_country';
    $current = $_COOKIE[$cookie_name] ?? '';
    if ($current === $country) {
        return;
    }

    $ttl = 7 * DAY_IN_SECONDS;
    $secure = is_ssl();
    $http_only = false;
    $host = wp_parse_url(home_url(), PHP_URL_HOST);
    if ($host && strpos($host, 'www.') === 0) {
        $host = substr($host, 4);
    }
    $cookie_domain = $host ? '.' . $host : COOKIE_DOMAIN;

    setcookie($cookie_name, $country, time() + $ttl, COOKIEPATH, $cookie_domain, $secure, $http_only);
    $_COOKIE[$cookie_name] = $country;
}, 1);

// Harden pricing panels + /books popups against stripped inline CSS/JS.
add_action('wp_enqueue_scripts', function(){
        if (is_admin()) {
                return;
        }

        global $post;
        if (!$post) {
                return;
        }

        $content = $post->post_content ?? '';
        $has_pricing = has_shortcode($content, 'license_pricing_table')
                || strpos($content, 'js-pricing-toggle') !== false;
        $has_popups = has_shortcode($content, 'ifun_compact_catalogue')
                || has_shortcode($content, 'ifun_quiz_explain')
                || strpos($content, 'id="order-guided"') !== false;

        if (!$has_pricing && !$has_popups) {
                return;
        }

        wp_register_style('ifun-page-fixes', false);
        wp_enqueue_style('ifun-page-fixes');

        $css = "";
        if ($has_pricing) {
                $css .= "body.ifun-js-pricing .pricing-panel.js-pricing-panel{display:none;}\n";
                $css .= "body.ifun-js-pricing .pricing-panel.js-pricing-panel.is-open{display:block;}\n";
        }
        if ($has_popups) {
                $css .= "body.ifun-js-popups #order-guided .popup{display:none;}\n";
                $css .= "body.ifun-js-popups #order-guided .popup.active{display:flex;}\n";
        }
        if ($css) {
                wp_add_inline_style('ifun-page-fixes', $css);
        }

        wp_register_script('ifun-page-fixes', '', [], null, true);
        wp_enqueue_script('ifun-page-fixes');

        $js = <<<'JS'
(function(){
    if (window.IFUN_PAGE_FIXES && window.IFUN_PAGE_FIXES.loaded) return;
    window.IFUN_PAGE_FIXES = window.IFUN_PAGE_FIXES || {};
    window.IFUN_PAGE_FIXES.loaded = true;

    function initPricing(){
        if (!document.querySelector('.js-pricing-toggle') || !document.querySelector('.js-pricing-panel')) return;
        document.body.classList.add('ifun-js-pricing');

        document.querySelectorAll('.js-pricing-panel').forEach(function(panel){
            panel.hidden = true;
            panel.classList.remove('is-open');
        });

        document.addEventListener('click', function(e){
            var btn = e.target.closest('.js-pricing-toggle');
            if (!btn) return;

            e.preventDefault();

            var scope = btn.closest('.pricing-block') || btn.closest('section, .container, .card') || document;
            var panel = scope.querySelector('.js-pricing-panel');
            if (!panel) {
                var next = scope.nextElementSibling;
                while (next && !(next.classList && next.classList.contains('js-pricing-panel'))) {
                    next = next.nextElementSibling;
                }
                panel = next || null;
            }
            if (!panel) return;

            var isOpen = panel.classList.contains('is-open');
            panel.classList.toggle('is-open', !isOpen);
            panel.hidden = isOpen;
            btn.setAttribute('aria-expanded', String(!isOpen));

            if (!isOpen) {
                var r = panel.getBoundingClientRect();
                var vh = window.innerHeight || document.documentElement.clientHeight;
                var mostlyOut = r.top < 0 || r.bottom > vh;
                if (mostlyOut && panel.scrollIntoView) {
                    panel.scrollIntoView({ behavior:'smooth', block:'nearest' });
                }
            }
        }, { passive:false });
    }

    function initPopups(){
        var root = document.getElementById('order-guided');
        if (!root) return;
        document.body.classList.add('ifun-js-popups');

        window.IFUN_CARDS = window.IFUN_CARDS || {};
        IFUN_CARDS.ajaxUrl = IFUN_CARDS.ajaxUrl || '/wp-admin/admin-ajax.php';
        IFUN_CARDS.nonce = IFUN_CARDS.nonce || '';

        function fetchCards(el){
            var raw = el.getAttribute('data-shortcode') || '';
            var sc = raw.replace(/(\bajax\s*=\s*")(?:true|1)(")/i, '$1false$2');
            var pg = el.getAttribute('data-page') || '1';
            var fd = new FormData();
            fd.append('action','ifun_product_cards');
            fd.append('shortcode', sc);
            fd.append('page', pg);
            if (IFUN_CARDS.nonce) fd.append('_ajax_nonce', IFUN_CARDS.nonce);

            el.setAttribute('aria-busy','true');
            el.innerHTML = '<div class="ifun-cards-loading" style="padding:24px;text-align:center">Loading...</div>';

            var url = IFUN_CARDS.ajaxUrl || '/wp-admin/admin-ajax.php';
            fetch(url, { method:'POST', credentials:'same-origin', body: fd })
                .then(function(r){ return r.text().then(function(text){ return { ok:r.ok, status:r.status, text:text }; }); })
                .then(function(res){
                    if (!res.ok) throw new Error('HTTP ' + res.status + (res.text ? ' - ' + res.text : ''));
                    if (!res.text || res.text === '0') throw new Error('Empty response (handler not loaded?)');
                    el.innerHTML = res.text;
                    el.removeAttribute('aria-busy');
                })
                .catch(function(err){
                    console.error('IFUN cards error:', err);
                    el.innerHTML = '<div style="padding:24px;text-align:center">Could not load products.<br><small>' +
                                                 String(err.message) + '</small></div>';
                    el.removeAttribute('aria-busy');
                });
        }

        document.addEventListener('DOMContentLoaded', function(){
            document.querySelectorAll('.ifun-cards[data-shortcode]').forEach(fetchCards);
        });

        window.ifunReloadCards = function(selector){
            document.querySelectorAll(selector).forEach(function(el){
                el.removeAttribute('data-page');
                fetchCards(el);
            });
        };

        var lastFocus = null;
        var getOpen = function(){ return Array.from(document.querySelectorAll('#order-guided .popup.active')); };
        function openModal(id){
            var m = document.getElementById(id);
            if (!m) return;
            m.classList.add('active');
            m.setAttribute('aria-hidden','false');
            document.body.style.overflow = 'hidden';
            if (window.ifunReloadCards) window.ifunReloadCards('#' + id + ' .ifun-cards');
            var f = m.querySelector('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])');
            (f || m).focus();
        }
        function closeModal(m){
            if (!m) return;
            m.classList.remove('active');
            m.setAttribute('aria-hidden','true');
            if (getOpen().length === 0) document.body.style.overflow = '';
        }
        function switchModal(id, opener){
            lastFocus = opener || document.activeElement;
            var open = getOpen();
            if (open.length) {
                open.forEach(closeModal);
                requestAnimationFrame(function(){ openModal(id); });
            } else {
                openModal(id);
            }
        }

        root.addEventListener('click', function(e){
            var opener = e.target.closest('[data-open]');
            if (opener && root.contains(opener)){
                e.preventDefault();
                e.stopPropagation();
                var id = opener.getAttribute('data-open');
                var already = document.getElementById(id) && document.getElementById(id).classList.contains('active');
                if (!already) switchModal(id, opener);
                return false;
            }

            var closer = e.target.closest('.popup-close');
            if (closer){
                e.preventDefault();
                closeModal(closer.closest('.popup'));
                if (lastFocus) lastFocus.focus();
                return false;
            }

            var overlay = e.target.classList && e.target.classList.contains('popup') ? e.target : null;
            if (overlay){
                closeModal(overlay);
                if (lastFocus) lastFocus.focus();
                return false;
            }
        });

        document.addEventListener('keydown', function(e){
            if (e.key === 'Escape'){
                var open = getOpen().pop();
                if (open) closeModal(open);
                if (lastFocus) lastFocus.focus();
            }
            if (e.key === 'Tab'){
                var openModalEl = getOpen().pop();
                if (!openModalEl) return;
                var focusables = openModalEl.querySelectorAll('button,[href],input,select,textarea,[tabindex]:not([tabindex="-1"])');
                if (!focusables.length) return;
                var first = focusables[0];
                var last = focusables[focusables.length - 1];
                if (e.shiftKey && document.activeElement === first){
                    e.preventDefault();
                    last.focus();
                } else if (!e.shiftKey && document.activeElement === last){
                    e.preventDefault();
                    first.focus();
                }
            }
        });

        var params = new URLSearchParams(window.location.search || '');
        var openId = params.get('open') || (window.location.hash && window.location.hash.replace('#',''));
        if (openId){
            openModal(openId);
        }
    }

    initPricing();
    initPopups();
})();
JS;

        wp_add_inline_script('ifun-page-fixes', $js);
}, 20);

/**
 * Plugin Name: Perfmatters PDF Viewer Exclusions
 */

if ( ! function_exists( 'custom_perfmatters_skip_on_pdf_viewer' ) ) {

    function custom_perfmatters_skip_on_pdf_viewer( $skip ) {
        if ( strpos( $_SERVER['REQUEST_URI'] ?? '', '/v.html' ) !== false
          || strpos( $_SERVER['REQUEST_URI'] ?? '', '/view-pdf/' ) !== false
        ) {
            return true;
        }
        return $skip;
    }

    add_filter( 'perfmatters_exclude_defer_js',     'custom_perfmatters_skip_on_pdf_viewer' );
    add_filter( 'perfmatters_exclude_delay_js',     'custom_perfmatters_skip_on_pdf_viewer' );
    add_filter( 'perfmatters_exclude_lazy_loading', 'custom_perfmatters_skip_on_pdf_viewer' );
    add_filter( 'perfmatters_exclude_instant_page', 'custom_perfmatters_skip_on_pdf_viewer' );
    add_filter( 'perfmatters_exclude_unused_css',   'custom_perfmatters_skip_on_pdf_viewer' );

}


if ( ! function_exists( 'render_email_template' ) ) {
    function render_email_template($template, $params = []) {
        extract($params); // makes $first_name, $book_link, etc. available in template
        ob_start();
        include WP_PLUGIN_DIR . "/ifun-emails/{$template}.php";
        return ob_get_clean();
    }
}

// 1. Return the GeoIP info object (or null)
if ( ! function_exists( 'ifun_geoip_get_info' ) ) {
    function ifun_geoip_get_info() {
        if ( function_exists( 'geoip_detect2_get_info_from_current_ip' ) ) {
            return geoip_detect2_get_info_from_current_ip();
        }
        return null;
    }
}

/**
 * Helper function to send email from services@ifunlearning.com with a standard signature.
 *
 * @param string $to Recipient email.
 * @param string $subject Email subject.
 * @param string $message Email body.
 */
if ( ! function_exists( 'ifun_send_mail' ) ) {
    function ifun_send_mail($to, $subject, $message, $headers = array()) {
        $from = "services@ifunlearning.com";
        
        // Default headers
        $default_headers = array(
            "Content-Type: text/html; charset=UTF-8",
            "From: iFunLearning Services Team <$from>"
        );

        // Merge headers (so caller can add Bcc, Cc, etc.)
        if (!empty($headers)) {
            if (is_array($headers)) {
                $headers = array_merge($default_headers, $headers);
            } else {
                // if caller passes string instead of array
                $headers = array_merge($default_headers, array($headers));
            }
        } else {
            $headers = $default_headers;
        }
        
        // Convert newlines to <br> tags so that they display in HTML.
        $message = nl2br($message);
        
        // Append a signature using HTML line breaks.
        $signature = "<br>Best regards,<br>Services Team<br>services@ifunlearning.com<br><a href='https://ifunlearning.com'>iFunLearning</a>";
        $message .= $signature;
        
        return wp_mail($to, $subject, $message, $headers);
    }
}

// 2. Return Country, State (or just State/Country) or City
if ( ! function_exists( 'ifun_get_geo_location' ) ) {
    /**
     * @param string $type 'full' (Country, State), 'country', 'state', or 'city'
     * @return string
     */
    function ifun_get_geo_location( $type = 'full' ) {
        $geo = ifun_geoip_get_info();
        if ( ! $geo ) {
            return '';
        }

        $country = $geo->country->name                     ?? '';
        $state   = $geo->mostSpecificSubdivision->name      ?? '';
        $city    = $geo->city->name                        ?? '';

        switch ( $type ) {
            case 'country':
                return $country;
            case 'state':
                if ( empty( $state ) || $state === $country ) {
                    return $country;
                }
                return $state;
            case 'city':
                return $city;
            case 'full':
            default:
                $parts = array_filter( [ $country, $state ] );
                return implode( ', ', $parts );
        }
    }
}

if ( ! function_exists( 'ifun_adbox_render' ) ) {
    /**
     * Render the adbox HTML+CSS.
     *
     * @param array $args {
     *   @type string $icon        Emoji or HTML icon.
     *   @type string $title       Box title.
     *   @type string $content     Box body text.
     *   @type string $button_text Button label.
     *   @type string $button_link Button URL.
     *   @type string $float       'left' or 'right'.
     * }
     * @return string
     */
    function ifun_adbox_render( $args ) {
        $defaults = [
            'icon'        => '',
            'title'       => '',
            'content'     => '',
            'button_text' => '',
            'button_link' => '#',
            'float'       => 'right',
            'subject'     => '', // NEW
            'year'        => '', // NEW
        ];
        $a = wp_parse_args( $args, $defaults );

        $float_class = ( $a['float'] === 'left' )
            ? 'ifun-float-left'
            : 'ifun-float-right';
            
        // build optional attributes
        $subject_attr = !empty($a['subject']) ? ' data-subject="'.esc_attr($a['subject']).'"' : '';
        $year_attr    = !empty($a['year'])    ? ' data-year="'.esc_attr($a['year']).'"'       : '';

        ob_start();
        ?>
        <div class="ifun-adbox <?= esc_attr( $float_class ) ?>">
            <div class="ifun-adbox-header">
                <span class="ifun-adbox-icon"><?= esc_html( $a['icon'] ) ?></span>
                <strong><?= esc_html( $a['title'] ) ?></strong>
            </div>
            <div class="ifun-adbox-content">
                <p><?= esc_html( $a['content'] ) ?></p>
                <a href="<?= esc_url( $a['button_link'] ) ?>"
                   class="ifun-adbox-button"
                   <?= $subject_attr.$year_attr ?>
                   rel="noopener">
                   <?= esc_html( $a['button_text'] ) ?>
                </a>
            </div>
        </div>
        <style>
        .ifun-adbox {
            background: #fffbe9;
            border: 1px solid #e0dca4;
            padding: .8em;
            border-radius: 8px;
            font-family: sans-serif;
            font-size: .82rem;
            line-height: 1.4;
            width: 250px;
            max-width: 280px;
            margin: .5em 0 1em 1em;
            box-sizing: border-box;
            vertical-align: top;
        }
        .ifun-float-right { float: right; display: inline-block; margin:0 20% 1em 1em; }
        .ifun-float-left  { float: left;  display: inline-block; margin:0 2em 1em 0; }
        @media (max-width:768px) {
          .ifun-adbox,
          .ifun-float-left,
          .ifun-float-right {
              float:none!important;
              width:100%!important;
              max-width:100%!important;
              margin:1em 0!important;
          }
        }
        .ifun-adbox-header { display:flex; align-items:center; margin-bottom:.3em; font-size:.92rem; font-weight:600; }
        .ifun-adbox-icon   { margin-right:.5em; font-size:1.25rem; }
        .ifun-adbox-content p { margin:0 0 .5em; font-size:.82rem; }
        .ifun-adbox-button { display:inline-block; background:#DCDAD4; color:#fff; padding:.35em .75em; border-radius:4px; text-decoration:none; font-size:.8rem; white-space:nowrap; }
        .ifun-adbox-button:hover { background:#F2DC8A; }
        </style>
        <?php
        return ob_get_clean();
    }
}

if ( ! function_exists( 'ifun_adbox_shortcode' ) ) {
    /**
     * [ifun_adbox id="workbooks" float="left"]
     */
    function ifun_adbox_shortcode( $atts ) {
        $ad_configs = [
            'workbooks' => [
                'icon' => '🎨',
                'title' => 'See the student books',
                'content' => 'Help cohorts practise skills through ready-to-use, instruction-free activities.',
                'button_text' => 'See Free Samples',
                'button_link' => '/samples',
            ],
    
            'va-quiz' => [
                'icon' => '📝',
                'title' => 'Diagnose your cohort',
                'content' => 'Quickly pinpoint learning-skill gaps in your cohorts.',
                'button_text' => 'View Quiz Demo',
                'button_link' => '/quizzes/',
            ],
    
            'rec-engine' => [
                'icon' => '🧭',
                'title' => 'Select activities',
                'content' => 'Receive activity recommendations for your cohort.',
                'button_text' => 'Get Recommendations',
                'button_link' => '/recommendations',
            ]
        ];

        $a = shortcode_atts( [
            'id'    => '',
            'float' => 'right',
            'subject'=> '',   // NEW
            'year'   => '',   // NEW
        ], $atts, 'ifun_adbox' );

        if ( ! isset( $ad_configs[ $a['id'] ] ) ) {
            return '';
        }

        // merge the config + float into one array and render
        $args = $ad_configs[ $a['id'] ];
        $args['float'] = $a['float'];
        $args['subject'] = $a['subject']; // NEW
        $args['year']    = $a['year'];    // NEW        

        return ifun_adbox_render( $args );
    }
    add_shortcode( 'ifun_adbox', 'ifun_adbox_shortcode' );
}

if ( ! function_exists( 'ifun_detailed_adbox_shortcode' ) ) {
    /**
     * [ifun_detailed_adbox icon="🎨" title="…" content="…" button_text="…" button_link="…" float="left"]
     */
    function ifun_detailed_adbox_shortcode( $atts ) {
        // simply pass everything straight to the renderer
        return ifun_adbox_render( $atts );
    }
    add_shortcode( 'ifun_detailed_adbox', 'ifun_detailed_adbox_shortcode' );
}

// -----------------------------------------------------------------------------
// WCPT: force strike-through on variation labels via JS fallback
// -----------------------------------------------------------------------------
add_action( 'wp_footer', function(){
  if ( ! class_exists('WC_Product_Variation') ) return;
  ?>
  <script>
  jQuery(function($){
    $('.wcpt-select-variation').each(function(){
      var $label    = $(this),
          variation = $label.data('wcpt-variation'),
          $input    = $label.find('input[type=radio]').first();

      if ( ! variation || ! variation.price_html ) return;

      // clean out screen-reader bits
      var price = variation.price_html
                     .replace(/<span class="screen-reader-text">.*?<\/span>/g,'')
                     .trim();
      // 1) Pull the last bit after " - "
      var parts = variation.display_name.split(' - '),
          name  = parts.pop().trim();
      
      // rebuild label
      $input = $input.detach();
      $label.empty()
            .append($input)
            .append('<span class="wcpt-variation-label">'+ name +' — '+ price +'</span>');
    });
  });
  </script>
  <?php
}, 100 );

// --- Open the Recommendations UI in a modal when "Get Recommendations" is clicked ---
add_action('wp_footer', function () {
    if (is_admin()) return;

    // Hidden modal wrapper with your existing recommendations UI inside
    // (no presets => the subject picker shows; if you want presets, pass [ifun_recommendations subject="VA" year="8"])
    ?>
    <div id="ifun-recs-modal"
         style="display:none; position:fixed; inset:0; z-index:100000; background:rgba(0,0,0,.55);">
      <div id="ifun-recs-modal-inner"
           style="position:relative; margin:auto; max-width:1200px; width:96%; max-height:90vh; overflow:auto; background:#fff; border-radius:10px; box-shadow:0 12px 30px rgba(0,0,0,.25);">
        <button type="button" id="ifun-recs-close"
                style="position:sticky; top:0; float:right; margin:.5rem; background:#eee; border:0; border-radius:6px; padding:.4rem .6rem; cursor:pointer;">✕</button>
        <?php echo do_shortcode('[ifun_recommendations]'); ?>
      </div>
    </div>
    <script>
    (function($){
      function openRecsModal(){
        $('#ifun-recs-modal').fadeIn(150);
        $('body').css('overflow','hidden');
      }
      function closeRecsModal(){
        $('#ifun-recs-modal').fadeOut(150, function(){ $('body').css('overflow',''); });
      }
      $(document).on('click', '#ifun-recs-close, #ifun-recs-modal', function(e){
        if (e.target.id === 'ifun-recs-modal' || e.target.id === 'ifun-recs-close') closeRecsModal();
      });

    })(jQuery);
    </script>
    <?php
}, 999);



