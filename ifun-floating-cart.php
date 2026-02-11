<?php
/**
 * Plugin Name: iFun Floating Cart (static pages only)
 * Description: Floating cart widget that appears only on regular Pages. Auto-hides on popups/iframes. No WCPT required.
 * Version: 1.3
 */

if (!defined('ABSPATH')) exit;

/* enqueue jQuery (safe) and try to ensure Woo fragments are available */
add_action('wp_enqueue_scripts', function () {
  if (is_admin()) return;
  wp_enqueue_script('jquery');
  // If the theme hasn't dequeued it, this will ensure wc-cart-fragments is present.
  if (wp_script_is('wc-cart-fragments', 'registered')) {
    wp_enqueue_script('wc-cart-fragments');
  }
}, 1);

/* 1) Render the widget (only on static pages) */

/*
add_action('wp_head', function(){
  ?>
  <style>
    body.ifun-modal-open #ifun-cart-root { display:none !important; }
  </style>
  <?php
}, 20);
*/

add_action('wp_footer', function () {
  if (is_admin()) return;

  // Only on real static pages
  if (!(is_page() || is_single() || is_home() || is_archive())) return;

  // Exclude common system/special pages
  if (function_exists('is_cart') && is_cart()) return;
  if (function_exists('is_checkout') && is_checkout()) return;
  if (function_exists('is_account_page') && is_account_page()) return;
  if (is_search() || is_404()) return;
  
    // Exclude “app-like” canvases by path/query
    $req = $_SERVER['REQUEST_URI'] ?? '';
    if (strpos($req, '/try-activity') !== false) return;   // << NEW
    if (strpos($req, '/rec-popup') !== false) return;   // << NEW
    if (strpos($req, '/v.html') !== false) return;         // PDF viewer
    if (strpos($req, '/view-pdf/') !== false) return;
    
  if (!class_exists('WooCommerce') || !WC()->cart) return;

  echo ifun_cart_widget_markup(); // prints the anchor + widget (or empty widget)
});

/* markup (always prints the anchor) */
function ifun_cart_widget_markup() {
  if (!WC()->cart) return '<div id="ifun-cart-root"></div>';

  $cart_url = wc_get_cart_url();
  $count    = WC()->cart->get_cart_contents_count();

  ob_start(); ?>
  <div id="ifun-cart-root">
    <?php if ($count > 0): ?>
    <div class="ifun-cart-widget" data-href="<?php echo esc_url($cart_url); ?>">
      <div class="ifun-cw-half">
        <div class="ifun-cw__totals">
          <span class="ifun-cw-qty-total">
            <span class="ifun-cw-figure"><?php echo (int) $count; ?></span>
            <span class="ifun-cw-text"><?php echo esc_html__('Items', 'your-textdomain'); ?></span>
          </span>
        </div>
      </div>
      <a href="<?php echo esc_url($cart_url); ?>" class="ifun-cw-half no-prefetch">
        <span class="ifun-cw-view-label"><?php echo esc_html__('View Cart', 'your-textdomain'); ?></span>
        <span class="ifun-cw-cart-icon" aria-hidden="true">🛒</span>
      </a>
    </div>
    <style id="ifun-cart-css">
      .ifun-cart-widget{
        position:fixed; left:50%; transform:translateX(-50%); bottom:50px;
        background:#4CAF50; color:#fff; border:1px solid rgba(0,0,0,.1);
        border-radius:10px; display:inline-flex; align-items:stretch; overflow:hidden;
        box-shadow:0 10px 24px rgba(0,0,0,.18); z-index:2147483000; font-family:inherit;
      }
      .ifun-cw-half{ padding:.75rem 1rem; display:flex; flex-direction:column; justify-content:center; }
      .ifun-cw-half.no-prefetch{ background:rgba(255,255,255,.12); text-decoration:none; color:#fff; display:flex; align-items:center; }
      .ifun-cw__totals{ display:flex; align-items:baseline; gap:.35rem; font-weight:600; }
      .ifun-cw-footer{ font-size:.75rem; opacity:.9; }
      @media(max-width:1199px){ .ifun-cart-widget{ bottom:20px; } }
    </style>
    <?php endif; ?>
  </div>
  <?php
  return ob_get_clean();
}

/* 2) Woo fragment so it updates live (server returns our HTML keyed by selector) */
add_filter('woocommerce_add_to_cart_fragments', function ($fragments) {
  if (!WC()->cart) return $fragments;
  $fragments['#ifun-cart-root'] = ifun_cart_widget_markup();
  return $fragments;
});

/* 3) Fallback AJAX endpoint to hard-refresh the widget if fragments arenâ€™t active */
add_action('wp_ajax_nopriv_ifun_refresh_cart_widget', 'ifun_refresh_cart_widget');
add_action('wp_ajax_ifun_refresh_cart_widget', 'ifun_refresh_cart_widget');
function ifun_refresh_cart_widget() {
  nocache_headers();
  echo ifun_cart_widget_markup();
  wp_die();
}

/* 4) One well-formed footer script block: auto-refresh after any cart change
      Uses Woo fragments if present; otherwise falls back to our admin-ajax endpoint. */
add_action('wp_footer', function () {
  $fallback_url = admin_url('admin-ajax.php?action=ifun_refresh_cart_widget');
  ?>
  <script>
  (function(){
    if (!window.jQuery) return;
    var $ = jQuery;
    var FALLBACK_URL = <?php echo wp_json_encode( esc_url($fallback_url) ); ?>;

    function hardReplaceWidget(){
      $.get(FALLBACK_URL, function(html){
        var $new = $(html);
        var $old = $('#ifun-cart-root');
        if ($old.length) {
          $old.replaceWith($new);
        }
        // IMPORTANT: do NOT append when anchor is missing
      });
    }

    function refreshCartWidget(){
      if (typeof wc_cart_fragments_params !== 'undefined') {
        $(document.body).trigger('wc_fragment_refresh');
      } else {
        hardReplaceWidget();
      }
    }

    // If Woo fragments run but donâ€™t include our selector, hard refresh after they finish
    $(document.body).on('wc_fragments_refreshed wc_fragments_loaded', function(){
      // If the anchor exists but didn't update properly, force replace;
      // otherwise do nothing.
      if ($('#ifun-cart-root').length && !$('#ifun-cart-root').children().length) {
        hardReplaceWidget();
      }
    });

    // Refresh after ANY Woo AJAX call
    $(document).ajaxComplete(function(e, xhr, settings){
      if (!settings || !settings.url) return;
      if (settings.url.indexOf('wc-ajax=') > -1) refreshCartWidget();
    });

    // Also refresh when Woo emits cart events
    $(document.body).on('added_to_cart removed_from_cart updated_cart_totals wc_cart_emptied', refreshCartWidget);

    // Refresh when common popups close (Elementor / JetPopup)
    $(window).on('elementor/popup/hide', refreshCartWidget);
    $(document).on('jet-popup/hide', refreshCartWidget);
    
    // Also listen to our own vanilla event fired by catalogue.js
    document.body.addEventListener('ifun_cart_changed', refreshCartWidget);

    // Initial sync
    $(document).ready(refreshCartWidget);
  })();
  </script>
  <?php
}, 20);
