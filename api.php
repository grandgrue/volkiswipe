<?php
// api.php - Backend API für Volketswil Umfrage
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Datenbank-Konfiguration
require_once 'config.php';

// Verbindung zur Datenbank herstellen
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Datenbankverbindung fehlgeschlagen']);
    exit();
}

// POST-Request: Umfrage-Daten speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    // Validierung
    if (!isset($data['userData']) || !isset($data['responses']) || !isset($data['timestamp'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Fehlende Daten']);
        exit();
    }
    
    $name = trim($data['userData']['name']);
    $email = trim($data['userData']['email']);
    $timestamp = $data['timestamp'];
    $responses = $data['responses'];
    
    // E-Mail-Validierung
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige E-Mail-Adresse']);
        exit();
    }
    
    try {
        // Transaktion starten
        $pdo->beginTransaction();
        
        // Teilnehmer speichern
        $stmt = $pdo->prepare("
            INSERT INTO participants (name, email, timestamp) 
            VALUES (:name, :email, :timestamp)
        ");
        $stmt->execute([
            ':name' => $name,
            ':email' => $email,
            ':timestamp' => $timestamp
        ]);
        
        $participantId = $pdo->lastInsertId();
        
        // Antworten speichern
        $stmt = $pdo->prepare("
            INSERT INTO responses (participant_id, question_id, response_value) 
            VALUES (:participant_id, :question_id, :response_value)
        ");
        
        foreach ($responses as $questionId => $responseValue) {
            $stmt->execute([
                ':participant_id' => $participantId,
                ':question_id' => $questionId,
                ':response_value' => $responseValue
            ]);
        }
        
        $pdo->commit();
        
        // E-Mail senden
        try {
            sendSummaryEmail($email, $name, $responses, $pdo);
        } catch (Exception $e) {
            // E-Mail-Fehler sollte nicht die gesamte Anfrage scheitern lassen
            error_log('E-Mail-Fehler: ' . $e->getMessage());
        }
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Daten erfolgreich gespeichert',
            'participantId' => $participantId
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        http_response_code(500);
        echo json_encode(['error' => 'Fehler beim Speichern der Daten']);
        error_log('Database error: ' . $e->getMessage());
    }
    
    exit();
}

// GET-Request: Statistiken abrufen (für Admin-Dashboard)
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    // Einfache Authentifizierung (später durch besseres System ersetzen)
    $authToken = $_GET['token'] ?? '';
    if ($authToken !== ADMIN_TOKEN) {
        http_response_code(401);
        echo json_encode(['error' => 'Nicht autorisiert']);
        exit();
    }
    
    try {
        // Anzahl Teilnehmer
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM participants");
        $participantCount = $stmt->fetch()['count'];
        
        // Antwortstatistiken pro Frage
        $stmt = $pdo->query("
            SELECT 
                question_id,
                response_value,
                COUNT(*) as count
            FROM responses
            GROUP BY question_id, response_value
            ORDER BY question_id, response_value
        ");
        $statistics = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'participantCount' => $participantCount,
            'statistics' => $statistics
        ]);
        
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Fehler beim Abrufen der Statistiken']);
        error_log('Database error: ' . $e->getMessage());
    }
    
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Methode nicht erlaubt']);

// Funktion zum Versenden der Zusammenfassung per E-Mail
function sendSummaryEmail($email, $name, $responses, $pdo) {
    
    // Fragen aus der Datenbank laden (oder hardcoded)
    $questions = getQuestions();
    
    // Zähle Antworten nach Kategorie
    $stats = [
        'sehr_wichtig' => 0,
        'wichtig' => 0,
        'unwichtig' => 0,
        'egal' => 0
    ];
    
    foreach ($responses as $response) {
        if (isset($stats[$response])) {
            $stats[$response]++;
        }
    }
    
    // E-Mail-Inhalt erstellen
    $subject = 'Ihre Teilnahme: Volketswil mitgestalten';
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background-color: #16a34a; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
            .stats { background-color: #f0fdf4; padding: 15px; border-radius: 8px; margin: 20px 0; }
            .stat-item { margin: 10px 0; }
            .footer { background-color: #f3f4f6; padding: 15px; text-align: center; font-size: 12px; color: #666; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>Vielen Dank für Ihre Teilnahme!</h1>
        </div>
        <div class='content'>
            <p>Liebe/r $name,</p>
            <p>Herzlichen Dank, dass Sie sich die Zeit genommen haben, an unserer Umfrage teilzunehmen. 
            Ihre Meinung ist wichtig für die Zukunft von Volketswil.</p>
            
            <div class='stats'>
                <h3>Ihre Antworten im Überblick:</h3>
                <div class='stat-item'>✅ <strong>Sehr wichtig:</strong> {$stats['sehr_wichtig']} Themen</div>
                <div class='stat-item'>👍 <strong>Wichtig:</strong> {$stats['wichtig']} Themen</div>
                <div class='stat-item'>👎 <strong>Unwichtig:</strong> {$stats['unwichtig']} Themen</div>
                <div class='stat-item'>😐 <strong>Egal:</strong> {$stats['egal']} Themen</div>
            </div>
            
            <p>Ihre Prioritäten zeigen, dass Ihnen besonders die Themen am Herzen liegen, 
            die Sie als 'sehr wichtig' oder 'wichtig' bewertet haben.</p>
            
            <p>In den kommenden Wochen werden wir die Ergebnisse aller Teilnehmenden auswerten 
            und die wichtigsten Themen für Volketswil identifizieren.</p>
            
            <p>Mit freundlichen Grüssen<br>
            <strong>Michael Grüebler</strong><br>
            Kandidat Gemeinderat Volketswil</p>
        </div>
        <div class='footer'>
            <p>Diese E-Mail wurde automatisch generiert im Rahmen der Umfrage 'Volketswil mitgestalten'.</p>
        </div>
    </body>
    </html>
    ";
    
    // E-Mail-Header
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= "From: Michael Grüebler <noreply@volkiswipe.ch>" . "\r\n";
    
    // E-Mail senden
    if (!mail($email, $subject, $message, $headers)) {
        throw new Exception('E-Mail konnte nicht gesendet werden');
    }
}

function getQuestions() {
    // Hier könnten die Fragen aus einer Datenbank geladen werden
    // Für jetzt hardcoded - kann später erweitert werden
    return [
        0 => "Volketswil braucht mehr Tempo-30-Zonen zum Schutz von Kindern und Anwohnenden",
        // ... weitere Fragen
    ];
}
?>