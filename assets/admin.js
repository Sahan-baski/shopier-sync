(function ($) {
    'use strict';

    function ajax(action, data) {
        return $.post(MOS.ajaxUrl, Object.assign({ action: action, nonce: MOS.nonce }, data));
    }

    function afterSave(promise, okMessage) {
        promise
            .done(function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    alert((res && res.data && res.data.message) || 'Bir hata oluştu.');
                }
            })
            .fail(function () {
                alert('Sunucuya ulaşılamadı, tekrar dener misin?');
            });
    }

    $(function () {
        // Ortak (havuz) stok - beden basli girisi
        $('.mos-shared-input').on('change', function () {
            var $el = $(this);
            afterSave(
                ajax('mos_set_shared_stock', {
                    pool_id: $el.data('pool'),
                    size_key: $el.data('size'),
                    qty: $el.val(),
                })
            );
        });

        // Tasarim stogu
        $('.mos-design-stock-input').on('change', function () {
            var $el = $(this);
            afterSave(
                ajax('mos_set_design_stock', {
                    design_id: $el.data('design'),
                    qty: $el.val(),
                })
            );
        });

        // Beden -> varyasyon eslemesi
        $('.mos-map-select').on('change', function () {
            var $el = $(this);
            afterSave(
                ajax('mos_map_variation', {
                    pool_id: $el.data('pool'),
                    design_id: $el.data('design'),
                    size_key: $el.data('size'),
                    variation_id: $el.val(),
                })
            );
        });

        // Shopier Urun ID (tasarim seviyesinde)
        $('.mos-shopier-product-input').on('change', function () {
            var $el = $(this);
            afterSave(
                ajax('mos_shopier_save_product_id', {
                    design_id: $el.data('design'),
                    shopier_product_id: $el.val(),
                })
            );
        });

        // Shopier beden -> selection eslemesi (hucre bazinda)
        $(document).on('change', '.mos-shopier-select', function () {
            var $el = $(this);
            afterSave(
                ajax('mos_shopier_map_variant', {
                    pool_id: $el.data('pool'),
                    design_id: $el.data('design'),
                    size_key: $el.data('size'),
                    shopier_variant_id: $el.val(),
                })
            );
        });

        // Hesap genelindeki Shopier beden secimlerini bir kerede cekip, sayfadaki
        // TUM Shopier dropdown'larini doldurur (sayfayi yenilemeden).
        $('#mos_shopier_fetch_sizes_btn').on('click', function () {
            var $btn = $(this);
            var $status = $('#mos_shopier_fetch_status');
            $btn.prop('disabled', true);
            $status.text('Shopier’den bedenler getiriliyor…');
            ajax('mos_shopier_fetch_child_sizes', {})
                .done(function (res) {
                    if (!res || !res.success) {
                        $status.text((res && res.data && res.data.message) || 'Bir hata oluştu.');
                        $btn.prop('disabled', false);
                        return;
                    }
                    var sizes = res.data;
                    $('.mos-shopier-select').each(function () {
                        var $select = $(this);
                        var current = $select.data('current') ? String($select.data('current')) : '';
                        $select.empty();
                        $select.append($('<option>').val('').text('— Shopier: Eşlenmemiş —'));
                        sizes.forEach(function (s) {
                            var label = s.selectionTitle + (s.variationTitle ? ' (' + s.variationTitle + ')' : '');
                            $select.append($('<option>').val(s.selectionId).text(label));
                        });
                        if (current) {
                            $select.val(current);
                        }
                    });
                    $status.text('Bedenler getirildi (' + sizes.length + ' seçenek) — şimdi her hücrenin altındaki listeden doğru bedeni seç.');
                    $btn.prop('disabled', false);
                })
                .fail(function () {
                    $status.text('Sunucuya ulaşılamadı, tekrar dener misin?');
                    $btn.prop('disabled', false);
                });
        });

        // Havuzdan cikar
        $('.mos-remove-design-btn').on('click', function () {
            if (!confirm('Bu tasarımı havuzdan çıkarmak istediğine emin misin?')) {
                return;
            }
            var $el = $(this);
            afterSave(ajax('mos_remove_design', { design_id: $el.data('design') }));
        });

        // Havuzu WooCommerce'e yeniden gonder
        $('#mos_sync_btn').on('click', function () {
            var $el = $(this);
            $el.prop('disabled', true).text('Gönderiliyor...');
            afterSave(ajax('mos_sync_pool', { pool_id: $el.data('pool') }));
        });

        // Urun arama (havuza tasarim ekleme)
        var searchTimer = null;
        var $searchInput = $('#mos_product_search');
        var $searchResults = $('#mos_product_search_results');
        var $searchIdField = $('#mos_product_search_id');
        var $addBtn = $('#mos_add_design_btn');

        $searchInput.on('input', function () {
            var term = $searchInput.val().trim();
            $addBtn.prop('disabled', true);
            $searchIdField.val('');
            clearTimeout(searchTimer);
            if (term.length < 2) {
                $searchResults.empty();
                return;
            }
            searchTimer = setTimeout(function () {
                ajax('mos_search_products', { term: term }).done(function (res) {
                    $searchResults.empty();
                    if (!res || !res.success) {
                        return;
                    }
                    res.data.forEach(function (item) {
                        var $li = $('<li>').text(item.text);
                        if (item.disabled) {
                            $li.addClass('mos-disabled');
                        } else {
                            $li.on('click', function () {
                                $searchInput.val(item.text);
                                $searchIdField.val(item.id);
                                $searchResults.empty();
                                $addBtn.prop('disabled', false);
                            });
                        }
                        $searchResults.append($li);
                    });
                });
            }, 300);
        });

        $addBtn.on('click', function () {
            var designId = $searchIdField.val();
            if (!designId) {
                return;
            }
            afterSave(
                ajax('mos_add_design', {
                    pool_id: $addBtn.data('pool'),
                    design_id: designId,
                })
            );
        });
    });
})(jQuery);
