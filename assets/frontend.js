(function ($) {
    function getColorAttributeKey() {
        if (window.syncmasterVariationImages && syncmasterVariationImages.colorAttribute) {
            return syncmasterVariationImages.colorAttribute;
        }
        return null;
    }

    function getMatchingVariation(variations, colorKey, colorValue) {
        if (!colorKey || !colorValue) {
            return null;
        }
        for (var i = 0; i < variations.length; i += 1) {
            var attrs = variations[i].attributes || {};
            if (attrs[colorKey] && attrs[colorKey] === colorValue) {
                return variations[i];
            }
        }
        return null;
    }

    function hasIncompleteSelections($form) {
        var incomplete = false;
        $form.find('select[name^="attribute_"]').each(function () {
            if (!$(this).val()) {
                incomplete = true;
                return false;
            }
            return true;
        });
        return incomplete;
    }

    function updateGalleryImage($form, image) {
        if (!image || !image.src) {
            return;
        }
        var $gallery = $form.closest('.product').find('.woocommerce-product-gallery');
        if (!$gallery.length) {
            return;
        }

        var $img = $gallery.find('.woocommerce-product-gallery__image img').first();
        if (!$img.length) {
            return;
        }

        var fullSrc = image.full_src || image.src;
        $img.attr('src', image.src);
        $img.attr('srcset', image.srcset || '');
        $img.attr('sizes', image.sizes || '');
        $img.attr('data-src', fullSrc);
        $img.attr('data-large_image', fullSrc);
        $img.attr('data-large_image_width', image.full_src_w || '');
        $img.attr('data-large_image_height', image.full_src_h || '');

        var $link = $img.closest('a');
        if ($link.length) {
            $link.attr('href', fullSrc);
        }

        var $thumb = $gallery.find('.flex-control-nav img').first();
        if ($thumb.length) {
            $thumb.attr('src', image.gallery_thumbnail_src || image.src);
            $thumb.attr('srcset', image.srcset || '');
        }
    }

    function attachVariationHandlers() {
        var colorKey = getColorAttributeKey();
        if (!colorKey) {
            return;
        }

        $('.variations_form').each(function () {
            var $form = $(this);
            var variations = $form.data('product_variations') || [];
            if (!variations.length) {
                return;
            }

            $form.on('change', 'select[name^="attribute_"]', function () {
                var colorValue = $form.find('select[name="' + colorKey + '"]').val();
                if (!colorValue) {
                    return;
                }
                if (!hasIncompleteSelections($form)) {
                    return;
                }

                var matching = getMatchingVariation(variations, colorKey, colorValue);
                if (matching && matching.image) {
                    updateGalleryImage($form, matching.image);
                }
            });
        });
    }

    $(attachVariationHandlers);
})(jQuery);
