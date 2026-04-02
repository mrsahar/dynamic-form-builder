<?php
if (!defined('ABSPATH')) exit;

function dfb_render_document_template($template_content, $response_row) {
    // Decode entities from wp_editor content and normalize placeholders so
    // `{{question_1}}` and `{{ question_1 }}` both work.
    $template_content = html_entity_decode((string) $template_content, ENT_QUOTES, 'UTF-8');
    $template_content = preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', '{{\1}}', $template_content);

    $answers = json_decode((string) ($response_row->answers ?? ''), true);
    if (!is_array($answers)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DFB: template-engine cannot decode answers JSON for response id: ' . (isset($response_row->id) ? intval($response_row->id) : 0));
        }
        $answers = [];
    }

    $replacements = [
        '{{user_name}}' => (string) ($response_row->user_name ?? ''),
        '{{user_email}}' => (string) ($response_row->user_email ?? ''),
        '{{current_date}}' => (string) wp_date('Y-m-d'),
    ];

    foreach ($answers as $key => $value) {
        $value_text = is_scalar($value) ? (string) $value : wp_json_encode($value);
        $placeholder = '{{' . $key . '}}';
        $replacements[$placeholder] = $value_text;

        // Helpful aliases: {{1}} and {{q1}} for question_1 style keys.
        if (preg_match('/^question_(\d+)$/i', (string) $key, $m)) {
            $num = $m[1];
            $replacements['{{' . $num . '}}'] = $value_text;
            $replacements['{{q' . $num . '}}'] = $value_text;
        }
    }

    $rendered = strtr((string) $template_content, $replacements);

    // Remove known boilerplate text that should not appear in final PDFs.
    // Templates created in the admin editor sometimes store extra placeholder/demo text.
    $rendered = preg_replace(
        '/\bDocument\s*Template\b.*?\bVisualCode\b/i',
        '',
        $rendered
    );
    $rendered = preg_replace('/\bAnd\s*so\s*on\s*for\s*each\s*question\b.*?\.\.\./i', '', $rendered);
    $rendered = str_ireplace(
        [
            'Document Template',
            'VisualCode',
            'And so on for each question...',
        ],
        '',
        $rendered
    );

    // Build header/footer from plugin settings.
    $header_logo_id  = (int) get_option('dfb_header_logo_id', 0);
    if ($header_logo_id) {
        $attached_file = get_attached_file($header_logo_id);
        if ($attached_file && file_exists($attached_file)) {
            $header_logo_url = 'file://' . $attached_file;
        } else {
            $header_logo_url = wp_get_attachment_url($header_logo_id); // fallback
        }
    } else {
        $header_logo_url = '';
    }
    $header_text_opt = (string) get_option('dfb_header_text', '');
    $footer_text_opt = (string) get_option('dfb_footer_text', '');

    // Signature block settings.
    $signature_title       = (string) get_option('dfb_signature_title', '');
    $signature_description = (string) get_option('dfb_signature_description', '');
    $signature_1_label     = (string) get_option('dfb_signature_1_label', '');
    $signature_1_text      = (string) get_option('dfb_signature_1_text', '');
    $signature_2_label     = (string) get_option('dfb_signature_2_label', '');
    $signature_2_text      = (string) get_option('dfb_signature_2_text', '');

    $site_name = get_bloginfo('name');
    if (!is_string($site_name)) {
        $site_name = '';
    }

    $header_html = '';
    if ($header_logo_url || $header_text_opt || $site_name) {
        $header_html = '
<style>
    body { font-family: Arial, sans-serif; color: #333; margin: 40px; }
    .dfb-header { border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 30px; }
    .dfb-header-top { display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px; }
    .dfb-header-logo { margin-right: 20px; flex-shrink: 0; }
    .dfb-header-logo img { max-height: 80px; width: auto; }
    .dfb-header-main { }
    .dfb-header-title { font-size: 24px; margin: 0; }
    .dfb-header-text { font-size: 12px; color: #666; margin: 5px 0 0; white-space: pre-line; }
    .dfb-generated-on { font-size: 11px; color: #999; margin-top: 5px; }
    .section { margin-bottom: 20px; }
    .section h3 { font-size: 14px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 8px 10px; border: 1px solid #ddd; font-size: 13px; }
    td:first-child { font-weight: bold; width: 35%; background: #f9f9f9; }
    .dfb-qa-section { margin-top: 25px; }
    .dfb-qa-section-title { font-size: 16px; margin: 0 0 10px; }
    .dfb-qa-item { padding: 8px 0; border-bottom: 1px solid #ddd; }
    .dfb-qa-question { font-weight: bold; display: block; }
    .dfb-qa-answer { margin-left: 10px; display: block; margin-top: 3px; }
    .dfb-custom-section { margin-top: 25px; }
    .dfb-custom-section-title { font-size: 16px; margin: 0 0 8px; }
    .dfb-custom-section-body { font-size: 13px; }
    .dfb-signature-section { margin-top: 40px; }
    .dfb-signature-title { font-size: 16px; margin: 0 0 8px; }
    .dfb-signature-description { font-size: 13px; margin: 0 0 16px; white-space: pre-line; }
    .dfb-signature-table { width: 100%; margin-top: 30px; border-collapse: collapse; }
    .dfb-signature-table td { width: 50%; text-align: center; vertical-align: bottom; padding: 0 20px; border: none; }
    .dfb-signature-line { border-top: 1px solid #000; margin: 40px 0 6px; }
    .dfb-signature-label { font-weight: bold; font-size: 13px; display: block; }
    .dfb-signature-text { font-size: 12px; color: #555; margin-top: 2px; }
    .dfb-footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #ddd; font-size: 11px; color: #666; text-align: center; white-space: pre-line; }
</style>
<div class="dfb-header">';

        // Top row: logo on the left, generated date on the right.
        $header_html .= '
<div class="dfb-header-top">';
        if ($header_logo_url) {
            $header_html .= '
    <div class="dfb-header-logo">
        <img src="' . esc_attr($header_logo_url) . '" alt="">
    </div>';
        }
        $header_html .= '
    <p class="dfb-generated-on">' . esc_html__('Generated on:', 'dynamic-form-builder') . ' ' . esc_html(wp_date('Y-m-d')) . '</p>
</div>';

        // Second row: title and optional header text full-width.
        $header_html .= '
<div class="dfb-header-main">';

        if ($site_name) {
            $header_html .= '
    <h1 class="dfb-header-title">' . esc_html($site_name) . '</h1>';
        }

        if ($header_text_opt) {
            $header_html .= '
    <p class="dfb-header-text">' . wp_kses_post(nl2br($header_text_opt)) . '</p>';
        }

        $header_html .= '
</div>
</div>';
    }

    $footer_html = '';
    if ($footer_text_opt) {
        $footer_html = '
<div class="dfb-footer">' . wp_kses_post(nl2br($footer_text_opt)) . '</div>';
    }

    // Always append a structured Question/Answer section if we have answers.
    if (!empty($answers)) {
        global $wpdb;
        $question_labels = [];
        if (!empty($response_row->form_id)) {
            $questions = $wpdb->get_results($wpdb->prepare(
                "SELECT question_title, question_order
                 FROM {$wpdb->prefix}dfb_questions
                 WHERE form_id = %d
                 ORDER BY question_order ASC",
                intval($response_row->form_id)
            ));
            if (is_array($questions)) {
                foreach ($questions as $question) {
                    $index = intval($question->question_order) + 1;
                    $question_labels['question_' . $index] = (string) $question->question_title;
                }
            }
        }

        $rendered .= '<div class="dfb-qa-section">';
        $rendered .= '<h3 class="dfb-qa-section-title">' . esc_html__('Submitted Answers', 'dynamic-form-builder') . '</h3>';

        foreach ($answers as $key => $value) {
            $label = isset($question_labels[$key]) && $question_labels[$key] !== ''
                ? $question_labels[$key]
                : ucwords(str_replace('_', ' ', (string) $key));
            $value_text = is_scalar($value) ? (string) $value : wp_json_encode($value);

            $rendered .= '<div class="dfb-qa-item">';
            $rendered .= '<div class="dfb-qa-question">' . esc_html($label) . ':</div>';
            $rendered .= '<div class="dfb-qa-answer">' . esc_html($value_text) . '</div>';
            $rendered .= '</div>';
        }

        $rendered .= '</div>';
    }

    // Append custom sections defined in settings.
    $sections_value = get_option('dfb_sections', '[]');
    $sections       = json_decode((string) $sections_value, true);
    if (is_array($sections) && !empty($sections)) {
        foreach ($sections as $section) {
            $title = isset($section['title']) ? (string) $section['title'] : '';
            $body  = isset($section['body']) ? (string) $section['body'] : '';
            if ($title === '' && $body === '') {
                continue;
            }

            $rendered .= '<div class="dfb-custom-section">';
            if ($title !== '') {
                $rendered .= '<h3 class="dfb-custom-section-title">' . esc_html($title) . '</h3>';
            }
            if ($body !== '') {
                $rendered .= '<div class="dfb-custom-section-body">' . wp_kses_post($body) . '</div>';
            }
            $rendered .= '</div>';
        }
    }

    // Append signature section above footer, if configured.
    if (
        $signature_title !== '' ||
        $signature_description !== '' ||
        $signature_1_label !== '' ||
        $signature_1_text !== '' ||
        $signature_2_label !== '' ||
        $signature_2_text !== ''
    ) {
        $rendered .= '<div class="dfb-signature-section">';

        if ($signature_title !== '') {
            $rendered .= '<h3 class="dfb-signature-title">' . esc_html($signature_title) . '</h3>';
        }

        if ($signature_description !== '') {
            $rendered .= '<div class="dfb-signature-description">' . wp_kses_post(nl2br($signature_description)) . '</div>';
        }

        // Use a simple table so both signatures sit on one row reliably in PDFs.
        $rendered .= '<table class="dfb-signature-table"><tr>';

        // Signature 1 (left cell).
        $rendered .= '<td>';
        $rendered .= '<div class="dfb-signature-line"></div>';
        if ($signature_1_label !== '') {
            $rendered .= '<span class="dfb-signature-label">' . esc_html($signature_1_label) . '</span>';
        }
        if ($signature_1_text !== '') {
            $rendered .= '<div class="dfb-signature-text">' . wp_kses_post($signature_1_text) . '</div>';
        }
        $rendered .= '</td>';

        // Signature 2 (right cell).
        $rendered .= '<td>';
        $rendered .= '<div class="dfb-signature-line"></div>';
        if ($signature_2_label !== '') {
            $rendered .= '<span class="dfb-signature-label">' . esc_html($signature_2_label) . '</span>';
        }
        if ($signature_2_text !== '') {
            $rendered .= '<div class="dfb-signature-text">' . wp_kses_post($signature_2_text) . '</div>';
        }
        $rendered .= '</td>';

        $rendered .= '</tr></table>';
        $rendered .= '</div>'; // .dfb-signature-section
    }

    return $header_html . $rendered . $footer_html;
}