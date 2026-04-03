<?php
if (!defined('ABSPATH')) exit;

/**
 * Resolve a template key (question_1, 1, q1) to the scalar answer string.
 *
 * @param string              $raw_key  Key from {{#if ...}}.
 * @param array<string,mixed> $answers  Decoded answers array.
 * @return string
 */
function dfb_template_resolve_answer_key($raw_key, $answers) {
    $raw_key = trim((string) $raw_key);
    if ($raw_key === '') {
        return '';
    }
    $key = $raw_key;
    if (preg_match('/^\d+$/', $raw_key)) {
        $key = 'question_' . $raw_key;
    } elseif (preg_match('/^q(\d+)$/i', $raw_key, $m)) {
        $key = 'question_' . $m[1];
    }
    if (!isset($answers[$key])) {
        return '';
    }
    $v = $answers[$key];
    if (is_array($v)) {
        return implode(', ', array_map('strval', $v));
    }
    return (string) $v;
}

/**
 * Truthy for Yes/No style answers: empty and common "false" literals are false.
 *
 * @param string $value_text
 * @return bool
 */
function dfb_template_answer_truthy($value_text) {
    $s = trim((string) $value_text);
    if ($s === '') {
        return false;
    }
    $lower = strtolower($s);
    return !in_array($lower, ['no', 'false', '0', 'n', 'off'], true);
}

/**
 * Process {{#if key}} ... {{else}} ... {{/if}} and {{#if key}} ... {{/if}} blocks (non-nested).
 *
 * @param string              $html
 * @param array<string,mixed> $answers
 * @return string
 */
function dfb_template_process_conditionals($html, $answers) {
    $max = 100;
    while ($max-- > 0) {
        if (preg_match(
            '/\{\{\s*#if\s+([a-zA-Z0-9_]+)\s*\}\}(.*?)\{\{\s*else\s*\}\}(.*?)\{\{\s*\/if\s*\}\}/s',
            $html,
            $m
        )) {
            $val   = dfb_template_resolve_answer_key($m[1], $answers);
            $branch = dfb_template_answer_truthy($val) ? $m[2] : $m[3];
            $html   = str_replace($m[0], $branch, $html);
            continue;
        }
        if (preg_match(
            '/\{\{\s*#if\s+([a-zA-Z0-9_]+)\s*\}\}(.*?)\{\{\s*\/if\s*\}\}/s',
            $html,
            $m
        )) {
            $val    = dfb_template_resolve_answer_key($m[1], $answers);
            $branch = dfb_template_answer_truthy($val) ? $m[2] : '';
            $html   = str_replace($m[0], $branch, $html);
            continue;
        }
        break;
    }
    return $html;
}

/**
 * Best-effort: embed an attachment image as a data URI for Dompdf reliability.
 *
 * Dompdf commonly cannot load remote URLs unless enabled, and Windows `file://`
 * paths are brittle. Embedding avoids both.
 *
 * @param int $attachment_id
 * @return string Data URI or empty string.
 */
function dfb_template_attachment_data_uri($attachment_id) {
    $attachment_id = (int) $attachment_id;
    if ($attachment_id <= 0) {
        return '';
    }

    $path = get_attached_file($attachment_id);
    if (!$path || !is_string($path) || !file_exists($path) || !is_readable($path)) {
        return '';
    }

    $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($path) : '';
    if (!$mime) {
        $mime = function_exists('mime_content_type') ? (string) @mime_content_type($path) : '';
    }
    if (!is_string($mime) || $mime === '') {
        $mime = 'image/png';
    }

    $contents = @file_get_contents($path);
    if ($contents === false || $contents === '') {
        return '';
    }

    return 'data:' . $mime . ';base64,' . base64_encode($contents);
}

/**
 * Normalize escaped apostrophes from stored HTML / DB so legend stripping works.
 *
 * @param string $html
 * @return string
 */
function dfb_normalize_pdf_html_escapes($html) {
    $html = (string) $html;
    // Backslash-escaped apostrophe in content (often stored as \').
    $html = str_replace("\\'", "'", $html);
    $html = str_replace('\\"', '"', $html);
    return $html;
}

