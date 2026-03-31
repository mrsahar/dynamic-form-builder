<?php
if (!defined('ABSPATH')) exit;

function dfb_render_document_template($template_content, $response_row) {
    $answers = json_decode((string) $response_row->answers, true);
    if (!is_array($answers)) {
        $answers = [];
    }

    $replacements = [
        '{{user_name}}' => (string) $response_row->user_name,
        '{{user_email}}' => (string) $response_row->user_email,
        '{{current_date}}' => wp_date('Y-m-d'),
    ];

    foreach ($answers as $key => $value) {
        $placeholder = '{{' . $key . '}}';
        $replacements[$placeholder] = (string) $value;
    }

    return strtr((string) $template_content, $replacements);
}
