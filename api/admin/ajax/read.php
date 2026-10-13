<?php
require_once __DIR__ . '/_guard.php';
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    echo json_encode(["success" => false, "message" => "Yetkisiz erişim."]);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    require __DIR__ . '/../../functions/db.php'; // N-21
    $database = Database::getInstance();
    $conn = $database->getConnection();

    $table = $_POST['table'] ?? null;
    $columns = $_POST['columns'] ?? "*"; // İstenirse kolon listesi, yoksa *
    $where = $_POST['where'] ?? "";      // Opsiyonel koşul
    $limit = $_POST['limit'] ?? "";      // Opsiyonel limit

    if (!$table) {
        echo json_encode([
            "success" => false,
            "message" => "No table specified!"
        ]);
        exit;
    }

    if (stripos($table, 'adminler') !== false) {
        echo json_encode([
            "success" => false,
            "message" => "Bu tabloya bu uç noktadan erişilemez."
        ]);
        exit;
    }

    try {
        Database::assertAllowedAdminTable($table, true);
        Database::assertSafeColumnList($columns);
        if (!empty($where)) {
            Database::assertSafeWhereFragment($where);
        }

        // Sorgu oluştur
        $sql = "$columns FROM $table";
        if (!empty($where)) {
            $sql .= " WHERE $where";
        }
        if (!empty($limit)) {
            $sql .= " LIMIT " . intval($limit);
        }
        $result = $database->selectMulti($sql);

        echo json_encode([
            "success" => true,
            "message" => "Data fetched successfully.",
            "data"    => $result
        ]);
    } catch (Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    }
}
?>