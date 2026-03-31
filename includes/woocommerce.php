<?php
if (!defined('ABSPATH')) exit;

// ============================================================
// Helper: get DFB form row linked to a WooCommerce product ID
// ============================================================
function dfb_get_form_by_product_id($product_id) {
    global $wpdb;
    return $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}dfb_forms WHERE woo_product_id = %d AND is_active = 1",
        intval($product_id)
    ));
}

// Helper: find the published page URL that contains [dfb_form id="X"]
function dfb_get_form_page_url($form_id) {
    global $wpdb;
    $form_id = intval($form_id);

    // Fast path: exact shortcode pattern.
    $shortcode = '[dfb_form id="' . $form_id . '"]';
    $page_id = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM {$wpdb->posts}
         WHERE post_status = 'publish'
           AND post_content LIKE %s
         LIMIT 1",
        '%' . $wpdb->esc_like($shortcode) . '%'
    ));
    if ($page_id) {
        return get_permalink(intval($page_id));
    }

    // Fallback: handle shortcode variants (single quotes, spaces, attribute order).
    $candidate_pages = $wpdb->get_results(
        "SELECT ID, post_content FROM {$wpdb->posts}
         WHERE post_status = 'publish'
           AND post_content LIKE '%[dfb_form%'"
    );

    if (!empty($candidate_pages)) {
        $pattern = '/\[dfb_form\b[^\]]*?\bid\s*=\s*(["\']?)' . preg_quote((string) $form_id, '/') . '\1[^\]]*\]/i';
        $fallback_page_id = 0;
        foreach ($candidate_pages as $candidate_page) {
            if ($fallback_page_id === 0 && !empty($candidate_page->ID)) {
                $fallback_page_id = intval($candidate_page->ID);
            }

            $content = isset($candidate_page->post_content) ? (string) $candidate_page->post_content : '';
            if ($content !== '' && preg_match($pattern, $content)) {
                return get_permalink(intval($candidate_page->ID));
            }
        }

        // Last-resort fallback: send user to any published DFB form page.
        if ($fallback_page_id > 0) {
            return get_permalink($fallback_page_id);
        }
    }

    return null;
}

// ============================================================
// Internal flag — lets the plugin's own add_to_cart call
// bypass the blocks below. Set true before, false after.
// ============================================================
$GLOBALS['dfb_internal_cart_operation'] = false;

/**
 * Remove stale DFB notices so checkout does not show old form warnings.
 */
function dfb_clear_form_required_notices() {
    if (!function_exists('wc_get_notices') || !function_exists('wc_set_notices')) {
        return;
    }

    $notices = wc_get_notices();
    if (!is_array($notices) || empty($notices['error']) || !is_array($notices['error'])) {
        return;
    }

    $filtered_errors = [];
    foreach ($notices['error'] as $notice) {
        $message = isset($notice['notice']) ? wp_strip_all_tags((string) $notice['notice']) : '';
        $is_dfb_form_notice = (stripos($message, 'fill out the required form') !== false);
        if (!$is_dfb_form_notice) {
            $filtered_errors[] = $notice;
        }
    }

    $notices['error'] = $filtered_errors;
    wc_set_notices($notices);
}

// ============================================================
// Core redirect function (called after form submit)
// ============================================================
function dfb_redirect_to_checkout_with_response($product_id, $response_id) {
    if (!function_exists('WC') || !WC()) {
        wp_die('WooCommerce is required.');
    }

    if (!WC()->cart) {
        wc_load_cart();
    }

    WC()->cart->empty_cart();

    // Raise flag so validation / purchasable hooks let this through.
    // Also persist a marker + response id in cart item data so cart/session restore
    // can distinguish this from direct add-to-cart attempts.
    $GLOBALS['dfb_internal_cart_operation'] = true;
    $added = WC()->cart->add_to_cart(
        intval($product_id),
        1,
        0,
        [],
        [
            'dfb_validated_submission' => 1,
            'dfb_response_id' => intval($response_id),
        ]
    );
    $GLOBALS['dfb_internal_cart_operation'] = false;

    if (!$added) {
        wp_die('Could not add product to cart.');
    }

    if (WC()->session) {
        WC()->session->set('dfb_response_id', intval($response_id));
    }

    // User has completed the form now; avoid showing stale warning on checkout.
    dfb_clear_form_required_notices();

    wp_safe_redirect(wc_get_checkout_url());
    exit;
}

// ============================================================
// (1) VALIDATION — block any direct add-to-cart attempt
//     Covers: shop/archive buttons, single product button,
//             direct ?add-to-cart=ID URLs, AJAX add-to-cart
// ============================================================
add_filter('woocommerce_add_to_cart_validation', 'dfb_block_direct_cart_add', 10, 6);
function dfb_block_direct_cart_add($valid, $product_id, $quantity = 1, $variation_id = 0, $variations = [], $cart_item_data = []) {
    if (!$valid || !empty($GLOBALS['dfb_internal_cart_operation'])) {
        return $valid;
    }

    $form = dfb_get_form_by_product_id($product_id);
    if (!$form) {
        return $valid; // not a DFB product — leave alone
    }

    // Do not block add-to-cart for DFB products.
    // Requirement is enforced at checkout via dfb_require_form_before_checkout().
    return $valid;
}

