<?php
class ChatController {
    /**
     * SEC-014 🟡 — beş yazma endpoint'i istemcinin JSON'unu doğrudan
     * insert()/update()'e veriyordu; yalnızca `user_id` sunucu tarafından
     * zorlanıyordu. Repository katmanında da beyaz liste yoktu (BIZ-004), yani
     * "istemci hangi anahtarı gönderirse o sütun yazılır" davranışı gerçekti:
     * `sent_time`, `started_at`, `commented_at` gibi sunucunun sahiplenmesi
     * gereken alanlar istemciden yazılabiliyordu (sahte zaman damgalı mesaj
     * geçmişi), var olmayan bir sütun adı ise SQL hatasına dönüşüyordu.
     */
    private const CHAT_COLUMNS         = ['chatbot_id', 'sent_by', 'message'];
    private const CONVERSATION_COLUMNS = ['chatbot_id', 'conversation_name'];

    /** Ortak yardımcı: beyaz liste dışındaki anahtarları açıkça reddeder. */
    private static function assertOnlyAllowed(array $data, array $allowed): array {
        [$clean, $rejected] = InputSanitizer::pickAllowed($data, $allowed);
        if ($rejected !== []) {
            JsonResponse::error(
                'Bu alanlar gönderilemez: ' . implode(', ', $rejected),
                403,
                AppConfig::ERR_PERMISSION
            );
        }
        return $clean;
    }

    public static function addChat(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // İstemci kendi user_id'sini gönderiyor olabilir — sunucu zaten
        // oturumdan zorluyor, o yüzden reddetmek yerine düşürüyoruz.
        unset($data['user_id'], $data['id']);
        $data = self::assertOnlyAllowed($data, self::CHAT_COLUMNS);

        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);
        if (!$chatbotId) JsonResponse::error('chatbot_id gereklidir.', 400, AppConfig::ERR_VALIDATION);

        // `sent_by` serbest metin değil: yalnızca iki taraf var.
        $sentBy = (string) ($data['sent_by'] ?? 'user');
        if (!in_array($sentBy, ['user', 'bot'], true)) {
            JsonResponse::error('Geçersiz gönderen.', 400, AppConfig::ERR_VALIDATION);
        }

        // N-30 — istemci artık üretilmiş bot cevabı YAZAMAZ (generateReply onu
        // sunucuda kaydediyor); yalnızca kullanıcı mesajı ve botun kendi
        // karşılama mesajı. İkisi de sohbet erişimi ister — eskiden erişimi
        // olmayan bota (ör. abone olunmamış ücretli bot) satır yazılabiliyor,
        // "Diyalog" sayacı şişirilebiliyordu.
        require_once __DIR__ . '/../../../functions/dialog_book.php';
        $db = Database::getInstance();
        try {
            chatMessageWriteCheck(new ChatbotRepository(), $db, $userId, $chatbotId, $sentBy, (string) ($data['message'] ?? ''));
        } catch (AppException $e) {
            JsonResponse::fromException($e);
        }

        $data['sent_by']   = $sentBy;
        $data['chatbot_id'] = $chatbotId;
        $data['user_id']   = $userId;

