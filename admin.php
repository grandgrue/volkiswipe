<?php
// admin.php - Admin Dashboard für Umfrage-Auswertung
session_start();

require_once 'config.php';

// Einfache Authentifizierung
$isLoggedIn = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

// Login-Verarbeitung
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $password = $_POST['password'] ?? '';
    if ($password === ADMIN_TOKEN) {
        $_SESSION['admin_logged_in'] = true;
        $isLoggedIn = true;
    } else {
        $error = 'Falsches Passwort';
    }
}

// Logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit();
}

// Wenn nicht eingeloggt, zeige Login-Formular
if (!$isLoggedIn) {
    ?>
    <!DOCTYPE html>
    <html lang="de">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login - Volketswil Umfrage</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: linear-gradient(to bottom right, #dcfce7, #dbeafe);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                margin: 0;
            }
            .login-box {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                max-width: 400px;
                width: 100%;
            }
            h1 { color: #16a34a; margin-top: 0; }
            input[type="password"] {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 6px;
                font-size: 16px;
                box-sizing: border-box;
            }
            button {
                width: 100%;
                padding: 12px;
                background: #16a34a;
                color: white;
                border: none;
                border-radius: 6px;
                font-size: 16px;
                cursor: pointer;
                margin-top: 10px;
            }
            button:hover { background: #15803d; }
            .error { color: #dc2626; margin-top: 10px; }
        </style>
    </head>
    <body>
        <div class="login-box">
            <h1>Admin Login</h1>
            <form method="POST">
                <input type="password" name="password" placeholder="Admin-Passwort" required>
                <button type="submit" name="login">Einloggen</button>
                <?php if (isset($error)): ?>
                    <p class="error"><?php echo htmlspecialchars($error); ?></p>
                <?php endif; ?>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Ab hier: Eingeloggt - Dashboard anzeigen
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    die('Datenbankverbindung fehlgeschlagen: ' . $e->getMessage());
}

// Statistiken abrufen
$participantCount = $pdo->query("SELECT COUNT(*) FROM participants")->fetchColumn();
$responseCount = $pdo->query("SELECT COUNT(*) FROM responses")->fetchColumn();

// Antwortverteilung
$responseDistribution = $pdo->query("
    SELECT 
        response_value,
        COUNT(*) as count
    FROM responses
    GROUP BY response_value
")->fetchAll();

// Top 10 wichtigste Themen
$topThemes = $pdo->query("
    SELECT 
        question_id,
        COUNT(*) as count
    FROM responses
    WHERE response_value IN ('sehr_wichtig', 'wichtig')
    GROUP BY question_id
    ORDER BY count DESC
    LIMIT 10
")->fetchAll();

// Neueste Teilnehmer
$recentParticipants = $pdo->query("
    SELECT name, email, timestamp
    FROM participants
    ORDER BY timestamp DESC
    LIMIT 10
")->fetchAll();

?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - Volketswil Umfrage</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, sans-serif;
            background: #f3f4f6;
            padding: 20px;
        }
        .header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        h1 { color: #16a34a; }
        .logout-btn {
            padding: 10px 20px;
            background: #dc2626;
            color: white;
            text-decoration: none;
            border-radius: 6px;
        }
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .stat-card h3 { color: #6b7280; font-size: 14px; margin-bottom: 10px; }
        .stat-card .number { font-size: 36px; font-weight: bold; color: #16a34a; }
        .section {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e5e7eb;
        }
        th { background: #f9fafb; font-weight: 600; }
        .export-btn {
            padding: 10px 20px;
            background: #16a34a;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            margin-top: 10px;
        }
        .export-btn:hover { background: #15803d; }
    </style>
</head>
<body>
    <div class="header">
        <h1>📊 Admin Dashboard</h1>
        <a href="?logout" class="logout-btn">Logout</a>
    </div>

    <div class="stats-grid">
        <div class="stat-card">
            <h3>Teilnehmer</h3>
            <div class="number"><?php echo $participantCount; ?></div>
        </div>
        <div class="stat-card">
            <h3>Antworten gesamt</h3>
            <div class="number"><?php echo $responseCount; ?></div>
        </div>
        <div class="stat-card">
            <h3>Durchschn. Antworten</h3>
            <div class="number">
                <?php echo $participantCount > 0 ? round($responseCount / $participantCount, 1) : 0; ?>
            </div>
        </div>
    </div>

    <div class="section">
        <h2>Antwortverteilung</h2>
        <table>
            <tr>
                <th>Bewertung</th>
                <th>Anzahl</th>
                <th>Prozent</th>
            </tr>
            <?php 
            $labels = [
                'sehr_wichtig' => '⭐ Sehr wichtig',
                'wichtig' => '👍 Wichtig',
                'unwichtig' => '👎 Unwichtig',
                'egal' => '😐 Egal'
            ];
            foreach ($responseDistribution as $row): 
                $percent = $responseCount > 0 ? round(($row['count'] / $responseCount) * 100, 1) : 0;
            ?>
            <tr>
                <td><?php echo $labels[$row['response_value']] ?? $row['response_value']; ?></td>
                <td><?php echo $row['count']; ?></td>
                <td><?php echo $percent; ?>%</td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="section">
        <h2>Top 10 wichtigste Themen</h2>
        <table>
            <tr>
                <th>Frage ID</th>
                <th>Anzahl (Wichtig/Sehr wichtig)</th>
            </tr>
            <?php foreach ($topThemes as $theme): ?>
            <tr>
                <td>Frage #<?php echo $theme['question_id']; ?></td>
                <td><?php echo $theme['count']; ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="section">
        <h2>Neueste Teilnehmer</h2>
        <table>
            <tr>
                <th>Name</th>
                <th>E-Mail</th>
                <th>Zeitpunkt</th>
            </tr>
            <?php foreach ($recentParticipants as $participant): ?>
            <tr>
                <td><?php echo htmlspecialchars($participant['name']); ?></td>
                <td><?php echo htmlspecialchars($participant['email']); ?></td>
                <td><?php echo date('d.m.Y H:i', strtotime($participant['timestamp'])); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <div class="section">
        <h2>Datenexport</h2>
        <a href="export.php?type=csv&token=<?php echo ADMIN_TOKEN; ?>" class="export-btn">
            📥 Als CSV exportieren
        </a>
        <a href="export.php?type=excel&token=<?php echo ADMIN_TOKEN; ?>" class="export-btn">
            📊 Als Excel exportieren
        </a>
    </div>
</body>
</html>