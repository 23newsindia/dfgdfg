<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('init', 'wns_check_verification_request');

function wns_check_verification_request() {
    if (isset($_GET['verify_email']) && isset($_GET['token'])) {
        $email = sanitize_email($_GET['verify_email']);
        $token = sanitize_text_field($_GET['token']);

        // Additional validation
        if (!is_email($email) || empty($token) || strlen($token) !== 64) {
            wp_safe_redirect(add_query_arg('verified', 'invalid', home_url()));
            exit;
        }

        // Rate limiting for verification attempts
        if (!wns_check_verification_rate_limit($email)) {
            wp_safe_redirect(add_query_arg('verified', 'rate_limited', home_url()));
            exit;
        }

        if (wns_verify_email_token($email, $token)) {
            wns_mark_email_as_verified($email);
            wp_safe_redirect(add_query_arg('verified', 'success', home_url()));
            exit;
        } else {
            wp_safe_redirect(add_query_arg('verified', 'invalid', home_url()));
            exit;
        }
    }
}

function wns_check_verification_rate_limit($email) {
    $transient_key = 'wns_verify_rate_' . md5($email);
    $attempts = get_transient($transient_key);
    
    if ($attempts === false) {
        set_transient($transient_key, 1, HOUR_IN_SECONDS);
        return true;
    }
    
    if ($attempts >= 3) { // Max 3 verification attempts per hour per email
        return false;
    }
    
    set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);
    return true;
}

function wns_generate_verification_token($email) {
    // Use a more secure token generation with timestamp
    $salt = defined('AUTH_SALT') ? AUTH_SALT : wp_salt();
    $timestamp = time();
    $data = $email . $timestamp . wp_get_session_token();
    
    // Store token with expiration (24 hours)
    $token = hash_hmac('sha256', $data, $salt);
    set_transient('wns_verify_token_' . md5($email), array(
        'token' => $token,
        'expires' => $timestamp + DAY_IN_SECONDS
    ), DAY_IN_SECONDS);
    
    return $token;
}

function wns_verify_email_token($email, $token) {
    global $wpdb;

    // Validate inputs
    if (!is_email($email) || empty($token) || strlen($token) !== 64) {
        return false;
    }

    $table_name = WNS_TABLE_SUBSCRIBERS;
    
    // Verify table exists
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
        return false;
    }

    // Check if email exists and is not already verified
    $subscriber = $wpdb->get_row($wpdb->prepare("SELECT * FROM `$table_name` WHERE `email` = %s", $email));

    if ($wpdb->last_error) {
        error_log('WNS Plugin Error in verification: ' . $wpdb->last_error);
        return false;
    }

    if (!$subscriber || $subscriber->verified) {
        return false;
    }

    // Verify token from transient
    $stored_token_data = get_transient('wns_verify_token_' . md5($email));
    if (!$stored_token_data || !is_array($stored_token_data)) {
        return false;
    }

    // Check token match and expiration
    if (!hash_equals($stored_token_data['token'], $token)) {
        return false;
    }

    if (time() > $stored_token_data['expires']) {
        delete_transient('wns_verify_token_' . md5($email));
        return false;
    }

    // Clean up token after successful verification
    delete_transient('wns_verify_token_' . md5($email));
    return true;
}

function wns_mark_email_as_verified($email) {
    global $wpdb;
    $table_name = WNS_TABLE_SUBSCRIBERS;

    $result = $wpdb->update(
        $table_name,
        array('verified' => 1),
        array('email' => $email),
        array('%d'),
        array('%s')
    );

    if ($wpdb->last_error) {
        error_log('WNS Plugin Error in mark verified: ' . $wpdb->last_error);
        return false;
    }

    return $result !== false;
}

function wns_send_verification_email($email) {
    // Validate email
    if (!is_email($email)) {
        return false;
    }

    // Rate limiting for sending verification emails
    $transient_key = 'wns_verify_email_sent_' . md5($email);
    if (get_transient($transient_key)) {
        return false; // Already sent within the last 5 minutes
    }

    $token = wns_generate_verification_token($email);
    $verify_link = add_query_arg(array(
        'verify_email' => urlencode($email),
        'token' => $token
    ), home_url());

    $subject = sanitize_text_field(get_option('wns_template_subscribe_subject', __('Confirm Your Subscription', 'wp-newsletter-subscription')));
    
    $message_template = get_option('wns_template_subscribe_body', __('Thank you for subscribing! Please verify your email by clicking the link below:\n\n{verify_link}', 'wp-newsletter-subscription'));
    $message = str_replace('{verify_link}', esc_url($verify_link), $message_template);
    $message = wp_kses_post($message);
    
    $headers = array('Content-Type: text/html; charset=UTF-8');

    $sent = wp_mail($email, $subject, $message, $headers);
    
    if ($sent) {
        // Set transient to prevent spam
        set_transient($transient_key, true, 5 * MINUTE_IN_SECONDS);
    }
    
    return $sent;
}