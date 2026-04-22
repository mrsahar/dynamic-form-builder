<?php
if (!defined('ABSPATH')) exit;

function dfb_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    /**
     * Update an option only when the field exists in POST.
     * This prevents saving one tab from wiping settings from other tabs.
     *
     * @param string        $post_key
     * @param string        $option_name
     * @param callable|null $sanitize_cb Receives raw (unslashed) value.
     * @return void
     */
    $dfb_update_option_if_posted = static function ($post_key, $option_name, $sanitize_cb = null) {
        if (!array_key_exists($post_key, $_POST)) {
            return;
        }
        $raw = wp_unslash($_POST[$post_key]);
        $value = is_callable($sanitize_cb) ? call_user_func($sanitize_cb, $raw) : $raw;
        update_option($option_name, $value);
    };

    // Handle form submission
    if (isset($_POST['dfb_settings_submit'])) {
        check_admin_referer('dfb_save_settings', 'dfb_settings_nonce');

        // General settings.
        $dfb_update_option_if_posted('dfb_header_logo_id', 'dfb_header_logo_id', static fn($v) => intval($v));
        $dfb_update_option_if_posted('dfb_header_text', 'dfb_header_text', static fn($v) => wp_kses_post((string) $v));
        $dfb_update_option_if_posted('dfb_footer_text', 'dfb_footer_text', static fn($v) => wp_kses_post((string) $v));
        // Checkbox only: when unchecked it is absent from POST (do not use a duplicate-name hidden field — PHP may merge values into an array).
        update_option(
            'dfb_hide_questions_in_pdf',
            isset($_POST['dfb_hide_questions_in_pdf']) && (string) wp_unslash($_POST['dfb_hide_questions_in_pdf']) === '1' ? 1 : 0
        );
        update_option(
            'dfb_hide_questions_and_answers_in_pdf',
            isset($_POST['dfb_hide_questions_and_answers_in_pdf']) && (string) wp_unslash($_POST['dfb_hide_questions_and_answers_in_pdf']) === '1' ? 1 : 0
        );

        // Sections JSON (stored as JSON string).
        if (array_key_exists('dfb_sections_data', $_POST)) {
            $sections_raw = (string) wp_unslash($_POST['dfb_sections_data']);
            $sections_decoded = json_decode($sections_raw, true);
            if (!is_array($sections_decoded)) {
                $sections_decoded = [];
            }
            $clean_sections = [];
            foreach ($sections_decoded as $section) {
                $title = isset($section['title']) ? sanitize_text_field((string) $section['title']) : '';
                $body  = isset($section['body']) ? wp_kses_post((string) $section['body']) : '';
                if ($title !== '' || $body !== '') {
                    $clean_sections[] = [
                        'title' => $title,
                        'body'  => $body,
                    ];
                }
            }
            update_option('dfb_sections', wp_json_encode($clean_sections));
        }

        // Email settings.
        $dfb_update_option_if_posted('dfb_email_subject', 'dfb_email_subject', static fn($v) => sanitize_text_field((string) $v));
        $dfb_update_option_if_posted('dfb_email_body', 'dfb_email_body', static fn($v) => wp_kses_post((string) $v));

        // Signature settings.
        $dfb_update_option_if_posted('dfb_signature_count', 'dfb_signature_count', static function ($v) {
            $n = intval($v);
            if ($n < 1) $n = 1;
            if ($n > 6) $n = 6;
            return $n;
        });
        $dfb_update_option_if_posted('dfb_signature_title', 'dfb_signature_title', static fn($v) => sanitize_text_field((string) $v));
        $dfb_update_option_if_posted('dfb_signature_description', 'dfb_signature_description', static fn($v) => wp_kses_post((string) $v));
        for ($i = 1; $i <= 6; $i++) {
            $dfb_update_option_if_posted('dfb_signature_' . $i . '_label', 'dfb_signature_' . $i . '_label', static fn($v) => sanitize_text_field((string) $v));
            $dfb_update_option_if_posted('dfb_signature_' . $i . '_text', 'dfb_signature_' . $i . '_text', static fn($v) => wp_kses_post((string) $v));
        }

        echo '<div class="updated notice is-dismissible"><p>' . esc_html__('Settings saved.', 'dynamic-form-builder') . '</p></div>';
    }

    $header_logo_id  = (int) get_option('dfb_header_logo_id', 0);
    $header_logo_url = $header_logo_id ? wp_get_attachment_image_url($header_logo_id, 'medium') : '';
    $header_text     = (string) get_option('dfb_header_text', '');
    $footer_text     = (string) get_option('dfb_footer_text', '');
    $sections_value  = get_option('dfb_sections', '[]');
    $sections        = json_decode((string) $sections_value, true);
    if (!is_array($sections)) {
        $sections = [];
    }

    $hide_questions_in_pdf = (int) get_option('dfb_hide_questions_in_pdf', 0) === 1;
    $hide_questions_and_answers_in_pdf = (int) get_option('dfb_hide_questions_and_answers_in_pdf', 0) === 1;

    // Load signature settings.
    $signature_count       = intval(get_option('dfb_signature_count', 2));
    if ($signature_count < 1) $signature_count = 1;
    if ($signature_count > 6) $signature_count = 6;
    $signature_title       = (string) get_option('dfb_signature_title', '');
    $signature_description = (string) get_option('dfb_signature_description', '');
    $signature_labels = [];
    $signature_texts  = [];
    for ($i = 1; $i <= 6; $i++) {
        $signature_labels[$i] = (string) get_option('dfb_signature_' . $i . '_label', '');
        $signature_texts[$i]  = (string) get_option('dfb_signature_' . $i . '_text', '');
    }

    // Load email settings (with sensible defaults).
    $email_subject = (string) get_option(
        'dfb_email_subject',
        __('Your generated document', 'dynamic-form-builder')
    );
    $email_body = (string) get_option(
        'dfb_email_body',
        __('Thank you for your order. Your generated document is attached.', 'dynamic-form-builder')
    );
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Form Builder Settings', 'dynamic-form-builder'); ?></h1>

        <h2 class="nav-tab-wrapper">
            <a href="#dfb-tab-general" class="nav-tab nav-tab-active" data-dfb-tab="general"><?php esc_html_e('General', 'dynamic-form-builder'); ?></a>
            <a href="#dfb-tab-signature" class="nav-tab" data-dfb-tab="signature"><?php esc_html_e('Signature', 'dynamic-form-builder'); ?></a>
            <a href="#dfb-tab-email" class="nav-tab" data-dfb-tab="email"><?php esc_html_e('Email', 'dynamic-form-builder'); ?></a>
        </h2>

        <form method="post" action="">
            <?php wp_nonce_field('dfb_save_settings', 'dfb_settings_nonce'); ?>

            <div id="dfb-tab-general" class="dfb-settings-tab dfb-settings-tab-active">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <label for="dfb_header_logo_id"><?php esc_html_e('Header Logo', 'dynamic-form-builder'); ?></label>
                            </th>
                            <td>
                                <div style="margin-bottom:10px;">
                                    <button type="button" class="button dfb-upload-logo"><?php esc_html_e('Upload / Select Logo', 'dynamic-form-builder'); ?></button>
                                    <button type="button" class="button dfb-remove-logo" <?php echo $header_logo_id ? '' : 'style="display:none;"'; ?>><?php esc_html_e('Remove', 'dynamic-form-builder'); ?></button>
                                </div>
                                <input type="hidden" id="dfb_header_logo_id" name="dfb_header_logo_id" value="<?php echo esc_attr($header_logo_id); ?>">
                                <div class="dfb-logo-preview" style="margin-top:10px;">
                                    <?php if ($header_logo_url) : ?>
                                        <img src="<?php echo esc_url($header_logo_url); ?>" alt="" style="max-height:80px;width:auto;">
                                    <?php endif; ?>
                                </div>
                                <p class="description"><?php esc_html_e('This logo will appear in the header of generated documents.', 'dynamic-form-builder'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="dfb_header_text"><?php esc_html_e('Header Text', 'dynamic-form-builder'); ?></label>
                            </th>
                            <td>
                                <textarea name="dfb_header_text" id="dfb_header_text" class="large-text" rows="3"><?php echo esc_textarea($header_text); ?></textarea>
                                <p class="description"><?php esc_html_e('Optional text displayed in the document header (for example company name, address, or contact details).', 'dynamic-form-builder'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="dfb_footer_text"><?php esc_html_e('Footer Text', 'dynamic-form-builder'); ?></label>
                            </th>
                            <td>
                                <textarea name="dfb_footer_text" id="dfb_footer_text" class="large-text" rows="3"><?php echo esc_textarea($footer_text); ?></textarea>
                                <p class="description"><?php esc_html_e('Optional text displayed above the page number at the bottom of the document (for example legal notice or contact details).', 'dynamic-form-builder'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label><?php esc_html_e('Document Sections', 'dynamic-form-builder'); ?></label>
                            </th>
                            <td>
                                <p class="description">
                                    <?php esc_html_e('Add as many sections as you need. Each section will appear as its own block in the generated document.', 'dynamic-form-builder'); ?>
                                </p>
                                <div id="dfb-sections-container"></div>
                                <p>
                                    <button type="button" class="button" id="dfb-add-section"><?php esc_html_e('Add Section', 'dynamic-form-builder'); ?></button>
                                </p>
                                <input type="hidden" id="dfb_sections_data" name="dfb_sections_data" value="<?php echo esc_attr(wp_json_encode($sections)); ?>">
                            </td>
                        </tr>

                        <tr>
                            <th scope="row"><?php esc_html_e('PDF output', 'dynamic-form-builder'); ?></th>
                            <td>
                                <label for="dfb_hide_questions_in_pdf">
                                    <input type="checkbox" name="dfb_hide_questions_in_pdf" id="dfb_hide_questions_in_pdf" value="1" <?php checked($hide_questions_in_pdf); ?>>
                                    <?php esc_html_e('Hide the questions in PDF', 'dynamic-form-builder'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When checked, question titles are hidden in the automatic answers list; answer values still appear. Your document template can still use placeholders such as {{question_1}}.', 'dynamic-form-builder'); ?>
                                </p>
                                <label for="dfb_hide_questions_and_answers_in_pdf" style="display:block;margin-top:10px;">
                                    <input type="checkbox" name="dfb_hide_questions_and_answers_in_pdf" id="dfb_hide_questions_and_answers_in_pdf" value="1" <?php checked($hide_questions_and_answers_in_pdf); ?>>
                                    <?php esc_html_e('Hide questions and answers in PDF (hide the automatic answers list)', 'dynamic-form-builder'); ?>
                                </label>
                                <p class="description">
                                    <?php esc_html_e('When checked, the automatic questions/answers list is not included in the PDF at all. Document templates can still use placeholders such as {{question_1}}.', 'dynamic-form-builder'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div id="dfb-tab-signature" class="dfb-settings-tab" style="display:none;">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <h2><?php esc_html_e('Signature Block', 'dynamic-form-builder'); ?></h2>
                            </th>
                            <td>
                                <p class="description">
                                    <?php esc_html_e('Configure the signature block that appears near the bottom of generated PDFs, above the footer.', 'dynamic-form-builder'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="dfb_signature_title"><?php esc_html_e('Signature Title', 'dynamic-form-builder'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="dfb_signature_title" id="dfb_signature_title" class="regular-text" value="<?php echo esc_attr($signature_title); ?>">
                                <p class="description"><?php esc_html_e('Main heading shown above the signature row.', 'dynamic-form-builder'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="dfb_signature_description"><?php esc_html_e('Signature Description', 'dynamic-form-builder'); ?></label>
                            </th>
                            <td>
                                <textarea name="dfb_signature_description" id="dfb_signature_description" class="large-text" rows="3"><?php echo esc_textarea($signature_description); ?></textarea>
                                <p class="description"><?php esc_html_e('Optional description text shown under the signature title.', 'dynamic-form-builder'); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="dfb_signature_count"><?php esc_html_e('Number of signatures', 'dynamic-form-builder'); ?></label>
                            </th>
                            <td>
                                <select name="dfb_signature_count" id="dfb_signature_count">
                                    <?php for ($i = 1; $i <= 6; $i++): ?>
                                        <option value="<?php echo esc_attr($i); ?>" <?php selected($signature_count, $i); ?>>
                                            <?php echo esc_html($i); ?>
                                        </option>
                                    <?php endfor; ?>
                                </select>
                                <p class="description">
                                    <?php esc_html_e('Select how many signature columns should appear in the PDF. Signatures are laid out 2 per row (e.g. 6 signatures become 3 rows).', 'dynamic-form-builder'); ?>
                                </p>
                            </td>
                        </tr>

                        <?php for ($sig = 1; $sig <= 6; $sig++): ?>
                        <tr class="dfb-signature-row" data-sig-index="<?php echo esc_attr($sig); ?>">
                            <th scope="row">
                                <label><?php echo esc_html(sprintf(__('Signature %d', 'dynamic-form-builder'), $sig)); ?></label>
                            </th>
                            <td>
                                <p>
                                    <label for="dfb_signature_<?php echo esc_attr($sig); ?>_label">
                                        <?php esc_html_e('Label (e.g. Applicant Signature)', 'dynamic-form-builder'); ?><br>
                                        <input type="text"
                                               name="dfb_signature_<?php echo esc_attr($sig); ?>_label"
                                               id="dfb_signature_<?php echo esc_attr($sig); ?>_label"
                                               class="regular-text"
                                               value="<?php echo esc_attr((string) ($signature_labels[$sig] ?? '')); ?>">
                                    </label>
                                </p>
                                <p>
                                    <label for="dfb_signature_<?php echo esc_attr($sig); ?>_text">
                                        <?php esc_html_e('Text below signature line', 'dynamic-form-builder'); ?><br>
                                        <input type="text"
                                               name="dfb_signature_<?php echo esc_attr($sig); ?>_text"
                                               id="dfb_signature_<?php echo esc_attr($sig); ?>_text"
                                               class="regular-text"
                                               value="<?php echo esc_attr((string) ($signature_texts[$sig] ?? '')); ?>">
                                    </label>
                                </p>
                            </td>
                        </tr>
                        <?php endfor; ?>
                    </tbody>
                </table>
            </div>

            <div id="dfb-tab-email" class="dfb-settings-tab" style="display:none;">
                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row">
                                <h2><?php esc_html_e('Email', 'dynamic-form-builder'); ?></h2>
                            </th>
                            <td>
                                <p class="description">
                                    <?php esc_html_e('Customize the subject and body of the email sent to customers with their generated document attached.', 'dynamic-form-builder'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="dfb_email_subject"><?php esc_html_e('Email Subject', 'dynamic-form-builder'); ?></label>
                            </th>
                            <td>
                                <input type="text" name="dfb_email_subject" id="dfb_email_subject" class="regular-text" value="<?php echo esc_attr($email_subject); ?>">
                                <p class="description">
                                    <?php esc_html_e('Subject line for the email that includes the generated document.', 'dynamic-form-builder'); ?>
                                </p>
                            </td>
                        </tr>

                        <tr>
                            <th scope="row">
                                <label for="dfb_email_body"><?php esc_html_e('Email Body', 'dynamic-form-builder'); ?></label>
                            </th>
                            <td>
                                <textarea name="dfb_email_body" id="dfb_email_body" class="large-text" rows="4"><?php echo esc_textarea($email_body); ?></textarea>
                                <p class="description">
                                    <?php esc_html_e('Main message of the email. Basic HTML is allowed.', 'dynamic-form-builder'); ?>
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <?php submit_button(__('Save Changes', 'dynamic-form-builder'), 'primary', 'dfb_settings_submit'); ?>
        </form>
    </div>

    <script type="text/javascript">
    jQuery(function($) {
        let fileFrame;

        // Simple tab switching between General and Signature settings.
        $('.nav-tab-wrapper').on('click', '.nav-tab', function(e) {
            e.preventDefault();

            const $tab = $(this);
            const target = $tab.data('dfb-tab');

            // Update active tab style.
            $('.nav-tab-wrapper .nav-tab').removeClass('nav-tab-active');
            $tab.addClass('nav-tab-active');

            // Show the selected panel and hide others.
            $('.dfb-settings-tab').hide();
            $('#dfb-tab-' + target).show();
        });

        $('.dfb-upload-logo').on('click', function(e) {
            e.preventDefault();

            if (fileFrame) {
                fileFrame.open();
                return;
            }

            fileFrame = wp.media({
                title: '<?php echo esc_js(__('Select Header Logo', 'dynamic-form-builder')); ?>',
                button: { text: '<?php echo esc_js(__('Use this logo', 'dynamic-form-builder')); ?>' },
                multiple: false
            });

            fileFrame.on('select', function() {
                const attachment = fileFrame.state().get('selection').first().toJSON();
                $('#dfb_header_logo_id').val(attachment.id);
                $('.dfb-logo-preview').html('<img src="' + attachment.url + '" style="max-height:80px;width:auto;" alt="">');
                $('.dfb-remove-logo').show();
            });

            fileFrame.open();
        });

        $('.dfb-remove-logo').on('click', function(e) {
            e.preventDefault();
            $('#dfb_header_logo_id').val('');
            $('.dfb-logo-preview').empty();
            $(this).hide();
        });

        // Dynamic document sections
        const sectionsInput = $('#dfb_sections_data');
        const sectionsContainer = $('#dfb-sections-container');

        function getSections() {
            try {
                const data = JSON.parse(sectionsInput.val() || '[]');
                return Array.isArray(data) ? data : [];
            } catch (e) {
                return [];
            }
        }

        function renderSections() {
            const sections = getSections();
            sectionsContainer.empty();

            if (!sections.length) {
                sections.push({ title: '', body: '' });
            }

            sections.forEach((section, index) => {
                const row = $('<div class="dfb-section-row" style="border:1px solid #ddd;padding:12px;margin-bottom:10px;background:#fafafa;"></div>');
                row.append(
                    '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">' +
                        '<strong>' + '<?php echo esc_js(__('Section', 'dynamic-form-builder')); ?> ' + (index + 1) + '</strong>' +
                        '<button type="button" class="button-link-delete dfb-remove-section" data-index="' + index + '"><?php echo esc_js(__('Remove', 'dynamic-form-builder')); ?></button>' +
                    '</div>'
                );
                row.append(
                    '<p><label>' +
                    '<?php echo esc_js(__('Section Title', 'dynamic-form-builder')); ?>' +
                    '<br><input type="text" class="widefat dfb-section-title" data-index="' + index + '" value="' + $('<div/>').text(section.title || '').html() + '"></label></p>'
                );
                row.append(
                    '<p><label>' +
                    '<?php echo esc_js(__('Section Content', 'dynamic-form-builder')); ?>' +
                    '<br><textarea rows="4" class="widefat dfb-section-body" data-index="' + index + '">' + $('<div/>').text(section.body || '').html() + '</textarea></label></p>'
                );
                sectionsContainer.append(row);
            });
        }

        function syncSectionsFromUI() {
            const sections = [];
            sectionsContainer.find('.dfb-section-row').each(function() {
                const title = $(this).find('.dfb-section-title').val() || '';
                const body  = $(this).find('.dfb-section-body').val() || '';
                sections.push({ title, body });
            });
            sectionsInput.val(JSON.stringify(sections));
        }

        sectionsContainer.on('input', '.dfb-section-title, .dfb-section-body', function() {
            syncSectionsFromUI();
        });

        $('#dfb-add-section').on('click', function(e) {
            e.preventDefault();
            const sections = getSections();
            sections.push({ title: '', body: '' });
            sectionsInput.val(JSON.stringify(sections));
            renderSections();
        });

        sectionsContainer.on('click', '.dfb-remove-section', function(e) {
            e.preventDefault();
            const index = parseInt($(this).data('index'), 10);
            let sections = getSections();
            sections.splice(index, 1);
            sectionsInput.val(JSON.stringify(sections));
            renderSections();
        });

        renderSections();

        // Signature count: show only the selected number of signature fields (1..6).
        function applySignatureCount() {
            const count = parseInt($('#dfb_signature_count').val() || '2', 10);
            $('.dfb-signature-row').each(function() {
                const idx = parseInt($(this).data('sig-index'), 10);
                if (!idx || idx <= count) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        }
        $(document).on('change', '#dfb_signature_count', applySignatureCount);
        applySignatureCount();
    });
    </script>
    <?php
}

