<?php
if (!defined('ABSPATH')) exit;

function dfb_documents_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    global $wpdb;
    $table_responses = $wpdb->prefix . 'dfb_responses';
    $table_forms = $wpdb->prefix . 'dfb_forms';

    $rows = $wpdb->get_results(
        "SELECT r.id, r.form_id, r.user_email, r.document_path, r.order_id, r.created_at, f.form_name
         FROM {$table_responses} r
         LEFT JOIN {$table_forms} f ON f.id = r.form_id
         ORDER BY r.created_at DESC"
    );
    ?>
    <div class="wrap">
        <h1>Generated Documents</h1>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Form</th>
                    <th>User Email</th>
                    <th>Order</th>
                    <th>Generated At</th>
                    <th>Document</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="7">No form responses found yet.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                        $path = wp_normalize_path((string) $row->document_path);
                        $is_absolute = preg_match('/^[A-Za-z]:[\\\\\\/]|^\//', $path);
                        $full_path = $is_absolute ? $path : wp_normalize_path(WP_CONTENT_DIR . '/' . ltrim($path, '/'));
                        $upload_info = wp_upload_dir();
                        $upload_base_dir = wp_normalize_path($upload_info['basedir']);
                        $upload_base_url = $upload_info['baseurl'];
                        $download_url = '';
                        $order_status_text = 'No order';
                        $order_id = intval($row->order_id);

                        if ($order_id > 0) {
                            $order = wc_get_order($order_id);
                            if ($order) {
                                $status_label = wc_get_order_status_name($order->get_status());
                                $doc_error = (string) $order->get_meta('_dfb_document_error');
                                $emailed = intval($order->get_meta('_dfb_document_emailed'));

                                if ($emailed === 1) {
                                    $order_status_text = 'PDF emailed';
                                } elseif ($doc_error !== '') {
                                    $order_status_text = 'Error: ' . $doc_error;
                                } else {
                                    $order_status_text = 'Order: ' . $status_label;
                                }
                            } else {
                                $order_status_text = 'Order not found';
                            }
                        }

                        if (strpos($full_path, $upload_base_dir) === 0) {
                            $relative = ltrim(str_replace($upload_base_dir, '', $full_path), '/');
                            $download_url = trailingslashit($upload_base_url) . str_replace('\\', '/', $relative);
                        }
                        ?>
                        <tr>
                            <td><?php echo intval($row->id); ?></td>
                            <td><?php echo esc_html($row->form_name ? $row->form_name : 'N/A'); ?></td>
                            <td><?php echo esc_html((string) $row->user_email); ?></td>
                            <td><?php echo intval($row->order_id) > 0 ? '#' . intval($row->order_id) : 'Not linked yet'; ?></td>
                            <td><?php echo esc_html(date('M d, Y H:i', strtotime((string) $row->created_at))); ?></td>
                            <td>
                                <?php if (!empty($download_url)): ?>
                                    <a href="<?php echo esc_url($download_url); ?>" target="_blank" rel="noopener noreferrer">Download PDF</a>
                                <?php else: ?>
                                    <span>Pending generation</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($order_status_text); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}
