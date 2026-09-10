<?php
/**
 * WooCommerce'in kendi siparis/stok olaylarina baglanir. Amac: Shopier'deki gibi
 * disaridan webhook beklemek yerine, WooCommerce zaten bir varyasyonun stogunu
 * dusurdugunde/geri yuklediginde bunu YAKALAMAK ve havuz mantigini calistirmak.
 *
 * Guvenilirlik icin WooCommerce'in kendi "_reduced_stock" sipariş kalemi meta'siyla
 * KARSILASTIRMALI (reconcile) calisir: her tetiklemede "su an gercekte ne kadar
 * dusulmus" ile "biz en son ne kadarini havuza isledik" farkini alir ve sadece
 * farki uygular. Bu sayede WooCommerce surumleri arasinda hook imzalari farkli
 * olsa bile (reduce/restore/iptal/kismi iade) sonuc her zaman dogru ve tekrar
 * calistirmaya (idempotent) dayanikli olur.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MOS_Hooks
{
    public static function init()
    {
        add_action('woocommerce_reduce_order_item_stock', [__CLASS__, 'on_reduce'], 10, 3);
        add_action('woocommerce_restore_order_item_stock', [__CLASS__, 'on_restore'], 10, 3);
        add_action('woocommerce_order_status_changed', [__CLASS__, 'on_status_changed'], 20, 4);

        // Bir varyasyonun stok miktari urun duzenleme ekranindan elle degistirilip
        // kaydedilirse (havuza bagli olsa bile), WooCommerce kaydettikten HEMEN
        // SONRA dogru hesaplanan degerle uzerine yazariz - "gorunen stok sadece
        // izlenir, elle degistirilmez" kuralini boylece zorunlu kilariz.
        add_action('woocommerce_save_product_variation', [__CLASS__, 'on_variation_saved'], 20, 1);
    }

    public static function on_variation_saved($variation_id)
    {
        if (MOS_Engine::$is_syncing) {
            return;
        }
        $map = MOS_DB::find_map_by_variation($variation_id);
        if (!$map) {
            return;
        }
        MOS_Engine::sync_cell($map->design_product_id, $map->size_key);
    }

    public static function on_reduce($item, $changes, $order)
    {
        self::reconcile_item($item, $order);
    }

    public static function on_restore($item, $order = null, $product = null)
    {
        if (!$order && method_exists($item, 'get_order_id')) {
            $order = wc_get_order($item->get_order_id());
        }
        self::reconcile_item($item, $order);
    }

    public static function on_status_changed($order_id, $old_status, $new_status, $order = null)
    {
        if (!$order) {
            $order = wc_get_order($order_id);
        }
        if (!$order) {
            return;
        }
        foreach ($order->get_items() as $item) {
            self::reconcile_item($item, $order);
        }
    }

    /**
     * Tek bir siparis kalemi icin: WooCommerce'in su anki "_reduced_stock" degeriyle
     * bizim en son havuza isledigimiz miktari karsilastirir, sadece farki uygular.
     */
    private static function reconcile_item($item, $order)
    {
        if (MOS_Engine::$is_syncing) {
            return;
        }
        if (!$item || !is_a($item, 'WC_Order_Item_Product')) {
            return;
        }

        $variation_id = $item->get_variation_id();
        $product_id = $variation_id ?: $item->get_product_id();
        if (!$product_id) {
            return;
        }

        // Sadece gercek varyasyonlar havuza eslenebilir (basit urunler degil)
        if (!$variation_id) {
            return;
        }

        $map = MOS_DB::find_map_by_variation($variation_id);
        if (!$map) {
            return; // bu varyasyon herhangi bir havuza eslenmemis, dokunma
        }

        $order_item_id = $item->get_id();
        $order_id = $order ? $order->get_id() : (method_exists($item, 'get_order_id') ? $item->get_order_id() : 0);

        $current_reduced = (int) $item->get_meta('_reduced_stock', true);
        $prev_applied = MOS_DB::get_reduced_state($order_item_id);

        $diff = $current_reduced - $prev_applied;
        if ($diff === 0) {
            return;
        }

        // diff pozitifse yeni dusus oldu demektir (stoktan -diff dusulmeli),
        // diff negatifse bir kismi/tamami geri yuklenmis demektir (+ (-diff) eklenmeli)
        MOS_Engine::apply_delta($map->design_product_id, $map->size_key, -$diff, [
            'orderRef' => $order_id,
            'note' => $diff > 0 ? 'siparis stok dusumu' : 'siparis iptali/iadesi - stok geri yuklendi',
        ]);

        MOS_DB::set_reduced_state(
            $order_item_id,
            $order_id,
            $variation_id,
            $map->design_product_id,
            $map->size_key,
            $current_reduced
        );
    }
}
