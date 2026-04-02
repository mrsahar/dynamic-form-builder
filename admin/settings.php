<?php
if (!defined('ABSPATH')) exit;

function dfb_settings_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    // Handle form submission
    if (isset($_POST['dfb_settings_submit'])) {
        check_admin_referer('dfb_save_settings', 'dfb_settings_nonce');

        $logo_id     = isset($_POST['dfb_header_logo_id']) ? intval($_POST['dfb_header_logo_id']) : 0;
        $header_text = isset($_POST['dfb_header_text']) ? wp_kses_post(wp_unslash($_POST['dfb_header_text'])) : '';
        $footer_text = isset($_POST['dfb_footer_text']) ? wp_kses_post(wp_unslash($_POST['dfb_footer_text'])) : '';

        $sections_raw = isset($_POST['dfb_sections_data']) ? wp_unslash($_POST['dfb_sections_data']) : '[]';
        $sections_decoded = json_decode($sections_raw, true);
        if (!is_array($sections_decoded)) {
            $sections_decoded = [];
        }
        $clean_sections = [];
        foreach ($sections_decoded as $section) {
            $title = isset($section['title']) ? sanitize_text_field($section['title']) : '';
            $body  = isset($section['body']) ? wp_kses_post($section['body']) : '';
            if ($title !== '' || $body !== '') {
                $clean_sections[] = [
                    'title' => $title,
                    'body'  => $body,
                ];
            }
        }

        update_option('dfb_header_logo_id', $logo_id);
        update_option('dfb_header_text', $header_text);
        update_option('dfb_footer_text', $footer_text);
        update_option('dfb_sections', wp_json_encode($clean_sections));

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
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('Form Builder Settings', 'dynamic-form-builder'); ?></h1>

        <form method="post" action="">
            <?php wp_nonce_field('dfb_save_settings', 'dfb_settings_nonce'); ?>

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
                </tbody>
            </table>

            <?php submit_button(__('Save Changes', 'dynamic-form-builder'), 'primary', 'dfb_settings_submit'); ?>
        </form>
    </div>

    <script type="text/javascript">
    jQuery(function($) {
        let fileFrame;

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
    });
    </script>
    <?php
}

