(function ($) {
    $(document).on('click', '.syncmaster-remove', function () {
        return window.confirm('Remove this SKU from monitored products?');
    });

    $(document).on('click', '.syncmaster-toggle-colors', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var button = $(this);
        var target = $(this).data('target');
        var panel = document.getElementById(target);
        if (!panel) {
            return;
        }
        var isOpen = panel.classList.contains('is-open');
        panel.classList.toggle('is-open');
        button.text(isOpen ? 'View Colors' : 'Hide Colors');

        if (isOpen || panel.dataset.loaded === '1') {
            return;
        }

        var sku = button.data('sku');
        if (!sku || typeof syncmasterAdmin === 'undefined') {
            return;
        }

        $.post(syncmasterAdmin.ajaxUrl, {
            action: 'syncmaster_load_colors_panel',
            nonce: syncmasterAdmin.colorsNonce,
            sku: sku
        }).done(function (response) {
            if (response && response.success && response.data && response.data.html) {
                panel.innerHTML = response.data.html;
                panel.dataset.loaded = '1';
            } else {
                panel.innerHTML = '<p class="syncmaster-muted">No color data found.</p>';
            }
        }).fail(function () {
            panel.innerHTML = '<p class="syncmaster-muted">Unable to load colors right now.</p>';
        });
    });

    $(document).on('click', '.syncmaster-select-all-categories', function () {
        $('.syncmaster-category-table input[type="checkbox"][name*="[enabled]"]').prop('checked', true);
    });

    $(document).on('click', '.syncmaster-clear-all-categories', function () {
        $('.syncmaster-category-table input[type="checkbox"][name*="[enabled]"]').prop('checked', false);
    });

    $(document).on('submit', '.syncmaster-category-sync-form', function () {
        var $form = $(this);
        $form.find('.syncmaster-category-table tbody tr').each(function () {
            var $row = $(this);
            var isEnabled = $row.find('input[type="checkbox"][name*="[enabled]"]').is(':checked');
            if (!isEnabled) {
                $row.find('input, select, textarea').prop('disabled', true);
            }
        });
    });

    $(document).on('change', '.syncmaster-group-toggle', function () {
        var target = $(this).data('target');
        if (!target) {
            return;
        }
        $('#' + target + ' .syncmaster-bulk-sku').prop('checked', $(this).is(':checked'));
    });

    $(document).on('submit', '#syncmaster-bulk-remove-form', function (event) {
        var $form = $(this);
        $form.find('input[name="skus[]"]').remove();
        var selected = $('.syncmaster-bulk-sku:checked');
        if (!selected.length) {
            event.preventDefault();
            window.alert('Please select at least one monitored product to remove.');
            return;
        }
        selected.each(function () {
            var sku = $(this).val();
            $('<input type="hidden" name="skus[]">').val(sku).appendTo($form);
        });
    });

    $(document).on('submit', '#syncmaster-sync-selected-form', function (event) {
        var $form = $(this);
        $form.find('input[name="skus[]"]').remove();
        var selected = $('.syncmaster-bulk-sku:checked');
        if (!selected.length) {
            event.preventDefault();
            window.alert('Please select at least one monitored product to sync.');
            return;
        }
        selected.each(function () {
            var sku = $(this).val();
            $('<input type="hidden" name="skus[]">').val(sku).appendTo($form);
        });
    });

    function refreshSyncProgress() {
        var progressBox = document.getElementById('syncmaster-sync-progress');
        if (!progressBox || typeof syncmasterAdmin === 'undefined') {
            return;
        }

        $.post(syncmasterAdmin.ajaxUrl, {
            action: 'syncmaster_sync_progress',
            nonce: syncmasterAdmin.progressNonce
        }).done(function (response) {
            if (!response || !response.success || !response.data) {
                return;
            }
            var data = response.data;
            var bar = progressBox.querySelector('.syncmaster-progress-bar span');
            var text = progressBox.querySelector('.syncmaster-progress-text');
            if (bar) {
                bar.style.width = (data.percent || 0) + '%';
            }
            if (text) {
                if (data.active) {
                    text.textContent = 'Sync Success: ' + (data.percent || 0) + '% (' + (data.success || 0) + '/' + (data.total || 0) + ' successful) · Processed: ' + (data.processed || 0) + '/' + (data.total || 0) + ' · Fail: ' + (data.fail || 0);
                    progressBox.classList.add('is-active');
                } else {
                    text.textContent = 'No active sync job.';
                    progressBox.classList.remove('is-active');
                }
            }
        });
    }

    $(document).on('submit', '.syncmaster-sync-form', function (event) {
        var progressBox = document.getElementById('syncmaster-sync-progress');
        if (!progressBox || progressBox.classList.contains('is-active') === false) {
            return;
        }

        var shouldReplace = window.confirm('A sync job is still running and incomplete. Do you want to stop it and start this new sync now?');
        if (!shouldReplace) {
            event.preventDefault();
            return;
        }

        var $form = $(this);
        $form.find('input[name="force_restart"]').remove();
        $('<input type="hidden" name="force_restart" value="1">').appendTo($form);
    });

    refreshSyncProgress();
    setInterval(refreshSyncProgress, 4000);
})(jQuery);
