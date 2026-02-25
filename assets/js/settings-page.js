/**
 * Levi Agent Settings Page JavaScript
 */

(function($) {
    'use strict';

    $(document).ready(function() {
        // Provider selection - update form on change
        $('input[name*="ai_provider"]').on('change', function() {
            // Show loading indicator
            const $form = $(this).closest('form');
            $form.addClass('levi-updating');
            
            // Reload page to show provider-specific fields
            setTimeout(function() {
                $form.submit();
            }, 300);
        });

        // Test connection button
        $('#levi-test-connection').on('click', function() {
            const $btn = $(this);
            const $result = $('#levi-test-result');
            
            $btn.prop('disabled', true).addClass('levi-loading');
            $result.html('<span class="levi-spinner"></span> ' + (leviSettings.i18n && leviSettings.i18n.testing ? leviSettings.i18n.testing : 'Testing…'));
            
            $.ajax({
                url: leviSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'levi_test_connection',
                    nonce: leviSettings.nonce,
                },
                success: function(response) {
                    if (response.success) {
                        var msg = response.data && response.data.message ? response.data.message : (leviSettings.i18n && leviSettings.i18n.connected ? leviSettings.i18n.connected : 'Connected');
                        $result.html('<span class="levi-success">✓ ' + msg + '</span>');
                        updateConnectionStatus(true);
                    } else {
                        $result.html('<span class="levi-error">✗ ' + (response.data || (leviSettings.i18n && leviSettings.i18n.failed ? leviSettings.i18n.failed : 'Failed')) + '</span>');
                        updateConnectionStatus(false);
                    }
                },
                error: function() {
                    $result.html('<span class="levi-error">✗ ' + (leviSettings.i18n && leviSettings.i18n.connectionError ? leviSettings.i18n.connectionError : 'Connection error') + '</span>');
                    updateConnectionStatus(false);
                },
                complete: function() {
                    $btn.prop('disabled', false).removeClass('levi-loading');
                }
            });
        });

        // Reload memories button
        $('#levi-reload-memories').on('click', function() {
            const $btn = $(this);
            const $result = $('#levi-reload-result');
            
            var confirmMsg = (leviSettings.i18n && leviSettings.i18n.reloadConfirm) ? leviSettings.i18n.reloadConfirm : 'Reload all memories? This may take a moment.';
            if (!confirm(confirmMsg)) {
                return;
            }
            
            $btn.prop('disabled', true);
            $result.text((leviSettings.i18n && leviSettings.i18n.reloading) ? leviSettings.i18n.reloading : 'Reloading…');
            
            $.ajax({
                url: leviSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'levi_reload_memories',
                    nonce: leviSettings.nonce,
                },
                success: function(response) {
                    if (response.success) {
                        const identityCount = Object.keys(response.data.results.identity.loaded || {}).length;
                        const referenceCount = Object.keys(response.data.results.reference.loaded || {}).length;
                        var reloaded = (leviSettings.i18n && leviSettings.i18n.reloaded) ? leviSettings.i18n.reloaded : 'Reloaded:';
                        var idLabel = (leviSettings.i18n && leviSettings.i18n.identity) ? leviSettings.i18n.identity : 'identity';
                        var refLabel = (leviSettings.i18n && leviSettings.i18n.reference) ? leviSettings.i18n.reference : 'reference';
                        var filesLabel = (leviSettings.i18n && leviSettings.i18n.files) ? leviSettings.i18n.files : 'files';
                        $result.html('<span class="levi-success">✓ ' + reloaded + ' ' + identityCount + ' ' + idLabel + ', ' + referenceCount + ' ' + refLabel + ' ' + filesLabel + '</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 1500);
                    } else {
                        $result.html('<span class="levi-error">✗ ' + (response.data || (leviSettings.i18n && leviSettings.i18n.failed ? leviSettings.i18n.failed : 'Failed')) + '</span>');
                    }
                },
                error: function() {
                    $result.html('<span class="levi-error">✗ ' + (leviSettings.i18n && leviSettings.i18n.error ? leviSettings.i18n.error : 'Error') + '</span>');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });

        // Run state snapshot button
        $('#levi-run-state-snapshot').on('click', function() {
            const $btn = $(this);
            const $result = $('#levi-state-snapshot-result');
            const $progressWrap = $('#levi-state-snapshot-progress-wrap');
            const $progressBar = $('#levi-state-snapshot-progress');
            
            $btn.prop('disabled', true);
            $progressWrap.show();
            $result.text('');
            
            // Animate progress
            let progress = 0;
            const progressInterval = setInterval(function() {
                progress += Math.random() * 15;
                if (progress > 90) progress = 90;
                $progressBar.css('width', progress + '%');
            }, 300);
            
            $.ajax({
                url: leviSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'levi_run_state_snapshot',
                    nonce: leviSettings.nonce,
                },
                success: function(response) {
                    clearInterval(progressInterval);
                    $progressBar.css('width', '100%');
                    
                    if (response.success) {
                        const meta = response.data.meta || {};
                        const capturedAt = meta.captured_at || '-';
                        const status = meta.status || 'unknown';
                        var doneLabel = (leviSettings.i18n && leviSettings.i18n.done) ? leviSettings.i18n.done : 'Done';
                        var statusLabel = status;
                        if (leviSettings.i18n) {
                            if (status === 'not_run') statusLabel = leviSettings.i18n.status_not_run || status;
                            if (status === 'unchanged') statusLabel = leviSettings.i18n.status_unchanged || status;
                            if (status === 'changed_stored') statusLabel = leviSettings.i18n.status_changed_stored || status;
                        }
                        $result.html('<span class="levi-success">✓ ' + doneLabel + ' (' + capturedAt + ', ' + statusLabel + ')</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 1200);
                    } else {
                        $result.html('<span class="levi-error">✗ ' + (response.data || (leviSettings.i18n && leviSettings.i18n.failed ? leviSettings.i18n.failed : 'Failed')) + '</span>');
                    }
                },
                error: function() {
                    clearInterval(progressInterval);
                    $result.html('<span class="levi-error">✗ ' + (leviSettings.i18n && leviSettings.i18n.error ? leviSettings.i18n.error : 'Error') + '</span>');
                },
                complete: function() {
                    setTimeout(function() {
                        $btn.prop('disabled', false);
                        $progressWrap.fadeOut();
                    }, 1000);
                }
            });
        });

        // Repair database button
        $('#levi-repair-database').on('click', function() {
            const $btn = $(this);
            const $result = $('#levi-repair-result');
            
            $btn.prop('disabled', true);
            $result.text((leviSettings.i18n && leviSettings.i18n.repairing) ? leviSettings.i18n.repairing : 'Repairing…');
            
            $.ajax({
                url: leviSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'levi_repair_database',
                    nonce: leviSettings.nonce,
                },
                success: function(response) {
                    if (response.success) {
                        var msg = response.data && response.data.message ? response.data.message : (leviSettings.i18n && leviSettings.i18n.done ? leviSettings.i18n.done : 'Done');
                        $result.html('<span class="levi-success">✓ ' + msg + '</span>');
                    } else {
                        $result.html('<span class="levi-error">✗ ' + (response.data || (leviSettings.i18n && leviSettings.i18n.failed ? leviSettings.i18n.failed : 'Failed')) + '</span>');
                    }
                },
                error: function() {
                    $result.html('<span class="levi-error">✗ ' + (leviSettings.i18n && leviSettings.i18n.error ? leviSettings.i18n.error : 'Error') + '</span>');
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        });

        // Form submission with visual feedback
        $('.levi-settings-form').on('submit', function() {
            const $form = $(this);
            const $submitBtn = $form.find('[type="submit"]');
            const $indicator = $('.levi-save-indicator');
            
            $submitBtn.prop('disabled', true).text((leviSettings.i18n && leviSettings.i18n.saving) ? leviSettings.i18n.saving : 'Saving…');
            
            // Let the form submit normally, but show indicator on page reload
            localStorage.setItem('levi_settings_saved', '1');
        });

        // Check for saved indicator
        if (localStorage.getItem('levi_settings_saved') === '1') {
            localStorage.removeItem('levi_settings_saved');
            $('.levi-save-indicator').addClass('show');
            setTimeout(function() {
                $('.levi-save-indicator').removeClass('show');
            }, 3000);
        }

        // Smooth scroll for anchor links
        $('a[href^="#"]').on('click', function(e) {
            const target = $(this.getAttribute('href'));
            if (target.length) {
                e.preventDefault();
                $('html, body').animate({
                    scrollTop: target.offset().top - 100
                }, 300);
            }
        });

        // Helper function to update connection status indicator
        function updateConnectionStatus(isConnected) {
            const $status = $('.levi-connection-status');
            $status.removeClass('levi-status-connected levi-status-disconnected');
            $status.addClass(isConnected ? 'levi-status-connected' : 'levi-status-disconnected');
            var conn = (leviSettings.i18n && leviSettings.i18n.connected) ? leviSettings.i18n.connected : 'Connected';
            var notConn = (leviSettings.i18n && leviSettings.i18n.notConnected) ? leviSettings.i18n.notConnected : 'Not Connected';
            $status.find('.levi-status-text').text(isConnected ? conn : notConn);
        }
    });

})(jQuery);
