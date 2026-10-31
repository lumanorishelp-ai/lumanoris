<?php
/**
 * Pazaryeri başvurusu (madde 3, Faz 5) — doğrulama, kayıt ve "kaydı var mı".
 *
 * Kararlar: GK-7 (alanlar), GK-8 (şahıs/kurumsal), GK-19…GK-26.
 * Tablo: `marketplace_applications` (migration 016) — kullanıcı başına tek
 * satır, durum submitted | reviewed | rejected. Bu dosya satıcıyı ASLA
 * `active` yapmaz; satıcı aktifliği param_marketplace_sellers'ta ve B1'e bağlı.
 *
 * Kişisel veri (IBAN, doğum tarihi, vergi no) burada hiçbir zaman log'a
 * yazılmaz; doğrulama hataları yalnızca ALAN ADI ve sebep taşır.
 */

require_once __DIR__ . '/db.php';

const MARKETPLACE_APPLICATIONS_TABLE = 'marketplace_applications';

/** İstemcinin gönderebileceği alanlar (beyaz liste). */
const MARKETPLACE_APPLICATION_FIELDS = ['full_name', 'phone', 'email', 'description'];

const MARKETPLACE_APPLICATION_NAME_MAX        = 150;
const MARKETPLACE_APPLICATION_EMAIL_MAX       = 190;
const MARKETPLACE_APPLICATION_DESCRIPTION_MIN = 20;
const MARKETPLACE_APPLICATION_DESCRIPTION_MAX = 2000;

function normalizeApplicationPhone(string $raw): ?string {
    $raw = trim($raw);
    if ($raw === '') return null;
    $plus   = str_starts_with($raw, '+') || str_starts_with($raw, '00');
    $digits = preg_replace('/\D/', '', $raw) ?? '';
    if (str_starts_with($raw, '00')) $digits = substr($digits, 2);

    if ($plus && str_starts_with($digits, '90')) $digits = substr($digits, 2);
    elseif (!$plus && strlen($digits) === 12 && str_starts_with($digits, '90')) $digits = substr($digits, 2);
    elseif (!$plus && strlen($digits) === 11 && str_starts_with($digits, '0')) $digits = substr($digits, 1);
    elseif ($plus) return (strlen($digits) >= 8 && strlen($digits) <= 15) ? '+' . $digits : null;

    return preg_match('/^[2-5]\d{9}$/', $digits) ? '+90' . $digits : null;
}

/**
 * Ham girdiyi doğrular ve yazılmaya hazır temiz diziyi döndürür.
 *
 * @throws ValidationException  `errors` alan adı → mesaj; bilinmeyen alanlar `_fields`
 */
function validateMarketplaceApplication(array $input): array {
    [$data, $rejected] = InputSanitizer::pickAllowed($input, MARKETPLACE_APPLICATION_FIELDS);
    if ($rejected !== []) {
        throw new ValidationException(
            'Bu alanlar gönderilemez: ' . implode(', ', $rejected),
            ['_fields' => 'Bu alanlar gönderilemez: ' . implode(', ', $rejected)]
        );
    }

    $errors = [];
    $clean  = [];

    $name = preg_replace('/\s+/u', ' ', trim(InputSanitizer::text($data['full_name'] ?? '', 500))) ?? '';
    if ($name === '') {
        $errors['full_name'] = 'Adınızı ve soyadınızı girin.';
    } elseif (mb_strlen($name) > MARKETPLACE_APPLICATION_NAME_MAX) {
        $errors['full_name'] = 'En fazla ' . MARKETPLACE_APPLICATION_NAME_MAX . ' karakter olabilir.';
    } elseif (count(explode(' ', $name)) < 2 || !preg_match("/^[\\p{L}\\p{M}'’. -]+$/u", $name)) {
        $errors['full_name'] = 'Adınızı ve soyadınızı birlikte, yalnızca harf kullanarak yazın.';
    }
    $clean['full_name'] = $name;

    $phone = normalizeApplicationPhone((string) ($data['phone'] ?? ''));
    if (trim((string) ($data['phone'] ?? '')) === '') {
        $errors['phone'] = 'Telefon numaranızı girin.';
    } elseif ($phone === null) {
        $errors['phone'] = 'Geçerli bir telefon numarası girin (örn. 0532 123 45 67).';
    }
    $clean['phone'] = $phone;

    $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
    if ($email === '') {
        $errors['email'] = 'E-posta adresinizi girin.';
    } elseif (mb_strlen($email) > MARKETPLACE_APPLICATION_EMAIL_MAX || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $errors['email'] = 'Geçerli bir e-posta adresi girin.';
    }
    $clean['email'] = $email;

    $desc = trim(InputSanitizer::text($data['description'] ?? '', MARKETPLACE_APPLICATION_DESCRIPTION_MAX + 1000));
    $len  = mb_strlen($desc);
    if ($desc === '') {
        $errors['description'] = 'Kendinizi ve pazaryerinde ne sunmak istediğinizi kısaca anlatın.';
    } elseif ($len < MARKETPLACE_APPLICATION_DESCRIPTION_MIN) {
        $errors['description'] = 'Açıklama en az ' . MARKETPLACE_APPLICATION_DESCRIPTION_MIN . ' karakter olmalıdır.';
    } elseif ($len > MARKETPLACE_APPLICATION_DESCRIPTION_MAX) {
        $errors['description'] = 'Açıklama en fazla ' . MARKETPLACE_APPLICATION_DESCRIPTION_MAX . ' karakter olabilir.';
    }
    $clean['description'] = $desc;

    if ($errors !== []) {
        throw new ValidationException('Başvuru formunda hatalı alanlar var.', $errors);
    }
    return $clean;
}