/**
 * Remove placeholder *legend* text often pasted from the form editor help (e.g. " - User's name")
 * that sits beside {{placeholders}} and would otherwise remain after replacement.
 *
 * @param string $html
 * @return string
 */
function dfb_strip_placeholder_legend_junk($html) {
    $html = dfb_normalize_pdf_html_escapes((string) $html);

    $literal_fragments = [
        " - User's name",
        " - User\'s name",
        ' - User&#8217;s name',
        ' - User&#039;s name',
        " - User's email",
        " - User\'s email",
        ' - User&#8217;s email',
        ' - Current date',
        ' - Current Date',
    ];
    foreach ($literal_fragments as $frag) {
        $html = str_ireplace($frag, '', $html);
    }

    for ($i = 1; $i <= 50; $i++) {
        $html = str_ireplace(' - Answer to Question ' . $i, '', $html);
    }

    // Regex: flexible spacing, apostrophe variants, line breaks.
    $html = preg_replace('/\s*-\s*User[\'\x{2019}]s\s+name\s*/iu', '', $html);
    $html = preg_replace('/\s*-\s*User[\'\x{2019}]s\s+email\s*/iu', '', $html);
    $html = preg_replace('/\s*-\s*Answer\s+to\s+Question\s+\d+\s*/iu', '', $html);
    $html = preg_replace('/\s*-\s*Current\s+date\s*/iu', '', $html);

    // Plain-text legend lines sometimes pasted above the real template body.
    $html = preg_replace('/^\s*Available\s+Placeholders:\s*$/mi', '', $html);

    // Pasted help blocks that end up inside <pre>/<code>.
    $html = preg_replace_callback(
        '/<(?:pre|code)\b[^>]*>[\s\S]*?<\/(?:pre|code)>/i',
        static function ($m) {
            $blk = $m[0];
            if (preg_match('/User[\'\x{2019}]?s\s+name|Answer\s+to\s+Question\s+\d+|Available\s+Placeholders/i', $blk)) {
                return '';
            }
            return $blk;
        },
        $html
    );

    // Remove accidental one-line dump: "root - User's name ... - Current date" (optional prefix).
    $html = preg_replace(
        '/(?:^|[\s>])(?:root\s*)?-\s*User[\'\x{2019}]s\s+name\s+[\s\S]{0,2000}?-\s*Current\s+date\b/iu',
        '',
        $html
    );

    return $html;
}

/**
 * Remove known English admin UI strings users sometimes paste into header/footer/sections/template.
 *
 * @param string $html
 * @return string
 */
function dfb_strip_pdf_admin_leak_phrases($html) {
    $html = (string) $html;
    $phrases = [
        'This logo will appear in the header of generated documents.',
        'his logo will appear in the header of generated documents.',
        'Optional text displayed in the document header (for example company name, address, or contact details).',
        'Optional text displayed above the page number at the bottom of the document (for example legal notice or contact details).',
        'Add as many sections as you need. Each section will appear as its own block in the generated document.',
        'Configure the signature block that appears near the bottom of generated PDFs, above the footer.',
        'Main heading shown above the signature row.',
        'Optional description text shown under the signature title.',
        'Customize the subject and body of the email sent to customers with their generated document attached.',
        'When checked, question titles are hidden in the automatic answers list; answer values still appear. Your document template can still use placeholders such as {{question_1}}.',
    ];
    foreach ($phrases as $p) {
        $html = str_ireplace($p, '', $html);
    }

    // Truncated footer help (PDF line-break) and similar fragments.
    $html = preg_replace(
        '/Optional\s+text\s+displayed\s+above\s+the\s+page\s+number\s+at\s+the\s+bottom\s+of\s+the\s+document\s*\([^)]*/iu',
        '',
        $html
    );

    // Short labels (e.g. pasted from Signature settings).
    $html = preg_replace('/\bLabel\s*\(e\.g\.\s*Applicant\s+Signature\)\s*/iu', '', $html);
    $html = preg_replace('/\bLabel\s*\(e\.g\.\s*Company\s+Representative\)\s*/iu', '', $html);
    $html = preg_replace('/\bText\s+below\s+signature\s+line\s*/iu', '', $html);

    return $html;
}

