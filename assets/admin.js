(function ($) {
    $(document).on('click', '.syncmaster-remove', function () {
        return window.confirm('Remove this SKU from monitored products?');
    });

    $(document).on('click', '.syncmaster-toggle-colors', function (event) {
        event.preventDefault();
        event.stopPropagation();
        var target = $(this).data('target');
        var panel = document.getElementById(target);
        if (!panel) {
            return;
        }
        var isOpen = panel.classList.contains('is-open');
        panel.classList.toggle('is-open');
        $(this).text(isOpen ? 'View Colors' : 'Hide Colors');
    });

    $(document).on('click', '.syncmaster-select-all-categories', function () {
        $('.syncmaster-category-table input[type="checkbox"][name*="[enabled]"]').prop('checked', true);
    });

    $(document).on('click', '.syncmaster-clear-all-categories', function () {
        $('.syncmaster-category-table input[type="checkbox"][name*="[enabled]"]').prop('checked', false);
    });
})(jQuery);
