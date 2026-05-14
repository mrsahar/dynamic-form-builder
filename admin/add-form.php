<?php
if (!defined('ABSPATH')) exit;

function dfb_add_form_page() {
    global $wpdb;
    
    // Check if editing
    $form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : 0;
    $is_edit = $form_id > 0;
    
    // Get form data if editing
    if ($is_edit) {
        $form = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dfb_forms WHERE id = %d",
            $form_id
        ));
        
        $questions = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dfb_questions WHERE form_id = %d ORDER BY question_order ASC",
            $form_id
        ));
        
        $template = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}dfb_templates WHERE form_id = %d",
            $form_id
        ));
    }
    
    // Handle form submission
    if (isset($_POST['dfb_save_form'])) {
        dfb_save_form_data();
    }
    
    ?>
    <div class="wrap">
        <h1><?php echo $is_edit ? 'Edit Form' : 'Add New Form'; ?></h1>
        
        <form method="post" id="dfb-form-builder">
            <?php wp_nonce_field('dfb_save_form', 'dfb_nonce'); ?>
            <input type="hidden" name="form_id" value="<?php echo $form_id; ?>">
            
            <!-- SECTION 1: Form Settings -->
            <div class="postbox" style="margin-top: 20px;">
                <div class="postbox-header">
                    <h2>Form Settings</h2>
                </div>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th><label for="form_name">Form Name *</label></th>
                            <td>
                                <input type="text" 
                                       id="form_name" 
                                       name="form_name" 
                                       class="regular-text" 
                                       value="<?php echo $is_edit ? esc_attr($form->form_name) : ''; ?>" 
                                       required>
                                <p class="description">Example: Rental Agreement Form, Legal Document Form</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="woo_product_id">WooCommerce Product *</label></th>
                            <td>
                                <select id="woo_product_id" name="woo_product_id" class="regular-text" required>
                                    <option value="">Select Product</option>
                                    <?php
                                    $products = get_posts([
                                        'post_type' => 'product',
                                        'posts_per_page' => -1,
                                        'post_status' => 'publish'
                                    ]);
                                    foreach ($products as $product):
                                        $selected = ($is_edit && $form->woo_product_id == $product->ID) ? 'selected' : '';
                                    ?>
                                        <option value="<?php echo $product->ID; ?>" <?php echo $selected; ?>>
                                            <?php echo esc_html($product->post_title); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="description">Form will redirect to this product after submission</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="is_active">Form Status</label></th>
                            <td>
                                <label>
                                    <input type="checkbox" 
                                           id="is_active" 
                                           name="is_active" 
                                           value="1" 
                                           <?php echo ($is_edit && $form->is_active) ? 'checked' : 'checked'; ?>>
                                    Active (users can submit this form)
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- SECTION 2: Question Builder -->
            <div class="postbox" style="margin-top: 20px;">
                <div class="postbox-header">
                    <h2>Question Builder</h2>
                </div>
                <div class="inside">
                    <div id="questions-container">
                        <?php if ($is_edit && !empty($questions)): ?>
                            <?php foreach ($questions as $index => $question): ?>
                                <?php echo dfb_render_question_row($index, $question); ?>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php echo dfb_render_question_row(0); ?>
                        <?php endif; ?>
                    </div>
                    
                    <button type="button" class="button button-secondary" id="add-question">
                        + Add Another Question
                    </button>
                </div>
            </div>
            
            <!-- SECTION 3: Document Template -->
            <div class="postbox" style="margin-top: 20px;">
                <div class="postbox-header">
                    <h2>Document Template</h2>
                </div>
                <div class="inside">
                    <p class="description" style="margin-bottom: 10px;">
                        <strong>Available Placeholders:</strong><br>
                        <code>{{user_name}}</code> - User's name<br>
                        <code>{{user_email}}</code> - User's email<br>
                        <code>{{question_1}}</code> - Answer to Question 1<br>
                        <code>{{question_2}}</code> - Answer to Question 2<br>
                        <code>{{current_date}}</code> - Current date<br>
                        And so on for each question...<br><br>
                        <strong>Spacing shortcuts:</strong><br>
                        <code>{{break}}</code> (or <code>{{beark}}</code>) - force a new line<br>
                        <code>{{space}}</code> - insert one visible space (non-breaking)<br><br>
                        <strong>Conditional blocks (PDF / document template):</strong><br>
                        <code>{{#if question_1}}...{{else}}...{{/if}}</code> — show the first part when Question 1 is “yes-like” (non-empty and not No/False/0), otherwise the part after <code>{{else}}</code>. You can use <code>question_1</code>, <code>1</code>, or <code>q1</code> as the key. Omit <code>{{else}}...{{/if}}</code> for a simple on/off block.
                    </p>
                    
                    <?php
                    $template_content = $is_edit && $template ? $template->template_content : '';
                    wp_editor($template_content, 'template_content', [
                        'textarea_name' => 'template_content',
                        'textarea_rows' => 15,
                        'teeny' => false,
                        'media_buttons' => false
                    ]);
                    ?>

                    <div style="margin-top: 10px; display:flex; gap:10px; align-items:center;">
                        <button type="button" class="button" id="dfb-preview-pdf">
                            Preview PDF
                        </button>
                        <span class="description" style="margin:0;">
                            Preview uses sample answers (Answer 1, Answer 2, …) and your current editor content.
                        </span>
                    </div>
                    
                    <p class="description" style="margin-top: 10px;">
                        <?php esc_html_e('Example: "This agreement is made on {{current_date}} between {{question_1}} and {{question_2}}..."', 'dynamic-form-builder'); ?>
                    </p>
                    <p class="description" style="color:#646970;">
                        <?php esc_html_e('Tip: Type your document here using placeholders only. Do not copy the gray help text above into the editor — that text would appear in the PDF.', 'dynamic-form-builder'); ?>
                    </p>
                    <p class="description" style="color:#646970;">
                        <?php esc_html_e('Tip: Use {{break}} for a forced line break and {{space}} for a fixed space in the generated PDF.', 'dynamic-form-builder'); ?>
                    </p>
                </div>
            </div>
            
            <!-- Save Button -->
            <p class="submit">
                <button type="submit" name="dfb_save_form" class="button button-primary button-large">
                    <?php echo $is_edit ? 'Update Form' : 'Create Form'; ?>
                </button>
                <a href="<?php echo admin_url('admin.php?page=dfb-forms'); ?>" class="button button-secondary button-large">
                    Cancel
                </a>
            </p>
        </form>
    </div>
    <?php
}

// Render question row HTML
function dfb_render_question_row($index, $question = null) {
    $question_id = $question ? $question->id : '';
    $title = $question ? $question->question_title : '';
    $description = $question ? $question->question_description : '';
    $video_url = $question ? $question->video_url : '';
    $image_url = $question ? $question->image_url : '';
    $input_type = $question ? $question->input_type : 'text';
    if ($input_type === 'file') {
        $input_type = 'text';
    }
    $input_options = $question ? $question->input_options : '';
    $is_required = $question ? $question->is_required : 1;
    $depends_on_question_order = $question && isset($question->depends_on_question_order)
        ? $question->depends_on_question_order
        : '';
    $depends_on_value = $question && isset($question->depends_on_value)
        ? $question->depends_on_value
        : '';
    
    ob_start();
    ?>
    <div class="question-row" data-index="<?php echo $index; ?>" style="border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; background: #f9f9f9;">
        <input type="hidden" name="questions[<?php echo $index; ?>][id]" value="<?php echo $question_id; ?>">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <h3 style="margin: 0;">Question #<?php echo $index + 1; ?></h3>
            <button type="button" class="button button-small remove-question" style="background: #dc3232; color: white;">
                Remove Question
            </button>
        </div>
        
        <table class="form-table">
            <tr>
                <th style="width: 200px;"><label>Question Title *</label></th>
                <td>
                    <input type="text" 
                           name="questions[<?php echo $index; ?>][title]" 
                           class="regular-text" 
                           value="<?php echo esc_attr($title); ?>" 
                           placeholder="e.g., What is your full name?" 
                           required>
                </td>
            </tr>
            <tr>
                <th><label>Question Description</label></th>
                <td>
                    <textarea name="questions[<?php echo $index; ?>][description]" 
                              class="large-text" 
                              rows="3" 
                              placeholder="Optional help text for users"><?php echo esc_textarea($description); ?></textarea>
                </td>
            </tr>
            <tr>
                <th><label>Video URL</label></th>
                <td>
                    <input type="url" 
                           name="questions[<?php echo $index; ?>][video_url]" 
                           class="regular-text" 
                           value="<?php echo esc_url($video_url); ?>" 
                           placeholder="https://youtube.com/watch?v=...">
                    <p class="description">YouTube or Vimeo video link</p>
                </td>
            </tr>
            <tr>
                <th><label>Image</label></th>
                <td>
                    <input type="hidden" 
                           name="questions[<?php echo $index; ?>][image_url]" 
                           class="question-image-url" 
                           value="<?php echo esc_url($image_url); ?>">
                    <button type="button" class="button upload-image-button">Upload Image</button>
                    <button type="button" class="button remove-image-button" style="display: <?php echo $image_url ? 'inline-block' : 'none'; ?>;">Remove</button>
                    <div class="image-preview" style="margin-top: 10px;">
                        <?php if ($image_url): ?>
                            <img src="<?php echo esc_url($image_url); ?>" style="max-width: 200px; height: auto;">
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <tr>
                <th><label>Input Type *</label></th>
                <td>
                    <select name="questions[<?php echo $index; ?>][input_type]" class="input-type-select">
                        <option value="text" <?php selected($input_type, 'text'); ?>>Text (single line)</option>
                        <option value="textarea" <?php selected($input_type, 'textarea'); ?>>Textarea (multiple lines)</option>
                        <option value="email" <?php selected($input_type, 'email'); ?>>Email</option>
                        <option value="number" <?php selected($input_type, 'number'); ?>>Number</option>
                        <option value="date" <?php selected($input_type, 'date'); ?>>Date Picker</option>
                        <option value="yes_no" <?php selected($input_type, 'yes_no'); ?>>Yes / No</option>
                        <option value="dropdown" <?php selected($input_type, 'dropdown'); ?>>Dropdown</option>
                        <option value="radio" <?php selected($input_type, 'radio'); ?>>Radio Buttons</option>
                        <option value="checkbox" <?php selected($input_type, 'checkbox'); ?>>Checkboxes</option>
                    </select>
                </td>
            </tr>
            <tr class="options-row" style="display: <?php echo in_array($input_type, ['dropdown', 'radio', 'checkbox'], true) ? 'table-row' : 'none'; ?>;">
                <th><label>Options</label></th>
                <td>
                    <textarea name="questions[<?php echo $index; ?>][options]" 
                              class="regular-text" 
                              rows="4" 
                              placeholder="Enter one option per line&#10;Option 1&#10;Option 2&#10;Option 3"><?php echo esc_textarea($input_options); ?></textarea>
                    <p class="description">One option per line</p>
                </td>
            </tr>
            <tr>
                <th><label>Required Field</label></th>
                <td>
                    <label>
                        <input type="checkbox" 
                               name="questions[<?php echo $index; ?>][required]" 
                               value="1" 
                               <?php checked($is_required, 1); ?>>
                        User must answer this question
                    </label>
                </td>
            </tr>
            <tr>
                <th><label>Depends on Question #</label></th>
                <td>
                    <input type="number"
                           min="1"
                           name="questions[<?php echo $index; ?>][depends_on_question_order]"
                           class="regular-text"
                           value="<?php echo is_numeric($depends_on_question_order) ? esc_attr((string) $depends_on_question_order) : ''; ?>"
                           placeholder="e.g., 1">
                    <p class="description">Leave empty to always show this question.</p>
                </td>
            </tr>
            <tr>
                <th><label>Show when answer equals</label></th>
                <td>
                    <input type="text"
                           name="questions[<?php echo $index; ?>][depends_on_value]"
                           class="regular-text"
                           value="<?php echo esc_attr((string) $depends_on_value); ?>"
                           placeholder="e.g., Yes (must match option value exactly)">
                </td>
            </tr>
        </table>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * Create (or reuse) a WordPress Page for a form shortcode.
 *
 * @param int    $form_id
 * @param string $form_name
 * @return int Page ID or 0 on failure.
 */
function dfb_ensure_form_page($form_id, $form_name) {
    $form_id = (int) $form_id;
    if ($form_id <= 0 || !function_exists('wp_insert_post')) {
        return 0;
    }

    // Reuse any existing page already associated with this form.
    $existing = get_posts([
        'post_type'      => 'page',
        'post_status'    => ['publish', 'draft', 'pending', 'private'],
        'fields'         => 'ids',
        'posts_per_page' => 1,
        'meta_key'       => '_dfb_form_id',
        'meta_value'     => (string) $form_id,
    ]);
    if (!empty($existing) && isset($existing[0])) {
        return (int) $existing[0];
    }

    $title = $form_name !== '' ? $form_name : ('Form ' . $form_id);

    $page_id = wp_insert_post([
        'post_type'    => 'page',
        'post_status'  => 'publish',
        'post_title'   => $title,
        'post_content' => '[dfb_form id="' . $form_id . '"]',
    ], true);

    if (is_wp_error($page_id)) {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('DFB: failed to create form page for form ' . $form_id . ' => ' . $page_id->get_error_message());
        }
        return 0;
    }

    $page_id = (int) $page_id;
    if ($page_id > 0) {
        update_post_meta($page_id, '_dfb_form_id', (string) $form_id);
    }
    return $page_id;
}

// Save form data
function dfb_save_form_data() {
    if (!isset($_POST['dfb_nonce']) || !wp_verify_nonce($_POST['dfb_nonce'], 'dfb_save_form')) {
        wp_die('Security check failed');
    }

    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }
    
    global $wpdb;
    
    $form_id = intval($_POST['form_id']);
    $is_new_form = $form_id <= 0;
    $form_name = sanitize_text_field($_POST['form_name']);
    $woo_product_id = intval($_POST['woo_product_id']);
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    
    $form_data = [
        'form_name' => $form_name,
        'woo_product_id' => $woo_product_id,
        'is_active' => $is_active
    ];

    $existing_form_id = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}dfb_forms WHERE woo_product_id = %d AND id != %d",
        $woo_product_id,
        $form_id
    ));
    if ($existing_form_id) {
        wp_die('This WooCommerce product is already assigned to another form.');
    }
    
    // Insert or update form
    if ($form_id > 0) {
        $wpdb->update($wpdb->prefix . 'dfb_forms', $form_data, ['id' => $form_id]);
    } else {
        $wpdb->insert($wpdb->prefix . 'dfb_forms', $form_data);
        $form_id = $wpdb->insert_id;
    }

    // Auto-create a page for new forms.
    if ($is_new_form && $form_id > 0) {
        dfb_ensure_form_page($form_id, $form_name);
    }
    
    // Delete old questions
    $wpdb->delete($wpdb->prefix . 'dfb_questions', ['form_id' => $form_id]);
    
    // Save questions
    if (isset($_POST['questions']) && is_array($_POST['questions'])) {
        foreach ($_POST['questions'] as $order => $question) {
            $input_type = sanitize_text_field($question['input_type']);
            if ($input_type === 'file') {
                $input_type = 'text';
            }

            $question_data = [
                'form_id' => $form_id,
                'question_title' => sanitize_text_field($question['title']),
                'question_description' => sanitize_textarea_field($question['description']),
                'video_url' => esc_url_raw($question['video_url']),
                'image_url' => esc_url_raw($question['image_url']),
                'input_type' => $input_type,
                'input_options' => sanitize_textarea_field($question['options']),
                'is_required' => isset($question['required']) ? 1 : 0,
                'question_order' => $order,
                'depends_on_question_order' => (isset($question['depends_on_question_order']) && $question['depends_on_question_order'] !== '')
                    ? intval($question['depends_on_question_order'])
                    : null,
                'depends_on_value' => (isset($question['depends_on_value']) && trim((string) $question['depends_on_value']) !== '')
                    ? sanitize_text_field((string) $question['depends_on_value'])
                    : null,
            ];

            // Normalize invalid dependency numbers to NULL.
            if (isset($question_data['depends_on_question_order']) && intval($question_data['depends_on_question_order']) <= 0) {
                $question_data['depends_on_question_order'] = null;
            }
            
            $wpdb->insert($wpdb->prefix . 'dfb_questions', $question_data);
        }
    }
    
    // Save template
    $template_content = wp_kses_post($_POST['template_content']);
    
    $existing_template = $wpdb->get_var($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}dfb_templates WHERE form_id = %d",
        $form_id
    ));
    
    if ($existing_template) {
        $wpdb->update(
            $wpdb->prefix . 'dfb_templates',
            ['template_content' => $template_content],
            ['form_id' => $form_id]
        );
    } else {
        $wpdb->insert($wpdb->prefix . 'dfb_templates', [
            'form_id' => $form_id,
            'template_content' => $template_content
        ]);
    }
    
    // Redirect with success message
    wp_safe_redirect(admin_url('admin.php?page=dfb-forms&message=success'));
    exit;
}