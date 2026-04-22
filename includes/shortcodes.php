<?php
if (!defined('ABSPATH')) exit;

/**
 * Human‑readable hint for a question type shown above the field.
 *
 * @param string $input_type
 * @return string
 */
function dfb_get_input_type_hint($input_type) {
    $input_type = (string) $input_type;
    switch ($input_type) {
        case 'text':
            return __('Short text answer', 'dynamic-form-builder');
        case 'email':
            return __('Email address (we will validate format)', 'dynamic-form-builder');
        case 'number':
            return __('Number only', 'dynamic-form-builder');
        case 'date':
            return __('Pick a date', 'dynamic-form-builder');
        case 'textarea':
            return __('Longer, free-form answer', 'dynamic-form-builder');
        case 'dropdown':
            return __('Choose one option from the list', 'dynamic-form-builder');
        case 'radio':
            return __('Choose one option', 'dynamic-form-builder');
        case 'checkbox':
            return __('You can select more than one option', 'dynamic-form-builder');
        case 'yes_no':
            return __('Choose Yes or No', 'dynamic-form-builder');
        default:
            return '';
    }
}

/**
 * Normalize a pasted video URL (e.g. www.youtube.com/... without scheme).
 *
 * @param string $url Raw URL.
 * @return string Sanitized URL or empty string.
 */
function dfb_normalize_video_url($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('~^https?://~i', $url)) {
        $url = 'https://' . ltrim($url, '/');
    }
    $clean = esc_url_raw($url);
    return is_string($clean) ? $clean : '';
}

/**
 * Extract YouTube video ID from common URL shapes (watch, shorts, embed, youtu.be, live).
 *
 * @param string $url Full URL.
 * @return string Video ID or empty string.
 */
function dfb_extract_youtube_video_id($url) {
    $url = (string) $url;
    $patterns = [
        '~[?&]v=([^&]+)~',
        '~youtu\\.be/([^/?&#]+)~i',
        '~youtube\\.com/embed/([^/?&#]+)~i',
        '~youtube-nocookie\\.com/embed/([^/?&#]+)~i',
        '~youtube\\.com/shorts/([^/?&#]+)~i',
        '~youtube\\.com/live/([^/?&#]+)~i',
        '~youtube\\.com/v/([^/?&#]+)~i',
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $url, $m)) {
            $id = $m[1];
            if (strpos($id, '?') !== false) {
                $id = strstr($id, '?', true);
            }
            $id = preg_replace('~[^0-9A-Za-z_-]~', '', $id);
            return $id !== '' ? $id : '';
        }
    }
    return '';
}

/**
 * Extract numeric Vimeo video ID.
 *
 * @param string $url Full URL.
 * @return string Video ID or empty string.
 */
function dfb_extract_vimeo_video_id($url) {
    $url = (string) $url;
    if (preg_match('~vimeo\\.com/(?:video/)?(\d+)~', $url, $m)) {
        return $m[1];
    }
    if (preg_match('~player\\.vimeo\\.com/video/(\d+)~', $url, $m)) {
        return $m[1];
    }
    return '';
}

/**
 * Build responsive embed markup for a question video URL.
 *
 * @param string $raw_url Value from question.video_url.
 * @return string HTML safe to print (iframes from trusted providers).
 */
