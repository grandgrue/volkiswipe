-- database.sql - MySQL Datenbankschema für Volketswil Umfrage
-- Führen Sie dieses Script in phpMyAdmin aus

-- Datenbank erstellen (falls noch nicht vorhanden)
-- CREATE DATABASE IF NOT EXISTS volkiswipe CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE volkiswipe;

-- Tabelle für Teilnehmer
CREATE TABLE IF NOT EXISTS participants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    timestamp DATETIME NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle für Antworten
CREATE TABLE IF NOT EXISTS responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    participant_id INT NOT NULL,
    question_id INT NOT NULL,
    response_value ENUM('sehr_wichtig', 'wichtig', 'unwichtig', 'egal') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (participant_id) REFERENCES participants(id) ON DELETE CASCADE,
    INDEX idx_question (question_id),
    INDEX idx_response (response_value)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabelle für Fragen (optional, für bessere Verwaltung)
CREATE TABLE IF NOT EXISTS questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100) NOT NULL,
    text TEXT NOT NULL,
    sort_order INT DEFAULT 0,
    active BOOLEAN DEFAULT TRUE,
    INDEX idx_category (category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Beispiel-Fragen einfügen (optional)
INSERT INTO questions (id, category, text, sort_order) VALUES
(0, 'Verkehr & Mobilität', 'Volketswil braucht mehr Tempo-30-Zonen zum Schutz von Kindern und Anwohnenden', 0),
(1, 'Verkehr & Mobilität', 'Die Gemeinde soll aktiv Durchgangsverkehr reduzieren und verkehrsberuhigende Massnahmen umsetzen', 1),
(2, 'Verkehr & Mobilität', 'Das Velowegnetz in Volketswil muss deutlich ausgebaut und sicherer gestaltet werden', 2),
(3, 'Verkehr & Mobilität', 'Volketswil braucht mehr und bessere ÖV-Verbindungen mit höheren Taktfrequenzen', 3),
(4, 'Verkehr & Mobilität', 'Volketswil braucht einen Mobility Hub beim Bahnhof Schwerzenbach mit Sharing-Angeboten', 4),
(5, 'Wohnen & Siedlungsentwicklung', 'Volketswil muss aktiv bezahlbaren Wohnraum für Familien und Menschen mit tiefem Einkommen schaffen', 5),
(6, 'Wohnen & Siedlungsentwicklung', 'Die Gemeinde soll beim Verdichten den Erhalt von Grünräumen und Bäumen priorisieren', 6),
(7, 'Wohnen & Siedlungsentwicklung', 'Volketswil braucht mehr genossenschaftlichen und gemeinnützigen Wohnungsbau', 7),
(8, 'Bildung & Kinderbetreuung', 'Volketswil braucht mehr subventionierte Krippenplätze für alle Einkommensschichten', 8),
(9, 'Bildung & Kinderbetreuung', 'Die Gemeinde soll flächendeckende Tagesstrukturen mit Betreuung über Mittag anbieten', 9),
(10, 'Bildung & Kinderbetreuung', 'Volketswil muss mehr in die Schulsozialarbeit und psychosoziale Unterstützung investieren', 10);
-- Weitere Fragen können hier hinzugefügt werden

-- View für einfache Statistik-Abfragen
CREATE OR REPLACE VIEW response_statistics AS
SELECT 
    q.category,
    q.text as question_text,
    r.response_value,
    COUNT(*) as response_count,
    ROUND(COUNT(*) * 100.0 / (SELECT COUNT(DISTINCT participant_id) FROM responses), 2) as percentage
FROM responses r
JOIN questions q ON r.question_id = q.id
GROUP BY q.category, q.text, r.response_value
ORDER BY q.sort_order, r.response_value;

-- View für Teilnehmer-Übersicht
CREATE OR REPLACE VIEW participant_summary AS
SELECT 
    p.id,
    p.name,
    p.email,
    p.timestamp,
    COUNT(r.id) as total_responses,
    SUM(CASE WHEN r.response_value = 'sehr_wichtig' THEN 1 ELSE 0 END) as sehr_wichtig_count,
    SUM(CASE WHEN r.response_value = 'wichtig' THEN 1 ELSE 0 END) as wichtig_count,
    SUM(CASE WHEN r.response_value = 'unwichtig' THEN 1 ELSE 0 END) as unwichtig_count,
    SUM(CASE WHEN r.response_value = 'egal' THEN 1 ELSE 0 END) as egal_count
FROM participants p
LEFT JOIN responses r ON p.id = r.participant_id
GROUP BY p.id, p.name, p.email, p.timestamp
ORDER BY p.timestamp DESC;

-- Optimierung: Indizes für bessere Performance
ALTER TABLE responses ADD INDEX idx_participant_question (participant_id, question_id);
ALTER TABLE responses ADD INDEX idx_question_response (question_id, response_value);