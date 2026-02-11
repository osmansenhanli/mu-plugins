<?php
/**
 * Plugin Name: Strip Perfmatters Wrappers on QSM (with Early Buffer & Debug)
 * Description: On /qsm_quiz/* pages, convert pmdelayedscript to normal <script>, drop Perfmatters data-attrs, and log counts.
 */

// Only on quiz front-end URLs:
if ( isset( $_SERVER['REQUEST_URI'] ) && strpos( $_SERVER['REQUEST_URI'], '/qsm_quiz/' ) !== false ) {
//    error_log( "[StripPM] 🚀 Starting output buffer on: " . $_SERVER['REQUEST_URI'] );
    ob_start( 'strip_perfmatters_callback' );
}

/**
 * Buffer callback: do the replacements and log counts.
 *
 * @param string $html Entire page HTML.
 * @return string      Modified HTML.
 */
function strip_perfmatters_callback( $html ) {
    // 1) Replace pmdelayedscript → text/javascript
//    error_log( "[StripPM] Running buffer callback" );
    $pattern1 = '#(<script\b[^>]*?)\s+type=(["\'])pmdelayedscript\2#i';
    $count1   = preg_match_all( $pattern1, $html, $m1 );
//    error_log( "[StripPM] pmdelayedscript matches: {$count1}" );
    $html = preg_replace( $pattern1, '$1 type="text/javascript"', $html );

    // 2) Remove Perfmatters data- attributes
    $pattern2 = '#\sdata-(?:cfasync|no-optimize|no-defer|no-minify)=(["\'])[^\1]*?\1#i';
    $count2   = preg_match_all( $pattern2, $html, $m2 );
//    error_log( "[StripPM] Perfmatters data-attr matches: {$count2}" );
    $html = preg_replace( $pattern2, '', $html );

    // 3) Fix preload-as-style links back to rel=stylesheet
    $pattern3 = '#<link\b([^>]*?)\srel=(["\'])preload\2([^>]*?)\sas=(["\'])style\4#i';
    $count3   = preg_match_all( $pattern3, $html, $m3 );
//    error_log( "[StripPM] preload-as-style link matches: {$count3}" );
    $html = preg_replace( $pattern3, '<link$1 rel="stylesheet"$3', $html );

//    error_log( "[StripPM] ✅ Buffer callback complete." );
    return $html;
}