function dfb_render_question_video_embed($raw_url) {
    $trimmed = trim((string) $raw_url);
    if ($trimmed === '') {
        return '';
    }

    $url = dfb_normalize_video_url($raw_url);
    // If sanitization stripped the URL, still try ID extraction from the raw paste.
    $for_parse = $url !== '' ? $url : $trimmed;

    // WordPress oEmbed: YouTube, Vimeo, and many others (uses HTTP request).
    if ($url !== '' && function_exists('wp_oembed_get')) {
        $oembed = wp_oembed_get($url, [
            'width'    => 640,
            'height'   => 360,
            'discover' => true,
        ]);
        if ($oembed) {
            return '<div class="dfb-step-media dfb-video-embed"><div class="dfb-video-embed-inner">' . $oembed . '</div></div>';
        }
    }

    // Manual iframes when oEmbed fails (offline, blocked HTTP, Shorts/embed URLs, etc.).
    $yt_id = dfb_extract_youtube_video_id($for_parse);
    if ($yt_id !== '') {
        $embed_src = 'https://www.youtube.com/embed/' . rawurlencode($yt_id);
        return '<div class="dfb-step-media dfb-video-embed"><div class="dfb-video-embed-inner"><iframe src="' . esc_url($embed_src) . '" loading="lazy" title="' . esc_attr__('Help video', 'dynamic-form-builder') . '" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share" allowfullscreen></iframe></div></div>';
    }

    $vm_id = dfb_extract_vimeo_video_id($for_parse);
    if ($vm_id !== '') {
        $embed_src = 'https://player.vimeo.com/video/' . rawurlencode($vm_id);
        return '<div class="dfb-step-media dfb-video-embed"><div class="dfb-video-embed-inner"><iframe src="' . esc_url($embed_src) . '" loading="lazy" title="' . esc_attr__('Help video', 'dynamic-form-builder') . '" allow="autoplay; fullscreen; picture-in-picture" allowfullscreen></iframe></div></div>';
    }

    $fallback_href = $url;
    if ($fallback_href === '' && $trimmed !== '') {
        $fallback_href = dfb_normalize_video_url($trimmed);
        if ($fallback_href === '') {
            $fallback_href = esc_url_raw('https://' . ltrim(preg_replace('~^https?://~i', '', $trimmed), '/'));
        }
    }

    return '<p class="dfb-step-media"><a class="dfb-inline-link" href="' . esc_url($fallback_href !== '' ? $fallback_href : '#') . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Watch help video', 'dynamic-form-builder') . '</a></p>';
}

add_shortcode('dfb_form', 'dfb_form_shortcode');

