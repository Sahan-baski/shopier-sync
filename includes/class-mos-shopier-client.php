<?php
/**
 * Shopier ile konusan TEK yer (eski Node.js servisindeki shopierClient.js'in
 * birebir PHP karsiligi - ayni API davranisi, ayni yorumlar/bulgular gecerli).
 *
 * NETLESEN Shopier API bilgileri (developer.shopier.com):
 *   - Taban adres: https://api.shopier.com/v1/
 *   - Kimlik dogrulama: Authorization: Bearer <Kisisel Erisim Anahtari>
 *   - GET /webhooks, POST /webhooks, DELETE /webhooks/{id} -> webhook aboneligi yonetimi
 *   - GET /products/{id} -> urunun TUM varyasyonlarini (selectionId, selectionTitle,
 *     stockQuantity) doner.
 *   - PUT /products/{id} -> govdede "variants" gonderilirse TUM varyasyon listesini
 *     degistirir - bu yuzden stok guncellerken ONCE GET ile mevcut TUM varyasyonlari
 *     cekip SADECE hedeflerin stockQuantity'sini degistirip TUM listeyi geri PUT ediyoruz.
 *   - "Beden" (selection) ID'leri HESAP GENELINDE ORTAK/GLOBAL - urune ozel degil. Yani
 *     tum tasarimlar icin TEK SEFERDE /selections + /variations cekilip kullanilabilir.
 *   - Bilinen kisit (06.09.2026 itibariyla): bu hesapta GET/PUT /products/{id} 403
 *     Forbidden donuyor, Shopier destekten yanit beklemede. /selections, /variations,
 *     /orders, /webhooks sorunsuz calisiyor. Stok YAZMA (PUT) izni acilana kadar
 *     DRY_RUN acik tutulmali.
 *
 * Ayarlar (Access Key, Webhook Secret, API Base, Dry Run) artik WordPress'te
 * (wp_options -> mos_shopier_settings) tutuluyor - MOS_DB::get_shopier_settings().
 */

if (!defined('ABSPATH')) {
    exit;
}

class MOS_Shopier_Client
{
    public static function init()
    {
        add_action('mos_shopier_push_product', [__CLASS__, 'handle_cron_push'], 10, 2);
    }

    private static function settings()
    {
        return MOS_DB::get_shopier_settings();
    }

    private static function is_dry_run()
    {
        return !empty(self::settings()['dry_run']);
    }

