<?php
/**
 * Yonetim paneli: havuz listesi/olusturma, stok tablosu (matris) ekrani,
 * urun duzenleme ekranina "Ortak Stok Havuzu" kutusu, ve tum bunlarin
 * AJAX/form-post uc noktalari.
 */

if (!defined('ABSPATH')) {
    exit;
}

class MOS_Admin
{
    const CAP = 'manage_woocommerce';
    const NONCE = 'mos_admin_nonce';

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);

        // Havuz CRUD - normal form POST (admin-post.php)
        add_action('admin_post_mos_create_pool', [__CLASS__, 'handle_create_pool']);
        add_action('admin_post_mos_update_pool', [__CLASS__, 'handle_update_pool']);
        add_action('admin_post_mos_delete_pool', [__CLASS__, 'handle_delete_pool']);

        // Shopier ayarlari - normal form POST (admin-post.php)
        add_action('admin_post_mos_save_shopier_settings', [__CLASS__, 'handle_save_shopier_settings']);
        add_action('admin_post_mos_create_shopier_webhook', [__CLASS__, 'handle_create_shopier_webhook']);

        // Stok tablosu - AJAX
        add_action('wp_ajax_mos_set_shared_stock', [__CLASS__, 'ajax_set_shared_stock']);
        add_action('wp_ajax_mos_set_design_stock', [__CLASS__, 'ajax_set_design_stock']);
        add_action('wp_ajax_mos_map_variation', [__CLASS__, 'ajax_map_variation']);
        add_action('wp_ajax_mos_add_design', [__CLASS__, 'ajax_add_design']);
        add_action('wp_ajax_mos_remove_design', [__CLASS__, 'ajax_remove_design']);
        add_action('wp_ajax_mos_sync_pool', [__CLASS__, 'ajax_sync_pool']);
        add_action('wp_ajax_mos_search_products', [__CLASS__, 'ajax_search_products']);
        add_action('wp_ajax_mos_get_variations', [__CLASS__, 'ajax_get_variations']);

        // Shopier esleme - AJAX
        add_action('wp_ajax_mos_shopier_fetch_child_sizes', [__CLASS__, 'ajax_shopier_fetch_child_sizes']);
        add_action('wp_ajax_mos_shopier_save_product_id', [__CLASS__, 'ajax_shopier_save_product_id']);
        add_action('wp_ajax_mos_shopier_map_variant', [__CLASS__, 'ajax_shopier_map_variant']);

        // Urun duzenleme ekrani kutusu
        add_action('add_meta_boxes', [__CLASS__, 'add_product_metabox']);
        add_action('save_post_product', [__CLASS__, 'save_product_metabox']);

        // Varyasyon satirinin icine "bu havuza bagli, otomatik hesaplanir" notu
        add_action('woocommerce_product_after_variable_attributes', [__CLASS__, 'render_variation_notice'], 10, 3);
    }

    public static function render_variation_notice($loop, $variation_data, $variation)
    {
        $map = MOS_DB::find_map_by_variation($variation->ID);
        if (!$map) {
            return;
        }
        $eff = MOS_Engine::effective_stock($map->design_product_id, $map->size_key);
        echo '<p class="form-row form-row-full mos-variation-note" style="background:#fff8e5;border:1px solid #e6d68a;padding:8px 10px;margin:8px 0;">';
        echo '🔒 Bu varyasyon bir <strong>Ortak Stok Havuzu</strong> bedenine bağlı. Stok miktarı burada elle değiştirilse bile kaydettiğinde otomatik olarak hesaplanan değere (' . (int) $eff . ') geri çevrilir. Gerçek değeri değiştirmek için ';
        echo '<a href="' . esc_url(admin_url('admin.php?page=mos-stock-table&pool_id=' . (int) $map->pool_id)) . '" target="_blank">Stok Tablosu</a> ekranını kullan.';
        echo '</p>';
    }

    public static function add_menu()
    {
        add_menu_page(
            'Ortak Stok Havuzu',
            'Ortak Stok Havuzu',
            self::CAP,
            'mos-pools',
            [__CLASS__, 'render_pools_page'],
            'dashicons-randomize',
            56
        );
        add_submenu_page('mos-pools', 'Havuzlar', 'Havuzlar', self::CAP, 'mos-pools', [__CLASS__, 'render_pools_page']);
        add_submenu_page('mos-pools', 'Stok Tablosu', 'Stok Tablosu', self::CAP, 'mos-stock-table', [__CLASS__, 'render_stock_table_page']);
        add_submenu_page('mos-pools', 'Shopier Bağlantısı', 'Shopier Bağlantısı', self::CAP, 'mos-shopier', [__CLASS__, 'render_shopier_settings_page']);
    }

    public static function enqueue($hook)
    {
        if (strpos($hook, 'mos-pools') === false && strpos($hook, 'mos-stock-table') === false && strpos($hook, 'mos-shopier') === false && !self::is_product_edit_screen()) {
            return;
        }
        wp_enqueue_style('mos-admin', MOS_PLUGIN_URL . 'assets/admin.css', [], MOS_VERSION);
        wp_enqueue_script('mos-admin', MOS_PLUGIN_URL . 'assets/admin.js', ['jquery'], MOS_VERSION, true);
        wp_localize_script('mos-admin', 'MOS', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE),
        ]);
    }

    private static function is_product_edit_screen()
    {
        global $pagenow, $typenow;
        return in_array($pagenow, ['post.php', 'post-new.php'], true) && $typenow === 'product';
    }

    // -----------------------------------------------------------------
    // HAVUZLAR SAYFASI
    // -----------------------------------------------------------------

    public static function render_pools_page()
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $pools = MOS_DB::get_pools();
        $edit_id = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
        $edit_pool = $edit_id ? MOS_DB::get_pool($edit_id) : null;
        $edit_sizes = $edit_id ? MOS_DB::get_pool_sizes($edit_id) : [];
        include MOS_PLUGIN_DIR . 'includes/views/pools-page.php';
    }

    public static function handle_create_pool()
    {
        self::guard_post();
        $name = sanitize_text_field($_POST['name'] ?? '');
        $sizes = self::parse_sizes_input($_POST['sizes'] ?? '');
        if (!$name || empty($sizes)) {
            self::redirect_with_notice('mos-pools', 'error', 'İsim ve en az bir beden gerekli.');
        }
        $pool_id = MOS_DB::insert_pool($name);
        MOS_DB::replace_pool_sizes($pool_id, $sizes);
        self::redirect_with_notice('mos-pools', 'success', 'Havuz oluşturuldu.');
    }

    public static function handle_update_pool()
    {
        self::guard_post();
        $pool_id = (int) ($_POST['pool_id'] ?? 0);
        $name = sanitize_text_field($_POST['name'] ?? '');
        $sizes = self::parse_sizes_input($_POST['sizes'] ?? '');
        if (!$pool_id || !$name || empty($sizes)) {
            self::redirect_with_notice('mos-pools', 'error', 'İsim ve en az bir beden gerekli.');
        }
        MOS_DB::rename_pool($pool_id, $name);
        MOS_DB::replace_pool_sizes($pool_id, $sizes);
        MOS_Engine::sync_pool_to_wc($pool_id);
        self::redirect_with_notice('mos-pools', 'success', 'Havuz güncellendi.');
    }

    public static function handle_delete_pool()
    {
        self::guard_post();
        $pool_id = (int) ($_POST['pool_id'] ?? 0);
        if ($pool_id) {
            MOS_DB::delete_pool($pool_id);
        }
        self::redirect_with_notice('mos-pools', 'success', 'Havuz silindi.');
    }

    private static function parse_sizes_input($raw)
    {
        $parts = array_filter(array_map('trim', explode(',', (string) $raw)));
        $sizes = [];
        $used_keys = [];
        foreach ($parts as $label) {
            $key = sanitize_title($label);
            if ($key === '') {
                continue;
            }
            $base = $key;
            $i = 2;
            while (in_array($key, $used_keys, true)) {
                $key = $base . '-' . $i;
                $i++;
            }
            $used_keys[] = $key;
            $sizes[] = ['key' => $key, 'label' => $label];
        }
        return $sizes;
    }

    private static function guard_post()
    {
        if (!current_user_can(self::CAP)) {
            wp_die('Yetkiniz yok.');
        }
        check_admin_referer(self::NONCE);
    }

    private static function redirect_with_notice($page, $type, $message)
    {
        $url = add_query_arg([
            'page' => $page,
            'mos_notice' => $type,
            'mos_msg' => rawurlencode($message),
        ], admin_url('admin.php'));
        wp_safe_redirect($url);
        exit;
    }

    // -----------------------------------------------------------------
    // STOK TABLOSU SAYFASI
    // -----------------------------------------------------------------

    public static function render_stock_table_page()
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $pools = MOS_DB::get_pools();
        $pool_id = isset($_GET['pool_id']) ? (int) $_GET['pool_id'] : (count($pools) ? (int) $pools[0]->id : 0);
        $data = $pool_id ? MOS_Engine::get_stock_table($pool_id) : ['pool' => null, 'table' => []];
        include MOS_PLUGIN_DIR . 'includes/views/stock-table-page.php';
    }

    // -----------------------------------------------------------------
    // SHOPIER BAGLANTISI SAYFASI
    // -----------------------------------------------------------------

    public static function render_shopier_settings_page()
    {
        if (!current_user_can(self::CAP)) {
            return;
        }
        $settings = MOS_DB::get_shopier_settings();
        $webhook_url = rest_url('mos/v1/shopier-order');
        $last_webhook = get_option('mos_shopier_last_webhook');
        include MOS_PLUGIN_DIR . 'includes/views/shopier-settings-page.php';
    }

    public static function handle_save_shopier_settings()
    {
        self::guard_post();
        MOS_DB::save_shopier_settings([
            'access_key' => $_POST['access_key'] ?? '',
            'webhook_secret' => $_POST['webhook_secret'] ?? '',
            'api_base' => $_POST['api_base'] ?? '',
            'dry_run' => isset($_POST['dry_run']),
        ]);
        self::redirect_with_notice('mos-shopier', 'success', 'Shopier ayarları kaydedildi.');
    }

    public static function handle_create_shopier_webhook()
    {
        self::guard_post();
        $result = MOS_Shopier_Client::create_webhook_subscription();
        if (is_wp_error($result)) {
            self::redirect_with_notice('mos-shopier', 'error', $result->get_error_message());
        }
        if (!empty($result['token'])) {
            MOS_DB::save_shopier_settings(['webhook_secret' => $result['token']]);
            self::redirect_with_notice('mos-shopier', 'success', 'Webhook aboneliği oluşturuldu, gelen token otomatik kaydedildi.');
        }
        self::redirect_with_notice('mos-shopier', 'error', 'Webhook oluşturuldu ama beklenmeyen bir cevap geldi, konsolu/logu kontrol et: ' . wp_json_encode($result));
    }

    // -----------------------------------------------------------------
    // AJAX
    // -----------------------------------------------------------------

    private static function ajax_guard()
    {
        if (!current_user_can(self::CAP)) {
            wp_send_json_error(['message' => 'Yetkiniz yok.'], 403);
        }
        check_ajax_referer(self::NONCE, 'nonce');
    }

    public static function ajax_set_shared_stock()
    {
        self::ajax_guard();
        $pool_id = (int) ($_POST['pool_id'] ?? 0);
        $size_key = sanitize_text_field($_POST['size_key'] ?? '');
        $qty = (int) ($_POST['qty'] ?? 0);
        if (!$pool_id || !$size_key) {
            wp_send_json_error(['message' => 'Eksik parametre.']);
        }
        MOS_Engine::set_shared_stock_and_sync($pool_id, $size_key, $qty);
        wp_send_json_success(MOS_Engine::get_stock_table($pool_id));
    }

    public static function ajax_set_design_stock()
    {
        self::ajax_guard();
        $design_id = (int) ($_POST['design_id'] ?? 0);
        $qty = (int) ($_POST['qty'] ?? 0);
        if (!$design_id) {
            wp_send_json_error(['message' => 'Eksik parametre.']);
        }
        MOS_Engine::set_design_stock_and_sync($design_id, $qty);
        $pool_id = MOS_DB::get_design_pool_id($design_id);
        wp_send_json_success(MOS_Engine::get_stock_table($pool_id));
    }

    public static function ajax_map_variation()
    {
        self::ajax_guard();
        $pool_id = (int) ($_POST['pool_id'] ?? 0);
        $design_id = (int) ($_POST['design_id'] ?? 0);
        $size_key = sanitize_text_field($_POST['size_key'] ?? '');
        $variation_id = (int) ($_POST['variation_id'] ?? 0);
        if (!$pool_id || !$design_id || !$size_key) {
            wp_send_json_error(['message' => 'Eksik parametre.']);
        }
        if ($variation_id) {
            // Bu varyasyon gercekten bu tasarimin (urunun) alt varyasyonu mu, dogrula.
            $variation = wc_get_product($variation_id);
            if (!$variation || (int) $variation->get_parent_id() !== $design_id) {
                wp_send_json_error(['message' => 'Geçersiz varyasyon.']);
            }
            MOS_DB::set_variation_map($pool_id, $design_id, $size_key, $variation_id);
            MOS_Engine::sync_cell($design_id, $size_key);
        } else {
            MOS_DB::clear_variation_map($design_id, $size_key);
        }
        wp_send_json_success(MOS_Engine::get_stock_table($pool_id));
    }

    public static function ajax_add_design()
    {
        self::ajax_guard();
        $pool_id = (int) ($_POST['pool_id'] ?? 0);
        $design_id = (int) ($_POST['design_id'] ?? 0);
        if (!$pool_id || !$design_id) {
            wp_send_json_error(['message' => 'Eksik parametre.']);
        }
        $product = wc_get_product($design_id);
        if (!$product || !$product->is_type('variable')) {
            wp_send_json_error(['message' => 'Sadece "değişken" (varyasyonlu) ürünler bir havuza eklenebilir.']);
        }
        MOS_DB::set_design_pool_id($design_id, $pool_id);
        wp_send_json_success(MOS_Engine::get_stock_table($pool_id));
    }

    public static function ajax_remove_design()
    {
        self::ajax_guard();
        $design_id = (int) ($_POST['design_id'] ?? 0);
        $pool_id = MOS_DB::get_design_pool_id($design_id);
        MOS_DB::set_design_pool_id($design_id, 0);
        wp_send_json_success(MOS_Engine::get_stock_table($pool_id));
    }

    public static function ajax_sync_pool()
    {
        self::ajax_guard();
        $pool_id = (int) ($_POST['pool_id'] ?? 0);
        $result = MOS_Engine::sync_pool_to_wc($pool_id);
        wp_send_json_success(array_merge($result, MOS_Engine::get_stock_table($pool_id)));
    }

    public static function ajax_search_products()
    {
        self::ajax_guard();
        $term = sanitize_text_field($_POST['term'] ?? '');
        $args = [
            'post_type' => 'product',
            'posts_per_page' => 20,
            's' => $term,
            'tax_query' => [[
                'taxonomy' => 'product_type',
                'field' => 'slug',
                'terms' => 'variable',
            ]],
        ];
        $q = new WP_Query($args);
        $results = [];
        foreach ($q->posts as $p) {
            $pool_id = MOS_DB::get_design_pool_id($p->ID);
            $results[] = [
                'id' => $p->ID,
                'text' => $p->post_title . ($pool_id ? ' (zaten bir havuzda)' : ''),
                'disabled' => (bool) $pool_id,
            ];
        }
        wp_send_json_success($results);
    }

    public static function ajax_get_variations()
    {
        self::ajax_guard();
        $design_id = (int) ($_POST['design_id'] ?? 0);
        $product = wc_get_product($design_id);
        if (!$product || !$product->is_type('variable')) {
            wp_send_json_error(['message' => 'Ürün bulunamadı.']);
        }
        $out = [];
        foreach ($product->get_children() as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) {
                continue;
            }
            $attrs = $variation->get_attributes();
            $label = [];
            foreach ($attrs as $k => $v) {
                $taxonomy = str_replace('attribute_', '', $k);
                if (taxonomy_exists($taxonomy)) {
                    $term = get_term_by('slug', $v, $taxonomy);
                    $label[] = $term ? $term->name : $v;
                } else {
                    $label[] = $v;
                }
            }
            $out[] = [
                'id' => $variation_id,
                'label' => $label ? implode(' / ', $label) : ('#' . $variation_id),
                'stock' => $variation->get_stock_quantity(),
            ];
        }
        wp_send_json_success($out);
    }

    // -----------------------------------------------------------------
    // SHOPIER ESLEME (AJAX)
    // -----------------------------------------------------------------

    // Hesap genelindeki TUM "beden" secimlerini Shopier'den ceker (urune ozel degil -
    // bir kere cekilip stok tablosundaki her hucrenin dropdown'unda kullanilir).
    public static function ajax_shopier_fetch_child_sizes()
    {
        self::ajax_guard();
        $sizes = MOS_Shopier_Client::fetch_child_size_selections();
        if (is_wp_error($sizes)) {
            wp_send_json_error(['message' => $sizes->get_error_message()]);
        }
        wp_send_json_success($sizes);
    }

    public static function ajax_shopier_save_product_id()
    {
        self::ajax_guard();
        $design_id = (int) ($_POST['design_id'] ?? 0);
        $shopier_product_id = sanitize_text_field($_POST['shopier_product_id'] ?? '');
        if (!$design_id) {
            wp_send_json_error(['message' => 'Eksik parametre.']);
        }
        MOS_DB::set_shopier_product_id($design_id, $shopier_product_id);
        // Urun ID'si degistiyse, bu tasarimin zaten eslenmis bedenlerini de (varsa)
        // yeni urune gore Shopier'e yeniden gondermeyi planla.
        MOS_Engine::sync_design_to_wc($design_id);
        $pool_id = MOS_DB::get_design_pool_id($design_id);
        wp_send_json_success(MOS_Engine::get_stock_table($pool_id));
    }

    public static function ajax_shopier_map_variant()
    {
        self::ajax_guard();
        $pool_id = (int) ($_POST['pool_id'] ?? 0);
        $design_id = (int) ($_POST['design_id'] ?? 0);
        $size_key = sanitize_text_field($_POST['size_key'] ?? '');
        $shopier_variant_id = sanitize_text_field($_POST['shopier_variant_id'] ?? '');
        if (!$pool_id || !$design_id || !$size_key) {
            wp_send_json_error(['message' => 'Eksik parametre.']);
        }
        MOS_DB::set_shopier_variant_id($pool_id, $design_id, $size_key, $shopier_variant_id);
        // Bu hucrenin guncel gorunen stogunu hemen Shopier'e de gondermeyi (arka planda) planla.
        MOS_Engine::sync_cell($design_id, $size_key);
        wp_send_json_success(MOS_Engine::get_stock_table($pool_id));
    }

    // -----------------------------------------------------------------
    // URUN DUZENLEME EKRANI KUTUSU
    // -----------------------------------------------------------------

    public static function add_product_metabox()
    {
        add_meta_box(
            'mos_pool_box',
            'Ortak Stok Havuzu',
            [__CLASS__, 'render_product_metabox'],
            'product',
            'side',
            'default'
        );
    }

    public static function render_product_metabox($post)
    {
        $product = wc_get_product($post->ID);
        if (!$product || !$product->is_type('variable')) {
            echo '<p>Ortak stok havuzu yalnızca <strong>değişken (varyasyonlu)</strong> ürünlerde kullanılabilir. Bu ürünün tipi "değişken" değil.</p>';
            return;
        }
        wp_nonce_field('mos_product_box', 'mos_product_box_nonce');
        $pool_id = MOS_DB::get_design_pool_id($post->ID);
        $design_stock = MOS_DB::get_design_stock($post->ID);
        $pools = MOS_DB::get_pools();
        ?>
        <p>
            <label for="mos_pool_id"><strong>Bağlı olduğu havuz</strong></label><br />
            <select name="mos_pool_id" id="mos_pool_id" style="width:100%">
                <option value="">— Bağlı değil —</option>
                <?php foreach ($pools as $p) : ?>
                    <option value="<?php echo esc_attr($p->id); ?>" <?php selected($pool_id, $p->id); ?>><?php echo esc_html($p->name); ?></option>
                <?php endforeach; ?>
            </select>
        </p>
        <p>
            <label for="mos_design_stock"><strong>Tasarım stoğu</strong></label><br />
            <input type="number" min="0" step="1" name="mos_design_stock" id="mos_design_stock" value="<?php echo esc_attr($design_stock); ?>" style="width:100%" />
            <span class="description">Bu tasarımın kendi baskı/ürün stoğu. Görünen stok = min(havuzun ortak beden stoğu, bu değer).</span>
        </p>
        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=mos-stock-table&pool_id=' . (int) $pool_id)); ?>">Beden ↔ varyasyon eşlemesini ve ortak stoğu Stok Tablosu'ndan düzenle →</a>
        </p>
        <?php
    }

    public static function save_product_metabox($post_id)
    {
        if (!isset($_POST['mos_product_box_nonce']) || !wp_verify_nonce($_POST['mos_product_box_nonce'], 'mos_product_box')) {
            return;
        }
        if (!current_user_can(self::CAP)) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        $pool_id = isset($_POST['mos_pool_id']) ? (int) $_POST['mos_pool_id'] : 0;
        MOS_DB::set_design_pool_id($post_id, $pool_id);
        if (isset($_POST['mos_design_stock'])) {
            MOS_Engine::set_design_stock_and_sync($post_id, (int) $_POST['mos_design_stock']);
        }
    }
}
