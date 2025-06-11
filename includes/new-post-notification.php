<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('transition_post_status', 'wns_send_new_post_notifications', 10, 3);

function wns_send_new_post_notifications($new_status, $old_status, $post) {
    // Only trigger for posts (not pages or other post types)
    if ($post->post_type !== 'post') return;
    
    // Only send notification when transitioning FROM non-published TO published
    // This prevents notifications on updates to already published posts
    if ($new_status !== 'publish' || $old_status === 'publish') return;
    
    // Skip autosaves and revisions
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post->ID)) return;

    $enabled = get_option('wns_enable_new_post_notification', false);
    if (!$enabled) return;

    // Double-check: only send if this is truly a new publication
    $already_notified = get_post_meta($post->ID, '_wns_notification_sent', true);
    if ($already_notified) {
        return; // Don't send duplicate notifications
    }

    // Mark this post as notified to prevent duplicates
    update_post_meta($post->ID, '_wns_notification_sent', true);

    // Schedule sending of emails via cron
    if (!wp_next_scheduled('wns_cron_send_post_notification', array($post->ID))) {
        wp_schedule_single_event(time(), 'wns_cron_send_post_notification', array($post->ID));
    }
}

add_action('wns_cron_send_post_notification', 'wns_cron_handler_send_post_notification', 10, 1);

function wns_cron_handler_send_post_notification($post_id) {
    global $wpdb;

    $post = get_post($post_id);
    if (!$post || $post->post_status !== 'publish') return;

    // Ensure tables exist before processing
    wns_ensure_tables_exist();

    $table_name = WNS_TABLE_SUBSCRIBERS;
    
    // Check if table exists before querying with prepared statement
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table_name)) != $table_name) {
        return; // Table doesn't exist, skip processing
    }

    $subscribers = $wpdb->get_results($wpdb->prepare("SELECT email FROM `$table_name` WHERE verified = %d", 1));

    if (!$subscribers || $wpdb->last_error) {
        if ($wpdb->last_error) {
            error_log('WNS Plugin Error in new post notification: ' . $wpdb->last_error);
        }
        return;
    }

    $subject = str_replace('{post_title}', sanitize_text_field($post->post_title), get_option('wns_template_new_post_subject'));
    $excerpt = has_excerpt($post_id) ? sanitize_text_field($post->post_excerpt) : sanitize_text_field(wp_trim_words($post->post_content, 50));
    $body = str_replace(
        array('{post_title}', '{post_excerpt}', '{post_url}'),
        array(sanitize_text_field($post->post_title), $excerpt, esc_url(get_permalink($post_id))),
        get_option('wns_template_new_post_body')
    );

    $headers = array('Content-Type: text/html; charset=UTF-8');

    $send_after = current_time('mysql');
    $queue_table = $wpdb->prefix . 'newsletter_email_queue';

    // Check if queue table exists before inserting with prepared statement
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) != $queue_table) {
        return; // Table doesn't exist, skip processing
    }

    foreach ($subscribers as $subscriber) {
        // Validate email before adding to queue
        if (!is_email($subscriber->email)) {
            continue;
        }

        $wpdb->insert($queue_table, array(
            'recipient' => sanitize_email($subscriber->email),
            'subject'   => sanitize_text_field($subject),
            'body'      => wp_kses_post($body),
            'headers'   => maybe_serialize($headers),
            'send_at'   => $send_after,
            'sent'      => 0
        ), array('%s', '%s', '%s', '%s', '%s', '%d'));
    }
}