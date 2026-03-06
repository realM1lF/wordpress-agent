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

        // Reload identity files button
        $('#levi-reload-memories').on('click', function() {
            const $btn = $(this);
            const $result = $('#levi-reload-result');
            
            var confirmMsg = (leviSettings.i18n && leviSettings.i18n.reloadConfirm) ? leviSettings.i18n.reloadConfirm : 'Reload identity files?';
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
                        var reloaded = (leviSettings.i18n && leviSettings.i18n.reloaded) ? leviSettings.i18n.reloaded : 'Reloaded:';
                        var idLabel = (leviSettings.i18n && leviSettings.i18n.identity) ? leviSettings.i18n.identity : 'identity';
                        var filesLabel = (leviSettings.i18n && leviSettings.i18n.files) ? leviSettings.i18n.files : 'files';
                        $result.html('<span class="levi-success">✓ ' + reloaded + ' ' + identityCount + ' ' + idLabel + ' ' + filesLabel + '</span>');
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

        // Clear audit log button
        $('#levi-clear-audit-log').on('click', function() {
            const $btn = $(this);
            const $result = $('#levi-audit-clear-result');
            const confirmMsg = (leviSettings.i18n && leviSettings.i18n.clearAuditConfirm)
                ? leviSettings.i18n.clearAuditConfirm
                : 'Delete all audit log entries now?';

            if (!confirm(confirmMsg)) {
                return;
            }

            $btn.prop('disabled', true);
            $result.text((leviSettings.i18n && leviSettings.i18n.clearing) ? leviSettings.i18n.clearing : 'Deleting…');

            $.ajax({
                url: leviSettings.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'levi_clear_audit_log',
                    nonce: leviSettings.nonce,
                },
                success: function(response) {
                    if (response.success) {
                        const msg = response.data && response.data.message
                            ? response.data.message
                            : ((leviSettings.i18n && leviSettings.i18n.cleared) ? leviSettings.i18n.cleared : 'Audit log deleted.');
                        $result.html('<span class="levi-success">✓ ' + msg + '</span>');
                        setTimeout(function() {
                            location.reload();
                        }, 600);
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

        // ── Cron Task Handlers ──────────────────────────────────────

        $(document).on('click', '.levi-cron-run', function() {
            var $btn = $(this);
            var taskId = $btn.data('task-id');
            $btn.prop('disabled', true).find('.dashicons').removeClass('dashicons-controls-play').addClass('dashicons-update levi-spin');

            $.ajax({
                url: leviSettings.ajaxUrl,
                type: 'POST',
                data: { action: 'levi_run_cron_task', nonce: leviSettings.nonce, task_id: taskId },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data || 'Error');
                        $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update levi-spin').addClass('dashicons-controls-play');
                    }
                },
                error: function() {
                    alert('Request failed');
                    $btn.prop('disabled', false).find('.dashicons').removeClass('dashicons-update levi-spin').addClass('dashicons-controls-play');
                }
            });
        });

        $(document).on('click', '.levi-cron-toggle', function() {
            var $btn = $(this);
            var taskId = $btn.data('task-id');
            $btn.prop('disabled', true);

            $.ajax({
                url: leviSettings.ajaxUrl,
                type: 'POST',
                data: { action: 'levi_toggle_cron_task', nonce: leviSettings.nonce, task_id: taskId },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(response.data || 'Error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Request failed');
                    $btn.prop('disabled', false);
                }
            });
        });

        $(document).on('click', '.levi-cron-delete', function() {
            var $btn = $(this);
            var taskId = $btn.data('task-id');
            var confirmMsg = (leviSettings.i18n && leviSettings.i18n.confirmDelete) || 'Delete this task?';
            if (!confirm(confirmMsg)) return;

            $btn.prop('disabled', true);

            $.ajax({
                url: leviSettings.ajaxUrl,
                type: 'POST',
                data: { action: 'levi_delete_cron_task', nonce: leviSettings.nonce, task_id: taskId },
                success: function(response) {
                    if (response.success) {
                        $btn.closest('tr').next('.levi-cron-detail').remove();
                        $btn.closest('tr').fadeOut(300, function() { $(this).remove(); });
                    } else {
                        alert(response.data || 'Error');
                        $btn.prop('disabled', false);
                    }
                },
                error: function() {
                    alert('Request failed');
                    $btn.prop('disabled', false);
                }
            });
        });

        $(document).on('click', '.levi-cron-email-toggle', function() {
            var $btn = $(this);
            var taskId = $btn.data('task-id');
            $btn.prop('disabled', true);
            $.post(leviSettings.ajaxUrl, {
                action: 'levi_toggle_cron_email',
                nonce: leviSettings.nonce,
                task_id: taskId
            }, function(response) {
                $btn.prop('disabled', false);
                if (response.success) {
                    var active = response.data.notify_email;
                    $btn.toggleClass('levi-btn-email-active', active)
                        .toggleClass('levi-btn-secondary', !active);
                    $btn.attr('title', active
                        ? (leviSettings.i18n && leviSettings.i18n.emailOn || 'E-Mail aktiv')
                        : (leviSettings.i18n && leviSettings.i18n.emailOff || 'E-Mail deaktiviert'));
                } else {
                    alert(response.data || 'Error');
                }
            }).fail(function() {
                $btn.prop('disabled', false);
                alert('Request failed');
            });
        });

        $(document).on('click', '.levi-cron-expand', function() {
            var taskId = $(this).data('task-id');
            var $detail = $('tr.levi-cron-detail[data-detail-for="' + taskId + '"]');
            var $icon = $(this).find('.dashicons');

            if ($detail.is(':visible')) {
                $detail.slideUp(200);
                $icon.removeClass('dashicons-arrow-up-alt2').addClass('dashicons-arrow-down-alt2');
            } else {
                $detail.slideDown(200);
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-up-alt2');
            }
        });

        $('#levi-cron-all-toggle').on('click', function() {
            var $content = $('#levi-cron-all-content');
            var $icon = $(this).find('.levi-collapse-icon');

            if ($content.is(':visible')) {
                $content.slideUp(300);
                $icon.removeClass('dashicons-arrow-down-alt2').addClass('dashicons-arrow-right-alt2');
            } else {
                $content.slideDown(300);
                $icon.removeClass('dashicons-arrow-right-alt2').addClass('dashicons-arrow-down-alt2');
            }
        });
    });

})(jQuery);
