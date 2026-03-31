<?php
if (!defined('ABSPATH')) exit;

add_shortcode('dfb_form', 'dfb_form_shortcode');

function dfb_form_shortcode($atts) {
    global $wpdb;

    $atts = shortcode_atts([
        'id' => 0,
    ], $atts, 'dfb_form');

    $form_id = intval($atts['id']);
    if ($form_id <= 0) {
        return '<p>Invalid form ID.</p>';
    }

    if (!is_user_logged_in()) {
        $login_url = wp_login_url(get_permalink());
        wp_safe_redirect($login_url);
        exit;
    }

    $table_forms = $wpdb->prefix . 'dfb_forms';
    $table_questions = $wpdb->prefix . 'dfb_questions';
    $form = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$table_forms} WHERE id = %d AND is_active = 1",
        $form_id
    ));

    if (!$form) {
        return '<p>This form is unavailable.</p>';
    }

    $questions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_questions} WHERE form_id = %d ORDER BY question_order ASC",
        $form_id
    ));

    if (empty($questions)) {
        return '<p>No questions configured for this form.</p>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dfb_front_submit'])) {
        return dfb_handle_frontend_form_submit($form, $questions);
    }

    wp_enqueue_style('dfb-frontend-css', DFB_PLUGIN_URL . 'assets/css/frontend.css', [], DFB_VERSION);
    wp_enqueue_script('dfb-frontend-js', DFB_PLUGIN_URL . 'assets/js/frontend.js', ['jquery'], DFB_VERSION, true);

    ob_start();
    ?>
    <div class="dfb-frontend-wrap">
        <div class="dfb-progress">
            <div class="dfb-progress-bar" id="dfb-progress-bar"></div>
        </div>
        <p class="dfb-progress-text" id="dfb-progress-text"></p>

        <form method="post" class="dfb-step-form">
            <?php wp_nonce_field('dfb_front_submit_' . $form->id, 'dfb_front_nonce'); ?>
            <input type="hidden" name="dfb_form_id" value="<?php echo intval($form->id); ?>">

            <?php foreach ($questions as $index => $question): ?>
                <div class="dfb-step" data-step="<?php echo intval($index + 1); ?>" style="display: <?php echo $index === 0 ? 'block' : 'none'; ?>;">
                    <h3><?php echo esc_html($question->question_title); ?></h3>
                    <?php if (!empty($question->question_description)): ?>
                        <p><?php echo esc_html($question->question_description); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($question->video_url)): ?>
                        <p><a href="<?php echo esc_url($question->video_url); ?>" target="_blank" rel="noopener noreferrer">Watch help video</a></p>
                    <?php endif; ?>

                    <?php if (!empty($question->image_url)): ?>
                        <p><img src="<?php echo esc_url($question->image_url); ?>" alt="" style="max-width: 100%; height: auto;"></p>
                    <?php endif; ?>

                    <?php
                    $input_name = 'answers[question_' . intval($index + 1) . ']';
                    $required = intval($question->is_required) === 1 ? 'required' : '';
                    $input_type = $question->input_type;
                    $options = array_filter(array_map('trim', explode("\n", (string) $question->input_options)));
                    ?>

                    <div class="dfb-input-wrap">
                        <?php if (in_array($input_type, ['text', 'email', 'number', 'date'], true)): ?>
                            <input type="<?php echo esc_attr($input_type); ?>" name="<?php echo esc_attr($input_name); ?>" <?php echo $required; ?>>
                        <?php elseif ($input_type === 'textarea'): ?>
                            <textarea name="<?php echo esc_attr($input_name); ?>" <?php echo $required; ?>></textarea>
                        <?php elseif ($input_type === 'dropdown'): ?>
                            <select name="<?php echo esc_attr($input_name); ?>" <?php echo $required; ?>>
                                <option value="">Select an option</option>
                                <?php foreach ($options as $option): ?>
                                    <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($input_type === 'radio'): ?>
                            <?php foreach ($options as $option): ?>
                                <label><input type="radio" name="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr($option); ?>" <?php echo $required; ?>> <?php echo esc_html($option); ?></label><br>
                            <?php endforeach; ?>
                        <?php elseif ($input_type === 'checkbox'): ?>
                            <?php foreach ($options as $option): ?>
                                <label><input type="checkbox" name="<?php echo esc_attr($input_name); ?>[]" value="<?php echo esc_attr($option); ?>" <?php echo $required; ?>> <?php echo esc_html($option); ?></label><br>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <input type="text" name="<?php echo esc_attr($input_name); ?>" <?php echo $required; ?>>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="dfb-nav-buttons">
                <button type="button" class="button" id="dfb-prev-btn" style="display:none;">Back</button>
                <button type="button" class="button button-primary" id="dfb-next-btn">Next</button>
                <button type="submit" name="dfb_front_submit" class="button button-primary" id="dfb-submit-btn" style="display:none;">Continue to Checkout</button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function dfb_handle_frontend_form_submit($form, $questions) {
    if (!isset($_POST['dfb_front_nonce']) || !wp_verify_nonce($_POST['dfb_front_nonce'], 'dfb_front_submit_' . $form->id)) {
        return '<p>Security check failed.</p>';
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        return '<p>Please login to continue.</p>';
    }

    $guard_key = 'dfb_submit_guard_' . $user_id . '_' . $form->id;
    $existing_response_id = intval(get_transient($guard_key));
    if ($existing_response_id > 0) {
        global $wpdb;
        $response_exists = intval($wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}dfb_responses WHERE id = %d",
            $existing_response_id
        )));

        if ($response_exists > 0) {
            // User already submitted recently; continue the intended flow.
            dfb_redirect_to_checkout_with_response(intval($form->woo_product_id), $existing_response_id);
            return '<p>Redirecting to checkout...</p>';
        }

        // Stale transient (response deleted/missing) — allow fresh submission.
        delete_transient($guard_key);
    }

    $submitted_answers = isset($_POST['answers']) && is_array($_POST['answers']) ? $_POST['answers'] : [];
    $answers = [];

    foreach ($questions as $index => $question) {
        $key = 'question_' . intval($index + 1);
        $value = isset($submitted_answers[$key]) ? $submitted_answers[$key] : '';

        if (is_array($value)) {
            $clean = array_map('sanitize_text_field', $value);
            $answers[$key] = implode(', ', $clean);
        } else {
            $answers[$key] = sanitize_text_field((string) $value);
        }

        if (intval($question->is_required) === 1 && $answers[$key] === '') {
            return '<p>Please answer all required questions.</p>';
        }
    }

    $user = wp_get_current_user();
    $response_id = dfb_create_form_response(
        intval($form->id),
        $user_id,
        $user->user_email,
        $user->display_name,
        $answers
    );

    if (!$response_id) {
        $error = isset($GLOBALS['dfb_last_response_error']) ? (string) $GLOBALS['dfb_last_response_error'] : '';
        if ($error !== '' && function_exists('current_user_can') && current_user_can('manage_options')) {
            return '<p>Could not save your response. Please try again. DB error: <code>' . esc_html($error) . '</code></p>';
        }
        return '<p>Could not save your response. Please try again.</p>';
    }

    set_transient($guard_key, $response_id, 20 * MINUTE_IN_SECONDS);
    dfb_redirect_to_checkout_with_response(intval($form->woo_product_id), $response_id);
    return '<p>Redirecting to checkout...</p>';
}

