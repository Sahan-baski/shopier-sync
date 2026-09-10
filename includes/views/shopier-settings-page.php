<?php
/** @var array $settings */
/** @var string $webhook_url */
/** @var array|false $last_webhook */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap mos-wrap">
    <h1>Shopier Bağlantısı</h1>
    <p class="description">
        Bu sayfa, Şahan Baskı/Miras Çocuk'un WooCommerce sitesindeki ortak stok havuzunu
        Shopier mağazanla da eşleştirir. Bir tasarımın bir bedeni <strong>ya sitede ya da
        Shopier'de</strong> satıldığında, bu havuza bağlı tüm tasarımların o bedeni
        <strong>her iki yerde de</strong> otomatik güncellenir.
    </p>

    <?php if (isset($_GET['mos_notice'])) : ?>
        <div class="notice notice-<?php echo esc_attr($_GET['mos_notice']); ?> is-dismissible">
            <p><?php echo esc_html(rawurldecode($_GET['mos_msg'] ?? '')); ?></p>
        </div>
    <?php endif; ?>

    <div class="mos-columns">
        <div class="mos-col">
            <h2>Ayarlar</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mos-card">
                <?php wp_nonce_field(MOS_Admin::NONCE); ?>
                <input type="hidden" name="action" value="mos_save_shopier_settings" />
                <p>
                    <label><strong>Shopier Kişisel Erişim Anahtarı</strong></label><br />
                    <input type="text" name="access_key" style="width:100%" value="<?php echo esc_attr($settings['access_key']); ?>" placeholder="Shopier panelinden: Hesap Yönetimi &gt; Kişisel Erişim Anahtarı" />
                </p>
                <p>
                    <label><strong>Webhook Secret (token)</strong></label><br />
                    <input type="text" name="webhook_secret" style="width:100%" value="<?php echo esc_attr($settings['webhook_secret']); ?>" placeholder="Aşağıdaki 'Webhook Aboneliği Oluştur' butonuyla otomatik doldurulur" />
                </p>
                <p>
                    <label><strong>API Adresi</strong></label><br />
                    <input type="text" name="api_base" style="width:100%" value="<?php echo esc_attr($settings['api_base']); ?>" />
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="dry_run" <?php checked($settings['dry_run']); ?> />
                        <strong>DRY RUN</strong> (işaretliyken Shopier'e hiçbir yazma isteği atılmaz, sadece ne gönderileceği loglanır - günlükleri PHP error log'unda görebilirsin)
                    </label>
                </p>
                <p>
                    <button type="submit" class="button button-primary">Kaydet</button>
                </p>
            </form>
        </div>

        <div class="mos-col">
            <h2>Webhook Kurulumu</h2>
            <div class="mos-card">
                <p>Shopier'de sipariş olduğunda bu adrese bildirim gelecek:</p>
                <p><code style="user-select:all"><?php echo esc_html($webhook_url); ?></code></p>
                <p class="description">Erişim Anahtarı'nı kaydettikten sonra aşağıdaki butonla Shopier'de bu adrese otomatik bir "sipariş oluştu" aboneliği kurabilirsin - dönen token yukarıdaki "Webhook Secret" alanına otomatik yazılır.</p>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                    <?php wp_nonce_field(MOS_Admin::NONCE); ?>
                    <input type="hidden" name="action" value="mos_create_shopier_webhook" />
                    <button type="submit" class="button">🔗 Webhook Aboneliği Oluştur</button>
                </form>
            </div>

            <h2>Tasarım ↔ Shopier Eşlemesi</h2>
            <div class="mos-card">
                <p>Her tasarımın Shopier Ürün ID'sini ve bedenlerin Shopier karşılığını <a href="<?php echo esc_url(admin_url('admin.php?page=mos-stock-table')); ?>">Stok Tablosu</a> ekranından, her hücrenin altındaki Shopier alanlarıyla eşleştiriyorsun.</p>
            </div>

            <?php if ($last_webhook) : ?>
                <h2>Son Gelen Sipariş Bildirimi</h2>
                <div class="mos-card">
                    <p><strong><?php echo esc_html($last_webhook['time']); ?></strong> (<?php echo esc_html($last_webhook['method']); ?>)</p>
                    <pre style="white-space:pre-wrap;max-height:240px;overflow:auto;background:#f6f7f7;padding:10px;"><?php echo esc_html(wp_json_encode($last_webhook['results'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>
