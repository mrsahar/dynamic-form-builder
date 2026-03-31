<?php
if (!defined('ABSPATH')) exit;

function dfb_forms_list_page() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    global $wpdb;
    $table_forms = $wpdb->prefix . 'dfb_forms';
    
    // Handle delete
    if (
        isset($_GET['action'], $_GET['form_id'], $_GET['_wpnonce']) &&
        $_GET['action'] === 'delete' &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'dfb_form_action_' . intval($_GET['form_id']))
    ) {
        $form_id = intval($_GET['form_id']);
        $wpdb->delete($table_forms, ['id' => $form_id]);
        $wpdb->delete($wpdb->prefix . 'dfb_questions', ['form_id' => $form_id]);
        $wpdb->delete($wpdb->prefix . 'dfb_templates', ['form_id' => $form_id]);
        echo '<div class="notice notice-success"><p>Form deleted successfully!</p></div>';
    }
    
    // Handle activate/deactivate
    if (
        isset($_GET['action'], $_GET['form_id'], $_GET['_wpnonce']) &&
        in_array($_GET['action'], ['activate', 'deactivate'], true) &&
        wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'dfb_form_action_' . intval($_GET['form_id']))
    ) {
        $form_id = intval($_GET['form_id']);
        $status = ($_GET['action'] == 'activate') ? 1 : 0;
        $wpdb->update($table_forms, ['is_active' => $status], ['id' => $form_id]);
        echo '<div class="notice notice-success"><p>Form status updated!</p></div>';
    }
    
    // Get all forms
    $forms = $wpdb->get_results("SELECT * FROM $table_forms ORDER BY created_at DESC");
    ?>
    
    <div class="wrap">
        <h1 class="wp-heading-inline">All Forms</h1>
        <a href="<?php echo admin_url('admin.php?page=dfb-add-form'); ?>" class="page-title-action">Add New</a>
        <hr class="wp-header-end">
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Form Name</th>
                    <th>Product</th>
                    <th>Status</th>
                    <th>Questions</th>
                    <th>Responses</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($forms)): ?>
                    <tr>
                        <td colspan="8">No forms found. <a href="<?php echo admin_url('admin.php?page=dfb-add-form'); ?>">Create one now</a></td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($forms as $form): ?>
                        <?php
                        $product = get_post($form->woo_product_id);
                        $question_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}dfb_questions WHERE form_id = %d",
                            $form->id
                        ));
                        $response_count = $wpdb->get_var($wpdb->prepare(
                            "SELECT COUNT(*) FROM {$wpdb->prefix}dfb_responses WHERE form_id = %d",
                            $form->id
                        ));
                        ?>
                        <?php
                        $edit_url = admin_url('admin.php?page=dfb-add-form&form_id=' . $form->id);
                        $activate_url = wp_nonce_url(
                            admin_url('admin.php?page=dfb-forms&action=activate&form_id=' . $form->id),
                            'dfb_form_action_' . $form->id
                        );
                        $deactivate_url = wp_nonce_url(
                            admin_url('admin.php?page=dfb-forms&action=deactivate&form_id=' . $form->id),
                            'dfb_form_action_' . $form->id
                        );
                        $delete_url = wp_nonce_url(
                            admin_url('admin.php?page=dfb-forms&action=delete&form_id=' . $form->id),
                            'dfb_form_action_' . $form->id
                        );
                        ?>
                        <tr>
                            <td><?php echo intval($form->id); ?></td>
                            <td><strong><?php echo esc_html($form->form_name); ?></strong></td>
                            <td><?php echo $product ? esc_html($product->post_title) : 'N/A'; ?></td>
                            <td>
                                <?php if ($form->is_active): ?>
                                    <span style="color: green;">● Active</span>
                                <?php else: ?>
                                    <span style="color: red;">● Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo intval($question_count); ?></td>
                            <td><?php echo intval($response_count); ?></td>
                            <td><?php echo date('M d, Y', strtotime($form->created_at)); ?></td>
                            <td>
                                <a href="<?php echo esc_url($edit_url); ?>">Edit</a> |
                                
                                <?php if ($form->is_active): ?>
                                    <a href="<?php echo esc_url($deactivate_url); ?>">Deactivate</a> |
                                <?php else: ?>
                                    <a href="<?php echo esc_url($activate_url); ?>">Activate</a> |
                                <?php endif; ?>
                                
                                <a href="<?php echo esc_url($delete_url); ?>" 
                                   onclick="return confirm('Are you sure you want to delete this form?');" 
                                   style="color: red;">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}