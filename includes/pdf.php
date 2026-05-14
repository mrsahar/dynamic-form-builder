<?php
if (!defined('ABSPATH')) exit;

/**
 * Writable cache/temp under uploads so Dompdf can write font metrics on hosts
 * where the plugin/vendor directory is not writable.
 *
 * @return string Absolute directory path (no trailing slash).
 */
function dfb_dompdf_local_work_dir() {
    $upload = wp_upload_dir();
    $base   = isset($upload['basedir']) ? (string) $upload['basedir'] : '';
    if ($base === '') {
        return '';
    }
    $dir = trailingslashit($base) . 'dfb-dompdf-cache';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    return is_dir($dir) ? $dir : '';
}

/**
 * @return \Dompdf\Options|null
 */
function dfb_create_dompdf_options() {
    if (!class_exists('\Dompdf\Options')) {
        return null;
    }

    $work = dfb_dompdf_local_work_dir();
    $vendor_fonts = DFB_PLUGIN_DIR . 'vendor/dompdf/dompdf/lib/fonts';
    if (!is_dir($vendor_fonts)) {
        return null;
    }

    $chroot = [ABSPATH, WP_CONTENT_DIR];
    if ($work !== '') {
        $chroot[] = $work;
    }
    $chroot[] = $vendor_fonts;
    $tmp = function_exists('sys_get_temp_dir') ? sys_get_temp_dir() : '';
    if (is_string($tmp) && $tmp !== '' && is_dir($tmp)) {
        $chroot[] = wp_normalize_path($tmp);
    }

    $opts = new \Dompdf\Options();
    $opts->set('isRemoteEnabled', true);
    $opts->set('isHtml5ParserEnabled', true);
    $opts->set('defaultFont', 'DejaVu Sans');
    $opts->setChroot($chroot);

    // Read bundled fonts from vendor; write metrics/cache under uploads.
    $opts->setFontDir($vendor_fonts);
    if ($work !== '') {
        $opts->setFontCache($work);
        $opts->setTempDir($work);
    }

    return $opts;
}

/**
 * Dompdf's CPDF backend uses PHP GD to decode/embed raster images (PNG/JPEG/GIF/WebP).
 * Without GD, any &lt;img&gt; (including data: URIs for the header logo) triggers a fatal error.
 * When GD is unavailable, strip image-related markup so PDFs still generate (text-only).
 *
 * @param string $html Full HTML passed to Dompdf.
 * @return string
 */
function dfb_pdf_prepare_html_for_dompdf($html) {
    $html = (string) $html;
    if (function_exists('imagecreatetruecolor') && function_exists('imagepng')) {
        return $html;
    }

    $html = preg_replace('/<img\b[^>]*>/i', '', $html);
    $html = preg_replace('/<svg\b[^>]*>.*?<\/svg>/is', '', $html);
    $html = preg_replace('/background-image\s*:\s*[^;]+;?/i', '', $html);

    return apply_filters('dfb_pdf_html_without_gd', $html);
}

function dfb_load_dompdf_autoload() {
    if (class_exists('\Dompdf\Dompdf')) {
        return true;
    }

    $autoload = DFB_PLUGIN_DIR . 'vendor/autoload.php';
    if (!file_exists($autoload)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DFB: dompdf autoload missing: ' . $autoload);
        }
        return false;
    }

    require_once $autoload;
    return class_exists('\Dompdf\Dompdf');
}