function dfb_form_shortcode($atts) {
    global $wpdb;

    $atts = shortcode_atts([
        'id' => 0,
    ], $atts, 'dfb_form');

    $form_id = intval($atts['id']);
    if ($form_id <= 0) {
        return '<p>' . esc_html__('Invalid form ID.', 'dynamic-form-builder') . '</p>';
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
        return '<p>' . esc_html__('This form is unavailable.', 'dynamic-form-builder') . '</p>';
    }

    $questions = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table_questions} WHERE form_id = %d ORDER BY question_order ASC",
        $form_id
    ));

    if (empty($questions)) {
        return '<p>' . esc_html__('No questions configured for this form.', 'dynamic-form-builder') . '</p>';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dfb_front_submit'])) {
        return dfb_handle_frontend_form_submit($form, $questions);
    }

    wp_enqueue_style('dfb-frontend-css', DFB_PLUGIN_URL . 'assets/css/frontend.css', [], DFB_VERSION);
    wp_enqueue_script('dfb-frontend-js', DFB_PLUGIN_URL . 'assets/js/frontend.js', ['jquery'], DFB_VERSION, true);

    wp_localize_script(
        'dfb-frontend-js',
        'dfbFrontendL10n',
        [
            /* translators: 1: current step number, 2: total visible steps */
            'stepProgress'     => __( 'Step %1$d of %2$d', 'dynamic-form-builder' ),
            'fieldRequired'    => __( 'This field is required.', 'dynamic-form-builder' ),
            'emailInvalid'     => __( 'Please enter a valid email address.', 'dynamic-form-builder' ),
            'chooseOption'     => __( 'Please choose an option.', 'dynamic-form-builder' ),
            'selectAtLeastOne' => __( 'Please select at least one option.', 'dynamic-form-builder' ),
        ]
    );

    ob_start();
    ?>
    <div class="dfb-frontend-wrap" data-dfb-step-progress="<?php echo esc_attr( __( 'Step %1$d of %2$d', 'dynamic-form-builder' ) ); ?>">
        <div class="dfb-progress">
            <div class="dfb-progress-bar" id="dfb-progress-bar"></div>
        </div>
        <p class="dfb-progress-text" id="dfb-progress-text"></p>

        <form method="post" class="dfb-step-form">
            <?php wp_nonce_field('dfb_front_submit_' . $form->id, 'dfb_front_nonce'); ?>
            <input type="hidden" name="dfb_form_id" value="<?php echo intval($form->id); ?>">

            <?php foreach ($questions as $index => $question): ?>
                <div class="dfb-step"
                     data-step="<?php echo intval($index + 1); ?>"
                     data-dfb-step-order="<?php echo intval($index + 1); ?>"
                     data-dfb-input-type="<?php echo esc_attr((string) $question->input_type); ?>"
                     <?php if (!empty($question->depends_on_question_order) && $question->depends_on_value !== null && $question->depends_on_value !== ''): ?>
                         data-dfb-dep-parent="<?php echo intval($question->depends_on_question_order); ?>"
                         data-dfb-dep-value="<?php echo esc_attr((string) $question->depends_on_value); ?>"
                     <?php endif; ?>
                     style="display: <?php echo $index === 0 ? 'block' : 'none'; ?>;">
                    <?php
                    $qid = isset($question->id) ? intval($question->id) : 0;
                    $title_raw = (string) ($question->question_title ?? '');
                    $desc_raw  = (string) ($question->question_description ?? '');
                    $title_key = 'form_' . intval($form->id) . '_q_' . ($qid > 0 ? $qid : intval($index + 1)) . '_title';
                    $desc_key  = 'form_' . intval($form->id) . '_q_' . ($qid > 0 ? $qid : intval($index + 1)) . '_description';
                    dfb_register_i18n_string($title_raw, $title_key);
                    dfb_register_i18n_string($desc_raw, $desc_key);
                    $title = dfb_translate_i18n_string($title_raw, $title_key);
                    $desc  = dfb_translate_i18n_string($desc_raw, $desc_key);
                    ?>
                    <h3><?php echo esc_html($title); ?></h3>
                    <?php if ($desc !== ''): ?>
                        <p><?php echo esc_html($desc); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($question->video_url)): ?>
                        <?php
                        echo dfb_render_question_video_embed($question->video_url); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- oEmbed + escaped iframe src
                        ?>
                    <?php endif; ?>

                    <?php if (!empty($question->image_url)): ?>
                        <p class="dfb-step-media"><img class="dfb-step-image" src="<?php echo esc_url($question->image_url); ?>" alt=""></p>
                    <?php endif; ?>

                    <?php
                    $input_name = 'answers[question_' . intval($index + 1) . ']';
                    $required   = intval($question->is_required) === 1 ? 'required' : '';
                    $input_type = $question->input_type;
                    $options    = array_filter(array_map('trim', explode("\n", (string) $question->input_options)));
                    $hint       = dfb_get_input_type_hint($input_type);
                    ?>

                    <?php if ($hint !== ''): ?>
                        <p class="dfb-field-hint"><?php echo esc_html($hint); ?></p>
                    <?php endif; ?>

                    <div class="dfb-input-wrap" data-dfb-input-type="<?php echo esc_attr($input_type); ?>">
                        <?php if (in_array($input_type, ['text', 'email', 'number', 'date'], true)): ?>
                            <input type="<?php echo esc_attr($input_type); ?>" name="<?php echo esc_attr($input_name); ?>" <?php echo $required; ?>>
                        <?php elseif ($input_type === 'textarea'): ?>
                            <textarea name="<?php echo esc_attr($input_name); ?>" <?php echo $required; ?>></textarea>
                        <?php elseif ($input_type === 'dropdown'): ?>
                            <select name="<?php echo esc_attr($input_name); ?>" <?php echo $required; ?>>
                                <option value=""><?php echo esc_html__('Select an option', 'dynamic-form-builder'); ?></option>
                                <?php foreach ($options as $option): ?>
                                    <option value="<?php echo esc_attr($option); ?>"><?php echo esc_html($option); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($input_type === 'radio'): ?>
                            <div class="dfb-choice-group" role="group">
                                <?php foreach ($options as $option): ?>
                                    <label class="dfb-choice"><input type="radio" name="<?php echo esc_attr($input_name); ?>" value="<?php echo esc_attr($option); ?>" <?php echo $required; ?>><span class="dfb-choice-text"><?php echo esc_html($option); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php elseif ($input_type === 'yes_no'): ?>
                            <div class="dfb-choice-group" role="group">
                                <label class="dfb-choice"><input type="radio" name="<?php echo esc_attr($input_name); ?>" value="Yes" <?php echo $required; ?>><span class="dfb-choice-text"><?php echo esc_html__('Yes', 'dynamic-form-builder'); ?></span></label>
                                <label class="dfb-choice"><input type="radio" name="<?php echo esc_attr($input_name); ?>" value="No" <?php echo $required; ?>><span class="dfb-choice-text"><?php echo esc_html__('No', 'dynamic-form-builder'); ?></span></label>
                            </div>
                        <?php elseif ($input_type === 'checkbox'): ?>
                            <div class="dfb-choice-group" role="group">
                                <?php foreach ($options as $option): ?>
                                    <label class="dfb-choice"><input type="checkbox" name="<?php echo esc_attr($input_name); ?>[]" value="<?php echo esc_attr($option); ?>" <?php echo $required; ?>><span class="dfb-choice-text"><?php echo esc_html($option); ?></span></label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <input type="text" name="<?php echo esc_attr($input_name); ?>" <?php echo $required; ?>>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="dfb-nav-buttons">
                <button type="button" class="dfb-btn dfb-btn--secondary" id="dfb-prev-btn" style="display:none;"><?php echo esc_html__('Back', 'dynamic-form-builder'); ?></button>
                <button type="button" class="dfb-btn dfb-btn--primary" id="dfb-next-btn"><?php echo esc_html__('Next', 'dynamic-form-builder'); ?></button>
                <button type="submit" name="dfb_front_submit" class="dfb-btn dfb-btn--primary" id="dfb-submit-btn" style="display:none;"><?php echo esc_html__('Continue to Checkout', 'dynamic-form-builder'); ?></button>
            </div>
        </form>
    </div>
    <?php
    return ob_get_clean();
}

