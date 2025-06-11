<?php
if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'wns_setup_batch_email_cron');

function wns_setup_batch_email_cron() {
    if (!wp_next_scheduled('wns_cron_process_email_queue')) {
        wp_schedule_event(time(), 'every_minute', 'wns_cron_process_email_queue');
    }
}

// Register custom cron interval
add_filter('cron_schedules', 'wns_add_custom_cron_intervals');

function wns_add_custom_cron_intervals($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display' => __('Once Every Minute')
    );
    return $schedules;
}

add_action('wns_cron_process_email_queue', 'wns_process_email_queue');

function wns_process_email_queue() {
    global $wpdb;

    // Ensure tables exist before processing
    wns_ensure_tables_exist();

    $batch_size = get_option('wns_email_batch_size', 100);
    $now = current_time('timestamp');
    $queue_table = $wpdb->prefix . 'newsletter_email_queue';

    // Check if table exists before querying with prepared statement
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) != $queue_table) {
        return; // Table doesn't exist, skip processing
    }

    // Get all pending emails with prepared statement
    $emails = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT * FROM `$queue_table`
             WHERE send_at <= %s AND sent = %d
             ORDER BY id ASC LIMIT %d",
            date('Y-m-d H:i:s', $now),
            0,
            $batch_size
        )
    );

    if (!$emails || $wpdb->last_error) {
        if ($wpdb->last_error) {
            error_log('WNS Plugin Error in email queue processing: ' . $wpdb->last_error);
        }
        return;
    }

    foreach ($emails as $email) {
        // Validate email before sending
        if (!is_email($email->recipient)) {
            // Mark invalid emails as sent to prevent retry
            $wpdb->update(
                $queue_table,
                array('sent' => 1, 'sent_at' => current_time('mysql')),
                array('id' => $email->id),
                array('%d', '%s'),
                array('%d')
            );
            continue;
        }

        $headers = maybe_unserialize($email->headers);
        $sent = wp_mail(sanitize_email($email->recipient), sanitize_text_field($email->subject), wp_kses_post($email->body), $headers);

        // Mark as sent
        $wpdb->update(
            $queue_table,
            array('sent' => 1, 'sent_at' => current_time('mysql')),
            array('id' => $email->id),
            array('%d', '%s'),
            array('%d')
        );
    }
}