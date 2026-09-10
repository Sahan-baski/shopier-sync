<?php
/**
 * Shopier'in siparis oldugunda cagiracagi adres. Artik ayri bir Node.js servisi
 * yok - bu WordPress'in kendi REST API'si uzerinden (wp-json/mos/v1/shopier-order)
 * dogrudan bu eklenti tarafindan karsilaniyor.
 *
 * ONEMLI: Shopier'in dokumantasyonuna gore webhook'a 5 SANIYE icinde 200 OK
 * donmezsek bildirim basarisiz sayilip 1dk/10dk/1sa/... araliklarla toplam 9 kez
 * tekrar deneniyor. Bu yuzden: 1) yerel hesaplama (WooCommerce'e yazma dahil) HIZLI
 * calisir ve istek icinde tamamlanir, 2) Shopier'e GERI yazma (baska tasarimlarin
 * Shopier stogunun guncellenmesi) MOS_Engine tarafindan otomatik olarak arka plana
 * (WP-Cron) birakilir - bu yuzden bu dosyanin Shopier'e HICBIR sekilde dogrudan
 * yazmasina gerek yok, sadece MOS_Engine::apply_delta cagirmasi yeterli.
 *
 * Tekrar denemelere karsi IDEMPOTENCY: ayni (siparis, urun, varyasyon) uclusu
 * daha once islendiyse bir daha uygulanmaz (mos_shopier_processed tablosu).
 */

if (!defined('ABSPATH')) {
    exit;
}

class MOS_Shopier_Webhook
{
    public static function init()
    {
        add_action('rest_api_init', [__CLASS__, 'register_routes']);
    }

    public static function register_routes()
    {
        register_rest_route('mos/v1', '/shopier-order', [
            [
                'methods' => 'POST',
                'callback' => [__CLASS__, 'handle_post'],
                'permission_callback' => '__return_true',
            ],
            [
                // Shopier'in eski/legacy "OSB" sistemi bazen GET ile cagirabiliyor -
                // ne gelirse gelsin yakalayip loglayalim, en azindan 200 donelim.
                'methods' => 'GET',
                'callback' => [__CLASS__, 'handle_get'],
                'permission_callback' => '__return_true',
            ],
        ]);
    }

    public static function handle_get(WP_REST_Request $request)
    {
        self::log_event('GET', $request->get_params(), []);
        return new WP_REST_Response(['ok' => true], 200);
    }

    public static function handle_post(WP_REST_Request $request)
    {
        $raw_body = $request->get_body();
        $body = $request->get_json_params();
        if (!is_array($body)) {
            $body = $request->get_body_params();
        }
        $body = is_array($body) ? $body : [];

        $signature = $request->get_header('shopier-signature') ?: $request->get_header('x-shopier-signature');
        $signature_ok = MOS_Shopier_Client::verify_webhook_signature($raw_body, $signature);
        if (!$signature_ok) {
            error_log('[MOS] UYARI: Shopier webhook imzası doğrulanamadı (sipariş yine de işlenecek).');
        }

        $items = MOS_Shopier_Client::parse_incoming_order($body);
        $results = [];

        foreach ($items as $item) {
            $order_ref = (string) ($item['orderRef'] ?? '');
            $map = MOS_DB::find_map_by_shopier_ids($item['shopierProductId'], $item['shopierVariantId']);
            if (!$map) {
                $results[] = ['item' => $item, 'ok' => false, 'reason' => 'shopier ürün/varyasyon eşlemesi yok - Stok Tablosu\'ndan eşleştir'];
                continue;
            }

            if ($order_ref && MOS_DB::is_shopier_line_processed($order_ref, $item['shopierProductId'], $item['shopierVariantId'])) {
                $results[] = ['item' => $item, 'ok' => true, 'reason' => 'zaten işlenmişti (Shopier tekrar denemesi), atlandı'];
                continue;
            }

            try {
                $outcome = MOS_Engine::apply_delta(
                    $map->design_product_id,
                    $map->size_key,
                    -1 * abs((int) $item['qty']),
                    ['orderRef' => $order_ref, 'note' => 'Shopier siparişi']
                );
                if (!empty($outcome['skipped'])) {
                    $results[] = ['item' => $item, 'ok' => false, 'reason' => $outcome['reason']];
                    continue;
                }
                if ($order_ref) {
                    MOS_DB::mark_shopier_line_processed($order_ref, $item['shopierProductId'], $item['shopierVariantId'], $item['qty']);
                }
                $results[] = ['item' => $item, 'ok' => true];
            } catch (\Throwable $e) {
                $results[] = ['item' => $item, 'ok' => false, 'reason' => $e->getMessage()];
            }
        }

        self::log_event('POST', $body, $results);

        return new WP_REST_Response(['ok' => true, 'processed' => count($results), 'results' => $results], 200);
    }

    private static function log_event($method, $payload, $results)
    {
        error_log('[MOS] Shopier webhook (' . $method . '): ' . wp_json_encode($payload));
        update_option('mos_shopier_last_webhook', [
            'time' => current_time('mysql'),
            'method' => $method,
            'payload' => $payload,
            'results' => $results,
        ], false);
    }
}