function dfb_create_form_response($form_id, $user_id, $user_email, $user_name, $answers) {
    global $wpdb;
    $GLOBALS['dfb_last_response_error'] = '';

    $answers_json = wp_json_encode($answers);
    if ($answers_json === false) {
        // JSON encoding failure (should be rare as we sanitize inputs).
        if (function_exists('error_log')) {
            error_log('DFB create_form_response: JSON encode failed for form_id=' . intval($form_id));
        }
        $GLOBALS['dfb_last_response_error'] = 'JSON encoding failed';
        return 0;
    }

    $user_email_clean = sanitize_email((string) $user_email);
    $user_name_clean = sanitize_text_field((string) $user_name);

    // Match DB schema lengths to avoid strict SQL truncation errors.
    if (function_exists('mb_substr')) {
        $user_email_clean = mb_substr($user_email_clean, 0, 100);
        $user_name_clean = mb_substr($user_name_clean, 0, 255);
    } else {
        $user_email_clean = substr($user_email_clean, 0, 100);
        $user_name_clean = substr($user_name_clean, 0, 255);
    }

    $inserted = $wpdb->insert($wpdb->prefix . 'dfb_responses', [
        'form_id' => $form_id,
        'user_id' => $user_id,
        'user_email' => $user_email_clean,
        'user_name' => $user_name_clean,
        'answers' => $answers_json,
    ]);

    if (!$inserted) {
        $last_error = isset($wpdb->last_error) ? (string) $wpdb->last_error : 'unknown';
        $GLOBALS['dfb_last_response_error'] = $last_error;
        if (function_exists('error_log')) {
            error_log('DFB create_form_response insert failed: ' . $last_error . ' | form_id=' . intval($form_id) . ' | user_id=' . intval($user_id));
        }
        return 0;
    }

    return intval($wpdb->insert_id);
}
