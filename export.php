<?php
// export.php - Datenexport als CSV oder Excel

require_once 'config.php';

// Authentifizierung
$token = $_GET['token'] ?? '';
if ($token !== ADMIN_TOKEN) {
    die('Nicht autorisiert');
}

try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('Datenbankverbindung fehlgeschlagen');
}

$type = $_GET['type'] ?? 'csv';

// Daten abrufen
$stmt = $pdo->query("
    SELECT 
        p.id,
        p.name,
        p.email,
        p.timestamp,
        r.question_id,
        r.response_value
    FROM participants p
    LEFT JOIN responses r ON p.id = r.participant_id
    ORDER BY p.timestamp DESC, r.question_id
");
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($type === 'csv') {
    // CSV Export
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="umfrage_export_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // UTF-8 BOM für Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Header
    fputcsv($output, ['ID', 'Name', 'E-Mail', 'Zeitpunkt', 'Frage ID', 'Antwort'], ';');
    
    // Daten
    foreach ($data as $row) {
        fputcsv($output, [
            $row['id'],
            $row['name'],
            $row['email'],
            $row['timestamp'],
            $row['question_id'],
            $row['response_value']
        ], ';');
    }
    
    fclose($output);
    exit();
}

if ($type === 'excel') {
    // Einfacher Excel Export (HTML-Tabelle, die Excel öffnen kann)
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="umfrage_export_' . date('Y-m-d') . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    echo '<tr><th>ID</th><th>Name</th><th>E-Mail</th><th>Zeitpunkt</th><th>Frage ID</th><th>Antwort</th></tr>';
    
    foreach ($data as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['id']) . '</td>';
        echo '<td>' . htmlspecialchars($row['name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['email']) . '</td>';
        echo '<td>' . htmlspecialchars($row['timestamp']) . '</td>';
        echo '<td>' . htmlspecialchars($row['question_id']) . '</td>';
        echo '<td>' . htmlspecialchars($row['response_value']) . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body></html>';
    exit();
}

// Statistik-Export
if ($type === 'stats') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="statistik_' . date('Y-m-d') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Statistik pro Frage
    $stmt = $pdo->query("
        SELECT 
            question_id,
            response_value,
            COUNT(*) as count
        FROM responses
        GROUP BY question_id, response_value
        ORDER BY question_id, response_value
    ");
    
    fputcsv($output, ['Frage ID', 'Antwort', 'Anzahl'], ';');
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        fputcsv($output, [
            $row['question_id'],
            $row['response_value'],
            $row['count']
        ], ';');
    }
    
    fclose($output);
    exit();
}

die('Unbekannter Export-Typ');
?>