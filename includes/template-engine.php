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
 * Process {{#if key}} ... {{else}} ... {{/if}} and {{#if key}} ... {{/if}} blocks.
 *
 * Supports nesting.
 *
 * @param string              $html
 * @param array<string,mixed> $answers
 * @return string
 */
function dfb_template_process_conditionals($html, $answers) {
    $html = (string) $html;

    // Tokenize the template and resolve innermost blocks first.
    // Tag forms:
    // - {{#if key}}
    // - {{else}}
    // - {{/if}}
    $tag_re = '/\{\{\s*(#if\s+([a-zA-Z0-9_]+)|else|\/if)\s*\}\}/';

    $max_passes = 200; // Hard cap to avoid infinite loops on malformed templates.
    while ($max_passes-- > 0) {
        if (!preg_match($tag_re, $html)) {
            break;
        }

        $stack = [];
        $pos   = 0;
        $did_replace = false;

        while (preg_match($tag_re, $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
            $full_tag  = $m[0][0];
            $full_pos  = (int) $m[0][1];
            $full_len  = strlen($full_tag);
            $cmd       = trim((string) $m[1][0]);
            $key       = isset($m[2]) ? (string) $m[2][0] : '';

            if (str_starts_with($cmd, '#if')) {
                $stack[] = [
                    'key' => $key,
                    'start' => $full_pos,
                    'open_len' => $full_len,
                    'else' => null,
                    'else_len' => null,
                ];
            } elseif ($cmd === 'else') {
                $top = count($stack) - 1;
                if ($top >= 0 && $stack[$top]['else'] === null) {
                    $stack[$top]['else'] = $full_pos;
                    $stack[$top]['else_len'] = $full_len;
                }
            } elseif ($cmd === '/if') {
                $top = count($stack) - 1;
                if ($top >= 0) {
                    $frame = $stack[$top];
                    array_pop($stack);

                    $block_start = (int) $frame['start'];
                    $block_end   = $full_pos + $full_len;

                    $open_end = $block_start + (int) $frame['open_len'];

                    $else_pos = $frame['else'];
                    $else_len = $frame['else_len'];

                    $true_part = '';
                    $false_part = '';

                    if ($else_pos !== null) {
                        $else_pos = (int) $else_pos;
                        $else_len = (int) $else_len;
                        $true_part  = substr($html, $open_end, $else_pos - $open_end);
                        $false_part = substr($html, $else_pos + $else_len, $full_pos - ($else_pos + $else_len));
                    } else {
                        $true_part = substr($html, $open_end, $full_pos - $open_end);
                        $false_part = '';
                    }

                    $val = dfb_template_resolve_answer_key((string) $frame['key'], $answers);
                    $replacement = dfb_template_answer_truthy($val) ? $true_part : $false_part;

                    // If a conditional resolves to nothing, also remove the "ghost line" it
                    // can leave behind when the tags were on their own line. This prevents
                    // `{{else}}` (or `{{#if}}`/`{{/if}}`) from creating blank spacing when
                    // the chosen branch is empty.
                    $remove_start = $block_start;
                    $remove_end   = $block_end;

                    if (trim((string) $replacement) === '') {
                        // Determine current line bounds around the block.
                        $before = substr($html, 0, $block_start);
                        $last_nl = strrpos($before, "\n");
                        $last_cr = strrpos($before, "\r");
                        $line_start = max($last_nl === false ? -1 : $last_nl, $last_cr === false ? -1 : $last_cr) + 1;

                        $after_newline_match = null;
                        if (preg_match('/\r\n|\r|\n/', $html, $after_newline_match, PREG_OFFSET_CAPTURE, $block_end)) {
                            $nl_pos = (int) $after_newline_match[0][1];
                            $nl_len = strlen((string) $after_newline_match[0][0]);
                            $line_end_inclusive = $nl_pos + $nl_len;

                            $left_ws  = substr($html, $line_start, $block_start - $line_start);
                            $right_ws = substr($html, $block_end, $nl_pos - $block_end);

                            if (trim((string) $left_ws) === '' && trim((string) $right_ws) === '') {
                                $remove_start = $line_start;
                                $remove_end   = $line_end_inclusive;
                            }
                        } else {
                            // End-of-string: still remove trailing whitespace if this was the last line.
                            $left_ws = substr($html, $line_start, $block_start - $line_start);
                            $right_ws = substr($html, $block_end);
                            if (trim((string) $left_ws) === '' && trim((string) $right_ws) === '') {
                                $remove_start = $line_start;
                                $remove_end   = strlen($html);
                            }
                        }

                        // Inline spacing cleanup: when the empty conditional sits inside text,
                        // remove dangling spaces so we don't end up with "word ." or double spaces.
                        $html_len = strlen($html);
                        if ($remove_start === $block_start && $remove_end === $block_end) {
                            $prev_char = ($remove_start > 0) ? substr($html, $remove_start - 1, 1) : '';
                            $next_char = ($remove_end < $html_len) ? substr($html, $remove_end, 1) : '';

                            // If we're right before punctuation, eat spaces/tabs on the left.
                            if ($prev_char !== '' && preg_match('/[ \t]/', $prev_char) && $next_char !== '' && preg_match('/[\\.,;:!\\?\\)\\]\\}]/', $next_char)) {
                                while ($remove_start > 0) {
                                    $c = substr($html, $remove_start - 1, 1);
                                    if ($c !== ' ' && $c !== "\t") {
                                        break;
                                    }
                                    $remove_start--;
                                }
                            }

                            // If we would create double spaces, eat spaces/tabs on the right.
                            $prev_char2 = ($remove_start > 0) ? substr($html, $remove_start - 1, 1) : '';
                            $next_char2 = ($remove_end < $html_len) ? substr($html, $remove_end, 1) : '';
                            if ($prev_char2 !== '' && preg_match('/[ \t]/', $prev_char2) && $next_char2 !== '' && preg_match('/[ \t]/', $next_char2)) {
                                while ($remove_end < $html_len) {
                                    $c = substr($html, $remove_end, 1);
                                    if ($c !== ' ' && $c !== "\t") {
                                        break;
                                    }
                                    $remove_end++;
                                }
                            }
                        }

                        // Join-point cleanup: if the conditional was surrounded by whitespace and
                        // the right side begins with whitespace + punctuation, drop the left-side
                        // whitespace so we don't produce "word  ." or "word .".
                        $left_join  = substr($html, 0, $remove_start);
                        $right_join = substr($html, $remove_end);
                        if (preg_match('/^[ \t]+[\\.,;:!\\?\\)\\]\\}]/', (string) $right_join)) {
                            // Eat spaces/tabs that sit directly before punctuation.
                            while ($remove_end < $html_len) {
                                $c = substr($html, $remove_end, 1);
                                if ($c !== ' ' && $c !== "\t") {
                                    break;
                                }
                                $remove_end++;
                            }
                        }
                        // If both sides had whitespace, drop the left-side whitespace too to avoid double spaces.
                        $left_join2  = substr($html, 0, $remove_start);
                        $right_join2 = substr($html, $remove_end);
                        if (preg_match('/[ \t]+$/', (string) $left_join2) && preg_match('/^[ \t]+/', (string) $right_join2)) {
                            $remove_start = strlen(rtrim((string) $left_join2, " \t"));
                        }
                    }

                    $html = substr($html, 0, $remove_start) . $replacement . substr($html, $remove_end);
                    $did_replace = true;
                    break; // Restart scan from beginning after a replacement.
                }
            }

            $pos = $full_pos + $full_len;
        }

        if (!$did_replace) {
            // Unbalanced tags (e.g. stray {{else}} or missing {{/if}}) — stop to avoid looping.
            break;
        }
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

/**
 * After conditional blocks are resolved, collapse blank lines they leave behind.
 *
 * A "blank line" here means a line that contains only whitespace after the
 * conditional tag(s) on it were replaced with ''. We also handle the special
 * {{break}} tag so authors can force a line-break only when a block renders.
 *
 * @param string $html
 * @return string
 */
function dfb_template_collapse_blank_lines($html) {
    // 1. Replace {{break}} with a real newline (used inside conditionals to
    //    produce spacing only when the block is truthy).
    $html = str_ireplace('{{break}}', "\n", (string) $html);

    // 2. Split on every newline, drop lines that are pure whitespace, rejoin.
    //    This removes the ghost lines left when an entire {{#if}}...{{/if}} block
    //    that spanned its own line(s) evaluated to empty.
    $lines = preg_split('/\r\n|\r|\n/', $html);
    $kept = [];
    $prev_blank = false;

    if (is_array($lines)) {
        foreach ($lines as $line) {
            $is_blank = (trim((string) $line) === '');

            // Allow at most ONE consecutive blank line (mirrors standard text behaviour).
            if ($is_blank && $prev_blank) {
                continue;
            }

            $kept[] = (string) $line;
            $prev_blank = $is_blank;
        }
    }

    return implode("\n", $kept);
}

/**
 * Remove whitespace that ends up directly before punctuation in rendered output.
 *
 * wp_editor / HTML often introduces regular spaces or non-breaking spaces (`&nbsp;`)
 * around template tags. When a conditional resolves or disappears, those can remain
 * and produce "word ." / "... ." in PDFs.
 *
 * @param string $html
 * @return string
 */
function dfb_template_fix_spacing_before_punctuation($html) {
    $html = (string) $html;

    // Decode common NBSP encodings to a normal space so one regex can handle them.
    $html = str_ireplace('&nbsp;', ' ', $html);
    $html = str_replace("\xC2\xA0", ' ', $html);

    // Remove spaces/tabs immediately before punctuation.
    $html = preg_replace('/[ \t]+([\\.,;:!\\?])/u', '$1', $html);

    return (string) $html;
}

/**
 * Custom trim marker: `<dfb-nospace>` / `<dfb-nospace/>` (and `{{nospace}}` alias).
 *
 * When present at the start of a line, removes leading spaces/tabs on that line.
 * Also removes the marker itself (and a single following space if present).
 *
 * @param string $html
 * @return string
 */
function dfb_template_apply_nospace_marker($html) {
    $html = (string) $html;

    // Alias: allow {{nospace}} in addition to an HTML-ish marker.
    $html = str_ireplace('{{nospace}}', '<dfb-nospace/>', $html);

    // 1) Beginning of string: optional whitespace + marker + optional whitespace.
    $html = preg_replace('/^[ \t]*<dfb-nospace\\b[^>]*\\/?>(?:[ \t]*)/i', '', $html);

    // 2) Start of a line in plain text.
    $html = preg_replace('/(^|\\r\\n|\\r|\\n)[ \t]*<dfb-nospace\\b[^>]*\\/?>(?:[ \t]*)/i', '$1', $html);

    // 3) Start of a line in HTML after a block-ish tag close (common from wp_editor).
    // Example: </p>\n    <dfb-nospace/>TEXT  -> </p>\nTEXT
    $html = preg_replace('/(<\\/p>|<br\\s*\\/?\\s*>|<\\/div>|<\\/h[1-6]>|<\\/li>)(\\s*)[ \t]*<dfb-nospace\\b[^>]*\\/?>(?:[ \t]*)/i', '$1$2', $html);

    // Remove any remaining markers that weren’t at line-start.
    $html = preg_replace('/<dfb-nospace\\b[^>]*\\/?>(?:[ \t]*)/i', '', $html);

    return (string) $html;
}

/**
 * Insert a space before `{{#if ...}}` when it is glued to a word in template text.
 *
 * Many templates are authored in wp_editor and stored as HTML; we must avoid
 * touching content inside tags/attributes. This only inserts the space when the
 * `{{#if` sequence is in text content (i.e. not between `<` and `>`).
 *
 * @param string $html
 * @return string
 */
function dfb_template_insert_space_before_if_in_text($html) {
    $html = (string) $html;
    $re = '/([0-9A-Za-z])\{\{\s*#if\b/';
    $pos = 0;
    while (preg_match($re, $html, $m, PREG_OFFSET_CAPTURE, $pos)) {
        $match_pos = (int) $m[0][1];
        $insert_at = (int) $m[1][1] + 1; // after the preceding character

        // Determine whether we're inside an HTML tag by looking at the last '<' and '>' before the match.
        $before = substr($html, 0, $match_pos);
        $last_lt = strrpos($before, '<');
        $last_gt = strrpos($before, '>');
        $inside_tag = ($last_lt !== false) && ($last_gt === false || $last_lt > $last_gt);

        if (!$inside_tag) {
            $html = substr($html, 0, $insert_at) . ' ' . substr($html, $insert_at);
            $pos = $insert_at + 1; // continue after inserted space
        } else {
            $pos = $match_pos + 1;
        }
    }
    return $html;
}

function dfb_render_document_template($template_content, $response_row) {
    // Decode entities from wp_editor content and normalize placeholders so
    // `{{question_1}}` and `{{ question_1 }}` both work.
    $template_content = html_entity_decode((string) $template_content, ENT_QUOTES, 'UTF-8');
    $template_content = preg_replace('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', '{{\1}}', $template_content);
    $template_had_html = preg_match('/<\s*\/?\s*[a-zA-Z][^>]*>/', (string) $template_content) === 1;
    // Prevent "WORD{{#if ...}}TEXT" from gluing together when the conditional renders.
    // Works for both plain text and HTML templates (but does not touch tag/attribute content).
    $template_content = dfb_template_insert_space_before_if_in_text((string) $template_content);
    $template_content = dfb_template_apply_nospace_marker((string) $template_content);

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
    $template_content = dfb_template_collapse_blank_lines($template_content);
    // Only normalize back-to-back placeholders AFTER conditionals are resolved, so we don't
    // accidentally inject spaces into the content of conditional branches (causing leading spaces
    // or double spaces when nested {{#if}}...{{/if}} chains are resolved).
    $template_content = preg_replace('/\}\}\s*\{\{/', '}} {{', $template_content);

    $rendered = strtr((string) $template_content, $replacements);
    $rendered = dfb_template_fix_spacing_before_punctuation($rendered);
    if (!$template_had_html) {
        // If the template was entered as plain text, preserve newlines and blank lines in HTML/PDF output.
        // HTML collapses raw \n into spaces unless we opt into preformatted whitespace.
        $rendered = '<div class="dfb-template-pre" style="white-space: pre-wrap;">' . esc_html($rendered) . '</div>';
    } else {
        // wp_editor often stores "plain text" templates wrapped in minimal HTML, which flips $template_had_html
        // and would otherwise collapse newlines in the final PDF. Preserve newlines while still allowing HTML.
        if (preg_match("/\r\n|\r|\n/", $rendered)) {
            $rendered = '<div class="dfb-template-pre dfb-template-pre--html" style="white-space: pre-wrap;">' . $rendered . '</div>';
        }
    }

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
    $signature_count = intval(get_option('dfb_signature_count', 2));
    if ($signature_count < 1) $signature_count = 1;
    if ($signature_count > 6) $signature_count = 6;
    $signature_title       = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_title', ''));
    $signature_description = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_description', ''));
    $signature_labels = [];
    $signature_texts  = [];
    $has_any_signature_field = false;
    for ($i = 1; $i <= $signature_count; $i++) {
        $lbl = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_' . $i . '_label', ''));
        $txt = dfb_sanitize_option_text_for_pdf((string) get_option('dfb_signature_' . $i . '_text', ''));
        $signature_labels[$i] = $lbl;
        $signature_texts[$i]  = $txt;
        if ($lbl !== '' || $txt !== '') {
            $has_any_signature_field = true;
        }
    }

    $header_title = '';
    $form_id_for_header = isset($response_row->form_id) ? intval($response_row->form_id) : 0;
    if ($form_id_for_header > 0) {
        global $wpdb;
        $form_name_row = $wpdb->get_var($wpdb->prepare(
            "SELECT form_name FROM {$wpdb->prefix}dfb_forms WHERE id = %d",
            $form_id_for_header
        ));
        if (is_string($form_name_row) && $form_name_row !== '') {
            $header_title = $form_name_row;
        }
    }

    $header_html = '';
    if ($header_logo_url || $header_text_opt || $header_title !== '') {
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

        if ($header_title !== '') {
            $header_html .= '
    <h1 class="dfb-header-title">' . esc_html($header_title) . '</h1>';
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

    // Structured Question/Answer section (only when no document template body).
    $has_custom_template = trim(strip_tags($template_content)) !== '';
    if (!empty($answers) && !$has_custom_template) {
        $hide_all_opt = get_option('dfb_hide_questions_and_answers_in_pdf', '0');
        $hide_all_qa = $hide_all_opt === 1 || $hide_all_opt === '1' || $hide_all_opt === true;
        if ($hide_all_qa) {
            // Skip appending the automatic Q/A list entirely.
            // Placeholders in the document template are unaffected (this block only runs when there is no template body).
            goto dfb_after_auto_qa;
        }

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
                    $raw = (string) $question->question_title;
                    $key = 'form_' . intval($response_row->form_id) . '_q_order_' . $index . '_title';
                    dfb_register_i18n_string($raw, $key);
                    $question_labels['question_' . $index] = dfb_translate_i18n_string($raw, $key);
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
    dfb_after_auto_qa:

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
    if ($signature_title !== '' || $signature_description !== '' || $has_any_signature_field) {
        $rendered .= '<div class="dfb-signature-section">';

        if ($signature_title !== '') {
            $rendered .= '<h3 class="dfb-signature-title">' . esc_html($signature_title) . '</h3>';
        }

        if ($signature_description !== '') {
            $rendered .= '<div class="dfb-signature-description">' . wp_kses_post(nl2br($signature_description)) . '</div>';
        }

        // Use a simple table so signature cells align reliably in PDFs. Layout is 2 per row.
        $rendered .= '<table class="dfb-signature-table">';

        if ($signature_count === 1) {
            $rendered .= '<tr><td colspan="2" style="width:100%;text-align:center;">';
            $rendered .= '<div class="dfb-signature-line"></div>';
            if (($signature_labels[1] ?? '') !== '') {
                $rendered .= '<span class="dfb-signature-label">' . esc_html($signature_labels[1]) . '</span>';
            }
            if (($signature_texts[1] ?? '') !== '') {
                $rendered .= '<div class="dfb-signature-text">' . wp_kses_post($signature_texts[1]) . '</div>';
            }
            $rendered .= '</td></tr>';
        } else {
            for ($i = 1; $i <= $signature_count; $i += 2) {
                $rendered .= '<tr>';

                // Left cell.
                $rendered .= '<td>';
                $rendered .= '<div class="dfb-signature-line"></div>';
                if (($signature_labels[$i] ?? '') !== '') {
                    $rendered .= '<span class="dfb-signature-label">' . esc_html($signature_labels[$i]) . '</span>';
                }
                if (($signature_texts[$i] ?? '') !== '') {
                    $rendered .= '<div class="dfb-signature-text">' . wp_kses_post($signature_texts[$i]) . '</div>';
                }
                $rendered .= '</td>';

                // Right cell (may be empty for odd counts).
                $j = $i + 1;
                if ($j <= $signature_count) {
                    $rendered .= '<td>';
                    $rendered .= '<div class="dfb-signature-line"></div>';
                    if (($signature_labels[$j] ?? '') !== '') {
                        $rendered .= '<span class="dfb-signature-label">' . esc_html($signature_labels[$j]) . '</span>';
                    }
                    if (($signature_texts[$j] ?? '') !== '') {
                        $rendered .= '<div class="dfb-signature-text">' . wp_kses_post($signature_texts[$j]) . '</div>';
                    }
                    $rendered .= '</td>';
                } else {
                    $rendered .= '<td></td>';
                }

                $rendered .= '</tr>';
            }
        }

        $rendered .= '</table>';
        $rendered .= '</div>'; // .dfb-signature-section
    }

    $out = $header_html . $rendered . $footer_html;
    $out = dfb_strip_placeholder_legend_junk($out);
    $out = dfb_strip_pdf_admin_leak_phrases($out);

    return $out;
}