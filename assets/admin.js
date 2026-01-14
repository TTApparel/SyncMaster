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
})(jQuery);
