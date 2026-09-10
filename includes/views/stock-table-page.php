<?php
/** @var array $pools */
/** @var int $pool_id */
/** @var array $data */
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('mos_get_variation_options')) {
    function mos_get_variation_options($design_id)
    {
        $product = wc_get_product($design_id);
        if (!$product || !$product->is_type('variable')) {
            return [];
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
                if ($v === '') {
                    continue;
                }
                $taxonomy = str_replace('attribute_', '', $k);
                if (taxonomy_exists($taxonomy)) {
                    $term = get_term_by('slug', $v, $taxonomy);
                    $label[] = $term ? $term->name : $v;
                } else {
                    $label[] = $v;
                }
            }
            $out[$variation_id] = $label ? implode(' / ', $label) : ('#' . $variation_id);
        }
        return $out;
    }
}
?>
<div class="wrap mos-wrap">
    <h1>Stok Tablosu</h1>

    <p>
        <label for="mos_pool_select"><strong>Havuz:</strong></label>
        <select id="mos_pool_select" onchange="if(this.value) location.href='<?php echo esc_url(admin_url('admin.php?page=mos-stock-table&pool_id=')); ?>'+this.value;">
            <?php foreach ($pools as $p) : ?>
                <option value="<?php echo esc_attr($p->id); ?>" <?php selected($pool_id, $p->id); ?>><?php echo esc_html($p->name); ?></option>
            <?php endforeach; ?>
        </select>
        <?php if ($pool_id) : ?>
            <button type="button" class="button" id="mos_sync_btn" data-pool="<?php echo esc_attr($pool_id); ?>">☁️ Bu Havuzu WooCommerce + Shopier'e Yeniden Gönder</button>
            <button type="button" class="button" id="mos_shopier_fetch_sizes_btn">🔄 Shopier Bedenlerini Getir</button>
        <?php endif; ?>
    </p>
    <p class="description" id="mos_shopier_fetch_status"></p>

    <?php if (empty($pools)) : ?>
        <p>Önce bir havuz oluşturman gerekiyor. <a href="<?php echo esc_url(admin_url('admin.php?page=mos-pools')); ?>">Havuz oluştur →</a></p>
        <?php return; ?>
    <?php endif; ?>

    <?php if (!$data['pool']) : ?>
        <p>Havuz bulunamadı.</p>
        <?php return; ?>
    <?php endif; ?>

    <div class="mos-card">
        <h2>Tasarım Ekle</h2>
        <p class="description">Bu havuza yalnızca <strong>değişken (varyasyonlu)</strong> WooCommerce ürünleri eklenebilir. Bir ürün aynı anda en fazla bir havuza bağlı olabilir.</p>
        <input type="text" id="mos_product_search" placeholder="Ürün adı yaz..." style="width:320px" autocomplete="off" />
        <input type="hidden" id="mos_product_search_id" value="" />
        <button type="button" class="button button-primary" id="mos_add_design_btn" data-pool="<?php echo esc_attr($pool_id); ?>" disabled>Havuza Ekle</button>
        <ul id="mos_product_search_results" class="mos-search-results"></ul>
    </div>

    <table class="widefat striped mos-card mos-stock-table">
        <thead>
            <tr>
                <th>Tasarım</th>
                <th>Tasarım Stoğu</th>
                <th>Shopier Ürün ID</th>
                <?php foreach ($data['pool']['sizes'] as $s) : ?>
                    <th>
                        <?php echo esc_html($s['label']); ?><br />
                        <span class="description">ortak stok</span><br />
                        <input type="number" min="0" step="1" class="mos-shared-input" data-pool="<?php echo esc_attr($pool_id); ?>" data-size="<?php echo esc_attr($s['key']); ?>" value="<?php echo esc_attr($s['sharedStock']); ?>" style="width:70px" />
                    </th>
                <?php endforeach; ?>
                <th></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($data['table'])) : ?>
            <tr><td colspan="<?php echo 4 + count($data['pool']['sizes']); ?>">Bu havuza henüz tasarım eklenmedi.</td></tr>
        <?php endif; ?>
        <?php foreach ($data['table'] as $row) :
            $design = $row['design'];
            $variation_options = mos_get_variation_options($design['id']);
            ?>
            <tr>
                <td>
                    <a href="<?php echo esc_url($design['editUrl']); ?>" target="_blank"><strong><?php echo esc_html($design['name']); ?></strong></a>
                    <?php if ($design['sku']) : ?><br /><span class="description">SKU: <?php echo esc_html($design['sku']); ?></span><?php endif; ?>
                </td>
                <td>
                    <input type="number" min="0" step="1" class="mos-design-stock-input" data-design="<?php echo esc_attr($design['id']); ?>" value="<?php echo esc_attr($design['stock']); ?>" style="width:70px" />
                </td>
                <td>
                    <input type="text" class="mos-shopier-product-input" data-design="<?php echo esc_attr($design['id']); ?>" value="<?php echo esc_attr($design['shopierProductId']); ?>" placeholder="Shopier ürün ID" style="width:120px" />
                </td>
                <?php foreach ($row['cells'] as $cell) : ?>
                    <td class="mos-cell">
                        <select class="mos-map-select" data-pool="<?php echo esc_attr($pool_id); ?>" data-design="<?php echo esc_attr($design['id']); ?>" data-size="<?php echo esc_attr($cell['size']); ?>">
                            <option value="0">— Eşlenmemiş —</option>
                            <?php foreach ($variation_options as $vid => $vlabel) : ?>
                                <option value="<?php echo esc_attr($vid); ?>" <?php selected($cell['variationId'], $vid); ?>><?php echo esc_html($vlabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="mos-eff-stock <?php echo $cell['effectiveStock'] <= 0 ? 'mos-eff-zero' : ''; ?>">
                            Görünen stok: <strong><?php echo (int) $cell['effectiveStock']; ?></strong>
                        </div>
                        <select class="mos-shopier-select" data-pool="<?php echo esc_attr($pool_id); ?>" data-design="<?php echo esc_attr($design['id']); ?>" data-size="<?php echo esc_attr($cell['size']); ?>" data-current="<?php echo esc_attr($cell['shopierVariantId']); ?>">
                            <option value="">— Shopier: Eşlenmemiş —</option>
                            <?php if ($cell['shopierVariantId']) : ?>
                                <option value="<?php echo esc_attr($cell['shopierVariantId']); ?>" selected>Mevcut: <?php echo esc_html($cell['shopierVariantId']); ?></option>
                            <?php endif; ?>
                        </select>
                    </td>
                <?php endforeach; ?>
                <td>
                    <button type="button" class="button button-small button-link-delete mos-remove-design-btn" data-design="<?php echo esc_attr($design['id']); ?>">Havuzdan Çıkar</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <p class="description">Görünen stok = min(o bedenin ortak stoğu, tasarımın kendi stoğu). Bu değer otomatik hesaplanır; ilgili WooCommerce varyasyonuna hemen, eşlenmiş Shopier varyasyonuna da (varsa) arka planda otomatik yazılır — hangi kanaldan satış gelirse gelsin, her iki yerde de aynı stok görünür.</p>
    <p class="description">Shopier eşlemesi: önce tasarımın "Shopier Ürün ID"sini gir, sonra üstteki "🔄 Shopier Bedenlerini Getir" ile hesabındaki tüm beden seçeneklerini çek — her hücrenin altındaki Shopier açılır listesinden doğru bedeni seç.</p>
</div>
