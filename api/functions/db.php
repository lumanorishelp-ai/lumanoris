<?php
// PDO Abstraction class version 1.2.0 (MySQL/MariaDB için uyarlanmış)
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/timezone.php';

class Database {
    private static $instance = null;
    private $conn;
    private $driver = 'mysql';

    private $host;
    private $username;
    private $password;
    private $database;

    // SEC-008: bu sınıf eskiden host/user/pass/db için hard-coded "dev"
    // değerleri taşıyordu ve DB_* tanımlı değilse sessizce onlara düşüyordu.
    // Parola git geçmişinde (a77323c) kalıcı olarak duruyor ve o yol
    // production'da da aktifti. Artık tek kaynak ortam değişkenleri:
    // yapılandırma eksikse bağlanmak yerine yüksek sesle hata veriyoruz.
    // Yerel geliştirme için değerler api/.env dosyasında (gitignore'lu).

    private function __construct() {
        // db.php doğrudan require edilebiliyor (admin paneli bootstrap.php
        // kullanmıyor), bu yüzden .env'i burada da yüklüyoruz. env_load()
        // idempotent ve gerçek ortam değişkenlerini asla ezmiyor.
        env_load();
        app_timezone_init(); // N-69

        // Each setting is read as "was it provided at all?", not "is it
        // truthy?". An earlier version used !empty(), so DB_PASS= (a
        // deliberately password-less user) and DB_PASS=0 both counted as
        // absent.
        $envKeys = ['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'];
        $env     = [];
        foreach ($envKeys as $key) {
            $value = env_get($key);
            if ($value !== null) {
                $env[$key] = $value;
            }
        }

        $missing = array_values(array_diff($envKeys, array_keys($env)));
        if ($missing !== []) {
            throw new Exception(
                'Veritabanı yapılandırması eksik: ' . implode(', ', $missing)
                . ' tanımlı değil. api/.env dosyasına DB_HOST, DB_USER, DB_PASS ve '
                . 'DB_NAME değişkenlerinin dördünü de ekleyin (örnek: api/.env.example). '
                . 'Parolasız bir kullanıcı için DB_PASS= satırını boş bırakın.'
            );
        }

        // An empty password is legitimate; an empty host/user/database is
        // always a misconfiguration.
        foreach (['DB_HOST', 'DB_USER', 'DB_NAME'] as $required) {
            if (trim($env[$required]) === '') {
                throw new Exception("Veritabanı yapılandırması geçersiz: $required boş olamaz.");
            }
        }

        $this->host     = $env['DB_HOST'];
        $this->username = $env['DB_USER'];
        $this->password = $env['DB_PASS'];
        $this->database = $env['DB_NAME'];

        $host_parts = explode(':', $this->host);
        $host_name = $host_parts[0];
        $port = isset($host_parts[1]) ? (int)$host_parts[1] : 3306;

        $dsn = "$this->driver:host=$host_name;dbname=$this->database;charset=utf8mb4;port=$port";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, 
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       
            PDO::ATTR_EMULATE_PREPARES   => false,                  
        ];

