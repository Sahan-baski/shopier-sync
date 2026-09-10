<?php
/**
 * Plugin Name: Miras Ortak Stok Havuzu
 * Plugin URI: https://mirasgiyim.com
 * Description: Aynı bedeni paylaşan birden fazla tasarım (varyasyonlu ürün) arasında ortak/paylaşımlı stok havuzu kurar. Bir tasarımın bir bedeninden satış olduğunda, o bedeni paylaşan TÜM tasarımların stoğu otomatik düşer. WooCommerce'in kendi sipariş/stok mekanizmasına bağlanır VE (eşleştirdiğin tasarımlar için) aynı stoğu Shopier'deki ürünlere de otomatik gönderir - ayrı bir Node.js servisine ihtiyaç yoktur, hepsi bu eklentinin içindedir.
 * Version: 2.0.0
 * Author: Şahan Baskı
 * Text Domain: miras-ortak-stok
 * Requires Plugins: woocommerce
 * Requires PHP: 7.4
 *
 * Bu eklenti sunucunun (WordPress'in) kendi içinde çalışır: veriyi WooCommerce'in
 * kendi veritabanından okur, hesaplamayı burada yapar, sonucu yine WooCommerce'in
 * kendi ürün/varyasyon stoğuna yazar - ve eşlenmiş hücreler için AYNI anda (arka
 * planda, WP-Cron ile) Shopier'in REST API'sine de yazar. Shopier'den gelen
 * siparişler de wp-json/mos/v1/shopier-order adresinden doğrudan bu eklenti
 * tarafından karşılanır (bkz. class-mos-shopier-webhook.php). Eski, ayrı
 * çalışan Node.js/SQLite servisinin (shopier-stok-sync) yerini tamamen alır.
 */

if (!defined('ABSPATH')) {
    exit; // dogrudan erisim yok
}

define('MOS_VERSION', '2.0.0');
define('MOS_PLUGIN_FILE', __FILE__);
define('MOS_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('MOS_PLUGIN_URL', plugin_dir_url(__FILE__));

// WooCommerce yuklu degilse eklentiyi sessizce devre disi birak, admin'e uyar.
add_action('admin_init', function () {
    if (!class_exists('WooCommerce')) {
        deactivate_plugins(plugin_basename(MOS_PLUGIN_FILE));
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p><strong>Miras Ortak Stok Havuzu</strong> çalışmak için WooCommerce eklentisinin aktif olmasını gerektirir. WooCommerce aktif olmadığı için bu eklenti kapatıldı.</p></div>';
        });
        if (isset($_GET['activate'])) {
            unset($_GET['activate']);
        }
    }
});

require_once MOS_PLUGIN_DIR . 'includes/class-mos-db.php';
require_once MOS_PLUGIN_DIR . 'includes/class-mos-engine.php';
require_once MOS_PLUGIN_DIR . 'includes/class-mos-hooks.php';
require_once MOS_PLUGIN_DIR . 'includes/class-mos-admin.php';
require_once MOS_PLUGIN_DIR . 'includes/class-mos-shopier-client.php';
require_once MOS_PLUGIN_DIR . 'includes/class-mos-shopier-webhook.php';

register_activation_hook(MOS_PLUGIN_FILE, ['MOS_DB', 'install']);

// Surum yukseldiginde (ör. 1.0.0 -> 2.0.0 Shopier eslemesi eklendi) dbDelta'yi
// tekrar calistirip yeni kolon/tablolari sessizce ekler - kullanicinin eklentiyi
// elle devre disi birakip yeniden etkinlestirmesine gerek kalmaz.
add_action('plugins_loaded', function () {
    if (get_option('mos_db_version') !== MOS_VERSION) {
        MOS_DB::install();
    }
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    MOS_Shopier_Client::init();
    MOS_Shopier_Webhook::init();
    MOS_Hooks::init();
    if (is_admin()) {
        MOS_Admin::init();
    }
});
