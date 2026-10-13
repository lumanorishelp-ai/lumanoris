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
    $where = $_POST['where'] ?? null;

    if (!$table || !$where) {
        echo json_encode([
            "success" => false,
            "message" => "Table or where condition not specified!"
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
        Database::assertAllowedAdminTable($table);
        Database::assertSafeWhereFragment($where);
        $database->delete($table, $where);
        echo json_encode([
            "success" => true,
            "message" => "Kayıt başarıyla silindi!"
        ]);
    } catch(Exception $e) {
        echo json_encode([
            "success" => false,
            "message" => $e->getMessage()
        ]);
    }
}
?>