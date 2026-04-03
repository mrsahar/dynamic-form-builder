jQuery(document).ready(function($) {
    var $form = $('.dfb-step-form');
    var $steps = $('.dfb-step', $form);
    if (!$steps.length) return;

    var $nextBtn = $('#dfb-next-btn');
    var $prevBtn = $('#dfb-prev-btn');
    var $submitBtn = $('#dfb-submit-btn');
    var $progressBar = $('#dfb-progress-bar');
    var $progressText = $('#dfb-progress-text');

    var stepElsByOrder = {};
    var allOrders = [];

    $steps.each(function() {
        var $el = $(this);
        var order = parseInt($el.attr('data-dfb-step-order') || $el.attr('data-step') || '', 10);
        if (!order) return;
        stepElsByOrder[order] = $el;
        allOrders.push(order);
    });

    allOrders.sort(function(a, b) {
        return a - b;
    });

    var visibleOrders = [];
    var currentOrder = null;

    function clearStepErrors($step) {
        $step.find('.dfb-input-wrap')
            .removeClass('dfb-input-wrap--error')
            .find('.dfb-error-text').remove();
    }

    function showFieldError($field, message) {
        var $wrap = $field.closest('.dfb-input-wrap');
        if (!$wrap.length) {
            return;
        }
        $wrap.addClass('dfb-input-wrap--error');
        if (!$wrap.find('.dfb-error-text').length) {
            $('<p class="dfb-error-text"></p>').text(message).appendTo($wrap);
        }
    }

    function evaluateStepVisible($step) {
        var parentOrderRaw = $step.attr('data-dfb-dep-parent');
        var depValue = $step.attr('data-dfb-dep-value');

        if (!parentOrderRaw || depValue === null || depValue === undefined || depValue === '') {
            return true;
        }

        var parentOrder = parseInt(parentOrderRaw, 10);
        if (!parentOrder) return false;

        var $parent = stepElsByOrder[parentOrder];
        if (!$parent || !$parent.length) return false;

        var parentType = ($parent.attr('data-dfb-input-type') || '').toString();

        var depValueStr = String(depValue);

        // Only certain parent types are supported for matching.
        if (parentType === 'checkbox') {
            var parentName = 'answers[question_' + parentOrder + '][]';
            var selected = [];
            $form.find('input[type="checkbox"][name="' + parentName + '"]:checked').each(function() {
                selected.push(String($(this).val()));
            });
            return selected.indexOf(depValueStr) !== -1;
        }

        if (parentType === 'radio' || parentType === 'yes_no') {
            var parentRadioName = 'answers[question_' + parentOrder + ']';
            var $checked = $form.find('input[type="radio"][name="' + parentRadioName + '"]:checked');
            if (!$checked.length) return false;
            return String($checked.val()) === depValueStr;
        }

        if (parentType === 'dropdown') {
            var parentSelectName = 'answers[question_' + parentOrder + ']';
            var val = $form.find('select[name="' + parentSelectName + '"]').val();
            return String(val) === depValueStr;
        }

        return false;
    }

    function clearHiddenStepInputs($step) {
        // Text-like
        $step.find('input[type="text"], input[type="email"], input[type="number"], input[type="date"], textarea').val('');
        // Select
        $step.find('select').val('');
        // Radio/Checkbox
        $step.find('input[type="radio"], input[type="checkbox"]').prop('checked', false);
    }

    function recalcVisibleSteps() {
        var prevVisible = visibleOrders.slice(0);
        var nextVisible = [];

        for (var i = 0; i < allOrders.length; i++) {
            var order = allOrders[i];
            var $step = stepElsByOrder[order];
            if (!$step || !$step.length) continue;
            if (evaluateStepVisible($step)) {
                nextVisible.push(order);
            }
        }

        // Clear inputs for steps that became hidden.
        for (var j = 0; j < prevVisible.length; j++) {
            var prevOrder = prevVisible[j];
            if (nextVisible.indexOf(prevOrder) === -1) {
                clearHiddenStepInputs(stepElsByOrder[prevOrder]);
            }
        }

        visibleOrders = nextVisible;

        if (currentOrder === null || visibleOrders.indexOf(currentOrder) === -1) {
            currentOrder = visibleOrders.length ? visibleOrders[0] : null;
        }
    }

    function updateUI() {
        $steps.hide();

        if (!currentOrder) {
            $progressBar.css('width', '0%');
            $progressText.text('');
            $prevBtn.hide();
            $nextBtn.hide();
            $submitBtn.hide();
            return;
        }

        var $current = stepElsByOrder[currentOrder];
        $current.show();

        var idx = visibleOrders.indexOf(currentOrder);
        var total = visibleOrders.length;
        var stepNum = idx + 1;

        var percent = total ? Math.round((stepNum / total) * 100) : 0;
        $progressBar.css('width', percent + '%');
        $progressText.text('Step ' + stepNum + ' of ' + total);

        if (idx === 0) $prevBtn.hide();
        else $prevBtn.show();

        if (idx === total - 1) {
            $nextBtn.hide();
            $submitBtn.show();
        } else {
            $nextBtn.show();
            $submitBtn.hide();
        }
    }

    function validateCurrentStep() {
        var $current = currentOrder !== null ? stepElsByOrder[currentOrder] : $();
        if (!$current || !$current.length) return false;

        var valid = true;
        clearStepErrors($current);

        // Check normal fields first (text/textarea/select).
        $current.find('input[required]:not([type="checkbox"]):not([type="radio"]), textarea[required], select[required]').each(function() {
            var $field = $(this);
            var value = $.trim($field.val());

            if (!value) {
                valid = false;
                showFieldError($field, 'This field is required.');
                return false;
            }

            if ($field.attr('type') === 'email') {
                var emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                if (!emailPattern.test(value)) {
                    valid = false;
                    showFieldError($field, 'Please enter a valid email address.');
                    return false;
                }
            }
        });

        if (!valid) return false;

        // Radio required: at least one in each required group must be checked.
        var radioNames = [];
        $current.find('input[type="radio"][required]').each(function() {
            var name = $(this).attr('name');
            if (name && radioNames.indexOf(name) === -1) radioNames.push(name);
        });
        for (var i = 0; i < radioNames.length; i++) {
            var rn = radioNames[i];
            var $checked = $current.find('input[type="radio"][name="' + rn + '"]:checked');
            if (!$checked.length) {
                valid = false;
                showFieldError($current.find('input[type="radio"][name="' + rn + '"]').first(), 'Please choose an option.');
                break;
            }
        }
        if (!valid) return false;

        // Checkbox required: at least one in each required group must be checked.
        var checkboxNames = [];
        $current.find('input[type="checkbox"][required]').each(function() {
            var name = $(this).attr('name');
            if (name && checkboxNames.indexOf(name) === -1) checkboxNames.push(name);
        });
        for (var j = 0; j < checkboxNames.length; j++) {
            var cn = checkboxNames[j];
            var $checked2 = $current.find('input[type="checkbox"][name="' + cn + '"]:checked');
            if (!$checked2.length) {
                valid = false;
                showFieldError($current.find('input[type="checkbox"][name="' + cn + '"]').first(), 'Please select at least one option.');
                break;
            }
        }

        return valid;
    }

    // Navigation
    $nextBtn.on('click', function() {
        if (!validateCurrentStep()) return;
        var idx = visibleOrders.indexOf(currentOrder);
        if (idx < 0) return;
        if (idx < visibleOrders.length - 1) {
            currentOrder = visibleOrders[idx + 1];
            updateUI();
        }
    });

    $prevBtn.on('click', function() {
        var idx = visibleOrders.indexOf(currentOrder);
        if (idx > 0) {
            currentOrder = visibleOrders[idx - 1];
            updateUI();
        }
    });

    // Recalculate visible steps when supported parent inputs change.
    $form.on('change', 'select, input[type="radio"], input[type="checkbox"]', function() {
        recalcVisibleSteps();
        updateUI();
    });

    // Initial compute.
    recalcVisibleSteps();
    updateUI();
});