        try {
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            // N-69 — NOW()/CURDATE() PHP ile aynı saati görsün (yalnızca bu
            // oturum; sunucunun genel ayarı değişmez).
            $this->conn->exec("SET time_zone = '" . app_timezone_offset() . "'");
        } catch (PDOException $e) {
            throw new Exception('Veritabanı bağlantısı başarısız: ' . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->conn;
    }

    private function __clone() {}

    // I-07: `getParamTypes()` buradan SİLİNDİ. mysqli döneminden kalma bir
    // tip dizesi üreticisiydi; PDO'da karşılığı yok, `private` olduğu için
    // dışarıdan çağrılamıyordu ve sınıf içinde de tek bir çağıranı yoktu.
    // Ayrıca bozuktu: `strtotime($param)` bir diziye/null'a uygulandığında
    // PHP 8'de TypeError fırlatırdı.

    private function executePreparedStatement($query, $params = []) {
        $stmt = $this->conn->prepare($query);

        if (!empty($params)) {
            // PDO'da parametreleri index'e göre bağlamak için bindValue kullanılır.
            foreach ($params as $index => $param) {
                $type = PDO::PARAM_STR;
                if (is_int($param)) $type = PDO::PARAM_INT;
                elseif (is_bool($param)) $type = PDO::PARAM_BOOL;
                elseif (is_null($param)) $type = PDO::PARAM_NULL;
                
                // Parametreler 1'den başlar (PDO için)
                $stmt->bindValue(($index + 1), $param, $type);
            }
        }

        $stmt->execute();
        return $stmt;
    }


    /**
     * Hazır ifadelerle ham SQL çalıştırır ve etkilenen satır sayısını döndürür.
     * insert/update/delete yardımcılarının ifade edemediği tek-atım ifadeler
     * için (ör. rate_limit.php'deki atomik INSERT … ON DUPLICATE KEY UPDATE).
     * $query her zaman sabit bir dize olmalı; değişkenler yalnızca $params
     * üzerinden geçirilir.
     */
    public function execute(string $query, array $params = []): int {
        return $this->executePreparedStatement($query, $params)->rowCount();
    }

    public function selectSingle($queryBody, $params = []) 
    {
        // Sorgu Body'si sadece SELECT'ten SONRASI olmalı.
        $query = "SELECT " . $queryBody;
        $stmt = $this->executePreparedStatement($query, $params);
        return $stmt->fetch(); // PDO'da fetch() tek satır getirir.
    }

    public function selectMulti($queryBody, $params = []) 
    {
        // Sorgu Body'si sadece SELECT'ten SONRASI olmalı.
        $query = "SELECT " . $queryBody;
        $stmt = $this->executePreparedStatement($query, $params);
        return $stmt->fetchAll(); // PDO'da fetchAll() tüm satırları getirir.
    }

    /**
     * Create a supporting table only if it is genuinely missing.
     *
     * Three hot paths (rate limiting on every login/register attempt, password
     * reset, plan selection) used to fire `CREATE TABLE IF NOT EXISTS` on every
     * single request. That works here only because the dev database user is
     * root: a least-privilege production user with SELECT/INSERT/UPDATE/DELETE
     * and no DDL rights would take a PDOException on every one of those calls,
     * turning all three flows into 500s — a fragility invisible in the code.
     *
     * Checking information_schema first needs no DDL privilege, and the static
     * cache means the check happens once per process instead of a DDL
     * round-trip per request.
     *
     * Note this does not detect schema drift: like CREATE TABLE IF NOT EXISTS,
     * it leaves an existing table alone even if its columns have diverged from
     * $ddl. Real migrations are the fix for that, not this.
     */
    /**
     * DB-004 🟡 — bu metotla oluşturulan tablolar hiçbir ENGINE/CHARSET/COLLATE
     * belirtmiyordu, yani sunucu varsayılanına düşüyorlardı. MySQL 8'in
     * varsayılanı `utf8mb4_0900_ai_ci`; bu collation **MariaDB'de yok**.
     * Sonuç: şemadaki 50 tablonun 9'u (hepsi ensureTable ya da MySQL 8'de
     * üretilmiş dökümlerden gelenler) MariaDB'de oluşturulamıyordu — README
     * ise üç ayrı yerde MariaDB desteği vaat ediyordu.
     *
     * Kök neden burasıydı: collation'ı sunucu varsayılanına bırakmak. DDL
     * kendi collation'ını belirtmiyorsa artık projenin standardı ekleniyor.
     */
    private const TABLE_SUFFIX = ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

    public function ensureTable(string $table, string $ddl): void {
        static $checked = [];
        if (isset($checked[$table])) {
            return;
        }
        $checked[$table] = true;

        $row = $this->selectSingle(
            'COUNT(*) AS cnt FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        if ((int) ($row['cnt'] ?? 0) > 0) {
            return;
        }

        // Çağıran zaten belirtmişse dokunma; belirtmemişse standardı ekle.
        $normalized = rtrim(trim($ddl), ';');
        if (stripos($normalized, 'COLLATE') === false && stripos($normalized, 'CHARSET') === false) {
            $normalized .= self::TABLE_SUFFIX;
        }

        $this->getConnection()->exec($normalized);
    }

    /**
     * Grammar whitelist for the legacy admin CRUD engine's raw $where
     * fragments (api/admin/ajax/{read,update,delete}.php). Those endpoints
     * accept a client-built fragment rather than a fully parameterized
     * clause, so this used to be a blocklist (";", "--", UNION,
     * information_schema, ...). That was bypassable: no blocked keyword is
     * needed to read another table, because a scalar subquery matches none
     * of those signatures — e.g.
     *     where = "id = (SELECT id FROM adminler LIMIT 1)"
     * A blocklist cannot enumerate every such form, so this now accepts
     * ONLY the shapes the admin pages actually send and rejects the rest:
     *
     *     <col> <op> <int>  [AND <col> <op> <int> ...]
     *     FIND_IN_SET('<token>', <col>) > 0
     *     ... [ORDER BY <col> [ASC|DESC]]
     *
     * where <col> is `name` or `alias.name`, and <op> is = != <> < > <= >=.
     * Parentheses occur only in FIND_IN_SET's fixed shape, so no subquery,
     * function call, or free string literal can reach the SQL through here.
     * Throws on anything else; call before using $where in a query.
     */
    public static function assertSafeWhereFragment($where) {
        $fragment = trim((string) $where);
        if ($fragment === '') {
            throw new Exception('Geçersiz koşul ifadesi.');
        }

        $col = '[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?';

        // An ORDER BY suffix is legitimate (admin lists sort by date/id), so
        // peel it off first and let the remainder parse as pure conditions.
        if (preg_match('/\s+ORDER\s+BY\s+(.*)$/i', $fragment, $m)) {
            if (!preg_match('/^' . $col . '(?:\s+(?:ASC|DESC))?$/iD', trim($m[1]))) {
                throw new Exception('Geçersiz koşul ifadesi.');
            }
            $fragment = trim(preg_replace('/\s+ORDER\s+BY\s+.*$/i', '', $fragment));
        }

        if ($fragment === '') {
            throw new Exception('Geçersiz koşul ifadesi.');
        }

        // FIND_IN_SET carries a quoted token, so its shape is pinned and the
        // token is restricted to word characters — no quote can close the
        // literal early and start a new expression.
        $comparison = '(?:' . $col . '\s*(?:=|!=|<>|<=|>=|<|>)\s*-?\d+)';
        $findInSet  = "(?:FIND_IN_SET\(\s*'[A-Za-z0-9_-]+'\s*,\s*" . $col . '\s*\)\s*>\s*0)';
        $condition  = '(?:' . $comparison . '|' . $findInSet . ')';

        if (!preg_match('/^' . $condition . '(?:\s+AND\s+' . $condition . ')*$/iD', $fragment)) {
            throw new Exception('Geçersiz koşul ifadesi.');
        }
    }

    /**
     * $columns is spliced into read.php's SELECT list, and that is the
     * surface the audit actually exploited: with $table whitelisted, a
     * subquery in the column list still read a non-whitelisted table —
     *     columns = "(SELECT sifre FROM adminler LIMIT 1) AS leaked"
     * leaked an admin bcrypt hash. The admin pages only ever send four
     * distinct column lists, so the two non-trivial ones are matched
     * exactly and anything else must be a plain identifier list.
     */
    private const ADMIN_ALLOWED_COLUMN_EXPRESSIONS = [
        'COUNT(*) as total',
        "c.*, CONCAT(k.ad_soyad, ' (', k.kullanici_adi, ')') AS creator_name",
    ];

    public static function assertSafeColumnList($columns) {
        $list = trim((string) $columns);
        if ($list === '') {
            throw new Exception('Geçersiz sütun listesi.');
        }

        if (in_array($list, self::ADMIN_ALLOWED_COLUMN_EXPRESSIONS, true)) {
            return;
        }

        // Plain list: "*", "c.*", "a, b, c" — identifiers only, no calls.
        $item = '(?:\*|[A-Za-z_][A-Za-z0-9_]*\.\*|[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?)';
        if (!preg_match('/^' . $item . '(?:\s*,\s*' . $item . ')*$/D', $list)) {
            throw new Exception('Geçersiz sütun listesi.');
        }
    }

    /**
     * Exact whitelist of tables/joins the legacy admin CRUD engine
     * (api/admin/ajax/{create,read,update,delete}.php) is allowed to touch.
     * $table used to be interpolated straight into the SQL (only wrapped in
     * backticks, which does not escape an embedded backtick) with no
     * validation beyond an "adminler" substring block — a client could send
     * any string as the table name and inject arbitrary SQL. Derived from
     * every literal `table` value the admin pages actually send (grepped
     * across api/admin/*.php); anything else is rejected.
     */
    private const ADMIN_ALLOWED_PLAIN_TABLES = [
        'plans', 'plan_icerikler', 'chatbotlar', 'chatbot_kategoriler',
        'kullanicilar', 'chatbot_reports', 'chatbot_visits', 'chatbot_likes',
        'chatbot_dislikes', 'chatbot_follows', 'chatbot_chats',
    ];

    // read.php is the only endpoint that ever sends a joined "table" (to pull
    // in the creator's display name); create/update/delete never do.
    private const ADMIN_ALLOWED_READ_JOINS = [
        'chatbotlar c JOIN kullanicilar k ON c.author_user_id = k.id',
    ];

    public static function assertAllowedAdminTable($table, $allowJoins = false) {
        if (in_array($table, self::ADMIN_ALLOWED_PLAIN_TABLES, true)) {
            return;
        }
        if ($allowJoins && in_array($table, self::ADMIN_ALLOWED_READ_JOINS, true)) {
            return;
        }
        throw new Exception('Geçersiz tablo.');
    }

    /**
     * @deprecated Use BaseRepository::exists() in new code.
     * Kept for legacy admin scripts only.
     * WARNING: $where must be a trusted internal string — never use user input here.
     */
    public function recordExists($table, $where, $params = []) {
        $query = "SELECT COUNT(*) as record_count FROM `$table` WHERE $where";
        $stmt  = $this->executePreparedStatement($query, $params);
        $result = $stmt->fetch();
        return (int) $result['record_count'] !== 0;
    }

    public function getGlobalVars(...$var_keys) {
        if (empty($var_keys)) {
            return [];
        }

        // Parameterized IN clause — no string interpolation of key names.
        $placeholders = implode(', ', array_fill(0, count($var_keys), '?'));
        $global_vars  = $this->selectMulti("var_key, var_value FROM global_vars WHERE var_key IN ($placeholders)", array_values($var_keys));

        $seo_data = [];
        foreach ($global_vars as $row) {
            $seo_data[$row['var_key']] = $row['var_value'];
        }

        return $seo_data;
    }

    /**
     * CNT-001 — bu fonksiyon YALNIZCA UPDATE yapıyordu; satır yoksa hiçbir şey
     * yazmıyor ama yine de "başarılı" dönüyordu.
     *
     * Somut sonucu: `global_vars` içinde `satis_kosullari` satırı hiç yoktu,
     * dolayısıyla /admin/satiskosullari sayfasından mesafeli satış sözleşmesi
     * KAYDEDİLEMİYORDU — panel "İçerikler başarıyla güncellendi!" diyor, metin
     * hiçbir yere yazılmıyordu. Aynı sessiz kayıp, henüz satırı olmayan her
     * yeni anahtar için geçerliydi (iletişim bilgileri, sosyal linkler,
     * anasayfa görselleri — hepsinin satırı eksik).
     *
     * Eski gövdedeki `if ($affected === 0 && count > 0) continue;` bloğu hiçbir
     * şey yapmıyordu: sadece döngünün zaten yapacağı şeyi yapıyordu.
     *
     * `var_key` üzerinde UNIQUE indeks var, o yüzden INSERT ... ON DUPLICATE
     * KEY UPDATE tek ifadede hem oluşturuyor hem güncelliyor.
     */
    public function updateGlobalVars(array $data) {
        try {
            foreach ($data as $var_key => $var_value) {
                $this->insert(
                    "global_vars",
                    ['var_key' => $var_key, 'var_value' => $var_value],
                    true
                );
            }
            return "Güncelleme işlemi başarılı!";
        } catch (Exception $e) {
            return "Güncelleme başarısız oldu: " . $e->getMessage();
        }
    }

    /**
     * insert()/update() build their column list from array_keys($data), and
     * many controllers pass a client-supplied JSON object straight through
     * as $data (e.g. NoteController::addDialogBook, ChatController, ...).
     * Only the values were ever parameterized — a key containing a backtick
     * breaks out of the `$key` identifier quoting and injects arbitrary SQL
     * via the column list itself. Column names are never legitimately
     * anything but a simple identifier, so reject anything else outright.
     */
    private static function assertSafeColumnName($key) {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $key)) {
            throw new Exception('Geçersiz sütun adı: ' . $key);
        }
    }

    public function insert($table, $data, $updateOnDuplicate = false) {
        foreach (array_keys($data) as $key) {
            self::assertSafeColumnName($key);
        }

        $columns = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $params = array_values($data);

        $sql = "INSERT INTO `$table` ($columns) VALUES ($placeholders)";
        if ($updateOnDuplicate) {
            $updateColumns = implode(', ', array_map(fn($key) => "`$key` = VALUES(`$key`)", array_keys($data)));
            $sql .= " ON DUPLICATE KEY UPDATE $updateColumns";
        }

        $stmt = $this->executePreparedStatement($sql, $params);

        return $this->conn->lastInsertId();
    }

    // update metodunu ? parametrelerini kabul edecek şekilde düzenledim.
    public function update($table, $data, $where, $params = []) {
        foreach (array_keys($data) as $key) {
            self::assertSafeColumnName($key);
        }

        // SET kısmında ? kullanıyoruz
        $setPart = implode(', ', array_map(fn($key) => "`$key` = ?", array_keys($data)));
        
        // SET değerleri
        $data_params = array_values($data);
        
        // WHERE değerleri ($params'ta gelmeli)
        $where_params = $params;
        
        // Tüm parametreleri birleştir (SET parametreleri + WHERE parametreleri)
        $all_params = array_merge($data_params, $where_params); 

        $sql = "UPDATE `$table` SET $setPart WHERE " . str_replace('?', '?', $where); // WHERE kısmındaki ?'leri koru

        $stmt = $this->executePreparedStatement($sql, $all_params);
    
        return $stmt->rowCount();
    }
    
    
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM `$table` WHERE " . str_replace('?', '?', $where);
    
        $stmt = $this->executePreparedStatement($sql, $params);
    
        return $stmt->rowCount();
    }
    
    // I-07: `truncate()` buradan SİLİNDİ. Sıfır çağıranı vardı (üçlü arama:
    // web/src, api/admin, api/router.php) ve iki ayrı nedenle tehlikeliydi:
    // `$table` doğrudan SQL'e gömülüyordu (allowlist yok, `assertAllowedAdminTable`
    // çağrılmıyordu) ve `$this->conn->error` PDO'da var olmayan bir alan —
    // yani hata dalı kendisi fatal üretirdi. CLAUDE.md TRUNCATE'i zaten
    // yasaklıyor; sınıfta böyle bir kapı bulunmaması doğrusu.

    public function count($table, $whereClause = "", $params = [])
    {
        $query = "SELECT COUNT(*) as total FROM `" . $table . "`";
        if (!empty($whereClause)) {
            $query .= " WHERE " . $whereClause;
        }

        $stmt = $this->executePreparedStatement($query, $params);
        $result = $stmt->fetch();
        return (int) $result['total'];
    }

    /**
     * SEC-001: yedekler eskiden api/admin/db_backup/ içine yazılıyordu —
     * doküman kökünün İÇİ. `GET /admin/db_backup/backup-….sql` kimlik
     * doğrulaması olmadan 1,5 MB'lık tam dökümü (tüm e-postalar + bcrypt
     * hash'ler) veriyordu. Artık varsayılan konum repo kökündeki
     * storage/db_backup, yani doküman kökünün dışında; DB_BACKUP_DIR ile
     * override edilebilir (production'da /var/backups/... önerilir).
     */
    private function backupDir(): string
    {
        $dir = env_get('DB_BACKUP_DIR');
        if ($dir === null || trim($dir) === '') {
            // api/functions -> api -> repo kökü
            $dir = __DIR__ . '/../../storage/db_backup';
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new Exception('Yedek dizini oluşturulamadı: ' . $dir);
        }
        $real = realpath($dir);
        if ($real === false) {
            throw new Exception('Yedek dizini okunamadı: ' . $dir);
        }

        // Doküman kökünün altına düşmüş bir yapılandırma sessizce kabul
        // edilirse SEC-001 aynen geri gelir — açıkça reddet.
        $docroot = realpath(__DIR__ . '/..');
        if ($docroot !== false && strpos($real, $docroot) === 0) {
            throw new Exception(
                'DB_BACKUP_DIR doküman kökünün (api/) altında olamaz: ' . $real
            );
        }

        return $real;
    }

    /**
     * `mysqldump` / `mysql` ikililerinin tam yolunu bulur.
     *
     * PATH'e güvenmek Windows'ta ve çoğu paylaşımlı hostingte çalışmıyor:
     * MySQL kurulumu ikilileri PATH'e eklemiyor, ve yedekleme "araç yok"
     * diye sessizce ya da anlaşılmaz bir hatayla düşüyordu. Sıra:
     * MYSQL_BIN_DIR → PATH → bilinen kurulum dizinleri.
     */
    private function mysqlBinary(string $name): string
    {
        static $cache = [];
        if (isset($cache[$name])) {
            return $cache[$name];
        }

        $isWindows = stripos(PHP_OS_FAMILY, 'Windows') !== false;
        $exe       = $isWindows ? $name . '.exe' : $name;

        $candidates = [];

        $configured = env_get('MYSQL_BIN_DIR');
        if ($configured !== null && trim($configured) !== '') {
            $candidates[] = rtrim(trim($configured), '/\\') . DIRECTORY_SEPARATOR . $exe;
        }

        // PATH üzerinde mi?
        $which = $isWindows ? "where $exe 2>NUL" : "command -v $exe 2>/dev/null";
        $found = @shell_exec($which);
        if (is_string($found) && trim($found) !== '') {
            $first = trim(strtok($found, "\r\n"));
            if ($first !== '') {
                $candidates[] = $first;
            }
        }

        // Bilinen kurulum dizinleri.
        $roots = $isWindows
            ? [
                'C:/Program Files/MySQL/MySQL Server 8.4/bin',
                'C:/Program Files/MySQL/MySQL Server 8.0/bin',
                'C:/Program Files/MariaDB 11.4/bin',
                'C:/Program Files/MariaDB 10.11/bin',
                'C:/xampp/mysql/bin',
                'C:/laragon/bin/mysql/mysql-8.0.30-winx64/bin',
              ]
            : ['/usr/bin', '/usr/local/bin', '/usr/local/mysql/bin', '/opt/homebrew/bin'];

        foreach ($roots as $root) {
            $candidates[] = $root . DIRECTORY_SEPARATOR . $exe;
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $cache[$name] = $candidate;
            }
        }

        throw new Exception(
            "$exe bulunamadı. MySQL istemci araçlarını kurun ya da api/.env içinde "
            . 'MYSQL_BIN_DIR ile dizinini belirtin (ör. MYSQL_BIN_DIR="C:/Program Files/MySQL/MySQL Server 8.0/bin").'
        );
    }

    /**
     * Parolayı komut satırına yazmak yerine MYSQL_PWD ile geçiriyoruz:
     * argv aynı makinedeki her kullanıcıya `ps` ile görünür.
     */
    private function mysqlCliEnv(): array
    {
        $env = $_ENV + $_SERVER;
        if ($this->password !== '') {
            $env['MYSQL_PWD'] = $this->password;
        }
        // proc_open ortamı dizi olarak alır; skaler olmayanları at.
        return array_filter($env, static fn($v) => is_scalar($v));
    }

    private function runMysqlCli(array $args, ?string $stdinFile = null, ?string $stdoutFile = null): void
    {
        // İlk argüman ikilinin adı — tam yola çevir.
        $args[0] = $this->mysqlBinary($args[0]);

        $descriptors = [
            0 => $stdinFile !== null ? ['file', $stdinFile, 'r'] : ['pipe', 'r'],
            1 => $stdoutFile !== null ? ['file', $stdoutFile, 'w'] : ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($args, $descriptors, $pipes, null, $this->mysqlCliEnv());
        if (!is_resource($process)) {
            throw new Exception(
                'mysql/mysqldump çalıştırılamadı: ' . $args[0]
            );
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        $stdout = (isset($pipes[1]) && is_resource($pipes[1])) ? stream_get_contents($pipes[1]) : '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $code = proc_close($process);
        if ($code !== 0) {
            // Parola stderr'e düşmez (MYSQL_PWD kullanıyoruz) ama yine de
            // yalnızca ilk satırları döndürüyoruz.
            $detail = trim($stderr !== '' ? $stderr : $stdout);
            $detail = implode("\n", array_slice(explode("\n", $detail), 0, 5));
            throw new Exception('mysql komutu hata verdi (' . $code . '): ' . $detail);
        }
    }

    private function hostAndPort(): array
    {
        $parts = explode(':', $this->host);
        return [$parts[0], isset($parts[1]) ? (int) $parts[1] : 3306];
    }

    public function backup(): string
    {
        [$hostName, $port] = $this->hostAndPort();
        $backupDir  = $this->backupDir();
        $backupFile = $backupDir . DIRECTORY_SEPARATOR . 'backup-' . date('Y-m-d-H-i-s') . '.sql';

        // proc_open stdout'u dosyaya yönlendirdiği için dosya, komut daha
        // çalışmadan oluşuyor. mysqldump başarısız olursa geriye 0 baytlık
        // bir "yedek" kalıyordu — ve restore() en yeni .sql dosyasını seçtiği
        // için o boş dosya bir sonraki geri yüklemede canlı veritabanının
        // üzerine yazılacak "yedek" olarak seçilebilirdi. Başarısız çıktıyı
        // her durumda sil.
        try {
            $this->runMysqlCli([
                'mysqldump',
                '--user=' . $this->username,
                '--host=' . $hostName,
                '--port=' . $port,
                '--single-transaction',
                '--default-character-set=utf8mb4',
                $this->database,
            ], null, $backupFile);
        } catch (Throwable $e) {
            if (is_file($backupFile)) {
                @unlink($backupFile);
            }
            throw $e;
        }

        // Komut 0 döndürse bile boş bir dosya yedek değildir.
        if (!is_file($backupFile) || filesize($backupFile) === 0) {
            @unlink($backupFile);
            throw new Exception('Yedekleme boş dosya üretti — yedek alınamadı.');
        }

        @chmod($backupFile, 0600);
        return $backupFile;
    }

    /**
     * Yıkıcı: mevcut şemanın üzerine yazar. Çağıran taraf (admin/ajax/db_backup.php)
     * POST + CSRF + açık onay istiyor (SEC-007).
     */
    public function restore(?string $backupFile = null): string
    {
        $backupDir = $this->backupDir();

        if ($backupFile === null) {
            $backups = glob($backupDir . DIRECTORY_SEPARATOR . '*.sql') ?: [];
            if ($backups === []) {
                throw new Exception('Hiç yedek bulunamadı: ' . $backupDir);
            }
            sort($backups); // dosya adı zaman damgalı, sıralama = kronoloji
            $backupFile = end($backups);
        } else {
            // Dizin dışına çıkışı engelle — dosya adı istemciden gelebilir.
            $candidate = realpath($backupDir . DIRECTORY_SEPARATOR . basename($backupFile));
            if ($candidate === false || strpos($candidate, $backupDir) !== 0 || !is_file($candidate)) {
                throw new Exception('Geçersiz yedek dosyası.');
            }
            $backupFile = $candidate;
        }

        [$hostName, $port] = $this->hostAndPort();
        $this->runMysqlCli([
            'mysql',
            '--user=' . $this->username,
            '--host=' . $hostName,
            '--port=' . $port,
            '--default-character-set=utf8mb4',
            $this->database,
        ], $backupFile, null);

        return $backupFile;
    }

    public function listBackups(): array
    {
        $files = glob($this->backupDir() . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        sort($files);
        return array_map(static fn($f) => [
            'name'  => basename($f),
            'size'  => filesize($f),
            'mtime' => filemtime($f),
        ], $files);
    }
}