        $id = $db->insert('chatbot_chats', $data);
        JsonResponse::success(['message' => 'Mesaj kaydedildi!', 'id' => $id]);
    }

    public static function getChat(): void {
        $chatbotId = InputSanitizer::positiveInt($_GET['chatbot_id'] ?? 0);
        $userId    = AuthMiddleware::requireAuth();
        if (!$chatbotId) JsonResponse::error('chatbot_id gereklidir.', 400, AppConfig::ERR_VALIDATION);

        $results = Database::getInstance()->selectMulti(
            // N-30: `id` defter kaydı için (bot mesajının kimliği) — eklemeli alan.
            'id, message, sent_by FROM chatbot_chats WHERE chatbot_id = ? AND user_id = ? ORDER BY id',
            [$chatbotId, $userId]
        );
        JsonResponse::success(['messages' => $results]);
    }

    public static function addConversation(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data) JsonResponse::error('Veri bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        // SEC-014: `started_at` / `last_message_at` sunucunun; istemci
        // yazamamalı.
        unset($data['user_id'], $data['id']);
        $data = self::assertOnlyAllowed($data, self::CONVERSATION_COLUMNS);

        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);
        if (!$chatbotId) JsonResponse::error('chatbot_id gereklidir.', 400, AppConfig::ERR_VALIDATION);
        $data['chatbot_id'] = $chatbotId;
        $data['user_id']    = $userId;

        // conversation_name is VARCHAR(50) — the frontend derives it from the
        // message's first few words with no length cap, so a long word (or
        // several short ones) can exceed the column and throw a hard SQL
        // truncation error (SQLSTATE 22001) instead of saving the message.
        if (isset($data['conversation_name'])) {
            $data['conversation_name'] = mb_substr((string) $data['conversation_name'], 0, 50);
        }
        $id = Database::getInstance()->insert('chatbot_conversations', $data);
        JsonResponse::success(['message' => 'Yeni sohbet başarıyla başlatıldı!', 'id' => $id]);
    }

    public static function getConversation(): void {
        $chatbotId = InputSanitizer::positiveInt($_GET['chatbot_id'] ?? 0);
        $userId    = AuthMiddleware::requireAuth();
        $convId    = InputSanitizer::positiveInt($_GET['convId'] ?? 0);

        if (!$chatbotId) JsonResponse::error('chatbot_id gereklidir.', 400, AppConfig::ERR_VALIDATION);

        $db = Database::getInstance();

        if ($convId) {
            // Previously looked up by id alone — anyone could read another
            // user's conversation_name by guessing/incrementing convId.
            $result = $db->selectSingle('id, conversation_name FROM chatbot_conversations WHERE id = ? AND user_id = ?', [$convId, $userId]);
            if (empty($result)) {
                $result = $db->selectSingle(
                    'id, conversation_name FROM chatbot_conversations WHERE chatbot_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1',
                    [$chatbotId, $userId]
                );
            }
            if (empty($result)) {
                // Flat shape, matching the successful-lookup branch above —
                // this used to be array-wrapped ([[...]]), which meant
                // getconversation.php returned an inconsistent shape
                // depending on whether a row was found, and chat/page.jsx's
                // consumption of `conversation.id`/`conversation.chatbot_id`
                // silently broke on the not-found path.
                $result = ['id' => 0, 'conversation_name' => 'Yeni Sohbet'];
            }
        } else {
            $result = $db->selectSingle(
                'id, chatbot_id, conversation_name FROM chatbot_conversations WHERE chatbot_id = ? AND user_id = ? ORDER BY id DESC LIMIT 1',
                [$chatbotId, $userId]
            );
            if (empty($result)) {
                // Flat shape, matching the successful-lookup branch above —
                // this used to be array-wrapped ([[...]]), which meant
                // getconversation.php returned an inconsistent shape
                // depending on whether a row was found, and chat/page.jsx's
                // consumption of `conversation.id`/`conversation.chatbot_id`
                // silently broke on the not-found path.
                $result = ['id' => 0, 'conversation_name' => 'Yeni Sohbet'];
            }
        }

        echo json_encode($result);
        exit;
    }

    public static function updateConversation(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $data   = json_decode($_POST['data'] ?? '', true) ?? null;
        if (!$data || !isset($data['id'])) JsonResponse::error('Veri veya ID bulunamadı!', 400, AppConfig::ERR_VALIDATION);

        $id = InputSanitizer::positiveInt($data['id']);
        unset($data['id'], $data['user_id']);

        // B-12 — sahiplik kontrolü vardı ama ardından ham $data update()'e
        // gidiyordu; dosyada hazır olan beyaz liste burada kullanılmıyordu.
        $data = self::assertOnlyAllowed($data, self::CONVERSATION_COLUMNS);
        if ($data === []) JsonResponse::error('Güncellenecek alan yok.', 400, AppConfig::ERR_VALIDATION);

        $db = Database::getInstance();
        // Previously had no ownership check — anyone could rename any conversation by id.
        if (!$db->selectSingle('id FROM chatbot_conversations WHERE id = ? AND user_id = ?', [$id, $userId])) {
            JsonResponse::error('Bu sohbet üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        $updated = $db->update('chatbot_conversations', $data, 'id = ?', [$id]);

        if ($updated) {
            JsonResponse::success(['message' => 'Sohbet başarıyla güncellendi!', 'id' => $id]);
        } else {
            JsonResponse::error('Güncelleme başarısız!', 400);
        }
    }

    public static function deleteConversation(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        $id     = InputSanitizer::positiveInt($_POST['id'] ?? 0);
        if (!$id) JsonResponse::error("Silinecek sohbet ID'si belirtilmedi.", 400, AppConfig::ERR_VALIDATION);

        $db   = Database::getInstance();
        // Previously had no ownership check — anyone could delete any
        // conversation (and, transitively, wipe that bot's chat history) by id.
        $conv = $db->selectSingle('chatbot_id, user_id FROM chatbot_conversations WHERE id = ? AND user_id = ?', [$id, $userId]);
        if (!$conv) {
            JsonResponse::error('Bu sohbet üzerinde yetkiniz yok.', 403, AppConfig::ERR_PERMISSION);
        }

        $db->delete('chatbot_conversations', 'id = ?', [$id]);

        // chatbot_chats has no per-conversation column (rows are keyed by
        // chatbot_id + user_id only), so this can only scope to "this user's
        // messages with this bot" — not the single conversation. That's still
        // correct in the common case of one conversation per bot per user.
        // Previously this used the conversation's own id as if it were the
        // chatbot's id, which could wipe an unrelated bot's messages for
        // every user that happened to share that numeric id.
        if ($conv) {
            $db->delete('chatbot_chats', 'chatbot_id = ? AND user_id = ?', [$conv['chatbot_id'], $conv['user_id']]);
        }

        JsonResponse::success(['message' => 'Sohbet başarıyla silindi.', 'deleted_id' => $id]);
    }

    public static function getHistory(): void {
        $userId = AuthMiddleware::requireAuth();

        // chatbot_chats has no per-conversation column (rows are keyed by
        // chatbot_id + user_id only — see the same note in
        // deleteConversation), so "latest message" can only be scoped to
        // "this user's messages with this bot", not the single conversation.
        // Previously this used the conversation's own id (cc.id) as if it
        // were the chatbot id, so the subquery never matched anything and
        // latest_message/latest_sent_time were always null.
        $results = Database::getInstance()->selectMulti(
            "cc.id, cc.chatbot_id, cc.conversation_name, cb.profil_fotografi,
             (SELECT bc_inner.message FROM chatbot_chats bc_inner WHERE bc_inner.chatbot_id = cc.chatbot_id AND bc_inner.user_id = cc.user_id ORDER BY bc_inner.sent_time DESC LIMIT 1) AS latest_message,
             (SELECT bc_inner.sent_time FROM chatbot_chats bc_inner WHERE bc_inner.chatbot_id = cc.chatbot_id AND bc_inner.user_id = cc.user_id ORDER BY bc_inner.sent_time DESC LIMIT 1) AS latest_sent_time
             FROM chatbot_conversations cc
             INNER JOIN chatbotlar cb ON cc.chatbot_id = cb.id
             WHERE cc.user_id = ? ORDER BY cc.id DESC",
            [$userId]
        );

        echo json_encode(['success' => true, 'message' => 'ok', 'results' => $results]);
        exit;
    }

    /**
     * Server-side proxy for the Gemini streaming call. The frontend used to
     * fetch the raw API key from /admin/ajax/readenv.php and call Google
     * directly from the browser — that endpoint requires an admin session
     * (which no regular user has, so chat was 403ing for everyone), and even
     * when it worked it handed the real API key to any client, visible in
     * devtools and reusable by anyone. The key never leaves the server now;
     * this just streams Gemini's SSE response straight through.
     */
    /**
     * Botun sistem talimatı için üst sınır (karakter).
     *
     * AI-001 🟠 — botun TÜM `training_prompt`'u (LONGTEXT, sınırsız) istemcide
     * toplanıp her mesajda Gemini'ye gönderiliyordu ve dört katmanın hiçbirinde
     * boyut sınırı yoktu. 500 KB eğitim metni ≈ 125k token; dakikada 20 istek
     * = tek kullanıcıdan 2,5M token/dk. Sınır artık sunucuda, yani istemci
     * değiştirilerek aşılamıyor.
     */
    // B-13: tek kaynak AppConfig — yazma tarafı (TrainingController) da onu okuyor.
    private const MAX_TRAINING_CHARS = AppConfig::MAX_TRAINING_CHARS;
    private const MAX_STYLE_CHARS    = 4000;
    private const MAX_MESSAGE_CHARS  = 8000;

    /**
     * Sohbete eklenen dosyanın ÇÖZÜLMÜŞ tavanı.
     *
     * 4 MB seçildi çünkü dosya base64 ile taşınıyor ve base64 veriyi ~1/3
     * büyütüyor: 4 MB'lık dosya ~5,3 MB'lık istek gövdesi demek. PHP'nin
     * `post_max_size` varsayılanı 8M; sınırı ona yaklaştırmak, gövdenin
     * sessizce atıldığı ve isteğin anlamsız bir hatayla döndüğü duruma
     * (bkz. AUDIT L-01) kapı açardı.
     *
     * İstemcideki karşılığı chat/page.jsx içindeki MAX_CHAT_FILE_BYTES;
     * ikisi elle senkron tutuluyor.
     */
    private const MAX_FILE_BYTES = 4 * 1024 * 1024;

    /**
     * Bir mesaja eklenebilecek belge SAYISI ve bunların TOPLAM boyutu.
     *
     * Toplam, tek dosya sınırından ayrı bir kapı: her biri 4 MB sınırını
     * geçen beş dosya birlikte ~6,7 MB base64 üretir ve `post_max_size`
     * (varsayılan 8M) aşılınca PHP gövdeyi tamamen atar. Toplamı 5 MB'ta
     * tutmak, mesaj metni ve JSON yükü de eklendiğinde güvenli bir pay
     * bırakıyor.
     *
     * İstemcideki karşılıkları: MessageInput MAX_FILES, chat/page.jsx
     * MAX_CHAT_FILE_BYTES / MAX_CHAT_TOTAL_FILE_BYTES.
     */
    private const MAX_FILES = 5;
    private const MAX_TOTAL_FILE_BYTES = 5 * 1024 * 1024;

    /**
     * Modele iletilebilecek dosya türleri — Gemini'nin inline_data ile
     * kabul ettiklerinin muhafazakâr bir alt kümesi. Listeyi serbest
     * bırakmak, modelin reddedeceği bir dosya için kullanıcıdan coin almak
     * anlamına gelirdi.
     */
    private const ALLOWED_FILE_MIMES = [
        'image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif',
        'application/pdf', 'text/plain',
    ];

    public static function generateReply(): void {
        require_method('POST');
        $userId = AuthMiddleware::requireAuth();
        require_once __DIR__ . '/../../../functions/coin_engine.php';

        $db = Database::getInstance();

        // Each call is an upstream Gemini request billed against the project
        // quota. Bu limit artık tek savunma değil (aşağıda gerçek mesaj hakkı
        // tüketimi var) ama patlama koruması olarak duruyor.
        checkRateLimit($db, 'genreply:' . $userId, 20, 60);

        $data      = json_decode($_POST['data'] ?? '', true) ?? null;
        $message   = $data['message'] ?? null;
        $chatbotId = InputSanitizer::positiveInt($data['chatbot_id'] ?? 0);
        /* Çoklu belge: istemci artık `files` dizisi gönderiyor. Tekil `file`
           biçimi geriye dönük uyumluluk için kabul ediliyor — eski bir sekme
           açık kaldıysa isteği reddetmek yerine tek elemanlı listeye
           çeviriyoruz. */
        $files = [];
        if (is_array($data['files'] ?? null)) {
            $files = array_values(array_filter($data['files'], 'is_array'));
        } elseif (is_array($data['file'] ?? null)) {
            $files = [$data['file']];
        }

        if (!$data || $message === null || $message === '') {
            JsonResponse::error('Eksik veri!', 400, AppConfig::ERR_VALIDATION);
        }

        // COIN-001 🔴 / SEC-015 / PAY-002 🔴 — istek eskiden `chatbot_id` bile
        // taşımıyordu. Sonuçları:
        //   • sunucu hangi botun konuştuğunu bilmediği için erişim kontrolü
        //     yapamıyordu (abonelik olmadan her bot kullanılabiliyordu),
        //   • `consumeMessage()` hiç çağrılmıyordu; mesaj limiti tamamen
        //     istemcinin gönüllü olarak ayrı bir endpoint'i çağırmasına
        //     bağlıydı — etkin limit 10/gün yerine 28.800/gün'dü,
        //   • sistem talimatı istemciden geliyordu; yani kullanıcı botun
        //     personasını değiştirebiliyor ya da botu tamamen atlayıp
        //     Gemini'yi genel amaçlı kullanabiliyordu.
        if (!$chatbotId) {
            JsonResponse::error('Chatbot ID gerekli.', 400, AppConfig::ERR_VALIDATION);
        }

        // Faz 4 / GK-2 — 'chat' = sahip/abone + ücretsiz herkese açık bot.
        if (!(new ChatbotRepository())->userHasAccess($chatbotId, $userId, 'chat')) {
            JsonResponse::error(
                'Bu chatbot ile sohbet edebilmek için aktif bir aboneliğiniz olmalı.',
                403,
                AppConfig::ERR_PERMISSION
            );
        }

        $bot = $db->selectSingle(
            'style_prompt, LEFT(training_prompt, ?) AS training_prompt FROM chatbotlar WHERE id = ?',
            [self::MAX_TRAINING_CHARS, $chatbotId]
        );
        if (!$bot) {
            JsonResponse::error('Chatbot bulunamadı.', 404, AppConfig::ERR_NOT_FOUND);
        }

        // Dosya doğrulaması coin TÜKETİMİNDEN ÖNCE yapılıyor: geçersiz bir
        // dosya için kullanıcıdan hak düşmemeli. Tümü doğrulanmadan hiçbiri
        // kabul edilmiyor — beşinci dosya reddedilirken ilk dördü için coin
        // düşmüş olması kullanıcı açısından açıklanamaz olurdu.
        $fileParts = [];
        if ($files !== []) {
            if (count($files) > self::MAX_FILES) {
                JsonResponse::error(
                    'Bir mesaja en fazla ' . self::MAX_FILES . ' belge ekleyebilirsiniz.',
                    400,
                    AppConfig::ERR_VALIDATION
                );
            }

            $totalBytes = 0;
            foreach ($files as $index => $file) {
                $mime = (string) ($file['mime_type'] ?? '');
                $b64  = (string) ($file['data'] ?? '');
                $sira = $index + 1;

                if (!in_array($mime, self::ALLOWED_FILE_MIMES, true)) {
                    JsonResponse::error(
                        $sira . '. belgenin türü desteklenmiyor. Görsel (JPEG/PNG/WebP/HEIC), PDF veya düz metin gönderebilirsiniz.',
                        400,
                        AppConfig::ERR_VALIDATION
                    );
                }

                $bytes = base64_decode($b64, true);
                if ($bytes === false || $bytes === '') {
                    JsonResponse::error($sira . '. belge okunamadı.', 400, AppConfig::ERR_VALIDATION);
                }
                if (strlen($bytes) > self::MAX_FILE_BYTES) {
                    JsonResponse::error(
                        $sira . '. belge çok büyük (dosya başına maks. ' . (int) (self::MAX_FILE_BYTES / 1024 / 1024) . ' MB).',
                        413,
                        AppConfig::ERR_VALIDATION
                    );
                }

                $totalBytes += strlen($bytes);
                if ($totalBytes > self::MAX_TOTAL_FILE_BYTES) {
                    JsonResponse::error(
                        'Belgelerin toplam boyutu çok büyük (maks. ' . (int) (self::MAX_TOTAL_FILE_BYTES / 1024 / 1024) . ' MB).',
                        413,
                        AppConfig::ERR_VALIDATION
                    );
                }

                // Yeniden kodluyoruz: istemciden gelen dizgede boşluk/satır sonu
                // bulunabiliyor ve upstream bunu reddediyor.
                $fileParts[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($bytes)]];
            }
        }

        $message = mb_substr((string) $message, 0, self::MAX_MESSAGE_CHARS);
        $style   = mb_substr((string) ($bot['style_prompt'] ?? ''), 0, self::MAX_STYLE_CHARS);
        $corpus  = (string) ($bot['training_prompt'] ?? '');

        // Talimat sunucuda kuruluyor — istemci artık bu metne dokunamıyor.
        $systemInstruction = "GÖREV: Aşağıdaki [BİLGİ KAYNAĞI] kısmına %100 sadık kalarak cevap ver.\n"
            . "Bilgi kaynağı dışına çıkma. [KİŞİLİK/STİL] direktiflerini uygula.\n\n"
            . "[BİLGİ KAYNAĞI]:\n"
            . ($corpus !== '' ? $corpus : 'Bilgi kaynağı yok, kullanıcının sana sorduğu sorulara cevap ver.')
            . "\n\n[KİŞİLİK/STİL]:\n" . $style;

        // AI-005 — mesaj hakkı SUNUCUDA tüketiliyor. İstemci bu adımı
        // atlayamıyor; ayrıca upstream hata verirse aşağıda iade ediliyor.
        $allowance = consumeMessage($db, $userId, $chatbotId);
        if (empty($allowance['allowed'])) {
            JsonResponse::error(
                'Günlük mesaj hakkınız doldu.',
                429,
                AppConfig::ERR_LIMIT_REACHED,
                ['remaining' => $allowance['remaining'] ?? 0, 'source' => $allowance['source'] ?? 'coins']
            );
        }
        $allowanceSource = (string) ($allowance['source'] ?? 'coins');

        /* Dosya eklenmişse mesajın ÜSTÜNE bir hak daha düşüyor: dosyalı bir
           istek modele belirgin biçimde daha fazla iş yaptırıyor, bu yüzden
           düz mesajla aynı fiyatta değil.

           İkinci düşüm ayrı bir mekanizma DEĞİL, aynı consumeMessage()
           çağrısı — böylece atomik "UPDATE ... WHERE remaining > 0" güvencesi
           ve satın alma kredisi/günlük havuz sıralaması aynen korunuyor.
           Kaynak ayrı tutuluyor: iki düşüm farklı havuzlardan gelebilir
           (biri kredi, biri günlük coin) ve iade doğru havuza dönmeli.

           Hak yetmezse BİRİNCİ düşüm geri veriliyor: kullanıcı hem coin'ini
           kaybedip hem cevapsız kalmamalı. */
        /* Her belge için AYRI bir hak düşüyor: 3 belgeli bir mesaj
           1 (mesaj) + 3 (belge) = 4 LumaCoin.

           Düşümlerin kaynakları tek tek saklanıyor; biri satın alma
           kredisinden, diğeri günlük havuzdan gelebiliyor ve iade doğru
           havuza dönmek zorunda.

           Ortada hak biterse O ANA KADAR düşülenlerin HEPSİ iade ediliyor:
           kullanıcı ne yarım bir işlem için ödemeli ne de cevapsız kalmalı. */
        $fileAllowanceSources = [];
        foreach ($fileParts as $index => $unused) {
            $fileAllowance = consumeMessage($db, $userId, $chatbotId);
            if (empty($fileAllowance['allowed'])) {
                refundMessage($db, $userId, $chatbotId, $allowanceSource);
                foreach ($fileAllowanceSources as $source) {
                    refundMessage($db, $userId, $chatbotId, $source);
                }
                JsonResponse::error(
                    sprintf(
                        '%d belge eklemek için %d ek LumaCoin gerekiyor, hakkınız yetmiyor.',
                        count($fileParts),
                        count($fileParts)
                    ),
                    429,
                    AppConfig::ERR_LIMIT_REACHED,
                    ['remaining' => $fileAllowance['remaining'] ?? 0, 'source' => $fileAllowance['source'] ?? 'coins']
                );
            }
            $fileAllowanceSources[] = (string) ($fileAllowance['source'] ?? 'coins');
            // İstemciye gösterilecek kalan, TÜM düşümlerden sonraki değer.
            $allowance['remaining'] = $fileAllowance['remaining'] ?? $allowance['remaining'] ?? null;
        }

        $apiKey = AppConfig::googleGeminiApiKey();
        if (!$apiKey) {
            // N-12 — burada yalnızca mesajın hakkı iade ediliyordu; belgeler
            // için düşülen ek haklar kayboluyordu. Kardeş dallar (hak yetmedi,
            // upstream hatası) hepsini iade ediyor; bu dal da aynı.
            refundMessage($db, $userId, $chatbotId, $allowanceSource);
            foreach ($fileAllowanceSources as $source) {
                refundMessage($db, $userId, $chatbotId, $source);
            }
            JsonResponse::error('Yapay zeka servisi yapılandırılmamış.', 500, AppConfig::ERR_SERVER);
        }

        // Dosya, metin parçalarının ARDINDAN ekleniyor: talimat ve kullanıcı
        // mesajı modele önce ulaşsın, dosya onların bağlamında okunsun.
        $parts = [
            ['text' => $systemInstruction],
            ['text' => $message],
        ];
        foreach ($fileParts as $filePart) {
            $parts[] = $filePart;
        }

        $payload = json_encode([
            'contents' => [[
                'role'  => 'user',
                'parts' => $parts,
            ]],
        ]);

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-3-flash-preview:streamGenerateContent'
             . '?alt=sse&key=' . urlencode($apiKey);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        while (ob_get_level() > 0) { ob_end_flush(); }

        // İstemcinin kalan hakkı ayrı bir istek atmadan güncelleyebilmesi için
        // ilk kare olarak meta gönderiyoruz.
        echo "event: meta\n";
        echo 'data: ' . json_encode([
            'remaining' => $allowance['remaining'] ?? null,
            'source'    => $allowanceSource,
        ], JSON_UNESCAPED_UNICODE) . "\n\n";
        @flush();

        // The SSE headers above commit a 200 before Gemini has answered, so an
        // upstream failure cannot change the status code any more. It used to
        // be echoed straight into the stream regardless: on a 403 the client
        // received a JSON error document where SSE frames were expected, parsed
        // nothing out of it, and the chat simply never replied — with no trace
        // server-side either. Capture a failing response instead and turn it
        // into an explicit SSE error frame the client can render.
        $httpStatus = 0;
        $errorBody  = '';
        $streamBody = '';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => $payload,
            // AI-004: istemcideki AbortController 15 sn'de kesiyordu, sunucu
            // 30 sn bekliyordu — kullanıcı hatayı görüyor ama upstream isteği
            // (ve faturası) devam ediyordu. İkisi de artık 20 sn.
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_WRITEFUNCTION  => static function ($handle, string $chunk) use (&$httpStatus, &$errorBody, &$streamBody): int {
                if ($httpStatus === 0) {
                    $httpStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                }
                if ($httpStatus >= 400) {
                    $errorBody .= $chunk;
                    return strlen($chunk);
                }
                $streamBody .= $chunk; // N-30: cevap sonunda sunucuda kaydedilecek
                echo $chunk;
                @flush();
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $curlError = curl_error($ch);
        if ($httpStatus === 0) {
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        }
        curl_close($ch);

        if ($curlError !== '' || $httpStatus === 0 || $httpStatus >= 400) {
            // Log the real upstream reason (suspended key, quota, bad model
            // name, network) — without this the failure was undiagnosable.
            error_log(sprintf(
                '[generatereply] Gemini call failed: http=%d curl=%s body=%s',
                $httpStatus,
                $curlError !== '' ? $curlError : '-',
                $errorBody !== '' ? mb_substr($errorBody, 0, 500) : '-'
            ));

            // AI-005: cevap üretilemedi — yakılan hakları geri ver. Dosya
            // için düşülen ikinci hak da buraya dahil, kendi kaynağına.
            refundMessage($db, $userId, $chatbotId, $allowanceSource);
            foreach ($fileAllowanceSources as $source) {
                refundMessage($db, $userId, $chatbotId, $source);
            }

            // Never leak the upstream body (it can echo the API key back) —
            // send a stable code the client maps to its own wording.
            echo "event: error\n";
            echo 'data: ' . json_encode(
                ['error' => ['code' => $httpStatus ?: 502, 'status' => 'UPSTREAM_ERROR', 'refunded' => true]],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . "\n\n";
            @flush();
        } else {
            // N-30 — bot cevabını SUNUCU kaydediyor (istemci addChat ile "bot"
            // yazamaz). Kimlik son SSE karesiyle istemciye gidiyor; Diyalog
            // Defteri kaydı bu kimliğe dayanıyor ve metni DB'den kopyalıyor.
            require_once __DIR__ . '/../../../functions/dialog_book.php';
            $replyText = geminiSseText($streamBody);
            if ($replyText !== '') {
                $savedId = chatSaveBotReply($db, $userId, $chatbotId, $replyText);
                echo "event: saved\n";
                echo 'data: ' . json_encode(['saved' => ['message_id' => $savedId]]) . "\n\n";
                @flush();
            }
        }
        exit;
    }
}