/**
 * Return required DFB form URL for current cart, or empty when not needed.
 */
function dfb_get_required_form_url_for_cart() {
    if (!function_exists('WC') || !WC() || !WC()->cart) {
        return '';
    }

    foreach (WC()->cart->get_cart() as $cart_item) {
        $product_id = !empty($cart_item['product_id']) ? intval($cart_item['product_id']) : 0;
        if ($product_id <= 0) {
            continue;
        }

        $form = dfb_get_form_by_product_id($product_id);
        if (!$form) {
            continue;
        }

        if (!empty($cart_item['dfb_validated_submission'])) {
            continue;
        }

        $form_url = dfb_get_form_page_url($form->id);
        return $form_url ? $form_url : '';
    }

    return '';
}

// Replace checkout links (cart + mini-cart) with required form URL when needed.
add_filter('woocommerce_get_checkout_url', 'dfb_checkout_url_to_required_form', 20);
function dfb_checkout_url_to_required_form($checkout_url) {
    $form_url = dfb_get_required_form_url_for_cart();
    return $form_url !== '' ? $form_url : $checkout_url;
}

// ============================================================
// Attach response ID to order on checkout
// ============================================================
add_action('woocommerce_checkout_create_order', 'dfb_attach_response_to_order', 10, 2);
function dfb_attach_response_to_order($order, $data) {
    dfb_link_response_to_order($order);
}

/**
 * Resolve response id from cart/session.
 */
function dfb_get_current_response_id() {
    $response_id = 0;

    // Prefer cart item data because it survives cart/session lifecycle reliably.
    if (function_exists('WC') && WC() && WC()->cart) {
        foreach (WC()->cart->get_cart() as $cart_item) {
            if (!empty($cart_item['dfb_response_id'])) {
                $response_id = intval($cart_item['dfb_response_id']);
                if ($response_id > 0) {
                    return $response_id;
                }
            }
        }
    }

    // Fallback for legacy flow.
    if (function_exists('WC') && WC() && WC()->session) {
        $response_id = intval(WC()->session->get('dfb_response_id'));
    }

    return $response_id > 0 ? $response_id : 0;
}

/**
 * Ensure order meta and response->order mapping are persisted.
 */
function dfb_link_response_to_order($order_or_id) {
    $order = is_a($order_or_id, 'WC_Order') ? $order_or_id : wc_get_order(intval($order_or_id));
    if (!$order) {
        return;
    }

    // Already linked.
    $existing = intval($order->get_meta('_dfb_response_id'));
    if ($existing > 0) {
        return;
    }

    $response_id = dfb_get_current_response_id();
    if ($response_id <= 0) {
        return;
    }

    $order->update_meta_data('_dfb_response_id', $response_id);
    $order->save();

    global $wpdb;
    $wpdb->update(
        $wpdb->prefix . 'dfb_responses',
        ['order_id' => $order->get_id()],
        ['id' => $response_id]
    );
}

// Fallback hooks: some checkout flows skip/alter checkout_create_order timing.
add_action('woocommerce_checkout_update_order_meta', 'dfb_link_response_to_order', 10, 1);
add_action('woocommerce_new_order', 'dfb_link_response_to_order', 10, 1);

// ============================================================
// Guard checkout: force form completion before checkout
// Covers both cart "Proceed to checkout" and mini-cart "Go to checkout"
// ============================================================
add_action('template_redirect', 'dfb_require_form_before_checkout', 9);
function dfb_require_form_before_checkout() {
    if (is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
        return;
    }

    if (!function_exists('is_checkout') || !is_checkout()) {
        return;
    }

    // Skip order-pay / order-received endpoints.
    if (function_exists('is_wc_endpoint_url') && (is_wc_endpoint_url('order-pay') || is_wc_endpoint_url('order-received'))) {
        return;
    }

    if (!function_exists('WC') || !WC() || !WC()->cart) {
        return;
    }

    $form_url = dfb_get_required_form_url_for_cart();
    if ($form_url !== '') {
        wc_add_notice(
            sprintf(
                'Please <a href="%s">fill out the required form</a> before proceeding to checkout.',
                esc_url($form_url)
            ),
            'error'
        );
        wp_safe_redirect($form_url);
        exit;
    }

    // Form is no longer required for current cart; cleanup old warning.
    dfb_clear_form_required_notices();
}

// ============================================================
// Cleanup response + document on failed / cancelled orders
// ============================================================
add_action('woocommerce_order_status_failed',    'dfb_cleanup_failed_or_cancelled_order');
add_action('woocommerce_order_status_cancelled', 'dfb_cleanup_failed_or_cancelled_order');
function dfb_cleanup_failed_or_cancelled_order($order_id) {
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $response_id = intval($order->get_meta('_dfb_response_id'));
    if ($response_id > 0) {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT document_path FROM {$wpdb->prefix}dfb_responses WHERE id = %d",
            $response_id
        ));

        if ($row && !empty($row->document_path) && file_exists($row->document_path)) {
            wp_delete_file($row->document_path);
        }

        $wpdb->delete($wpdb->prefix . 'dfb_responses', ['id' => $response_id]);
    }

    if (function_exists('WC') && WC() && WC()->session) {
        WC()->session->__unset('dfb_response_id');
    }
}
