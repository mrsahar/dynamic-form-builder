<?php
if (!defined('ABSPATH')) exit;

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

    $response = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dfb_responses WHERE id = %d",
        $response_id
    ));
    if (!$response) {
        return '';
    }

    if (!empty($response->document_path) && file_exists($response->document_path)) {
        return $response->document_path;
    }

    if (!dfb_load_dompdf_autoload()) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DFB: dompdf not available, cannot generate PDF for response ' . intval($response_id));
        }
        return '';
    }

    $template = $wpdb->get_row($wpdb->prepare(
        "SELECT template_content FROM {$wpdb->prefix}dfb_templates WHERE form_id = %d",
        intval($response->form_id)
    ));
    if (!$template) {
        return '';
    }

    $html_content = dfb_render_document_template($template->template_content, $response);
    if ($html_content === '') {
        return '';
    }

    $upload = wp_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'dfb-documents';
    if (!file_exists($dir)) {
        wp_mkdir_p($dir);
    }

    $filename = 'dfb-form-' . intval($response->form_id) . '-response-' . intval($response->id) . '.pdf';
    $filepath = trailingslashit($dir) . $filename;

    try {
        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html_content);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        file_put_contents($filepath, $dompdf->output());
    } catch (\Throwable $e) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DFB: PDF generation exception for response ' . intval($response_id) . ' => ' . $e->getMessage());
        }
        return '';
    }

    if (defined('WP_DEBUG') && WP_DEBUG && !file_exists($filepath)) {
        error_log('DFB: PDF file write failed: ' . $filepath);
    }

    $wpdb->update(
        $wpdb->prefix . 'dfb_responses',
        ['document_path' => $filepath],
        ['id' => $response_id]
    );

    return $filepath;
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
        $order->save_meta_data();
        return;
    }

    $to = $order->get_billing_email();
    if (empty($to)) {
        return;
    }

    // Allow site owners to customize subject/body via settings, with safe defaults.
    $subject = (string) get_option('dfb_email_subject', 'Your generated document');
    $message = (string) get_option('dfb_email_body', 'Thank you for your order. Your generated document is attached.');
    $headers = ['Content-Type: text/html; charset=UTF-8'];

    $sent = wp_mail($to, $subject, $message, $headers, [$path]);
    if ($sent) {
        $order->update_meta_data('_dfb_document_emailed', 1);
        $order->delete_meta_data('_dfb_document_error');
        $order->save_meta_data();
    } else {
        $order->update_meta_data('_dfb_document_error', 'email_send_failed');
        $order->save_meta_data();
    }
}

// Trigger after payment completion (works with gateways like Stripe).
add_action('woocommerce_payment_complete', 'dfb_send_pdf_on_order_completed');
// Fallback for gateways/orders that remain in processing after payment.
add_action('woocommerce_order_status_processing', 'dfb_send_pdf_on_order_completed');
// Last-resort fallback once thank you page is reached.
add_action('woocommerce_thankyou', 'dfb_send_pdf_on_order_completed', 20);
