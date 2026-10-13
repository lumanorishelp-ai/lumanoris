<?php
/**
 * Pazaryeri başvurusu — kullanıcı uçları (madde 3, Faz 5).
 *
 *   POST /api/seller/application_submit.php
 *   GET  /api/seller/application_status.php
 *
 * İş kuralları functions/marketplace_application.php'de (GK-19…GK-26).
 * Başvuru satıcıyı `active` YAPMAZ — o B1'e bağlı; arayüz bunu dürüstçe
 * "Başvurunuz alındı / inceleniyor" diye gösterir.
 */
class MarketplaceApplicationController {
    private static function load(): Database {
        require_once __DIR__ . '/../../../functions/marketplace_application.php';
        $db = Database::getInstance();
        if (!marketplaceApplicationsReady($db)) {
            error_log('[marketplace_application] marketplace_applications yok — migration 016 uygulanmamış.');
            JsonResponse::error(
                'Pazaryeri başvurusu şu an alınamıyor. Lütfen daha sonra tekrar deneyin.',
                503,
                AppConfig::ERR_UNAVAILABLE
            );
        }
        return $db;
    }

    public static function submit(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $db     = self::load();
        checkRateLimit($db, 'mapp_submit:' . $userId, 5, 3600);

        $data = json_decode($_POST['data'] ?? '', true);
        if (!is_array($data)) {
            JsonResponse::error('Eksik veri.', 400, AppConfig::ERR_VALIDATION);
        }

        try {
            $clean  = validateMarketplaceApplication($data);
            $result = submitMarketplaceApplication($db, $userId, $clean);
        } catch (AppException $e) {
            // Doğrulama hataları alan bazında döner; değerler log'a YAZILMAZ.
            JsonResponse::fromException($e);
        }

        JsonResponse::success([
            'message'     => 'Başvurunuz alındı. Ekibimiz inceledikten sonra sonucu bu sayfada ve bildirimlerinizde göreceksiniz.',
            'application' => $result,
        ]);
    }

    public static function status(): void {
        $userId = AuthMiddleware::requireAuth();
        $db     = self::load();
        $app    = getMarketplaceApplication($db, $userId);

        JsonResponse::success([
            // GK-22 — arayüzdeki tüm "kaydı var mı" kararları bu alanı okur.
            'registered'  => hasMarketplaceRegistration($db, $userId),
            // Doğum tarihi, tam IBAN ve vergi no DÖNMEZ (GK-26 yalnızca admin).
            'application' => $app ? [
                'status'        => $app['status'],
                'account_type'  => $app['account_type'],
                'company_title' => $app['company_title'],
                'iban_masked'   => maskIban($app['iban']),
                'submitted_at'  => marketplaceApplicationSubmittedAt($app),
                'reviewed_at'   => $app['reviewed_at'],
                'review_note'   => $app['status'] === 'rejected' ? $app['review_note'] : null,
            ] : null,
        ]);
    }
}
