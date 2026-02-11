<?php
/**
 * Plugin Name: iFun Login UX Tweaks
 * Description: Improves WooCommerce login UX for teachers/coordinators.
 *  - Shows a friendly message when the login form nonce is stale (tab left open too long)
 *  - Changes "Username or email address" to "Email address"
 *  - After login, sends non-admin users to My Resources
 * Author: iFun Learning
 * Version: 1.0
 */

/**
 * 1. Catch expired WooCommerce login nonce and show a clear message
 *
 * Scenario: User leaves /my-account/ open overnight.
 * The hidden woocommerce-login-nonce expires.
 * WooCommerce normally just bounces them back to login with no explanation.
 *
 * We intercept BEFORE WooCommerce's own processor (priority < 10),
 * detect invalid nonce, add our own notice, and redirect cleanly.
 */
add_action('init', 'ifun_handle_expired_wc_login_nonce', 1);
function ifun_handle_expired_wc_login_nonce() {

    // Only run for guests trying to log in via the WooCommerce form.
    if ( is_user_logged_in() ) {
        return;
    }

    // Woo's login form posts to /my-account/ with these fields.
    $is_login_attempt =
        isset($_POST['login']) &&                      // the submit button "Log in"
        isset($_POST['woocommerce-login-nonce']);      // WooCommerce nonce field

    if ( ! $is_login_attempt ) {
        return;
    }

    // Check the nonce.
    $nonce_val = sanitize_text_field($_POST['woocommerce-login-nonce']);
    $nonce_ok  = wp_verify_nonce($nonce_val, 'woocommerce-login');

    if ( $nonce_ok ) {
        // nonce is fine, let WooCommerce continue as normal.
        return;
    }

    // Nonce is NOT valid → most likely the tab sat open too long.
    if ( function_exists('wc_add_notice') ) {
        wc_add_notice(
            __(
                'Your login page was left open too long and expired. Please reload the page and sign in again.',
                'ifun'
            ),
            'error'
        );
    }

    // Send them back to a fresh /my-account/ page (new nonce).
    wp_safe_redirect( home_url('/my-account/my-resources/') );
    exit;
}

/**
 * 2. Change the login form label from "Username or email address" → "Email address"
 *
 * Easiest, safest way is to filter gettext so we don't have to override the whole template.
 * We handle both with and without the required asterisk.
 */
add_filter('gettext', 'ifun_rename_username_email_label', 10, 3);
function ifun_rename_username_email_label( $translated, $original, $domain ) {

    // Woo's core strings are usually exactly:
    // "Username or email address"  and "Username or email address *"
    // We'll rewrite both to "Email address" / "Email address *".
    if ( $original === 'Username or email address' ) {
        return 'Email address';
    }

    if ( $original === 'Username or email address *' ) {
        return 'Email address *';
    }

    return $translated;
}

/**
 * 3. After successful login, send normal users to "My Resources"
 *    https://ifunlearning.com/my-account/my-resources/
 *
 * We ONLY override for non-admin, non-shop-manager style accounts.
 * Admin / shop manager can keep default so you (Hilary) still land in wp-admin or Woo dashboard.
 */
add_filter('woocommerce_login_redirect', 'ifun_login_redirect_my_resources', 10, 2);
function ifun_login_redirect_my_resources( $redirect, $user ) {

    // Safety: if somehow we didn't get a WP_User, bail.
    if ( ! $user instanceof WP_User ) {
        return $redirect;
    }

    // Let high-privilege users keep their normal flow.
    if ( user_can( $user, 'manage_options' ) || user_can( $user, 'manage_woocommerce' ) ) {
        return $redirect;
    }

    // Everyone else (Teacher, Coordinator, Customer, etc.) goes to My Resources.
    return home_url('/my-account/my-resources/');
}
