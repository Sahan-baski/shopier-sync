<?php
/** @var array $pools */
/** @var object|null $edit_pool */
/** @var array $edit_sizes */
if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="wrap mos-wrap">
    <h1>Ortak Stok Havuzları</h1>
    <p class="description">Aynı bedeni paylaşan tasarımları burada gruplarsın. Bir havuzdaki bedenlerden biri satıldığında, o havuza bağlı TÜM tasarımların o bedeni otomatik düşer.</p>

    <?php if (isset($_GET['mos_notice'])) : ?>
        <div class="notice notice-<?php echo esc_attr($_GET['mos_notice']); ?> is-dismissible">
            <p><?php echo esc_html(rawurldecode($_GET['mos_msg'] ?? '')); ?></p>
        </div>
    <?php endif; ?>

    <div class="mos-columns">
        <div class="mos-col">
            <h2><?php echo $edit_pool ? 'Havuzu Düzenle: ' . esc_html($edit_pool->name) : 'Yeni Havuz'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="mos-card">
                <?php wp_nonce_field(MOS_Admin::NONCE); ?>
                <input type="hidden" name="action" value="<?php echo $edit_pool ? 'mos_update_pool' : 'mos_create_pool'; ?>" />
                <?php if ($edit_pool) : ?>
                    <input type="hidden" name="pool_id" value="<?php echo esc_attr($edit_pool->id); ?>" />
                <?php endif; ?>
                <p>
                    <label><strong>Havuz adı</strong></label><br />
                    <input type="text" name="name" required style="width:100%" value="<?php echo $edit_pool ? esc_attr($edit_pool->name) : ''; ?>" placeholder="Örn. Çocuk Tişört Bedenleri" />
                </p>
                <p>
                    <label><strong>Bedenler</strong> (virgülle ayır)</label><br />
                    <input type="text" name="sizes" required style="width:100%" value="<?php echo esc_attr(implode(', ', wp_list_pluck($edit_sizes, 'size_label'))); ?>" placeholder="Örn. 3-4 Yaş, 5-6 Yaş, 7-8 Yaş" />
                    <span class="description">Var olan bir havuzu düzenlerken, listeden çıkardığın bedenlerin stok/eşleme kaydı silinir; kalan bedenlerin mevcut stoğu korunur.</span>
                </p>
                <p>
                    <button type="submit" class="button button-primary"><?php echo $edit_pool ? 'Güncelle' : 'Havuzu Oluştur'; ?></button>
                    <?php if ($edit_pool) : ?>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=mos-pools')); ?>" class="button">Vazgeç</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <div class="mos-col">
            <h2>Mevcut Havuzlar</h2>
            <?php if (empty($pools)) : ?>
                <p>Henüz havuz yok.</p>
            <?php else : ?>
                <table class="widefat striped mos-card">
                    <thead>
                        <tr><th>Ad</th><th>Bedenler</th><th></th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($pools as $p) :
                        $sizes = MOS_DB::get_pool_sizes($p->id);
                        $design_count = count(MOS_DB::get_designs_for_pool($p->id));
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($p->name); ?></strong><br /><span class="description"><?php echo (int) $design_count; ?> tasarım bağlı</span></td>
                            <td><?php echo esc_html(implode(', ', wp_list_pluck($sizes, 'size_label'))); ?></td>
                            <td>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=mos-stock-table&pool_id=' . $p->id)); ?>">Stok Tablosu</a>
                                <a class="button button-small" href="<?php echo esc_url(admin_url('admin.php?page=mos-pools&edit=' . $p->id)); ?>">Düzenle</a>
                                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline" onsubmit="return confirm('Bu havuzu silmek istediğine emin misin? Bağlı tasarımların havuz bağlantısı kaldırılır (tasarımlar silinmez).');">
                                    <?php wp_nonce_field(MOS_Admin::NONCE); ?>
                                    <input type="hidden" name="action" value="mos_delete_pool" />
                                    <input type="hidden" name="pool_id" value="<?php echo esc_attr($p->id); ?>" />
                                    <button type="submit" class="button button-small button-link-delete">Sil</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>
