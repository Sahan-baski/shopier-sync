<?php
/**
 * Veritabani katmani: tablo olusturma + dusuk seviyeli CRUD.
 * Tum SQL burada toplaniyor ki geri kalan kod dogrudan $wpdb ile ugrasmasin.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MOS_DB
{
    public static function pools_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mos_pools';
    }

    public static function pool_sizes_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mos_pool_sizes';
    }

    public static function variation_map_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mos_variation_map';
    }

    public static function reduced_state_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mos_reduced_state';
    }

    public static function log_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mos_log';
    }

    public static function shopier_processed_table()
    {
        global $wpdb;
        return $wpdb->prefix . 'mos_shopier_processed';
    }

    public static function install()
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();

        $pools = self::pools_table();
        $pool_sizes = self::pool_sizes_table();
        $variation_map = self::variation_map_table();
        $reduced_state = self::reduced_state_table();
        $log = self::log_table();
        $shopier_processed = self::shopier_processed_table();

        $sql = "CREATE TABLE $pools (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(191) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id)
        ) $charset_collate;

        CREATE TABLE $pool_sizes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pool_id BIGINT UNSIGNED NOT NULL,
            size_key VARCHAR(191) NOT NULL,
            size_label VARCHAR(191) NOT NULL DEFAULT '',
            sort_order INT NOT NULL DEFAULT 0,
            shared_stock INT NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY pool_size (pool_id, size_key),
            KEY pool_id (pool_id)
        ) $charset_collate;

        CREATE TABLE $variation_map (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pool_id BIGINT UNSIGNED NOT NULL,
            design_product_id BIGINT UNSIGNED NOT NULL,
            size_key VARCHAR(191) NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
            shopier_product_id VARCHAR(64) NOT NULL DEFAULT '',
            shopier_variant_id VARCHAR(64) NOT NULL DEFAULT '',
            PRIMARY KEY  (id),
            UNIQUE KEY design_size (design_product_id, size_key),
            KEY pool_id (pool_id),
            KEY variation_id (variation_id),
            KEY shopier_lookup (shopier_product_id, shopier_variant_id)
        ) $charset_collate;

        CREATE TABLE $reduced_state (
            order_item_id BIGINT UNSIGNED NOT NULL,
            order_id BIGINT UNSIGNED NOT NULL,
            variation_id BIGINT UNSIGNED NOT NULL,
            design_product_id BIGINT UNSIGNED NOT NULL,
            size_key VARCHAR(191) NOT NULL,
            applied_qty INT NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (order_item_id)
        ) $charset_collate;

        CREATE TABLE $log (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            pool_id BIGINT UNSIGNED NOT NULL,
            design_product_id BIGINT UNSIGNED NOT NULL,
            size_key VARCHAR(191) NOT NULL,
            qty_change INT NOT NULL,
            shared_after INT NOT NULL,
            design_after INT NOT NULL,
            order_id BIGINT UNSIGNED NULL,
            note VARCHAR(255) NOT NULL DEFAULT '',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY pool_id (pool_id),
            KEY design_product_id (design_product_id)
        ) $charset_collate;

        CREATE TABLE $shopier_processed (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            shopier_order_id VARCHAR(64) NOT NULL,
            shopier_product_id VARCHAR(64) NOT NULL,
            shopier_variant_id VARCHAR(64) NOT NULL,
            qty INT NOT NULL DEFAULT 0,
            processed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY order_line (shopier_order_id, shopier_product_id, shopier_variant_id)
        ) $charset_collate;";

        dbDelta($sql);

        update_option('mos_db_version', MOS_VERSION);
    }

    // -----------------------------------------------------------------
    // POOLS
    // -----------------------------------------------------------------

    public static function get_pools()
    {
        global $wpdb;
        $table = self::pools_table();
        return $wpdb->get_results("SELECT * FROM $table ORDER BY name ASC");
    }

    public static function get_pool($pool_id)
    {
        global $wpdb;
        $table = self::pools_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE id = %d", $pool_id));
    }

    public static function insert_pool($name)
    {
        global $wpdb;
        $wpdb->insert(self::pools_table(), ['name' => $name], ['%s']);
        return (int) $wpdb->insert_id;
    }

    public static function rename_pool($pool_id, $name)
    {
        global $wpdb;
        $wpdb->update(self::pools_table(), ['name' => $name], ['id' => $pool_id], ['%s'], ['%d']);
    }

    public static function delete_pool($pool_id)
    {
        global $wpdb;
        $wpdb->delete(self::variation_map_table(), ['pool_id' => $pool_id], ['%d']);
        $wpdb->delete(self::pool_sizes_table(), ['pool_id' => $pool_id], ['%d']);
        $wpdb->delete(self::pools_table(), ['id' => $pool_id], ['%d']);
        // Bu havuza bagli tasarimlarin baglantisini kaldir (tasarim/urun silinmez)
        foreach (self::get_designs_for_pool($pool_id) as $pid) {
            delete_post_meta($pid, '_mos_pool_id');
        }
    }

    // -----------------------------------------------------------------
    // POOL SIZES
    // -----------------------------------------------------------------

    public static function get_pool_sizes($pool_id)
    {
        global $wpdb;
        $table = self::pool_sizes_table();
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE pool_id = %d ORDER BY sort_order ASC", $pool_id));
    }

    public static function get_pool_size($pool_id, $size_key)
    {
        global $wpdb;
        $table = self::pool_sizes_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE pool_id = %d AND size_key = %s", $pool_id, $size_key));
    }

    /**
     * sizes: [ ['key' => 'xxx', 'label' => 'Xxx'], ... ]
     * Var olan bedenlerin stogu korunur; yeni eklenenler 0 ile baslar;
     * listeden cikarilanlar (ve onlara bagli varyasyon eslemeleri) silinir.
     */
    public static function replace_pool_sizes($pool_id, $sizes)
    {
        global $wpdb;
        $table = self::pool_sizes_table();
        $existing = self::get_pool_sizes($pool_id);
        $existing_by_key = [];
        foreach ($existing as $row) {
            $existing_by_key[$row->size_key] = $row;
        }

        $keep_keys = wp_list_pluck($sizes, 'key');
        $removed_keys = array_diff(array_keys($existing_by_key), $keep_keys);

        foreach ($removed_keys as $key) {
            $wpdb->delete($table, ['pool_id' => $pool_id, 'size_key' => $key], ['%d', '%s']);
            $wpdb->delete(self::variation_map_table(), ['pool_id' => $pool_id, 'size_key' => $key], ['%d', '%s']);
        }

        $order = 0;
        foreach ($sizes as $s) {
            $key = trim($s['key']);
            if ($key === '') {
                continue;
            }
            $label = isset($s['label']) && $s['label'] !== '' ? $s['label'] : $key;
            if (isset($existing_by_key[$key])) {
                $wpdb->update(
                    $table,
                    ['size_label' => $label, 'sort_order' => $order],
                    ['pool_id' => $pool_id, 'size_key' => $key],
                    ['%s', '%d'],
                    ['%d', '%s']
                );
            } else {
                $wpdb->insert($table, [
                    'pool_id' => $pool_id,
                    'size_key' => $key,
                    'size_label' => $label,
                    'sort_order' => $order,
                    'shared_stock' => 0,
                ], ['%d', '%s', '%s', '%d', '%d']);
            }
            $order++;
        }
    }

    public static function set_shared_stock($pool_id, $size_key, $qty)
    {
        global $wpdb;
        $wpdb->update(
            self::pool_sizes_table(),
            ['shared_stock' => max(0, (int) $qty)],
            ['pool_id' => $pool_id, 'size_key' => $size_key],
            ['%d'],
            ['%d', '%s']
        );
    }

    public static function bump_shared_stock($pool_id, $size_key, $signed_delta)
    {
        global $wpdb;
        $table = self::pool_sizes_table();
        $current = self::get_pool_size($pool_id, $size_key);
        if (!$current) {
            return null;
        }
        $new_val = max(0, (int) $current->shared_stock + (int) $signed_delta);
        $wpdb->update($table, ['shared_stock' => $new_val], ['pool_id' => $pool_id, 'size_key' => $size_key], ['%d'], ['%d', '%s']);
        return $new_val;
    }

    // -----------------------------------------------------------------
    // VARIATION MAP (tasarim + beden -> gercek WooCommerce varyasyon ID'si)
    // -----------------------------------------------------------------

    public static function set_variation_map($pool_id, $design_product_id, $size_key, $variation_id)
    {
        global $wpdb;
        $table = self::variation_map_table();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE design_product_id = %d AND size_key = %s",
            $design_product_id,
            $size_key
        ));
        if ($existing) {
            $wpdb->update($table, [
                'pool_id' => $pool_id,
                'variation_id' => $variation_id,
            ], ['id' => $existing->id], ['%d', '%d'], ['%d']);
        } else {
            $wpdb->insert($table, [
                'pool_id' => $pool_id,
                'design_product_id' => $design_product_id,
                'size_key' => $size_key,
                'variation_id' => $variation_id,
                'shopier_product_id' => self::get_shopier_product_id($design_product_id),
            ], ['%d', '%d', '%s', '%d', '%s']);
        }
    }

    public static function clear_variation_map($design_product_id, $size_key)
    {
        global $wpdb;
        $wpdb->delete(self::variation_map_table(), [
            'design_product_id' => $design_product_id,
            'size_key' => $size_key,
        ], ['%d', '%s']);
    }

    public static function get_variation_map_for_design($design_product_id)
    {
        global $wpdb;
        $table = self::variation_map_table();
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table WHERE design_product_id = %d", $design_product_id));
        $by_size = [];
        foreach ($rows as $row) {
            $by_size[$row->size_key] = $row;
        }
        return $by_size;
    }

    public static function find_map_by_variation($variation_id)
    {
        global $wpdb;
        $table = self::variation_map_table();
        return $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE variation_id = %d", $variation_id));
    }

    // -----------------------------------------------------------------
    // DESIGNS (= WooCommerce degiskenli urunler; ayarlar post meta'da tutulur)
    // -----------------------------------------------------------------

    public static function get_design_pool_id($product_id)
    {
        $val = get_post_meta($product_id, '_mos_pool_id', true);
        return $val ? (int) $val : 0;
    }

    public static function set_design_pool_id($product_id, $pool_id)
    {
        if ($pool_id) {
            update_post_meta($product_id, '_mos_pool_id', (int) $pool_id);
        } else {
            delete_post_meta($product_id, '_mos_pool_id');
        }
    }

    public static function get_design_stock($product_id)
    {
        $val = get_post_meta($product_id, '_mos_design_stock', true);
        return $val === '' ? 0 : (int) $val;
    }

    public static function set_design_stock($product_id, $qty)
    {
        update_post_meta($product_id, '_mos_design_stock', max(0, (int) $qty));
    }

    public static function get_designs_for_pool($pool_id)
    {
        $ids = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'meta_key' => '_mos_pool_id',
            'meta_value' => $pool_id,
            'orderby' => 'title',
            'order' => 'ASC',
        ]);
        return $ids;
    }

    // -----------------------------------------------------------------
    // REDUCED STATE (siparis kalemi basina "su an ne kadari dusulmus" takibi -
    // WooCommerce'in kendi _reduced_stock meta'siyla karsilastirmak icin)
    // -----------------------------------------------------------------

    public static function get_reduced_state($order_item_id)
    {
        global $wpdb;
        $table = self::reduced_state_table();
        $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE order_item_id = %d", $order_item_id));
        return $row ? (int) $row->applied_qty : 0;
    }

    public static function set_reduced_state($order_item_id, $order_id, $variation_id, $design_product_id, $size_key, $applied_qty)
    {
        global $wpdb;
        $table = self::reduced_state_table();
        $wpdb->replace($table, [
            'order_item_id' => $order_item_id,
            'order_id' => $order_id,
            'variation_id' => $variation_id,
            'design_product_id' => $design_product_id,
            'size_key' => $size_key,
            'applied_qty' => $applied_qty,
        ], ['%d', '%d', '%d', '%d', '%s', '%d']);
    }

    // -----------------------------------------------------------------
    // LOG
    // -----------------------------------------------------------------

    public static function add_log($pool_id, $design_product_id, $size_key, $qty_change, $shared_after, $design_after, $order_id, $note)
    {
        global $wpdb;
        $wpdb->insert(self::log_table(), [
            'pool_id' => $pool_id,
            'design_product_id' => $design_product_id,
            'size_key' => $size_key,
            'qty_change' => $qty_change,
            'shared_after' => $shared_after,
            'design_after' => $design_after,
            'order_id' => $order_id ?: null,
            'note' => $note,
        ], ['%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s']);
    }

    public static function get_recent_log($pool_id = null, $limit = 100)
    {
        global $wpdb;
        $table = self::log_table();
        if ($pool_id) {
            return $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $table WHERE pool_id = %d ORDER BY id DESC LIMIT %d",
                $pool_id,
                $limit
            ));
        }
        return $wpdb->get_results($wpdb->prepare("SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit));
    }

    // -----------------------------------------------------------------
    // SHOPIER AYARLARI (tek bir option'da tutulur)
    // -----------------------------------------------------------------

    public static function get_shopier_settings()
    {
        $defaults = [
            'access_key' => '',
            'webhook_secret' => '',
            'api_base' => 'https://api.shopier.com/v1',
            'dry_run' => true,
        ];
        $saved = get_option('mos_shopier_settings', []);
        if (!is_array($saved)) {
            $saved = [];
        }
        return array_merge($defaults, $saved);
    }

    public static function save_shopier_settings($data)
    {
        $current = self::get_shopier_settings();
        $updated = array_merge($current, array_intersect_key($data, $current));
        $updated['dry_run'] = !empty($updated['dry_run']);
        $updated['access_key'] = trim((string) $updated['access_key']);
        $updated['webhook_secret'] = trim((string) $updated['webhook_secret']);
        $updated['api_base'] = rtrim(trim((string) $updated['api_base']), '/') ?: 'https://api.shopier.com/v1';
        update_option('mos_shopier_settings', $updated);
        return $updated;
    }

    // -----------------------------------------------------------------
    // SHOPIER ESLEME (tasarim <-> Shopier urun/varyasyon id)
    // -----------------------------------------------------------------

    // Shopier urun ID'si TASARIM (WooCommerce urunu) seviyesinde - tum bedenler icin ayni.
    // Asil kayit post meta'da tutulur, ayrica hizli webhook aramasi icin variation_map
    // satirlarina da kopyalanir (asagida sync_shopier_product_id_rows).
    public static function get_shopier_product_id($design_product_id)
    {
        return (string) get_post_meta($design_product_id, '_mos_shopier_product_id', true);
    }

    public static function set_shopier_product_id($design_product_id, $shopier_product_id)
    {
        $shopier_product_id = trim((string) $shopier_product_id);
        if ($shopier_product_id === '') {
            delete_post_meta($design_product_id, '_mos_shopier_product_id');
        } else {
            update_post_meta($design_product_id, '_mos_shopier_product_id', $shopier_product_id);
        }
        self::sync_shopier_product_id_rows($design_product_id, $shopier_product_id);
    }

    private static function sync_shopier_product_id_rows($design_product_id, $shopier_product_id)
    {
        global $wpdb;
        $wpdb->update(
            self::variation_map_table(),
            ['shopier_product_id' => $shopier_product_id],
            ['design_product_id' => $design_product_id],
            ['%s'],
            ['%d']
        );
    }

    private static function ensure_map_row($pool_id, $design_product_id, $size_key)
    {
        global $wpdb;
        $table = self::variation_map_table();
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM $table WHERE design_product_id = %d AND size_key = %s",
            $design_product_id,
            $size_key
        ));
        if ($existing) {
            return;
        }
        $wpdb->insert($table, [
            'pool_id' => $pool_id,
            'design_product_id' => $design_product_id,
            'size_key' => $size_key,
            'variation_id' => 0,
            'shopier_product_id' => self::get_shopier_product_id($design_product_id),
            'shopier_variant_id' => '',
        ], ['%d', '%d', '%s', '%d', '%s', '%s']);
    }

    public static function set_shopier_variant_id($pool_id, $design_product_id, $size_key, $shopier_variant_id)
    {
        global $wpdb;
        self::ensure_map_row($pool_id, $design_product_id, $size_key);
        $wpdb->update(
            self::variation_map_table(),
            [
                'shopier_variant_id' => trim((string) $shopier_variant_id),
                'shopier_product_id' => self::get_shopier_product_id($design_product_id),
            ],
            ['design_product_id' => $design_product_id, 'size_key' => $size_key],
            ['%s', '%s'],
            ['%d', '%s']
        );
    }

    public static function get_shopier_variant_id($design_product_id, $size_key)
    {
        global $wpdb;
        $table = self::variation_map_table();
        $val = $wpdb->get_var($wpdb->prepare(
            "SELECT shopier_variant_id FROM $table WHERE design_product_id = %d AND size_key = %s",
            $design_product_id,
            $size_key
        ));
        return $val ? (string) $val : '';
    }

    // Shopier siparis webhook'undan gelen (urun id, varyasyon/selection id) ciftinden
    // hangi (tasarim, beden) hucresine karsilik geldigini bulur.
    public static function find_map_by_shopier_ids($shopier_product_id, $shopier_variant_id)
    {
        global $wpdb;
        $table = self::variation_map_table();
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table WHERE shopier_product_id = %s AND shopier_variant_id = %s LIMIT 1",
            (string) $shopier_product_id,
            (string) $shopier_variant_id
        ));
    }

    // Bir havuzdaki Shopier'e eslenmis (shopier_variant_id dolu) TUM hucreleri dondurur -
    // "Tumunu Shopier'e Gonder" toplu islemi icin.
    public static function get_shopier_mapped_cells_for_pool($pool_id)
    {
        global $wpdb;
        $table = self::variation_map_table();
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table WHERE pool_id = %d AND shopier_product_id != '' AND shopier_variant_id != ''",
            $pool_id
        ));
    }

    // -----------------------------------------------------------------
    // SHOPIER SIPARIS IDEMPOTENCY (ayni webhook tekrar gelirse iki kez uygulamamak icin)
    // -----------------------------------------------------------------

    public static function is_shopier_line_processed($shopier_order_id, $shopier_product_id, $shopier_variant_id)
    {
        global $wpdb;
        $table = self::shopier_processed_table();
        $found = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table WHERE shopier_order_id = %s AND shopier_product_id = %s AND shopier_variant_id = %s",
            (string) $shopier_order_id,
            (string) $shopier_product_id,
            (string) $shopier_variant_id
        ));
        return (bool) $found;
    }

    public static function mark_shopier_line_processed($shopier_order_id, $shopier_product_id, $shopier_variant_id, $qty)
    {
        global $wpdb;
        // Ayni siparis/kalem tekrar gelirse (Shopier'in kendi retry mekanizmasi) ikinci
        // kez islenmesin diye UNIQUE anahtara guveniyoruz - cakisma olursa sessizce yut.
        $wpdb->suppress_errors(true);
        $wpdb->insert(self::shopier_processed_table(), [
            'shopier_order_id' => (string) $shopier_order_id,
            'shopier_product_id' => (string) $shopier_product_id,
            'shopier_variant_id' => (string) $shopier_variant_id,
            'qty' => (int) $qty,
        ], ['%s', '%s', '%s', '%d']);
        $wpdb->suppress_errors(false);
    }
}
