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
const MARKETPLACE_APPLICATION_FIELDS = [
    'account_type', 'company_title', 'tax_number', 'tax_office', 'mersis_no',
    'authorized_first_name', 'authorized_last_name', 'authorized_birth_date',
    'iban', 'il', 'ilce', 'address',
];

/** Zorunlu metin alanları ve azami uzunlukları (016 şemasıyla aynı). */
const MARKETPLACE_APPLICATION_TEXT_FIELDS = [
    'company_title'         => 255,
    'tax_office'            => 100,
    'authorized_first_name' => 100,
    'authorized_last_name'  => 100,
    'il'                    => 100,
    'ilce'                  => 100,
    'address'               => 500,
];

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

    // Hesap türü — GK-8: yalnızca şirketler.
    $type = (string) ($data['account_type'] ?? '');
    if (!in_array($type, ['sahis', 'kurumsal'], true)) {
        $errors['account_type'] = 'Hesap türü şahıs şirketi ya da kurumsal olmalıdır.';
    }
    $clean['account_type'] = $type;

    foreach (MARKETPLACE_APPLICATION_TEXT_FIELDS as $field => $max) {
        $value = trim(InputSanitizer::text($data[$field] ?? '', $max));
        if ($value === '') {
            $errors[$field] = 'Bu alan zorunludur.';
        } elseif (mb_strlen($value) > $max) {
            $errors[$field] = "En fazla $max karakter olabilir.";
        }
        $clean[$field] = $value;
    }

    // Vergi no — GK-24: kurumsal VKN (10); şahıs TCKN (11) ya da VKN (10).
    $taxRaw    = preg_replace('/\D/', '', (string) ($data['tax_number'] ?? '')) ?? '';
    try {
        if ($type === 'kurumsal') {
            $clean['tax_number'] = BankIdentity::normalizeVkn($taxRaw);
        } elseif (strlen($taxRaw) === 11) {
            $clean['tax_number'] = BankIdentity::normalizeTckn($taxRaw);
        } else {
            $clean['tax_number'] = BankIdentity::normalizeVkn($taxRaw);
        }
    } catch (ValidationException $e) {
        $errors['tax_number'] = $type === 'sahis'
            ? 'Şahıs şirketinde vergi no 11 haneli T.C. Kimlik No ya da 10 haneli Vergi Kimlik No olmalıdır.'
            : $e->getMessage();
    }

    // MERSİS — GK-24: kurumsalda zorunlu, şahısta isteğe bağlı; 16 hane rakam.
    $mersis = preg_replace('/\s+/', '', (string) ($data['mersis_no'] ?? '')) ?? '';
    if ($mersis === '') {
        if ($type === 'kurumsal') {
            $errors['mersis_no'] = 'Kurumsal başvuruda MERSİS numarası zorunludur.';
        }
        $clean['mersis_no'] = null;
    } elseif (!preg_match('/^\d{16}$/', $mersis)) {
        $errors['mersis_no'] = 'MERSİS numarası 16 haneli olmalıdır.';
        $clean['mersis_no'] = null;
    } else {
        $clean['mersis_no'] = $mersis;
    }

    // Yetkili doğum tarihi — geçerli tarih ve en az MIN_REGISTRATION_AGE.
    $birthRaw = (string) ($data['authorized_birth_date'] ?? '');
    $birth    = DateTimeImmutable::createFromFormat('!Y-m-d', $birthRaw);
    if (!$birth || $birth->format('Y-m-d') !== $birthRaw) {
        $errors['authorized_birth_date'] = 'Geçerli bir doğum tarihi girin (YYYY-AA-GG).';
    } elseif ($birth->diff(new DateTimeImmutable('today'))->y < AppConfig::MIN_REGISTRATION_AGE) {
        $errors['authorized_birth_date'] = 'Yetkili kişi en az ' . AppConfig::MIN_REGISTRATION_AGE . ' yaşında olmalıdır.';
    }
    $clean['authorized_birth_date'] = $birthRaw;

    // IBAN — TR + mod97 (BankIdentity; COMP-004).
    try {
        $clean['iban'] = BankIdentity::normalizeIban($data['iban'] ?? '');
    } catch (ValidationException $e) {
        $errors['iban'] = $e->getMessage();
    }

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
            'Başvurunuz incelendi; bilgilerinizi değiştirmek için destek ekibiyle iletişime geçin.'
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
    if ($status === 'reviewed') {
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
            $reviewed ? 'Pazaryeri başvurunuz incelendi' : 'Pazaryeri başvurunuz onaylanmadı',
            $reviewed ? 'Your marketplace application was reviewed' : 'Your marketplace application was not approved',
            $reviewed
                ? 'Başvurunuz incelendi. Pazaryerinde ücretli satış, ödeme altyapısı tamamlandığında açılacaktır.'
                : 'Başvurunuz onaylanmadı. Açıklamayı Pazaryeri Başvurusu sayfasında görebilir, düzeltip yeniden gönderebilirsiniz.',
            $reviewed
                ? 'Your application was reviewed. Paid marketplace sales will open once the payment infrastructure is ready.'
                : 'Your application was not approved. See the note on the Marketplace Application page, fix it and resubmit.',
        ]
    );

    return ['status' => $status, 'iban' => $ibanResult];
}