    private static function auth_headers()
    {
        $s = self::settings();
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $s['access_key'],
        ];
    }

    private static function api_base()
    {
        return self::settings()['api_base'];
    }

    /**
     * Bir urunun Shopier'daki GUNCEL halini (tum varyasyonlariyla) ceker.
     * Sadece okuma - DRY_RUN'dan bagimsiz her zaman calisir.
     * Donen: ['raw' => <ham obje>, 'variants' => [['selectionId'=>, 'selectionTitle'=>, 'stockQuantity'=>], ...]]
     */
    public static function fetch_product($shopier_product_id)
    {
        $s = self::settings();
        if (empty($s['access_key'])) {
            return new WP_Error('mos_shopier_no_key', 'Shopier Erişim Anahtarı ayarlanmamış (Shopier Bağlantısı sayfasından gir).');
        }
        if (!$shopier_product_id) {
            return new WP_Error('mos_shopier_no_product', 'shopier_product_id gerekli.');
        }

        $url = self::api_base() . '/products/' . rawurlencode($shopier_product_id);
        $res = wp_remote_get($url, ['headers' => self::auth_headers(), 'timeout' => 15]);
        if (is_wp_error($res)) {
            return $res;
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('mos_shopier_http', "Shopier ürün okuma hatası ($code): " . wp_remote_retrieve_body($res));
        }
        $raw = json_decode(wp_remote_retrieve_body($res), true);
        $variants = [];
        foreach (($raw['variants'] ?? []) as $v) {
            $variants[] = [
                'selectionId' => is_array($v['selectionId'] ?? null) ? ($v['selectionId'][0] ?? '') : ($v['selectionId'] ?? ''),
                'selectionTitle' => is_array($v['selectionTitle'] ?? null) ? ($v['selectionTitle'][0] ?? '') : ($v['selectionTitle'] ?? ''),
                'stockQuantity' => $v['stockQuantity'] ?? 0,
            ];
        }
        return ['raw' => $raw, 'variants' => $variants];
    }

    private static function fetch_paginated($path)
    {
        $s = self::settings();
        if (empty($s['access_key'])) {
            return new WP_Error('mos_shopier_no_key', 'Shopier Erişim Anahtarı ayarlanmamış (Shopier Bağlantısı sayfasından gir).');
        }
        $all = [];
        $page = 1;
        for (;;) {
            $url = self::api_base() . $path . (strpos($path, '?') === false ? '?' : '&') . 'page=' . $page . '&limit=50';
            $res = wp_remote_get($url, ['headers' => self::auth_headers(), 'timeout' => 15]);
            if (is_wp_error($res)) {
                return $res;
            }
            $code = wp_remote_retrieve_response_code($res);
            if ($code < 200 || $code >= 300) {
                return new WP_Error('mos_shopier_http', "Shopier $path hatası ($code): " . wp_remote_retrieve_body($res));
            }
            $data = json_decode(wp_remote_retrieve_body($res), true);
            if (!is_array($data)) {
                break;
            }
            $all = array_merge($all, $data);
            $total_pages = (int) wp_remote_retrieve_header($res, 'shopier-pagination-total-pages') ?: 1;
            if (!count($data) || $page >= $total_pages) {
                break;
            }
            $page++;
        }
        return $all;
    }

    public static function fetch_all_selections()
    {
        return self::fetch_paginated('/selections');
    }

    public static function fetch_all_variations()
    {
        return self::fetch_paginated('/variations');
    }

    /**
     * "Beden" iceren TUM varyasyon boyutlarinin (or. "Cocuk Tisort Beden") altindaki
     * secenekleri (3-4 Yas, 5-6 Yas, ...) tek listede doner - HESAP GENELINDE ortak,
     * urun ID'sine ihtiyac YOK. Her eleman: ['selectionId'=>, 'selectionTitle'=>,
     * 'variationId'=>, 'variationTitle'=>].
     */
    public static function fetch_child_size_selections()
    {
        $selections = self::fetch_all_selections();
        if (is_wp_error($selections)) {
            return $selections;
        }
        $variations = self::fetch_all_variations();
        if (is_wp_error($variations)) {
            return $variations;
        }

        $size_variation_ids = [];
        $title_by_id = [];
        foreach ($variations as $v) {
            if (preg_match('/beden/i', $v['title'] ?? '')) {
                $size_variation_ids[$v['id']] = true;
                $title_by_id[$v['id']] = $v['title'];
            }
        }

        $out = [];
        foreach ($selections as $s) {
            if (isset($size_variation_ids[$s['variationId']])) {
                $out[] = [
                    'selectionId' => $s['id'],
                    'selectionTitle' => $s['title'],
                    'variationId' => $s['variationId'],
                    'variationTitle' => $title_by_id[$s['variationId']] ?? '',
                ];
            }
        }
        return $out;
    }

    /**
     * Bir Shopier urununun BIRDEN FAZLA varyasyonunun stogunu TEK GET + TEK PUT ile
     * gunceller (her hucre icin ayri ayri cagirmak yerine - hem daha hizli, hem de
     * ayni urune ayni anda gelen iki ayri istegin birbirinin degisikligini EZMESINI
     * onler). $updates: [['shopier_variant_id'=>'...', 'new_stock'=>N], ...]
     */
    public static function push_product_variant_stocks($shopier_product_id, $updates)
    {
        if (!$shopier_product_id) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'shopier_product_id yok'];
        }
        if (empty($updates)) {
            return ['ok' => false, 'skipped' => true, 'reason' => 'guncellenecek varyasyon yok'];
        }

        if (self::is_dry_run()) {
            foreach ($updates as $u) {
                error_log(sprintf(
                    '[MOS][DRY_RUN] Shopier ürün %s / varyasyon %s stoğu %d olarak güncellenecekti.',
                    $shopier_product_id,
                    $u['shopier_variant_id'],
                    $u['new_stock']
                ));
            }
            return ['ok' => true, 'dryRun' => true];
        }

        $s = self::settings();
        if (empty($s['access_key'])) {
            return ['ok' => false, 'error' => 'Shopier Erişim Anahtarı ayarlanmamış.'];
        }

        $fetched = self::fetch_product($shopier_product_id);
        if (is_wp_error($fetched)) {
            return ['ok' => false, 'error' => $fetched->get_error_message()];
        }

        $target_by_id = [];
        foreach ($updates as $u) {
            $target_by_id[(string) $u['shopier_variant_id']] = max(0, (int) $u['new_stock']);
        }

        $found_any = false;
        $updated_variants = [];
        foreach ($fetched['variants'] as $v) {
            $sid = (string) $v['selectionId'];
            if (isset($target_by_id[$sid])) {
                $found_any = true;
            }
            $updated_variants[] = [
                'selectionId' => [$v['selectionId']],
                'stockQuantity' => isset($target_by_id[$sid]) ? $target_by_id[$sid] : $v['stockQuantity'],
            ];
        }

        if (!$found_any) {
            return ['ok' => false, 'error' => "Shopier ürün $shopier_product_id içinde hedef varyasyon(lar) bulunamadı - eşleştirme yanlış olabilir."];
        }

        $url = self::api_base() . '/products/' . rawurlencode($shopier_product_id);
        $res = wp_remote_request($url, [
            'method' => 'PUT',
            'headers' => self::auth_headers(),
            'timeout' => 20,
            'body' => wp_json_encode(['variants' => $updated_variants]),
        ]);
        if (is_wp_error($res)) {
            return ['ok' => false, 'error' => $res->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => "Shopier stok güncelleme hatası ($code): " . wp_remote_retrieve_body($res)];
        }
        return ['ok' => true, 'dryRun' => false];
    }

    /**
     * WP-Cron kancasi: MOS_Engine, degisen (tasarim, beden) hucrelerini urun bazinda
     * toparlayip wp_schedule_single_event('mos_shopier_push_product', [$shopier_product_id,
     * $cells]) ile buraya birakir - boylece musterinin odeme ekrani Shopier'i beklemez.
     *
     * Stok degeri burada, cron GERCEKTEN calisirken YENIDEN hesaplanir (tamponlanan
     * eski bir deger kullanilmaz) - kisa surede arka arkaya gelen satislarda Shopier'e
     * her zaman o anki guncel stogun gitmesini garantiler.
     */
    public static function handle_cron_push($shopier_product_id, $cells)
    {
        $updates = [];
        foreach ((array) $cells as $cell) {
            $eff = MOS_Engine::effective_stock($cell['design_product_id'], $cell['size_key']);
            if ($eff === null) {
                continue;
            }
            $updates[] = ['shopier_variant_id' => $cell['shopier_variant_id'], 'new_stock' => $eff];
        }
        if (empty($updates)) {
            return;
        }
        $result = self::push_product_variant_stocks($shopier_product_id, $updates);
        if (empty($result['ok'])) {
            error_log('[MOS] Shopier push hatası (ürün ' . $shopier_product_id . '): ' . ($result['error'] ?? $result['reason'] ?? 'bilinmeyen hata'));
        }
    }

    /**
     * Shopier'den gelen siparis webhook govdesini
     * [['shopierProductId'=>, 'shopierVariantId'=>, 'qty'=>, 'orderRef'=>], ...] listesine cevirir.
     * Gercek "order.created" webhook govdesi: { id, lineItems: [ { productId, quantity,
     * selection: [ { id, title, variationTitle } ] } ] }
     */
    public static function parse_incoming_order($body)
    {
        $order_ref = $body['id'] ?? ($body['order_id'] ?? ($body['orderId'] ?? null));
        $line_items = $body['lineItems'] ?? ($body['line_items'] ?? ($body['items'] ?? []));

        $results = [];
        foreach ((array) $line_items as $item) {
            $product_id = (string) ($item['productId'] ?? ($item['product_id'] ?? ''));
            $qty = (int) ($item['quantity'] ?? ($item['qty'] ?? 1));
            $selections = $item['selection'] ?? ($item['selections'] ?? []);
            if (empty($selections)) {
                continue; // bedeni olmayan/varyasyonsuz urun - bu sistemde yonetilmiyor
            }
            $variant_ids = array_map(function ($s) {
                return (string) ($s['id'] ?? '');
            }, $selections);
            $results[] = [
                'shopierProductId' => $product_id,
                'shopierVariantId' => implode('|', $variant_ids),
                'qty' => $qty,
                'orderRef' => $order_ref,
            ];
        }
        return $results;
    }

    /**
     * Webhook'un gercekten Shopier'den geldigini dogrular. Shopier dokumantasyonuna
     * gore: govde, webhook aboneligi olusturulurken donen "token" ile HS256 (HMAC-SHA256)
     * imzalanir, sonuc "Shopier-Signature" header'inda gelir. Hex mi base64 mu oldugu
     * net degil - ikisini de deniyoruz. Secret bos ise (henuz ayarlanmadi) dogrulamayi
     * atlayip true donuyoruz - webhook.js'teki temkinli davranisin aynisi.
     */
    public static function verify_webhook_signature($raw_body, $signature_header)
    {
        $secret = self::settings()['webhook_secret'];
        if (!$secret) {
            return true;
        }
        if (!$signature_header) {
            return false;
        }
        $computed_hex = hash_hmac('sha256', $raw_body, $secret);
        $computed_base64 = base64_encode(hash_hmac('sha256', $raw_body, $secret, true));
        $provided = trim($signature_header);
        return hash_equals($computed_hex, $provided) || hash_equals($computed_base64, $provided);
    }

    /**
     * Shopier'de bu sitenin webhook adresine bir "order.created" aboneligi olusturur.
     * Donen token SADECE bu cevapta bir kez gelir - admin ekrani bunu otomatik olarak
     * webhook_secret ayarina kaydeder.
     */
    public static function create_webhook_subscription($event = 'order.created')
    {
        $s = self::settings();
        if (empty($s['access_key'])) {
            return new WP_Error('mos_shopier_no_key', 'Önce Shopier Erişim Anahtarı gir.');
        }
        $url = self::api_base() . '/webhooks';
        $res = wp_remote_post($url, [
            'headers' => self::auth_headers(),
            'timeout' => 15,
            'body' => wp_json_encode([
                'event' => $event,
                'url' => rest_url('mos/v1/shopier-order'),
            ]),
        ]);
        if (is_wp_error($res)) {
            return $res;
        }
        $code = wp_remote_retrieve_response_code($res);
        if ($code < 200 || $code >= 300) {
            return new WP_Error('mos_shopier_http', "Webhook aboneliği oluşturulamadı ($code): " . wp_remote_retrieve_body($res));
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        return $data;
    }
}
