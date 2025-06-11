<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('admin_init', 'wns_handle_broadcast_submission');

function wns_handle_broadcast_submission() {
    // Security checks
    if (!current_user_can('manage_options')) {
        return;
    }

    if (!isset($_POST['wns_send_newsletter']) || !check_admin_referer('wns_send_newsletter')) {
        return;
    }

    $subject = sanitize_text_field($_POST['wns_email_subject']);
    $body = wp_kses_post($_POST['wns_email_body']);
    $send_now = isset($_POST['wns_send_now']) ? true : false;

    if (empty($subject) || empty($body)) {
        add_settings_error('wns_broadcast_messages', 'error', __('Subject and body are required.', 'wp-newsletter-subscription'), 'error');
        return;
    }

    // Additional validation
    if (strlen($subject) > 255) {
        add_settings_error('wns_broadcast_messages', 'error', __('Subject line is too long (maximum 255 characters).', 'wp-newsletter-subscription'), 'error');
        return;
    }

    global $wpdb;
    $subscriber_table = WNS_TABLE_SUBSCRIBERS;
    $queue_table = $wpdb->prefix . 'newsletter_email_queue';

    // Verify tables exist with prepared statements
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $subscriber_table)) != $subscriber_table ||
        $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) != $queue_table) {
        add_settings_error('wns_broadcast_messages', 'error', __('Database tables are missing. Please contact administrator.', 'wp-newsletter-subscription'), 'error');
        return;
    }

    $headers = array('Content-Type: text/html; charset=UTF-8');
    $send_at = $send_now ? current_time('mysql') : date('Y-m-d H:i:s', strtotime('+1 minute'));

    $subscribers = $wpdb->get_results($wpdb->prepare("SELECT email FROM `$subscriber_table` WHERE verified = %d", 1));

    if ($wpdb->last_error) {
        error_log('WNS Plugin Error in broadcast: ' . $wpdb->last_error);
        add_settings_error('wns_broadcast_messages', 'error', __('Database error occurred. Please try again.', 'wp-newsletter-subscription'), 'error');
        return;
    }

    if (empty($subscribers)) {
        add_settings_error('wns_broadcast_messages', 'error', __('No verified subscribers found.', 'wp-newsletter-subscription'), 'error');
        return;
    }

    $success_count = 0;
    foreach ($subscribers as $subscriber) {
        // Validate email before adding to queue
        if (!is_email($subscriber->email)) {
            continue;
        }

        $result = $wpdb->insert($queue_table, array(
            'recipient' => sanitize_email($subscriber->email),
            'subject'   => $subject,
            'body'      => $body,
            'headers'   => maybe_serialize($headers),
            'send_at'   => $send_at,
            'sent'      => 0
        ), array('%s', '%s', '%s', '%s', '%s', '%d'));

        if ($result) {
            $success_count++;
        }
    }

    if ($success_count > 0) {
        $message = sprintf(
            _n('%d email added to queue.', '%d emails added to queue.', $success_count, 'wp-newsletter-subscription'), 
            $success_count
        );
        add_settings_error('wns_broadcast_messages', 'success', $message, 'success');
    } else {
        add_settings_error('wns_broadcast_messages', 'error', __('Failed to add emails to queue.', 'wp-newsletter-subscription'), 'error');
    }
}