<?php
/**
 * Butun sistemin kalbi burasi (eski stockEngine.js'in PHP karsiligi).
 * WooCommerce'e hic karismadan, sadece "bir hucrenin degeri degisti" bilgisinden
 * yola cikarak hangi (tasarim, beden) hucrelerinin yeni stogunun ne olmasi
 * gerektigini hesaplar - sonra bunu gercek WooCommerce varyasyon stoguna yazar.
 *
 * Iki sayac (havuz bazinda):
 *   - Havuzun ortak beden stogu: mos_pool_sizes.shared_stock (o havuza bagli TUM tasarimlar paylasir)
 *   - Tasarim stogu: post meta _mos_design_stock (tasarima ozel, havuzdan/bedenden bagimsiz)
 * Gorunen/gercek stok = min(havuzun o bedendeki ortak stogu, tasarimin kendi stogu)
 * Bu deger hesaplanip doğrudan ilgili WooCommerce varyasyonunun stok miktarina yazilir.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MOS_Engine
{
    /**
     * Kendi yazdigimiz stok guncellemelerinin kendi kancalarimizi tekrar
     * tetiklemesini (sonsuz donguyu) engellemek icin kilit.
     */
    public static $is_syncing = false;

    /**
     * Bir istek icinde (ör. bir siparis ya da toplu senkron) degisen ve Shopier'e
     * eslenmis hucreleri urun bazinda toplayan tampon. Boylece ayni Shopier urununun
     * birden fazla bedeni tek GET+PUT ile gonderilir (her hucre icin ayri istek yerine).
     * Yapisi: [ shopier_product_id => [ shopier_variant_id => yeni_stok ] ]
     */
    private static $shopier_buffer = [];

    public static function effective_stock($design_product_id, $size_key)
    {
        $pool_id = MOS_DB::get_design_pool_id($design_product_id);
        if (!$pool_id) {
            return null;
        }
        $size_row = MOS_DB::get_pool_size($pool_id, $size_key);
        if (!$size_row) {
            return null;
        }
        $design_stock = MOS_DB::get_design_stock($design_product_id);
        return max(0, min((int) $size_row->shared_stock, (int) $design_stock));
    }

    /**
     * Bir (tasarim, beden) hucresinin WooCommerce'deki gercek varyasyonuna
     * hesaplanan gecerli stogu yazar. Havuza/bedene bagli degilse dokunmaz.
     */
    public static function push_cell_to_wc($design_product_id, $size_key)
    {
        $map = MOS_DB::get_variation_map_for_design($design_product_id);
        if (!isset($map[$size_key])) {
            return false; // bu tasarimin bu bedeni henuz bir varyasyona eslenmemis
        }
        $variation_id = (int) $map[$size_key]->variation_id;
        $eff = self::effective_stock($design_product_id, $size_key);
        if ($eff === null) {
            return false;
        }
        self::write_variation_stock($variation_id, $eff);
        return true;
    }

    /**
     * Tek bir hucreyi hem WooCommerce'e yazar hem (eslenmisse) Shopier tamponuna
     * ekleyip HEMEN gonderir. Havuz disindan (admin AJAX, WooCommerce kancalari) tek
     * hucre degisikliklerinde bunu kullan - toplu islemler (asagida) kendi ici batch'ini
     * kendisi yonetir.
     */
    public static function sync_cell($design_product_id, $size_key)
    {
        $ok = self::push_cell_to_wc($design_product_id, $size_key);
        self::buffer_shopier_cell($design_product_id, $size_key);
        self::flush_shopier_buffer();
        return $ok;
    }

    /**
     * Bir hucreyi (varsa) Shopier eslemesiyle birlikte tampona ekler - henuz gonderilmez.
     * Havuza/bedene bagli degilse ya da Shopier'e eslenmemisse sessizce yok sayilir.
     *
     * BILEREK stok DEGERINI degil, (tasarim, beden) KIMLIGINI tamponluyoruz - gercek
     * stok, cron gerçekten calisirken YENIDEN hesaplanacak (asagida handle_cron_push).
     * Boylece kisa surede arka arkaya iki satis olursa (ör. ayni urunden iki musteri),
     * planlanan iki cron gorevinden hangisi ONCE calisirsa calissin, Shopier'e HER ZAMAN
     * o anki gercek/guncel stok gider - eski bir anlik goruntu (stale deger) degil.
     */
    private static function buffer_shopier_cell($design_product_id, $size_key)
    {
        $shopier_product_id = MOS_DB::get_shopier_product_id($design_product_id);
        if (!$shopier_product_id) {
            return;
        }
        $shopier_variant_id = MOS_DB::get_shopier_variant_id($design_product_id, $size_key);
        if (!$shopier_variant_id) {
            return;
        }
        self::$shopier_buffer[$shopier_product_id][$shopier_variant_id] = [
            'design_product_id' => $design_product_id,
            'size_key' => $size_key,
        ];
    }

    /**
     * Tamponda biriken tum urunleri, her biri icin AYRI bir arka plan (WP-Cron) gorevi
     * olarak Shopier'e gonderilmek uzere planlar. Cron kullanmamizin sebebi: Shopier'e
     * giden istek yavas olabilir (GET+PUT) - bunu musterinin odeme/kaydetme isteginin
     * icinde BEKLETMEK istemiyoruz, arka planda (siteye bir sonraki ziyarette ya da
     * sunucu cron'unda) calissin.
     */
    private static function flush_shopier_buffer()
    {
        if (empty(self::$shopier_buffer)) {
            return;
        }
        foreach (self::$shopier_buffer as $shopier_product_id => $variant_map) {
            $cells = [];
            foreach ($variant_map as $variant_id => $cell) {
                $cells[] = [
                    'shopier_variant_id' => $variant_id,
                    'design_product_id' => $cell['design_product_id'],
                    'size_key' => $cell['size_key'],
                ];
            }
            wp_schedule_single_event(time(), 'mos_shopier_push_product', [$shopier_product_id, $cells]);
        }
        self::$shopier_buffer = [];
    }

    private static function write_variation_stock($variation_id, $qty)
    {
        $product = wc_get_product($variation_id);
        if (!$product) {
            return;
        }
        self::$is_syncing = true;
        $product->set_manage_stock(true);
        $product->set_stock_quantity(max(0, (int) $qty));
        $product->set_stock_status($qty > 0 ? 'instock' : 'outofstock');
        $product->set_backorders('no');
        $product->save();
        self::$is_syncing = false;
    }

    /**
     * Bir havuzdaki TUM eslenmis hucreleri WooCommerce'e (yeniden) gonderir.
     * "Tumunu Yenile" butonu icin.
     */
    public static function sync_pool_to_wc($pool_id)
    {
        $pushed = 0;
        $skipped = 0;
        $design_ids = MOS_DB::get_designs_for_pool($pool_id);
        $sizes = MOS_DB::get_pool_sizes($pool_id);
        foreach ($design_ids as $design_id) {
            foreach ($sizes as $size) {
                if (self::push_cell_to_wc($design_id, $size->size_key)) {
                    $pushed++;
                } else {
                    $skipped++;
                }
                self::buffer_shopier_cell($design_id, $size->size_key);
            }
        }
        self::flush_shopier_buffer();
        return ['pushed' => $pushed, 'skipped' => $skipped];
    }

    public static function sync_design_to_wc($design_product_id)
    {
        $pool_id = MOS_DB::get_design_pool_id($design_product_id);
        if (!$pool_id) {
            return ['pushed' => 0, 'skipped' => 0];
        }
        $pushed = 0;
        $skipped = 0;
        foreach (MOS_DB::get_pool_sizes($pool_id) as $size) {
            if (self::push_cell_to_wc($design_product_id, $size->size_key)) {
                $pushed++;
            } else {
                $skipped++;
            }
            self::buffer_shopier_cell($design_product_id, $size->size_key);
        }
        self::flush_shopier_buffer();
        return ['pushed' => $pushed, 'skipped' => $skipped];
    }

    /**
     * Havuzun bir bedeninin ortak stogu elle degistirildiginde cagrilir.
     * O havuza bagli TUM tasarimlarin o bedenini WooCommerce'e yeniden yazar.
     */
    public static function set_shared_stock_and_sync($pool_id, $size_key, $qty)
    {
        MOS_DB::set_shared_stock($pool_id, $size_key, $qty);
        $design_ids = MOS_DB::get_designs_for_pool($pool_id);
        foreach ($design_ids as $design_id) {
            self::push_cell_to_wc($design_id, $size_key);
            self::buffer_shopier_cell($design_id, $size_key);
        }
        self::flush_shopier_buffer();
    }

    /**
     * Bir tasarimin kendi Tasarim Stogu elle degistirildiginde cagrilir.
     * O tasarimin TUM bedenlerini WooCommerce'e yeniden yazar.
     */
    public static function set_design_stock_and_sync($design_product_id, $qty)
    {
        MOS_DB::set_design_stock($design_product_id, $qty);
        $pool_id = MOS_DB::get_design_pool_id($design_product_id);
        if (!$pool_id) {
            return;
        }
        foreach (MOS_DB::get_pool_sizes($pool_id) as $size) {
            self::push_cell_to_wc($design_product_id, $size->size_key);
            self::buffer_shopier_cell($design_product_id, $size->size_key);
        }
        self::flush_shopier_buffer();
    }

    /**
     * Bir satis/iade geldiginde cagrilir. signedQty kadar dusurur
     * (negatif olabilir - satis; pozitif - iade/geri ekleme).
     * Hem havuzun ortak stogunu hem tasarimin kendi stogunu degistirir,
     * etkilenen TUM hucreleri (bu bedeni paylasan tum tasarimlar + bu
     * tasarimin tum bedenleri) WooCommerce'e yazar.
     */
    public static function apply_delta($design_product_id, $size_key, $signed_qty, $meta = [])
    {
        $signed_qty = (int) $signed_qty;
        if ($signed_qty === 0) {
            return ['skipped' => true, 'reason' => 'sifir adet'];
        }

        $pool_id = MOS_DB::get_design_pool_id($design_product_id);
        if (!$pool_id) {
            return ['skipped' => true, 'reason' => 'bu tasarim hicbir havuza bagli degil'];
        }

        $size_row = MOS_DB::get_pool_size($pool_id, $size_key);
        if (!$size_row) {
            return ['skipped' => true, 'reason' => "'$size_key' bedeni bu tasarimin havuzunda tanimli degil"];
        }

        $new_shared = MOS_DB::bump_shared_stock($pool_id, $size_key, $signed_qty);

        $old_design_stock = MOS_DB::get_design_stock($design_product_id);
        $new_design_stock = max(0, $old_design_stock + $signed_qty);
        MOS_DB::set_design_stock($design_product_id, $new_design_stock);

        MOS_DB::add_log(
            $pool_id,
            $design_product_id,
            $size_key,
            $signed_qty,
            $new_shared,
            $new_design_stock,
            isset($meta['orderRef']) ? $meta['orderRef'] : null,
            isset($meta['note']) ? $meta['note'] : ''
        );

        // Etkilenen tum hucreleri WooCommerce'e yaz:
        // 1) bu havuzda bu bedene sahip TUM tasarimlar (paylasilan stok degisti)
        $design_ids = MOS_DB::get_designs_for_pool($pool_id);
        foreach ($design_ids as $did) {
            self::push_cell_to_wc($did, $size_key);
            self::buffer_shopier_cell($did, $size_key);
        }
        // 2) bu tasarimin TUM bedenleri (kendi tasarim stogu degisti)
        foreach (MOS_DB::get_pool_sizes($pool_id) as $s) {
            self::push_cell_to_wc($design_product_id, $s->size_key);
            self::buffer_shopier_cell($design_product_id, $s->size_key);
        }
        self::flush_shopier_buffer();

        return [
            'skipped' => false,
            'poolId' => $pool_id,
            'sharedStockAfter' => $new_shared,
            'designStockAfter' => $new_design_stock,
        ];
    }

    /**
     * Admin panelindeki "Stok Tablosu" gorunumu icin: bir havuzun tum
     * tasarim x beden matrisini, eslenmis varyasyon bilgisiyle birlikte dondurur.
     */
    public static function get_stock_table($pool_id)
    {
        $pool = MOS_DB::get_pool($pool_id);
        if (!$pool) {
            return ['pool' => null, 'table' => []];
        }
        $sizes = MOS_DB::get_pool_sizes($pool_id);
        $design_ids = MOS_DB::get_designs_for_pool($pool_id);

        $table = [];
        foreach ($design_ids as $design_id) {
            $product = wc_get_product($design_id);
            if (!$product) {
                continue;
            }
            $design_stock = MOS_DB::get_design_stock($design_id);
            $map = MOS_DB::get_variation_map_for_design($design_id);
            $cells = [];
            foreach ($sizes as $s) {
                $mapped = isset($map[$s->size_key]) ? $map[$s->size_key] : null;
                $cells[] = [
                    'size' => $s->size_key,
                    'sizeLabel' => $s->size_label,
                    'sharedStock' => (int) $s->shared_stock,
                    'designStock' => (int) $design_stock,
                    'effectiveStock' => max(0, min((int) $s->shared_stock, (int) $design_stock)),
                    'variationId' => $mapped ? (int) $mapped->variation_id : 0,
                    'shopierVariantId' => $mapped ? (string) $mapped->shopier_variant_id : '',
                ];
            }
            $table[] = [
                'design' => [
                    'id' => $design_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'stock' => $design_stock,
                    'editUrl' => get_edit_post_link($design_id, ''),
                    'shopierProductId' => MOS_DB::get_shopier_product_id($design_id),
                ],
                'cells' => $cells,
            ];
        }

        return [
            'pool' => [
                'id' => (int) $pool->id,
                'name' => $pool->name,
                'sizes' => array_map(function ($s) {
                    return ['key' => $s->size_key, 'label' => $s->size_label, 'sharedStock' => (int) $s->shared_stock];
                }, $sizes),
            ],
            'table' => $table,
        ];
    }
}
