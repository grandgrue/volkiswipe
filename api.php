<?php
// api.php - Backend API für Volketswil Umfrage
error_reporting(E_ALL);
ini_set('display_errors', 0); // Nicht in Production anzeigen
ini_set('log_errors', 1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Logging-Funktion
function logDebug($message, $data = null) {
    $logMessage = date('Y-m-d H:i:s') . ' - ' . $message;
    if ($data !== null) {
        $logMessage .= ' - ' . json_encode($data);
    }
    error_log($logMessage);
}

logDebug('API Request', [
    'method' => $_SERVER['REQUEST_METHOD'],
    'action' => $_GET['action'] ?? 'none',
    'uri' => $_SERVER['REQUEST_URI']
]);

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
    logDebug('Database connected successfully');
} catch (PDOException $e) {
    logDebug('Database connection failed', ['error' => $e->getMessage()]);
    http_response_code(500);
    echo json_encode(['error' => 'Datenbankverbindung fehlgeschlagen', 'details' => $e->getMessage()]);
    exit();
}

// POST-Request: Umfrage-Daten speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $input = file_get_contents('php://input');
    logDebug('POST data received', ['length' => strlen($input)]);
    
    $data = json_decode($input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        logDebug('JSON decode error', ['error' => json_last_error_msg()]);
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige JSON-Daten: ' . json_last_error_msg()]);
        exit();
    }
    
    logDebug('Decoded data', [
        'has_userData' => isset($data['userData']),
        'has_responses' => isset($data['responses']),
        'has_timestamp' => isset($data['timestamp']),
        'response_count' => isset($data['responses']) ? count($data['responses']) : 0
    ]);
    
    // Validierung
    if (!isset($data['userData']) || !isset($data['responses']) || !isset($data['timestamp'])) {
        logDebug('Missing required fields');
        http_response_code(400);
        echo json_encode(['error' => 'Fehlende Daten']);
        exit();
    }
    
    $name = trim($data['userData']['name']);
    $email = trim($data['userData']['email']);
    $timestamp = $data['timestamp'];
    $responses = $data['responses'];
    $participantId = $data['participantId'] ?? null;
    $isAutoSave = $data['isAutoSave'] ?? false;
    $sendEmail = $data['sendEmail'] ?? false;
    
    // Name ist Pflichtfeld
    if (empty($name) || trim($name) === '') {
        logDebug('Name missing');
        http_response_code(400);
        echo json_encode(['error' => 'Name ist ein Pflichtfeld']);
        exit();
    }
    
    // ISO-8601 Timestamp in MySQL Format konvertieren
    try {
        $dateTime = new DateTime($timestamp);
        $mysqlTimestamp = $dateTime->format('Y-m-d H:i:s');
    } catch (Exception $e) {
        logDebug('Invalid timestamp format', ['timestamp' => $timestamp]);
        http_response_code(400);
        echo json_encode(['error' => 'Ungültiges Zeitformat']);
        exit();
    }
    
    logDebug('Validated data', [
        'name' => $name,
        'email' => $email,
        'timestamp' => $mysqlTimestamp,
        'response_count' => count($responses),
        'participantId' => $participantId,
        'isAutoSave' => $isAutoSave,
        'sendEmail' => $sendEmail
    ]);
    
    // E-Mail-Validierung nur wenn E-Mail angegeben und sendEmail = true
    if ($sendEmail && !empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        logDebug('Invalid email', ['email' => $email]);
        http_response_code(400);
        echo json_encode(['error' => 'Ungültige E-Mail-Adresse']);
        exit();
    }
    
    try {
        // Transaktion starten
        $pdo->beginTransaction();
        logDebug('Transaction started');
        
        // Prüfen ob Teilnehmer schon existiert (Update-Fall)
        if ($participantId) {
            // Participant aktualisieren
            $stmt = $pdo->prepare("
                UPDATE participants 
                SET name = :name, email = :email, timestamp = :timestamp
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':timestamp' => $mysqlTimestamp,
                ':id' => $participantId
            ]);
            logDebug('Participant updated', ['id' => $participantId]);
            
            // Bestehende Antworten löschen und neu einfügen
            $stmt = $pdo->prepare("DELETE FROM responses WHERE participant_id = :participant_id");
            $stmt->execute([':participant_id' => $participantId]);
            logDebug('Old responses deleted');
            
        } else {
            // Neuer Teilnehmer
            $stmt = $pdo->prepare("
                INSERT INTO participants (name, email, timestamp) 
                VALUES (:name, :email, :timestamp)
            ");
            $stmt->execute([
                ':name' => $name,
                ':email' => $email,
                ':timestamp' => $mysqlTimestamp
            ]);
            
            $participantId = $pdo->lastInsertId();
            logDebug('Participant inserted', ['id' => $participantId]);
        }
        
        // Antworten speichern
        $stmt = $pdo->prepare("
            INSERT INTO responses (participant_id, question_id, response_value) 
            VALUES (:participant_id, :question_id, :response_value)
        ");
        
        $savedCount = 0;
        foreach ($responses as $questionId => $responseValue) {
            $stmt->execute([
                ':participant_id' => $participantId,
                ':question_id' => $questionId,
                ':response_value' => $responseValue
            ]);
            $savedCount++;
        }
        
        logDebug('Responses inserted', ['count' => $savedCount]);
        
        $pdo->commit();
        logDebug('Transaction committed');
        
        // E-Mail nur senden wenn sendEmail = true und E-Mail vorhanden
        if ($sendEmail && !empty($email)) {
            try {
                sendSummaryEmail($email, $name, $responses, $pdo);
                logDebug('Email sent successfully');
            } catch (Exception $e) {
                // E-Mail-Fehler sollte nicht die gesamte Anfrage scheitern lassen
                logDebug('Email error (non-fatal)', ['error' => $e->getMessage()]);
            }
        }
        
        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Daten erfolgreich gespeichert',
            'participantId' => $participantId,
            'responsesCount' => $savedCount,
            'isAutoSave' => $isAutoSave
        ]);
        
    } catch (PDOException $e) {
        $pdo->rollBack();
        logDebug('Database error', ['error' => $e->getMessage()]);
        http_response_code(500);
        echo json_encode([
            'error' => 'Fehler beim Speichern der Daten',
            'details' => $e->getMessage()
        ]);
    }
    
    exit();
}

