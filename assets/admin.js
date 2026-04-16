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
})(jQuery);
