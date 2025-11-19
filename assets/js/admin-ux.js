/**
 * Step 13: Admin UX Improvements - JavaScript
 * Form validation, keyboard shortcuts, preview panels, tooltips
 */

(function($) {
    'use strict';

    // Real-time form field validation
    $(document).on('blur', '.octopus-form-field input, .octopus-form-field textarea', function() {
        const field = $(this).closest('.octopus-form-field');
        const value = $(this).val().trim();
        const feedback = field.find('.octopus-validation-feedback');
        
        // Required validation
        if ($(this).prop('required') && !value) {
            field.addClass('octopus-field-error').removeClass('octopus-field-valid');
            feedback.text('This field is required').show();
            return;
        }
        
        // Email validation
        if ($(this).attr('type') === 'email' && value) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            if (!emailRegex.test(value)) {
                field.addClass('octopus-field-error').removeClass('octopus-field-valid');
                feedback.text('Invalid email format').show();
                return;
            }
        }
        
        // URL validation
        if ($(this).attr('type') === 'url' && value) {
            try {
                new URL(value);
            } catch {
                field.addClass('octopus-field-error').removeClass('octopus-field-valid');
                feedback.text('Invalid URL format').show();
                return;
            }
        }
        
        // Number validation
        if ($(this).attr('type') === 'number' && value) {
            if (isNaN(value)) {
                field.addClass('octopus-field-error').removeClass('octopus-field-valid');
                feedback.text('Must be a number').show();
                return;
            }
        }
        
        // Length validation
        const minLength = $(this).attr('minlength');
        if (minLength && value.length < minLength) {
            field.addClass('octopus-field-error').removeClass('octopus-field-valid');
            feedback.text('Minimum length is ' + minLength + ' characters').show();
            return;
        }
        
        // Success
        field.removeClass('octopus-field-error').addClass('octopus-field-valid');
        feedback.text('').hide();
    });
    
    // Focus event - clear previous errors
    $(document).on('focus', '.octopus-form-field input, .octopus-form-field textarea', function() {
        const field = $(this).closest('.octopus-form-field');
        field.removeClass('octopus-field-error octopus-field-valid');
        field.find('.octopus-validation-feedback').hide();
    });
    
    // Form submission validation
    $(document).on('submit', 'form', function() {
        let isValid = true;
        
        $(this).find('.octopus-form-field input[required], .octopus-form-field textarea[required]').each(function() {
            if (!$(this).val().trim()) {
                $(this).trigger('blur');
                isValid = false;
            }
        });
        
        return isValid;
    });
    
    // Preview panel toggle
    $(document).on('click', '.octopus-preview-header, .octopus-preview-toggle', function(e) {
        e.preventDefault();
        const panel = $(this).closest('.octopus-preview-panel');
        panel.toggleClass('octopus-preview-open');
        panel.find('.octopus-preview-toggle .dashicons').toggleClass('dashicons-arrow-down-alt2 dashicons-arrow-up-alt2');
    });
    
    // Tooltip initialization
    $(document).on('mouseenter', '[data-tooltip]', function() {
        const tooltip = $(this).data('tooltip');
        const position = $(this).data('position') || 'top';
        
        // Remove existing tooltip
        $('.octopus-tooltip').remove();
        
        const tipDiv = $('<div class="octopus-tooltip octopus-tooltip-' + position + '">' + tooltip + '</div>');
        $('body').append(tipDiv);
        
        const offset = $(this).offset();
        const elemWidth = $(this).outerWidth();
        const elemHeight = $(this).outerHeight();
        const tipWidth = tipDiv.outerWidth();
        const tipHeight = tipDiv.outerHeight();
        
        let top, left;
        
        switch (position) {
            case 'top':
                top = offset.top - tipHeight - 10;
                left = offset.left + (elemWidth / 2) - (tipWidth / 2);
                break;
            case 'bottom':
                top = offset.top + elemHeight + 10;
                left = offset.left + (elemWidth / 2) - (tipWidth / 2);
                break;
            case 'left':
                top = offset.top + (elemHeight / 2) - (tipHeight / 2);
                left = offset.left - tipWidth - 10;
                break;
            case 'right':
                top = offset.top + (elemHeight / 2) - (tipHeight / 2);
                left = offset.left + elemWidth + 10;
                break;
        }
        
        tipDiv.css({ top: Math.max(0, top), left: Math.max(0, left) }).fadeIn(200);
        $(this).data('tooltip-element', tipDiv);
    }).on('mouseleave', '[data-tooltip]', function() {
        const tipDiv = $(this).data('tooltip-element');
        if (tipDiv) {
            tipDiv.fadeOut(200, function() { $(this).remove(); });
            $(this).removeData('tooltip-element');
        }
    });
    
    // Keyboard shortcuts
    $(document).on('keydown', function(e) {
        // Only trigger on non-input elements
        if ($('input:focus, textarea:focus').length) {
            // Ctrl/Cmd+S: Save form
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                const form = $('textarea:focus').closest('form');
                if (form.length) {
                    form.submit();
                    console.log('Form submitted via Ctrl+S');
                }
            }
            return;
        }
        
        // Ctrl/Cmd+F: Focus search
        if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
            e.preventDefault();
            const search = $('input[type="search"], input[placeholder*="Search"]').first();
            if (search.length) search.focus();
        }
        
        // Ctrl/Cmd+P: Preview toggle
        if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
            e.preventDefault();
            $('.octopus-preview-toggle').first().click();
        }
        
        // Ctrl/Cmd+?: Show keyboard shortcuts
        if ((e.ctrlKey || e.metaKey) && e.shift && e.key === '?') {
            e.preventDefault();
            octopus_show_keyboard_shortcuts();
        }
    });
    
    // Show keyboard shortcuts
    function octopus_show_keyboard_shortcuts() {
        const shortcuts = [
            ['Ctrl+S', 'Save current form'],
            ['Ctrl+F', 'Focus search box'],
            ['Ctrl+P', 'Toggle preview panel'],
            ['Ctrl+Shift+?', 'Show this help'],
        ];
        
        let html = '<h3>Keyboard Shortcuts</h3><ul style="margin-left: 20px;">';
        shortcuts.forEach(function(shortcut) {
            html += '<li><kbd>' + shortcut[0] + '</kbd> - ' + shortcut[1] + '</li>';
        });
        html += '</ul>';
        
        alert('Keyboard Shortcuts:\n\n' + shortcuts.map(s => s[0] + ' - ' + s[1]).join('\n'));
    }
    
    // Make keyboard shortcuts dialog accessible
    window.octopus_show_keyboard_shortcuts = octopus_show_keyboard_shortcuts;
    
    // Initialize tooltips on page load
    $(document).ready(function() {
        // Add visual feedback to buttons
        $('.button').on('click', function() {
            $(this).blur();
        });
        
        // Auto-dismiss admin notices after 5 seconds
        $('.notice.is-dismissible').not('.notice-error').each(function() {
            const notice = $(this);
            setTimeout(function() {
                notice.slideUp(300, function() { $(this).remove(); });
            }, 5000);
        });
    });

})(jQuery);