// GET-Request: Fragen laden oder Statistiken abrufen
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    
    $action = $_GET['action'] ?? 'stats';
    
    // Fragen für Frontend laden (öffentlich zugänglich)
    if ($action === 'getQuestions') {
        try {
            // Fragen mit Kategorie-Info laden
            $stmt = $pdo->query("
                SELECT 
                    q.id,
                    q.category,
                    q.text,
                    q.sort_order
                FROM questions q
                WHERE q.active = 1
                ORDER BY q.sort_order
            ");
            $questions = $stmt->fetchAll();
            
            // Kategorien mit Icons definieren
            $categoryMap = [
                'Verkehr & Mobilität' => '🚲',
                'Wohnen & Siedlungsentwicklung' => '🏘️',
                'Bildung & Kinderbetreuung' => '🎓',
                'Wirtschaft & Arbeit' => '💼',
                'Natur, Umwelt & Energie' => '🌳',
                'Infrastruktur & Versorgung' => '🏗️',
                'Gesundheit & Soziales' => '❤️',
                'Kultur, Freizeit & Sport' => '🎨',
                'Sicherheit' => '🛡️',
                'Finanzen & Steuern' => '💰',
                'Politik & Verwaltung' => '🏛️',
                'Generationenthemen' => '👨‍👩‍👧‍👦',
                'Landwirtschaft & Landschaft' => '🌾',
                'Digitalisierung & Innovation' => '💻',
                'Demokratie & Mitbestimmung' => '🗳️',
                'Strategische Entwicklung & Planung' => '📊',
                'Zentrum & Ortsteile' => '🏙️',
                'Regionale Zusammenarbeit' => '🤝',
                'Quartierentwicklung' => '🏘️'
            ];
            
            // Fragen mit Icon anreichern
            foreach ($questions as &$question) {
                $question['category_name'] = $question['category'];
                $question['category_icon'] = $categoryMap[$question['category']] ?? '📋';
            }
            
            // Unique Kategorien extrahieren
            $categories = [];
            $seenCategories = [];
            foreach ($questions as $question) {
                if (!in_array($question['category'], $seenCategories)) {
                    $categories[] = [
                        'name' => $question['category'],
                        'icon' => $question['category_icon']
                    ];
                    $seenCategories[] = $question['category'];
                }
            }
            
            echo json_encode([
                'success' => true,
                'questions' => $questions,
                'categories' => $categories
            ]);
            exit();
            
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Fehler beim Laden der Fragen']);
            error_log('Database error: ' . $e->getMessage());
            exit();
        }
    }
    
    // Statistiken abrufen (nur mit Token)
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
    
    // Fragen aus der Datenbank laden
    $stmt = $pdo->query("
        SELECT id, category, text 
        FROM questions 
        WHERE active = 1 
        ORDER BY sort_order
    ");
    $allQuestions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Fragen nach Response-Type gruppieren
    $categorized = [
        'sehr_wichtig' => [],
        'wichtig' => [],
        'unwichtig' => [],
        'egal' => []
    ];
    
    foreach ($allQuestions as $question) {
        $responseValue = $responses[$question['id']] ?? null;
        if ($responseValue && isset($categorized[$responseValue])) {
            $categorized[$responseValue][] = $question;
        }
    }
    
    // Zähle Antworten nach Kategorie
    $stats = [
        'sehr_wichtig' => count($categorized['sehr_wichtig']),
        'wichtig' => count($categorized['wichtig']),
        'unwichtig' => count($categorized['unwichtig']),
        'egal' => count($categorized['egal'])
    ];
    
    // E-Mail-Inhalt erstellen
    $subject = 'Ihre Teilnahme: 100 Wünsche für Volketswil';
    
    // Funktion zum Erstellen der Listen
    function createQuestionList($questions) {
        if (empty($questions)) {
            return '<p style="color: #999; font-style: italic;">Keine Fragen in dieser Kategorie</p>';
        }
        
        $html = '<ul style="list-style-type: none; padding-left: 0; margin: 10px 0;">';
        foreach ($questions as $question) {
            $html .= '<li style="margin: 8px 0; padding: 8px; background: #f9fafb; border-radius: 6px;">';
            $html .= '• ' . htmlspecialchars($question['text']);
            $html .= '</li>';
        }
        $html .= '</ul>';
        return $html;
    }
    
    $message = "
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .header { background-color: #16a34a; color: white; padding: 30px 20px; text-align: center; }
            .content { padding: 20px; max-width: 800px; margin: 0 auto; }
            .stats { background-color: #f0fdf4; padding: 20px; border-radius: 8px; margin: 20px 0; }
            .stat-item { margin: 10px 0; font-size: 16px; }
            .section { margin: 30px 0; padding: 20px; border-radius: 8px; }
            .section-sehr-wichtig { background-color: #f0fdf4; border-left: 4px solid #16a34a; }
            .section-wichtig { background-color: #eff6ff; border-left: 4px solid #2563eb; }
            .section-unwichtig { background-color: #fef2f2; border-left: 4px solid #dc2626; }
            .section-egal { background-color: #f9fafb; border-left: 4px solid #6b7280; }
            .section h3 { margin-top: 0; margin-bottom: 15px; }
            .footer { background-color: #f3f4f6; padding: 20px; text-align: center; font-size: 12px; color: #666; margin-top: 30px; }
            .signature { background-color: #f0fdf4; padding: 20px; border-radius: 8px; margin-top: 30px; }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1>🎄 Vielen Dank für Ihre Teilnahme!</h1>
        </div>
        <div class='content'>
            <p style='font-size: 18px;'>Liebe/r $name,</p>
            <p>Herzlichen Dank, dass Sie sich die Zeit genommen haben, an meiner Befragung «100 Wünsche für Volketswil» teilzunehmen. 
            Ihre Meinung hilft mir sehr zu verstehen, was den Menschen in Volketswil wirklich wichtig ist.</p>
            
            <div class='stats'>
                <h3 style='margin-top: 0;'>📊 Ihre Antworten im Überblick:</h3>
                <div class='stat-item'>✅ <strong>Sehr wichtig:</strong> {$stats['sehr_wichtig']} Themen</div>
                <div class='stat-item'>👍 <strong>Wichtig:</strong> {$stats['wichtig']} Themen</div>
                <div class='stat-item'>👎 <strong>Unwichtig:</strong> {$stats['unwichtig']} Themen</div>
                <div class='stat-item'>😐 <strong>Egal / Weiss nicht:</strong> {$stats['egal']} Themen</div>
            </div>
            
            <h2 style='color: #16a34a; margin-top: 40px;'>📋 Ihre detaillierten Antworten:</h2>
            
            <div class='section section-sehr-wichtig'>
                <h3 style='color: #16a34a;'>✅ Sehr wichtig ({$stats['sehr_wichtig']})</h3>
                " . createQuestionList($categorized['sehr_wichtig']) . "
            </div>
            
            <div class='section section-wichtig'>
                <h3 style='color: #2563eb;'>👍 Wichtig ({$stats['wichtig']})</h3>
                " . createQuestionList($categorized['wichtig']) . "
            </div>
            
            <div class='section section-unwichtig'>
                <h3 style='color: #dc2626;'>👎 Unwichtig ({$stats['unwichtig']})</h3>
                " . createQuestionList($categorized['unwichtig']) . "
            </div>
            
            <div class='section section-egal'>
                <h3 style='color: #6b7280;'>😐 Egal / Weiss nicht ({$stats['egal']})</h3>
                " . createQuestionList($categorized['egal']) . "
            </div>
            
            <div class='signature'>
                <p style='margin-bottom: 15px;'>
                    In den kommenden Wochen werde ich die Ergebnisse aller Teilnehmenden auswerten. 
                    Falls ich als Gemeinderat gewählt werde, weiss ich dann genau, wofür ich mich einsetzen soll.
                </p>
                <p style='margin-bottom: 15px;'>
                    Ihre Stimme zählt! Die Ergebnisse dieser Befragung helfen mir sehr zu verstehen, 
                    was die Bedürfnisse der Bevölkerung von Volketswil wirklich sind.
                </p>
                <p style='margin-top: 25px; margin-bottom: 5px;'>
                    Mit herzlichen Grüssen
                </p>
                <p style='margin: 0;'>
                    <strong style='font-size: 16px;'>Michael Grüebler</strong><br>
                    <span style='color: #666;'>Kandidat Gemeinderat Volketswil</span>
                </p>
            </div>
        </div>
        <div class='footer'>
            <p>Diese E-Mail wurde automatisch generiert im Rahmen der Befragung «100 Wünsche für Volketswil».</p>
        </div>
    </body>
    </html>
    ";
    
    // E-Mail-Header mit korrekter Kodierung
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    
    // Sender-Name OHNE Umlaute kodieren (RFC 2047)
    $senderName = '=?UTF-8?B?' . base64_encode('Michael Gruebler - Volketswil') . '?=';
    $headers .= "From: " . $senderName . " <volki@grue.ch>" . "\r\n";
    $headers .= "Reply-To: " . $senderName . " <volki@grue.ch>" . "\r\n";
    
    // Subject MIT Umlauten (wird automatisch kodiert)
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    
    // E-Mail senden
    if (!mail($email, $encodedSubject, $message, $headers)) {
        throw new Exception('E-Mail konnte nicht gesendet werden');
    }
}

function getQuestions() {
    // Diese Funktion wird nicht mehr benötigt, da wir direkt aus der DB laden
    return [];
}
?>