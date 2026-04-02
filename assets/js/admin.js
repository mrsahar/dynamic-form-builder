jQuery(document).ready(function($) {
    let questionIndex = $('.question-row').length;
    
    // Add new question
    $('#add-question').on('click', function() {
        const newQuestion = createQuestionRow(questionIndex);
        $('#questions-container').append(newQuestion);
        questionIndex++;
    });
    
    // Remove question
    $(document).on('click', '.remove-question', function() {
        if ($('.question-row').length > 1) {
            $(this).closest('.question-row').remove();
            updateQuestionNumbers();
        } else {
            alert('You must have at least one question!');
        }
    });
    
    // Show/hide options field based on input type
    $(document).on('change', '.input-type-select', function() {
        const row = $(this).closest('.question-row');
        const inputType = $(this).val();
        const optionsRow = row.find('.options-row');
        
        if (['dropdown', 'radio', 'checkbox'].includes(inputType)) {
            optionsRow.show();
        } else {
            optionsRow.hide();
        }
    });
    
    // Image upload
    $(document).on('click', '.upload-image-button', function(e) {
        e.preventDefault();
        
        const button = $(this);
        const row = button.closest('.question-row');
        
        const mediaUploader = wp.media({
            title: 'Select Image',
            button: { text: 'Use this image' },
            multiple: false
        });
        
        mediaUploader.on('select', function() {
            const attachment = mediaUploader.state().get('selection').first().toJSON();
            row.find('.question-image-url').val(attachment.url);
            row.find('.image-preview').html('<img src="' + attachment.url + '" style="max-width: 200px; height: auto;">');
            row.find('.remove-image-button').show();
        });
        
        mediaUploader.open();
    });
    
    // Remove image
    $(document).on('click', '.remove-image-button', function(e) {
        e.preventDefault();
        const row = $(this).closest('.question-row');
        row.find('.question-image-url').val('');
        row.find('.image-preview').html('');
        $(this).hide();
    });
    
    function createQuestionRow(index) {
        return `
            <div class="question-row" data-index="${index}" style="border: 1px solid #ccc; padding: 15px; margin-bottom: 15px; background: #f9f9f9;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                    <h3 style="margin: 0;">Question #${index + 1}</h3>
                    <button type="button" class="button button-small remove-question" style="background: #dc3232; color: white;">Remove Question</button>
                </div>
                
                <table class="form-table">
                    <tr>
                        <th style="width: 200px;"><label>Question Title *</label></th>
                        <td><input type="text" name="questions[${index}][title]" class="regular-text" placeholder="e.g., What is your full name?" required></td>
                    </tr>
                    <tr>
                        <th><label>Question Description</label></th>
                        <td><textarea name="questions[${index}][description]" class="large-text" rows="3"></textarea></td>
                    </tr>
                    <tr>
                        <th><label>Video URL</label></th>
                        <td><input type="url" name="questions[${index}][video_url]" class="regular-text" placeholder="https://youtube.com/watch?v=..."></td>
                    </tr>
                    <tr>
                        <th><label>Image</label></th>
                        <td>
                            <input type="hidden" name="questions[${index}][image_url]" class="question-image-url">
                            <button type="button" class="button upload-image-button">Upload Image</button>
                            <button type="button" class="button remove-image-button" style="display: none;">Remove</button>
                            <div class="image-preview" style="margin-top: 10px;"></div>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Input Type *</label></th>
                        <td>
                            <select name="questions[${index}][input_type]" class="input-type-select">
                                <option value="text">Text (single line)</option>
                                <option value="textarea">Textarea (multiple lines)</option>
                                <option value="email">Email</option>
                                <option value="number">Number</option>
                                <option value="date">Date Picker</option>
                                <option value="dropdown">Dropdown</option>
                                <option value="radio">Radio Buttons</option>
                                <option value="checkbox">Checkboxes</option>
                            </select>
                        </td>
                    </tr>
                    <tr class="options-row" style="display: none;">
                        <th><label>Options</label></th>
                        <td>
                            <textarea name="questions[${index}][options]" class="regular-text" rows="4" placeholder="Enter one option per line"></textarea>
                            <p class="description">One option per line</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Required Field</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="questions[${index}][required]" value="1" checked>
                                User must answer this question
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Depends on Question #</label></th>
                        <td>
                            <input type="number" min="1"
                                   name="questions[${index}][depends_on_question_order]"
                                   class="regular-text"
                                   placeholder="e.g., 1">
                            <p class="description">Leave empty to always show this question.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Show when answer equals</label></th>
                        <td>
                            <input type="text"
                                   name="questions[${index}][depends_on_value]"
                                   class="regular-text"
                                   placeholder="e.g., Yes (must match option value exactly)">
                        </td>
                    </tr>
                </table>
            </div>
        `;
    }
    
    function updateQuestionNumbers() {
        $('.question-row').each(function(index) {
            $(this).find('h3').text('Question #' + (index + 1));
        });
    }
});