function dfb_generate_pdf_for_response($response_id) {
    global $wpdb;

    $GLOBALS['dfb_last_pdf_error'] = '';

    $response = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dfb_responses WHERE id = %d",
        $response_id
    ));
    if (!$response) {
        $GLOBALS['dfb_last_pdf_error'] = 'response_not_found';
        return '';
    }

    if (!empty($response->document_path) && file_exists($response->document_path)) {
        @unlink($response->document_path);
        $wpdb->update(
            $wpdb->prefix . 'dfb_responses',
            ['document_path' => ''],
            ['id' => $response_id]
        );
    }

    if (!dfb_load_dompdf_autoload()) {
        $GLOBALS['dfb_last_pdf_error'] = 'dompdf_missing';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DFB: dompdf not available, cannot generate PDF for response ' . intval($response_id));
        }
        return '';
    }

    $template = $wpdb->get_row($wpdb->prepare(
        "SELECT template_content FROM {$wpdb->prefix}dfb_templates WHERE form_id = %d",
        intval($response->form_id)
    ));
    // Allow PDF generation even if no template row exists (header + answers still render).
    $template_content = $template ? (string) $template->template_content : '';

    $html_content = dfb_render_document_template($template_content, $response);
    if ($html_content === '') {
        $GLOBALS['dfb_last_pdf_error'] = 'empty_html';
        return '';
    }

    $html_content = dfb_pdf_prepare_html_for_dompdf($html_content);

    $upload = wp_upload_dir();
    if (!empty($upload['error'])) {
        $GLOBALS['dfb_last_pdf_error'] = 'upload_dir_error';
        return '';
    }

    $dir = trailingslashit($upload['basedir']) . 'dfb-documents';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }
    if (!is_dir($dir) || !is_writable($dir)) {
        $GLOBALS['dfb_last_pdf_error'] = 'documents_dir_not_writable';
        return '';
    }

    $filename = 'dfb-form-' . intval($response->form_id) . '-response-' . intval($response->id) . '.pdf';
    $filepath = trailingslashit($dir) . $filename;

    $dompdf_opts = dfb_create_dompdf_options();
    if ($dompdf_opts === null) {
        $GLOBALS['dfb_last_pdf_error'] = 'dompdf_options_failed';
        return '';
    }

    try {
        $dompdf = new \Dompdf\Dompdf($dompdf_opts);
        $dompdf->loadHtml($html_content, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf_binary = $dompdf->output();
        if (!is_string($pdf_binary) || $pdf_binary === '') {
            $GLOBALS['dfb_last_pdf_error'] = 'empty_pdf_output';
            return '';
        }

        $written = @file_put_contents($filepath, $pdf_binary, LOCK_EX);
        if ($written === false || $written < 1) {
            $GLOBALS['dfb_last_pdf_error'] = 'file_write_failed';
            return '';
        }
    } catch (\Throwable $e) {
        $msg = preg_replace('/\s+/', ' ', (string) $e->getMessage());
        $GLOBALS['dfb_last_pdf_error'] = 'exception:' . substr($msg, 0, 120);
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DFB: PDF generation exception for response ' . intval($response_id) . ' => ' . $e->getMessage());
        }
        return '';
    }

    if (!file_exists($filepath) || filesize($filepath) < 1) {
        $GLOBALS['dfb_last_pdf_error'] = 'pdf_file_missing';
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DFB: PDF file write failed: ' . $filepath);
        }
        return '';
    }

    $wpdb->update(
        $wpdb->prefix . 'dfb_responses',
        ['document_path' => $filepath],
        ['id' => $response_id]
    );

    if (!empty($wpdb->last_error)) {
        $GLOBALS['dfb_last_pdf_error'] = 'db_update_failed';
        return '';
    }

    return $filepath;
}

