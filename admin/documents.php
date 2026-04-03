<?php
if (!defined('ABSPATH')) exit;

/**
 * Delete generated PDF documents older than N months.
 *
 * Runs from the Documents admin page on load.
 *
 * @param int $months
 * @return array{deleted:int, cleared:int, cutoff:int} Summary.
 */
function dfb_cleanup_old_documents_on_view($months = 3) {
    if (!current_user_can('manage_options')) {
        return ['deleted' => 0, 'cleared' => 0, 'cutoff' => 0];
    }

    $months = (int) $months;
    if ($months <= 0) {
        $months = 3;
    }

    $cutoff_ts = (new DateTimeImmutable('now', wp_timezone()))
        ->modify('-' . $months . ' months')
        ->getTimestamp();

    $upload = wp_upload_dir();
    $base_dir = isset($upload['basedir']) ? (string) $upload['basedir'] : '';
    if ($base_dir === '') {
        return ['deleted' => 0, 'cleared' => 0, 'cutoff' => $cutoff_ts];
    }

    $docs_dir = trailingslashit($base_dir) . 'dfb-documents';
    if (!is_dir($docs_dir)) {
        return ['deleted' => 0, 'cleared' => 0, 'cutoff' => $cutoff_ts];
    }

    $deleted = 0;
    $deleted_paths = [];

    $pattern = trailingslashit($docs_dir) . '*.pdf';
    $files = glob($pattern);
    if (is_array($files)) {
        foreach ($files as $file) {
            $file = (string) $file;
            if ($file === '' || !is_file($file)) {
                continue;
            }

            // Only delete our generated PDFs (avoid nuking unrelated PDFs if user placed any).
            $basename = basename($file);
            if (!preg_match('/^dfb-form-\\d+-response-\\d+\\.pdf$/', $basename)) {
                continue;
            }

            $mtime = @filemtime($file);
            if (!$mtime || $mtime >= $cutoff_ts) {
                continue;
            }

            if (function_exists('wp_delete_file')) {
                wp_delete_file($file);
            } else {
                @unlink($file);
            }

            if (!file_exists($file)) {
                $deleted++;
                $deleted_paths[] = wp_normalize_path($file);
            }
        }
    }

    // Clear document_path for deleted files so Documents list won't show broken links.
    $cleared = 0;
    if ($deleted_paths) {
        global $wpdb;
        $table = $wpdb->prefix . 'dfb_responses';
        foreach ($deleted_paths as $p) {
            $wpdb->update(
                $table,
                ['document_path' => null],
                ['document_path' => $p],
                ['%s'],
                ['%s']
            );
            $cleared += (int) $wpdb->rows_affected;
        }
    }

    return ['deleted' => $deleted, 'cleared' => $cleared, 'cutoff' => $cutoff_ts];
}

function dfb_documents_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    if (isset($_GET['dfb_deleted']) && (string) $_GET['dfb_deleted'] === '1') {
        echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Response and PDF file deleted.', 'dynamic-form-builder') . '</p></div>';
    }
    if (isset($_GET['dfb_err'])) {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Could not delete that document.', 'dynamic-form-builder') . '</p></div>';
    }

    $cleanup = dfb_cleanup_old_documents_on_view(3);

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
        <h1><?php esc_html_e('Generated Documents', 'dynamic-form-builder'); ?></h1>
        <p class="description">
            <?php esc_html_e('If a row shows “PDF emailed” but the customer did not receive mail, your server often accepts wp_mail without delivering. Install WP Mail SMTP (or similar) and test. Status reflects WordPress mail success, not inbox delivery.', 'dynamic-form-builder'); ?>
        </p>

        <?php if (!empty($cleanup['deleted'])): ?>
            <div class="notice notice-info is-dismissible">
                <p>
                    <?php
                    echo esc_html(
                        sprintf(
                            'Cleaned up %d document(s) older than 3 months.',
                            (int) $cleanup['deleted']
                        )
                    );
                    ?>
                </p>
            </div>
        <?php endif; ?>

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
                    <th><?php esc_html_e('Actions', 'dynamic-form-builder'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr>
                        <td colspan="8"><?php esc_html_e('No form responses found yet.', 'dynamic-form-builder'); ?></td>
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
                                $doc_error_detail = (string) $order->get_meta('_dfb_document_error_detail');
                                $emailed = intval($order->get_meta('_dfb_document_emailed'));

                                if ($emailed === 1) {
                                    $order_status_text = __('PDF emailed (wp_mail OK)', 'dynamic-form-builder');
                                } elseif ($doc_error !== '') {
                                    $order_status_text = 'Error: ' . $doc_error;
                                    if ($doc_error_detail !== '') {
                                        $order_status_text .= ' — ' . $doc_error_detail;
                                    }
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
                            <td>
                                <?php
                                $del_url = wp_nonce_url(
                                    admin_url('admin-post.php?action=dfb_delete_document&response_id=' . intval($row->id)),
                                    'dfb_delete_document_' . intval($row->id)
                                );
                                ?>
                                <a href="<?php echo esc_url($del_url); ?>"
                                   class="button button-small"
                                   onclick="return confirm('<?php echo esc_js(__('Delete this response and its PDF file permanently?', 'dynamic-form-builder')); ?>');">
                                    <?php esc_html_e('Delete', 'dynamic-form-builder'); ?>
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

add_action('admin_post_dfb_delete_document', 'dfb_handle_admin_delete_document');
function dfb_handle_admin_delete_document() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized access.', 'dynamic-form-builder'), '', ['response' => 403]);
    }

    $response_id = isset($_GET['response_id']) ? intval($_GET['response_id']) : 0;
    if ($response_id <= 0 || !isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'dfb_delete_document_' . $response_id)) {
        wp_safe_redirect(admin_url('admin.php?page=dfb-documents&dfb_err=1'));
        exit;
    }

    global $wpdb;
    $table = $wpdb->prefix . 'dfb_responses';
    $row   = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $response_id));
    if (!$row) {
        wp_safe_redirect(admin_url('admin.php?page=dfb-documents&dfb_err=1'));
        exit;
    }

    $doc_path = isset($row->document_path) ? (string) $row->document_path : '';
    if ($doc_path !== '') {
        $doc_path = wp_normalize_path($doc_path);
        if (file_exists($doc_path) && is_file($doc_path)) {
            if (function_exists('wp_delete_file')) {
                wp_delete_file($doc_path);
            } else {
                @unlink($doc_path);
            }
        }
    }

    $order_id = isset($row->order_id) ? intval($row->order_id) : 0;
    if ($order_id > 0 && function_exists('wc_get_order')) {
        $order = wc_get_order($order_id);
        if ($order) {
            $order->delete_meta_data('_dfb_document_emailed');
            $order->delete_meta_data('_dfb_document_error');
            $order->delete_meta_data('_dfb_document_error_detail');
            $order->delete_meta_data('_dfb_pdf_email_sent_at');
            if (intval($order->get_meta('_dfb_response_id')) === $response_id) {
                $order->delete_meta_data('_dfb_response_id');
            }
            $order->save_meta_data();
        }
    }

    $wpdb->delete($table, ['id' => $response_id], ['%d']);

    wp_safe_redirect(admin_url('admin.php?page=dfb-documents&dfb_deleted=1'));
    exit;
}
