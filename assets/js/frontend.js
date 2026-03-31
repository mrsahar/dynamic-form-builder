jQuery(document).ready(function($) {
    var $steps = $('.dfb-step');
    if (!$steps.length) {
        return;
    }

    var currentStep = 1;
    var totalSteps = $steps.length;
    var $nextBtn = $('#dfb-next-btn');
    var $prevBtn = $('#dfb-prev-btn');
    var $submitBtn = $('#dfb-submit-btn');
    var $progressBar = $('#dfb-progress-bar');
    var $progressText = $('#dfb-progress-text');

    function updateUI() {
        $steps.hide();
        $('.dfb-step[data-step="' + currentStep + '"]').show();

        var percent = Math.round((currentStep / totalSteps) * 100);
        $progressBar.css('width', percent + '%');
        $progressText.text('Step ' + currentStep + ' of ' + totalSteps);

        if (currentStep === 1) {
            $prevBtn.hide();
        } else {
            $prevBtn.show();
        }

        if (currentStep === totalSteps) {
            $nextBtn.hide();
            $submitBtn.show();
        } else {
            $nextBtn.show();
            $submitBtn.hide();
        }
    }

    function validateCurrentStep() {
        var valid = true;
        var $current = $('.dfb-step[data-step="' + currentStep + '"]');

        // Check normal fields first (text/textarea/select).
        $current.find('input[required]:not([type="checkbox"]):not([type="radio"]), textarea[required], select[required]').each(function() {
            if (!$(this).val()) {
                valid = false;
                return false;
            }
        });

        if (!valid) return valid;

        // Radio required: at least one in the group must be checked.
        $current.find('input[type="radio"][required]').each(function() {
            var name = $(this).attr('name');
            if (!name) return;
            if ($current.find('input[type="radio"][name="' + name + '"]:checked').length === 0) {
                valid = false;
                return false;
            }
        });

        if (!valid) return valid;

        // Checkbox required: at least one in the group must be checked.
        $current.find('input[type="checkbox"][required]').each(function() {
            var name = $(this).attr('name');
            if (!name) return;
            if ($current.find('input[type="checkbox"][name="' + name + '"]:checked').length === 0) {
                valid = false;
                return false;
            }
        });

        return valid;
    }

    $nextBtn.on('click', function() {
        if (!validateCurrentStep()) {
            alert('Please complete required fields before continuing.');
            return;
        }

        if (currentStep < totalSteps) {
            currentStep += 1;
            updateUI();
        }
    });

    $prevBtn.on('click', function() {
        if (currentStep > 1) {
            currentStep -= 1;
            updateUI();
        }
    });

    updateUI();
});