add_action('wp_ajax_dfb_preview_template_pdf', 'dfb_ajax_preview_template_pdf');
function dfb_ajax_preview_template_pdf() {
    if (!current_user_can('manage_options')) {
        wp_die(esc_html__('Unauthorized access.', 'dynamic-form-builder'), '', ['response' => 403]);
    }

    if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'dfb_nonce')) {
        wp_die(esc_html__('Invalid request.', 'dynamic-form-builder'), '', ['response' => 400]);
    }

    $template_content = isset($_POST['template_content']) ? (string) wp_unslash($_POST['template_content']) : '';
    $answers_json     = isset($_POST['answers_json']) ? (string) wp_unslash($_POST['answers_json']) : '';

    if (!dfb_load_dompdf_autoload()) {
        wp_die(esc_html__('PDF generator is not available (dompdf missing).', 'dynamic-form-builder'), '', ['response' => 500]);
    }

    $answers = json_decode($answers_json, true);
    if (!is_array($answers)) {
        $answers = [];
    }

    $response_row = (object) [
        'id'         => 0,
        'form_id'    => 0,
        'user_name'  => 'Preview User',
        'user_email' => 'preview@example.com',
        'answers'    => wp_json_encode($answers),
    ];

    $html_content = dfb_render_document_template($template_content, $response_row);
    if ($html_content === '') {
        wp_die(esc_html__('Preview failed: empty HTML.', 'dynamic-form-builder'), '', ['response' => 500]);
    }

    $html_content = dfb_pdf_prepare_html_for_dompdf($html_content);

    $dompdf_opts = dfb_create_dompdf_options();
    if ($dompdf_opts === null) {
        wp_die(esc_html__('Preview failed: dompdf options could not be created.', 'dynamic-form-builder'), '', ['response' => 500]);
    }

    try {
        $dompdf = new \Dompdf\Dompdf($dompdf_opts);
        $dompdf->loadHtml($html_content, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf_binary = $dompdf->output();
    } catch (\Throwable $e) {
        $msg = preg_replace('/\s+/', ' ', (string) $e->getMessage());
        wp_die(esc_html__('Preview failed: ', 'dynamic-form-builder') . esc_html(substr($msg, 0, 160)), '', ['response' => 500]);
    }

    if (!is_string($pdf_binary) || $pdf_binary === '') {
        wp_die(esc_html__('Preview failed: empty PDF output.', 'dynamic-form-builder'), '', ['response' => 500]);
    }

    nocache_headers();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="dfb-template-preview.pdf"');
    header('Content-Length: ' . (string) strlen($pdf_binary));
    echo $pdf_binary;
    exit;
}

