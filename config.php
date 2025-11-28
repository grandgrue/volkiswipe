<?php
// config.php - Datenbank-Konfiguration

// WICHTIG: Diese Datei NICHT ins Git-Repository committen!
// Fügen Sie config.php zu .gitignore hinzu

// Datenbank-Zugangsdaten (von Hoststar My Panel)
define('DB_HOST', 'localhost'); // Meistens 'localhost' oder 'lx#.hoststar.hosting'
define('DB_NAME', 'ihr_datenbankname'); // Ersetzen Sie dies mit Ihrem Datenbanknamen
define('DB_USER', 'ihr_benutzername'); // MySQL-Benutzername
define('DB_PASS', 'ihr_passwort'); // MySQL-Passwort

// Admin-Token für API-Zugriff auf Statistiken
// WICHTIG: Ändern Sie diesen Token zu einem sicheren, zufälligen Wert
define('ADMIN_TOKEN', 'ihr-sicherer-admin-token-hier');

// Fehlerberichterstattung (für Production auf 0 setzen)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Zeitzone
date_default_timezone_set('Europe/Zurich');

// E-Mail-Einstellungen (optional, falls Sie SMTP verwenden möchten)
define('SMTP_HOST', ''); // Falls Sie SMTP nutzen
define('SMTP_PORT', 587);
define('SMTP_USER', '');
define('SMTP_PASS', '');
define('FROM_EMAIL', 'noreply@volkiswipe.ch');
define('FROM_NAME', 'Volketswil Umfrage');
?>