<?php
if (!defined('ABSPATH')) {
    exit;
}

add_shortcode('newsletter_unsubscribe', 'wns_render_unsubscribe_form');

function wns_render_unsubscribe_form() {
    ob_start();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['wns_unsubscribe_email'])) {
        // Verify nonce
        if (!wp_verify_nonce($_POST['wns_unsubscribe_nonce'], 'wns_unsubscribe_action')) {
            echo '<div class="wns-message error">' . esc_html__('Security check failed. Please try again.', 'wp-newsletter-subscription') . '</div>';
        } else {
            $email = sanitize_email($_POST['wns_unsubscribe_email']);
            $result = wns_handle_unsubscribe($email);

            if ($result === true) {
                echo '<div class="wns-message success">' . esc_html__('You have been successfully unsubscribed.', 'wp-newsletter-subscription') . '</div>';
            } else {
                echo '<div class="wns-message error">' . esc_html($result) . '</div>';
            }
        }
    }

    ?>
    <form method="post" class="wns-unsubscribe-form">
        <?php wp_nonce_field('wns_unsubscribe_action', 'wns_unsubscribe_nonce'); ?>
        <p><?php esc_html_e('Enter your email to unsubscribe:', 'wp-newsletter-subscription'); ?></p>
        <input 
            type="email" 
            name="wns_unsubscribe_email" 
            placeholder="<?php esc_attr_e('Your email address', 'wp-newsletter-subscription'); ?>" 
            required 
            maxlength="254"
            autocomplete="email"
        />
        <button type="submit"><?php esc_html_e('Unsubscribe', 'wp-newsletter-subscription'); ?></button>
    </form>
    <?php

    return ob_get_clean();
}

function wns_handle_unsubscribe($email) {
    global $wpdb;

    if (!is_email($email)) {
        return __('Invalid email address.', 'wp-newsletter-subscription');
    }

    // Additional validation
    if (strlen($email) > 254) {
        return __('Email address is too long.', 'wp-newsletter-subscription');
    }

    // Rate limiting check
    if (!wns_check_unsubscribe_rate_limit()) {
        return __('Too many unsubscribe attempts. Please try again later.', 'wp-newsletter-subscription');
    }

    // Sanitize email
    $email = sanitize_email($email);

    $table_name = WNS_TABLE_SUBSCRIBERS;

    // Verify table exists with prepared statement
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
        return __('Service temporarily unavailable. Please try again later.', 'wp-newsletter-subscription');
    }

    // Check if exists with prepared statement
    $exists = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `$table_name` WHERE `email` = %s", $email));
    
    if ($wpdb->last_error) {
        error_log('WNS Plugin Error in unsubscribe: ' . $wpdb->last_error);
        return __('An error occurred. Please try again later.', 'wp-newsletter-subscription');
    }
    
    if ($exists == 0) {
        return __('This email is not subscribed.', 'wp-newsletter-subscription');
    }

    $deleted = $wpdb->delete($table_name, array('email' => $email), array('%s'));

    if (!$deleted) {
        error_log('WNS Plugin Error in unsubscribe delete: ' . $wpdb->last_error);
        return __('An error occurred. Please try again later.', 'wp-newsletter-subscription');
    }

    // Send confirmation email
    $subject = get_option('wns_template_unsubscribe_subject', __('You Have Been Unsubscribed', 'wp-newsletter-subscription'));
    $body = get_option('wns_template_unsubscribe_body', __("You have successfully unsubscribed from our newsletter. We're sorry to see you go!", 'wp-newsletter-subscription'));

    // Sanitize email content
    $subject = sanitize_text_field($subject);
    $body = wp_kses_post($body);

    $headers = array('Content-Type: text/html; charset=UTF-8');
    wp_mail($email, $subject, $body, $headers);

    return true;
}

function wns_check_unsubscribe_rate_limit() {
    $ip = wns_get_client_ip();
    $transient_key = 'wns_unsub_rate_' . md5($ip);
    $attempts = get_transient($transient_key);
    
    if ($attempts === false) {
        set_transient($transient_key, 1, HOUR_IN_SECONDS);
        return true;
    }
    
    if ($attempts >= 3) { // Max 3 unsubscribe attempts per hour
        return false;
    }
    
    set_transient($transient_key, $attempts + 1, HOUR_IN_SECONDS);
    return true;
}