/** Tablo var mı? (migration 016 uygulanmamış kurulumda uçlar 503 verir.) */
function marketplaceApplicationsReady(Database $db): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        $row = $db->selectSingle(
            'COUNT(*) AS cnt FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [MARKETPLACE_APPLICATIONS_TABLE]
        );
        return $ready = ((int) ($row['cnt'] ?? 0) === 1);
    } catch (Throwable $e) {
        error_log('[marketplace_application] tablo kontrolü başarısız: ' . $e->getMessage());
        return $ready = false;
    }
}

function getMarketplaceApplication(Database $db, int $userId): ?array {
    if (!marketplaceApplicationsReady($db)) return null;
    return $db->selectSingle('* FROM ' . MARKETPLACE_APPLICATIONS_TABLE . ' WHERE user_id = ?', [$userId]) ?: null;
}

/**
 * Başvuruyu kaydeder. GK-20: tek satır, yeniden gönderim üzerine yazar ve
 * durumu `submitted`'a, inceleme alanlarını boşa çeker. GK-21: `reviewed`
 * başvuru değiştirilemez.
 *
 * @return array{status:string, submitted_at:string}
 * @throws DuplicateException  (409) başvuru incelenmişse
 */
function submitMarketplaceApplication(Database $db, int $userId, array $clean): array {
    $existing = getMarketplaceApplication($db, $userId);
    if ($existing && $existing['status'] === 'reviewed') {
        throw new DuplicateException(
            'Başvurunuz onaylandı; bilgilerinizi değiştirmek için destek ekibiyle iletişime geçin.'
        );
    }

    $columns = array_keys($clean);
    $sets    = implode(', ', array_map(fn($c) => "`$c` = VALUES(`$c`)", $columns));
    $db->execute(
        'INSERT INTO ' . MARKETPLACE_APPLICATIONS_TABLE . ' (`user_id`, `' . implode('`, `', $columns) . '`, `status`)
         VALUES (?' . str_repeat(', ?', count($columns)) . ", 'submitted')
         ON DUPLICATE KEY UPDATE $sets,
             `status` = 'submitted', `review_note` = NULL, `created_at` = NOW(),
             `reviewed_by_admin_id` = NULL, `reviewed_at` = NULL",
        array_merge([$userId], array_values($clean))
    );

    $row = getMarketplaceApplication($db, $userId);
    return ['status' => (string) $row['status'], 'submitted_at' => marketplaceApplicationSubmittedAt($row)];
}

/**
 * N-29 — başvurunun gönderim zamanı. `updated_at` DEĞİL: o sütun ON UPDATE
 * CURRENT_TIMESTAMP, yani admin incelemesi de onu değiştiriyor. Tek satırlık
 * modelde (GK-20) `created_at` son gönderimin zamanıdır: yeniden gönderim
 * onu NOW() yapar, inceleme dokunmaz.
 */
function marketplaceApplicationSubmittedAt(array $row): string {
    return (string) ($row['created_at'] ?? '');
}

/**
 * GK-22 — "kaydı var" TEK kaynağı: yeni tabloda submitted/reviewed VEYA eski
 * param_marketplace_sellers'ta active/suspended. Eski `pending` / `rejected`
 * (B1 kaynaklı otomatik ret) ve yeni `rejected` sayılmaz.
 */
function hasMarketplaceRegistration(Database $db, int $userId): bool {
    if ($userId <= 0) return false;

    $app = getMarketplaceApplication($db, $userId);
    if ($app && in_array($app['status'], ['submitted', 'reviewed'], true)) {
        return true;
    }
    try {
        $legacy = $db->selectSingle('status FROM ' . AppConfig::TABLE_SELLERS . ' WHERE user_id = ?', [$userId]);
    } catch (Throwable $e) {
        return false; // tablo tembel oluşturuluyor; yoksa kayıt da yok
    }
    return in_array($legacy['status'] ?? null, ['active', 'suspended'], true);
}

/**
 * Madde 2 (müşteri kararı, 2026-10-17): "pazaryeri kaydı bulunan kullanıcı"
 * = admin tarafından ONAYLANMIŞ (`reviewed`) başvurusu olan kullanıcı.
 * Gönderilmiş ama incelenmemiş (`submitted`) ya da reddedilmiş başvuru sayılmaz.
 * Onay geri alınırsa (admin → `rejected`) bu false döner; çağıranlar yalnızca
 * YENİ işlemleri sınırlar, mevcut botlara dokunmaz.
 */
function hasApprovedMarketplaceApplication(Database $db, int $userId): bool {
    if ($userId <= 0) return false;
    $app = getMarketplaceApplication($db, $userId);
    return $app !== null && ($app['status'] ?? '') === 'reviewed';
}

/** Eski Param alt üye işyeri akışında kabul edilen şirket hesap türleri. */
const COMPANY_BANK_ACCOUNT_TYPES = ['Şahıs Şirketi', 'Kurumsal Hesap'];

/**
 * N-51 — eski satıcı/banka uçlarının kapısı (save_bank_info,
 * submerchant_register, submerchant_resubmit). Bireysel satıcılık hiçbir
 * yoldan açılmasın: Param entegrasyonunun yazılmamış olmasına güvenilmez.
 *
 * @param string|null $bankAccountType  istekte/kayıtta yazılan hesap türü;
 *                                      null = tür bu işlemde söz konusu değil
 * @throws PermissionException (403)
 */
function assertCompanySellerAllowed(Database $db, int $userId, ?string $bankAccountType = null): void {
    if (!hasApprovedMarketplaceApplication($db, $userId)) {
        throw new PermissionException(
            'Pazaryeri satıcı işlemleri yalnızca onaylanmış bir şirket başvurusu olan hesaplara açıktır. '
            . 'Önce Pazaryeri Başvurusu yapın; ekibimiz başvurunuzu onayladıktan sonra bu işlemi yapabilirsiniz.'
        );
    }
    if ($bankAccountType !== null && !in_array(trim($bankAccountType), COMPANY_BANK_ACCOUNT_TYPES, true)) {
        throw new PermissionException(
            'Bireysel satıcı kaydı kabul edilmiyor; yalnızca şahıs şirketi ve kurumsal hesaplar pazaryerinde satış yapabilir.'
        );
    }
}

/** "TR** **** … 1326" — yalnızca son dört hane görünür. */
function maskIban(?string $iban): ?string {
    if ($iban === null || $iban === '') return null;
    $iban = preg_replace('/\s+/', '', $iban) ?? '';
    return substr($iban, 0, 2) . '** **** **** **** **** ' . substr($iban, -4);
}

/**
 * Admin incelemesi (GK-9, GK-21, GK-23, GK-25). ÇAĞIRAN transaction açmalı:
 * durum, IBAN aktarımı, işlem logu ve bildirim birlikte yazılır ya da hiçbiri.
 *
 * status = reviewed:
 *   • Başvuru "incelendi" olur — satıcıyı `active` YAPMAZ (B1).
 *   • GK-23: başvurudaki IBAN `banka_bilgileri.iban`'a aktarılır, ŞU ŞARTLARLA:
 *       – kullanıcının `beklemede` para çekme talebi varsa aktarılmaz
 *         (çekim eski IBAN'la açıldı; ödeme sırasında IBAN değişmesin);
 *       – IBAN zaten aynıysa yazım ve log yapılmaz;
 *       – her gerçek değişiklik `admin_audit_log`'a yazılır: kim, ne zaman,
 *         eski ve yeni IBAN MASKELİ (tam IBAN log'a girmez).
 * status = rejected: açıklama zorunlu; IBAN'a dokunulmaz.
 *
 * @return array{status:string, iban:string}  iban: updated | unchanged |
 *         blocked_pending_withdrawal | not_applicable
 * @throws ValidationException|NotFoundException
 */
function reviewMarketplaceApplication(
    Database $db,
    int $applicationId,
    string $status,
    ?string $note,
    ?int $adminId,
    string $adminName,
    ?string $ip
): array {
    if (!in_array($status, ['reviewed', 'rejected'], true)) {
        throw new ValidationException('Geçersiz durum.');
    }
    $note = trim((string) $note);
    if ($status === 'rejected' && $note === '') {
        throw new ValidationException('Reddetmek için kullanıcıya gösterilecek bir açıklama yazın.');
    }

    $app = $db->selectSingle('id, user_id, iban FROM ' . MARKETPLACE_APPLICATIONS_TABLE . ' WHERE id = ? FOR UPDATE', [$applicationId]);
    if (!$app) {
        throw new NotFoundException('Başvuru bulunamadı.');
    }
    $userId = (int) $app['user_id'];

    $db->execute(
        'UPDATE ' . MARKETPLACE_APPLICATIONS_TABLE . '
         SET status = ?, review_note = ?, reviewed_by_admin_id = ?, reviewed_at = NOW()
         WHERE id = ?',
        [$status, $note !== '' ? mb_substr($note, 0, 1000) : null, $adminId, $applicationId]
    );

    $ibanResult = 'not_applicable';
    if ($status === 'reviewed' && trim((string) $app['iban']) !== '') {
        $newIban = strtoupper(preg_replace('/\s+/', '', (string) $app['iban']) ?? '');
        $oldRow  = $db->selectSingle('iban FROM banka_bilgileri WHERE user_id = ? FOR UPDATE', [$userId]);
        $oldIban = $oldRow ? strtoupper(preg_replace('/\s+/', '', (string) $oldRow['iban']) ?? '') : '';

        $pending = (int) ($db->selectSingle(
            "COUNT(*) AS c FROM para_cekme_talepleri WHERE user_id = ? AND durum = 'beklemede'",
            [$userId]
        )['c'] ?? 0);

        if ($oldIban === $newIban) {
            $ibanResult = 'unchanged';
        } elseif ($pending > 0) {
            $ibanResult = 'blocked_pending_withdrawal';
        } else {
            $db->execute(
                'INSERT INTO banka_bilgileri (user_id, iban) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE iban = VALUES(iban)',
                [$userId, $newIban]
            );
            $db->execute(
                'INSERT INTO admin_audit_log (admin_id, admin_name, action, target_user_id, target_type, target_id, details, ip)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $adminId,
                    mb_substr($adminName !== '' ? $adminName : 'bilinmiyor', 0, 64),
                    'iban_update_from_application',
                    $userId,
                    'marketplace_application',
                    $applicationId,
                    json_encode([
                        'application_id' => $applicationId,
                        'old_iban'       => $oldIban !== '' ? maskIban($oldIban) : null,
                        'new_iban'       => maskIban($newIban),
                    ], JSON_UNESCAPED_UNICODE),
                    $ip !== null ? mb_substr($ip, 0, 45) : null,
                ]
            );
            $ibanResult = 'updated';
        }
    }

    // GK-25 — uygulama içi bildirim (mevcut altyapı). Mesajda kişisel veri yok.
    $reviewed = $status === 'reviewed';
    $db->execute(
        'INSERT INTO notifications (user_id, type, title_tr, title_en, message_tr, message_en, is_read)
         VALUES (?, ?, ?, ?, ?, ?, 0)',
        [
            $userId,
            'marketplace_application',
            $reviewed ? 'Pazaryeri başvurunuz onaylandı' : 'Pazaryeri başvurunuz onaylanmadı',
            $reviewed ? 'Your marketplace application was approved' : 'Your marketplace application was not approved',
            $reviewed
                ? 'Başvurunuz onaylandı. Ekibimiz pazaryeri süreciyle ilgili sizinle iletişime geçecek.'
                : 'Başvurunuz onaylanmadı. Açıklamayı Pazaryeri Başvurusu sayfasında görebilir, düzenleyip yeniden başvurabilirsiniz.',
            $reviewed
                ? 'Your application was approved. Our team will contact you about the marketplace process.'
                : 'Your application was not approved. See the note on the Marketplace Application page, fix it and resubmit.',
        ]
    );

    return ['status' => $status, 'iban' => $ibanResult];
}