function dfb_handle_frontend_form_submit($form, $questions) {
    if (!isset($_POST['dfb_front_nonce']) || !wp_verify_nonce($_POST['dfb_front_nonce'], 'dfb_front_submit_' . $form->id)) {
        return '<p>' . esc_html__('Security check failed.', 'dynamic-form-builder') . '</p>';
    }

    $user_id = get_current_user_id();
    if (!$user_id) {
        return '<p>' . esc_html__('Please login to continue.', 'dynamic-form-builder') . '</p>';
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

    $total_questions = count($questions);

    foreach ($questions as $index => $question) {
        $question_number = intval($index + 1); // matches frontend key: question_{number}
        $key = 'question_' . $question_number;

        // Determine visibility for conditional questions.
        $is_visible = true;
        $dep_parent_order = isset($question->depends_on_question_order) ? $question->depends_on_question_order : null;
        $dep_value = isset($question->depends_on_value) ? $question->depends_on_value : null;

        if (!empty($dep_parent_order) && $dep_value !== null && $dep_value !== '') {
            $parent_number = intval($dep_parent_order);
            $expected_value = (string) $dep_value;

            // Only support parent questions with known input types.
            if ($parent_number > 0 && $parent_number <= $total_questions) {
                $parent_question = $questions[$parent_number - 1];
                $parent_type = isset($parent_question->input_type) ? (string) $parent_question->input_type : '';
                $parent_key = 'question_' . $parent_number;
                $parent_raw_value = isset($submitted_answers[$parent_key]) ? $submitted_answers[$parent_key] : '';

                if ($parent_type === 'checkbox') {
                    $parent_vals = is_array($parent_raw_value) ? $parent_raw_value : [];
                    $is_visible = in_array($expected_value, $parent_vals, true);
                } elseif ($parent_type === 'radio' || $parent_type === 'dropdown' || $parent_type === 'yes_no') {
                    $parent_str = is_array($parent_raw_value) ? '' : (string) $parent_raw_value;
                    $is_visible = $parent_str === $expected_value;
                } else {
                    $is_visible = false;
                }
            } else {
                $is_visible = false;
            }
        }

        $value = isset($submitted_answers[$key]) ? $submitted_answers[$key] : '';

        if (!$is_visible) {
            // Hidden questions should not block submission.
            $answers[$key] = '';
            continue;
        }

        if (is_array($value)) {
            $clean = array_map('sanitize_text_field', $value);
            $answers[$key] = implode(', ', $clean);
        } else {
            $answers[$key] = sanitize_text_field((string) $value);
        }

        if (intval($question->is_required) === 1 && $answers[$key] === '') {
            return '<p>' . esc_html__('Please answer all required questions.', 'dynamic-form-builder') . '</p>';
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
        return '<p>' . esc_html__('Could not save your response. Please try again.', 'dynamic-form-builder') . '</p>';
    }

    set_transient($guard_key, $response_id, 20 * MINUTE_IN_SECONDS);
    dfb_redirect_to_checkout_with_response(intval($form->woo_product_id), $response_id);
    return '<p>' . esc_html__('Redirecting to checkout...', 'dynamic-form-builder') . '</p>';
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