/**
 * Sanitize a single option field before putting it in the PDF (header/footer/signature).
 *
 * @param string $text
 * @return string
 */
function dfb_sanitize_option_text_for_pdf($text) {
    $text = (string) $text;
    $text = dfb_strip_pdf_admin_leak_phrases($text);
    return trim($text);
}

function dfb_render_document_template($template_content, $response_row) {
    // Decode entities from wp_editor content and normalize placeholders so
    // `{{question_1}}` and `{{ question_1 }}` both work.
    $template_content = html_entity_decode((string) $template_content, ENT_QUOTES, 'UTF-8');
    $template_content = preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', '{{\1}}', $template_content);
    // If placeholders are pasted back-to-back (}} {{ with no space), replaced values run together in the PDF.
    $template_content = preg_replace('/\}\}\s*\{\{/', '}} {{', $template_content);

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

    $template_content = dfb_template_process_conditionals((string) $template_content, $answers);

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
        $header_logo_url = dfb_template_attachment_data_uri($header_logo_id);
        if ($header_logo_url === '') {
            // Fallback: remote URL (may still fail in Dompdf if remote is disabled).
            $header_logo_url = (string) wp_get_attachment_url($header_logo_id);
        }
    } else {
        $header_logo_url = '';
    }
    $header_text_opt = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_header_text', ''));
    $footer_text_opt = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_footer_text', ''));

    // Signature block settings.
    $signature_title       = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_title', ''));
    $signature_description = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_description', ''));
    $signature_1_label     = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_1_label', ''));
    $signature_1_text      = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_1_text', ''));
    $signature_2_label     = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_2_label', ''));
    $signature_2_text      = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_2_text', ''));

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
    .dfb-qa-item--answers-only .dfb-qa-answer { margin-left: 0; margin-top: 0; }
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

    // Structured Question/Answer section (only when no document template body). Settings → General can hide only labels; answers still print.
    $has_custom_template = trim(strip_tags($template_content)) !== '';
    if (!empty($answers) && !$has_custom_template) {
        $hide_opt = get_option('dfb_hide_questions_in_pdf', '0');
        $hide_question_labels = $hide_opt === 1 || $hide_opt === '1' || $hide_opt === true;

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

        $section_class = 'dfb-qa-section' . ($hide_question_labels ? ' dfb-qa-section--answers-only' : '');
        $rendered .= '<div class="' . esc_attr($section_class) . '">';

        foreach ($answers as $key => $value) {
            $label = isset($question_labels[$key]) && $question_labels[$key] !== ''
                ? $question_labels[$key]
                : ucwords(str_replace('_', ' ', (string) $key));
            $value_text = is_scalar($value) ? (string) $value : wp_json_encode($value);

            $item_class = 'dfb-qa-item' . ($hide_question_labels ? ' dfb-qa-item--answers-only' : '');
            $rendered .= '<div class="' . esc_attr($item_class) . '">';
            if (!$hide_question_labels) {
                $rendered .= '<div class="dfb-qa-question">' . esc_html($label) . ':</div>';
            }
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
            $title = isset($section['title']) ? dfb_sanitize_option_text_for_pdf((string) $section['title']) : '';
            $body  = isset($section['body']) ? dfb_sanitize_option_text_for_pdf((string) $section['body']) : '';
            if ($title === '' && $body === '') {
                continue;
            }
            // Skip a section that is only the Settings UI boilerplate (accidentally saved).
            if (
                strcasecmp(trim($title), 'Document Sections') === 0
                && stripos($body, 'Add as many sections as you need') !== false
            ) {
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

    $out = $header_html . $rendered . $footer_html;
    $out = dfb_strip_placeholder_legend_junk($out);
    $out = dfb_strip_pdf_admin_leak_phrases($out);

    return $out;
}