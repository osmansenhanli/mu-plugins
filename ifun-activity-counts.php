<?php
/**
 * iFun – Activity counts (REST)
 * v=2 (cache-busting suffix)
 */

add_action('init', function () {
  // proves mu-plugin loaded (check your php_errorlog)
  if (!defined('IFUN_ACTIVITY_COUNT_BOOT_LOGGED')) {
    define('IFUN_ACTIVITY_COUNT_BOOT_LOGGED', true);
//    error_log('ifun_activity_count: mu-plugin loaded (v2)');
  }
});

add_action('rest_api_init', function () {
  register_rest_route('ifun/v1', '/activity-count', [
    'methods'  => 'GET',
    'callback' => 'ifun_activity_count_handler',
    'permission_callback' => '__return_true',
    'args' => [
      'subject' => ['required' => true],
      'year'    => ['required' => true, 'validate_callback' => function($v){ return preg_match('/^\d{1,2}$/', $v); }],
      'debug'   => ['required' => false],
      'nocache' => ['required' => false],
    ],
  ]);

  register_rest_route('ifun/v1', '/activity-counts', [
    'methods'  => 'GET',
    'callback' => 'ifun_activity_counts_handler',
    'permission_callback' => '__return_true',
    'args' => [
      'subject' => ['required' => true],
      'years'   => ['required' => false],
      'debug'   => ['required' => false],
      'nocache' => ['required' => false],
    ],
  ]);
});

function ifun_activity_cache_version() {
  // bump this to instantly bust all transients from code
  return 'v2';
}

function ifun_activity_yearlevel_from_year($year){
  $yl = (int)$year;
  if ($yl > 4) { $yl -= 6; } // 7→1, 8→2, 9→3, 10→4
  return $yl;
}

function ifun_activity_count_query($subject, $year, $debug=false){
  global $wpdb;
  $table = $wpdb->prefix . 'ifun_activities';

  $year_level = ifun_activity_yearlevel_from_year($year);

  // Main query: DISTINCT activity_no (in case dupes exist)
  $sql = "SELECT COUNT(DISTINCT activity_no) FROM {$table} WHERE subject_code = %s AND year_level = %d";
  $prepared = $wpdb->prepare($sql, $subject, $year_level);
  $res = (int)$wpdb->get_var($prepared);

  if ($debug){
    error_log("ifun_activity_count SQL: {$prepared}");
    if (!empty($wpdb->last_error)) {
      error_log("ifun_activity_count DB ERROR: {$wpdb->last_error}");
    }
    error_log("ifun_activity_count DISTINCT result: {$res}");
  }

  // If result is 0, sanity-check with COUNT(*) in case activity_no is NULL for rows
  if ($res === 0) {
    $sql2 = "SELECT COUNT(*) FROM {$table} WHERE subject_code = %s AND year_level = %d";
    $prepared2 = $wpdb->prepare($sql2, $subject, $year_level);
    $rows_any = (int)$wpdb->get_var($prepared2);
    if ($debug){
      error_log("ifun_activity_count fallback SQL: {$prepared2}");
      error_log("ifun_activity_count fallback COUNT(*): {$rows_any}");
    }
    // If COUNT(*) > 0 but DISTINCT(activity_no) == 0, your activity_no field is NULL/empty.
    // Switch to COUNT(*) as the returned count so UI isn't empty.
    if ($rows_any > 0) {
      $res = $rows_any;
    }
  }

  return $res;
}

function ifun_activity_count_handler(WP_REST_Request $req){
  $subject = strtoupper(sanitize_text_field($req->get_param('subject')));
  $year    = (int)$req->get_param('year');
  $debug   = (bool)$req->get_param('debug') || (bool)$req->get_param('nocache');
  $no_cache = (bool)$req->get_param('nocache');

  $ck = "ifun_acount_" . ifun_activity_cache_version() . "_{$subject}_{$year}";

  if (!$no_cache){
    $cached = get_transient($ck);
    if ($cached !== false){
      return new WP_REST_Response(['subject'=>$subject,'year'=>$year,'count'=>(int)$cached,'cached'=>true], 200);
    }
  }

  $count = ifun_activity_count_query($subject, $year, $debug);
  set_transient($ck, $count, HOUR_IN_SECONDS * 12);

  return new WP_REST_Response([
    'subject'=>$subject,
    'year'=>$year,
    'count'=>$count,
    'cached'=>false,
    'yl'=>ifun_activity_yearlevel_from_year($year),
  ], 200);
}

function ifun_activity_counts_handler(WP_REST_Request $req){
  $subject = strtoupper(sanitize_text_field($req->get_param('subject')));
  $years   = $req->get_param('years') ? array_filter(array_map('intval', explode(',', $req->get_param('years')))) : [7,8,9,10];
  $debug   = (bool)$req->get_param('debug') || (bool)$req->get_param('nocache');
  $no_cache = (bool)$req->get_param('nocache');

  $out = [];
  foreach ($years as $y){
    $ck = "ifun_acount_" . ifun_activity_cache_version() . "_{$subject}_{$y}";
    $val = false;

    if (!$no_cache){
      $val = get_transient($ck);
    }

    if ($val === false){
      $val = ifun_activity_count_query($subject, $y, $debug);
      set_transient($ck, $val, HOUR_IN_SECONDS * 12);
    }
    $out[$y] = (int)$val;
  }
  return new WP_REST_Response(['subject'=>$subject,'years'=>$out], 200);
}