add_action('woocommerce_order_status_completed', 'dfb_send_pdf_on_order_completed');
function dfb_send_pdf_on_order_completed($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    // Stripe commonly leaves orders in `processing` for paid one-time products.
    // Only send once Woo confirms payment is complete.
    if (method_exists($order, 'is_paid') && !$order->is_paid()) {
        return;
    }

    if ($order->get_meta('_dfb_document_emailed')) {
        return;
    }

    $response_id = intval($order->get_meta('_dfb_response_id'));
    if ($response_id <= 0) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DFB: missing _dfb_response_id for order ' . intval($order_id));
        }
        return;
    }

    $path = dfb_generate_pdf_for_response($response_id);
    if (empty($path) || !file_exists($path)) {
        $order->update_meta_data('_dfb_document_error', 'pdf_generation_failed');
        $detail = isset($GLOBALS['dfb_last_pdf_error']) ? (string) $GLOBALS['dfb_last_pdf_error'] : '';
        if ($detail !== '') {
            $order->update_meta_data('_dfb_document_error_detail', substr($detail, 0, 200));
        } else {
            $order->delete_meta_data('_dfb_document_error_detail');
        }
        $order->save_meta_data();
        return;
    }

    $to = $order->get_billing_email();
    if (empty($to)) {
        return;
    }
    // Persist recipient email so Generated Documents can display the real "sent to" address.
    $order->update_meta_data('_dfb_pdf_email_to', sanitize_email((string) $to));
    $order->save_meta_data();

    $attachment_path = wp_normalize_path($path);
    if (!is_readable($attachment_path)) {
        $order->update_meta_data('_dfb_document_error', 'pdf_attachment_unreadable');
        $order->save_meta_data();
        return;
    }

    // Allow site owners to customize subject/body via settings, with safe defaults.
    $subject = (string) get_option('dfb_email_subject', __('Your generated document', 'dynamic-form-builder'));
    $message = (string) get_option('dfb_email_body', __('Thank you for your order. Your generated document is attached.', 'dynamic-form-builder'));
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    // Prefer WooCommerce “From” (same as order emails) so deliverability matches store mail.
    $from_email = '';
    $from_name  = '';
    $wc_from    = (string) get_option('woocommerce_email_from_address', '');
    $wc_name    = (string) get_option('woocommerce_email_from_name', '');
    if ($wc_from !== '' && is_email($wc_from)) {
        $from_email = sanitize_email($wc_from);
        $from_name  = $wc_name !== ''
            ? wp_specialchars_decode($wc_name, ENT_QUOTES)
            : wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    }
    if ($from_email === '') {
        $from_email = sanitize_email((string) get_option('admin_email', ''));
        $from_name  = wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES);
    }
    if ($from_name === '') {
        $from_name = 'WordPress';
    }
    if ($from_email !== '') {
        $headers[] = sprintf('From: %s <%s>', $from_name, $from_email);
        $headers[] = 'Reply-To: ' . $from_email;
    }

    $headers = apply_filters('dfb_pdf_email_headers', $headers, $order, $attachment_path);

    $mail_error_msg = '';
    $on_mail_failed = static function ($wp_error) use (&$mail_error_msg) {
        if ($wp_error instanceof \WP_Error) {
            $mail_error_msg = $wp_error->get_error_message();
        }
    };
    add_action('wp_mail_failed', $on_mail_failed);

    $sent = wp_mail($to, $subject, $message, $headers, [$attachment_path]);

    remove_action('wp_mail_failed', $on_mail_failed);

    if ($mail_error_msg !== '') {
        $sent = false;
    }

    /**
     * Allow hosts/plugins to treat wp_mail "success" as failure (some servers lie; use SMTP plugins).
     *
     * @param bool     $trust Default true when wp_mail returned true and PHPMailer did not fire wp_mail_failed.
     * @param WC_Order $order
     */
    $trust_result = apply_filters('dfb_trust_wp_mail_for_pdf', true, $order);
    if ($sent && ! $trust_result) {
        $sent = false;
        if ($mail_error_msg === '') {
            $mail_error_msg = 'dfb_trust_wp_mail_for_pdf returned false';
        }
    }

    if ($sent) {
        $order->update_meta_data('_dfb_document_emailed', 1);
        $order->update_meta_data('_dfb_pdf_email_sent_at', current_time('mysql'));
        $order->delete_meta_data('_dfb_document_error');
        $order->delete_meta_data('_dfb_document_error_detail');
        $order->save_meta_data();
        if (method_exists($order, 'add_order_note')) {
            $order->add_order_note(
                sprintf(
                    /* translators: %s: recipient email address */
                    __('DFB: PDF emailed to %s.', 'dynamic-form-builder'),
                    $to
                ),
                false,
                true
            );
        }
    } else {
        $order->update_meta_data('_dfb_document_error', 'email_send_failed');
        if ($mail_error_msg !== '') {
            $order->update_meta_data('_dfb_document_error_detail', substr($mail_error_msg, 0, 200));
        }
        $order->save_meta_data();
        if (method_exists($order, 'add_order_note')) {
            $note = __('DFB: PDF email was not sent. On many hosts you must configure SMTP (e.g. WP Mail SMTP). Check wp_mail / PHPMailer logs.', 'dynamic-form-builder');
            if ($mail_error_msg !== '') {
                $note .= ' ' . $mail_error_msg;
            }
            $order->add_order_note($note, false, true);
        }
    }
}

// Trigger after payment completion (works with gateways like Stripe).
add_action('woocommerce_payment_complete', 'dfb_send_pdf_on_order_completed');
// Fallback for gateways/orders that remain in processing after payment.
add_action('woocommerce_order_status_processing', 'dfb_send_pdf_on_order_completed');
// Last-resort fallback once thank you page is reached.
add_action('woocommerce_thankyou', 'dfb_send_pdf_on_order_completed', 20);
