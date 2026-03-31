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

    // Add website name header centered at the top.
    $site_name = get_bloginfo('name');
    if (!is_string($site_name)) {
        $site_name = '';
    }
    $site_header = '';
    if ($site_name !== '') {
        $site_header = '
<style>
    body { font-family: Arial, sans-serif; color: #333; margin: 40px; }
    .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 30px; }
    .header h1 { font-size: 24px; margin: 0; }
    .header p { font-size: 12px; color: #666; margin: 5px 0 0; }
    .section { margin-bottom: 20px; }
    .section h3 { font-size: 14px; border-bottom: 1px solid #ddd; padding-bottom: 5px; }
    table { width: 100%; border-collapse: collapse; }
    td { padding: 8px 10px; border: 1px solid #ddd; font-size: 13px; }
    td:first-child { font-weight: bold; width: 35%; background: #f9f9f9; }
</style>
<div class="header">
    <h1>' . esc_html($site_name) . '</h1>
    <p>Generated on: ' . esc_html(wp_date('Y-m-d')) . '</p>
</div>';
    }

    // Fallback: if no answer placeholder was used, append submitted answers.
    $has_answer_placeholders = preg_match('/\{\{(?:question_\d+|q\d+|\d+)\}\}/i', (string) $template_content) === 1;
    if (!$has_answer_placeholders && !empty($answers)) {
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

        $rendered .= '<hr><h3>Submitted Answers</h3><ul>';
        foreach ($answers as $key => $value) {
            $label = isset($question_labels[$key]) && $question_labels[$key] !== ''
                ? $question_labels[$key]
                : ucwords(str_replace('_', ' ', (string) $key));
            $value_text = is_scalar($value) ? (string) $value : wp_json_encode($value);
            $rendered .= '<li><strong>' . esc_html($label) . ':</strong> ' . esc_html($value_text) . '</li>';
        }
        $rendered .= '</ul>';
    }

    return $site_header . $rendered;
}
