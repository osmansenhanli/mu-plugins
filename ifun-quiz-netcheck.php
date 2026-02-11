<?php
/**
 * Plugin Name: IFun Quiz Network Check (All QSM Quizzes)
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('ifun_output_netcheck_block')) {
  function ifun_output_netcheck_block() {
    static $already_added = false; // prevents multiple inline script injections

    $html = '<div id="quiz-network-check" style="font-family:system-ui, sans-serif; font-size:14px; line-height:1.45; margin:0 0 1rem 0"></div>';

    if (!$already_added) {
      if (!wp_script_is('ifun-quiz-netcheck', 'registered')) {
        wp_register_script('ifun-quiz-netcheck', false, [], null, true);
      }
      wp_enqueue_script('ifun-quiz-netcheck');

      $js = <<<JS
(function(){
  var el = document.getElementById('quiz-network-check');
  if(!el) return;
  var showOk = /(?:\?|&)netcheck=verbose(?:&|$)/.test(window.location.search);

  function line(t){ el.insertAdjacentHTML('beforeend','<div>'+t+'</div>'); }
  function ok(t){
    if (!showOk) return;
    line('<span style="color:#1b5e20;font-weight:600">OK:</span> ' + t);
  }
  function fail(t,e){ 
    var msg = 'BLOCKED/ERROR: ' + t + (e ? (' — ' + (e.message||e)) : '');
    line('<span style="color:red;font-weight:bold">' + msg + ' — CONTACT IT to resolve this.</span>');
  }

  try { ok('JavaScript is enabled'); } catch(e){}

  try {
    document.cookie = "ifun_check=1; path=/";
    var cookiesEnabled = document.cookie.indexOf('ifun_check=1') !== -1;
    window.localStorage.setItem('ifun_ls','1');
    var lsEnabled = window.localStorage.getItem('ifun_ls') === '1';
    ok('Cookies: ' + (cookiesEnabled ? 'yes' : 'no'));
    ok('localStorage: ' + (lsEnabled ? 'yes' : 'no'));
  } catch(e){ fail('Storage (cookies/localStorage)', e); }

  fetch('/wp-json/', {method:'GET', credentials:'omit'})
    .then(function(r){ ok('REST API (/wp-json/) HTTP ' + r.status); })
    .catch(function(e){ fail('REST API (/wp-json/)', e); });

  fetch('/wp-admin/admin-ajax.php?action=ifun_netcheck_ping', {method:'GET', credentials:'omit'})
    .then(function(r){ ok('AJAX (/wp-admin/admin-ajax.php) HTTP ' + r.status); })
    .catch(function(e){ fail('AJAX (/wp-admin/admin-ajax.php)', e); });
})();
JS;
      wp_add_inline_script('ifun-quiz-netcheck', $js);

      $already_added = true;
    }

    return $html;
  }
}

add_filter('the_content', function($content){
  if (is_admin() || is_feed() || is_preview()) return $content;
  if (!is_singular('qsm_quiz')) return $content;
  if (isset($_GET['netcheck']) && $_GET['netcheck'] === 'off') return $content;
  return ifun_output_netcheck_block() . $content;
}, 9);

add_filter('the_content', function($content){
  if (is_admin() || is_feed() || is_preview()) return $content;
  if (is_singular('qsm_quiz')) return $content;
  if (isset($_GET['netcheck']) && $_GET['netcheck'] === 'off') return $content;
  if (has_shortcode($content, 'qsm')) {
    return ifun_output_netcheck_block() . $content;
  }
  return $content;
}, 8);

add_action('wp_ajax_ifun_netcheck_ping', 'ifun_netcheck_ping');
add_action('wp_ajax_nopriv_ifun_netcheck_ping', 'ifun_netcheck_ping');

if (!function_exists('ifun_netcheck_ping')) {
  function ifun_netcheck_ping() {
    wp_send_json_success(['ok' => true]);
  }
}
