# Doku-Tool (PHP / MySQL)

Eigenständige PHP-Version des Doku-Tools. Leicht modular: Datenbank, Auth und
Security in `includes/`, Seiten in `public/` mit Logik + HTML.

## Voraussetzungen
- PHP 8.1+
- MySQL 5.7+ / MariaDB 10.3+
- Webserver (Apache/Nginx) mit Document-Root auf `public/`

## Installation
1. Datenbank anlegen, dann `install.sql` einspielen:
   ```bash
   mysql -u root -p < install.sql
   ```
2. `config.example.php` nach `config.php` kopieren und DB-Zugang eintragen.
3. Webserver auf den Ordner `public/` zeigen lassen.
4. `https://deine-domain/register.php` aufrufen und ersten Benutzer anlegen.

## Features
- Authentifizierung (Registrierung / Login, `password_hash`)
- Projekte: Anlegen, Bearbeiten, Löschen
- Einträge je Projekt: Titel, Beschreibung, Ursache, Maßnahme, Link, Kunde, Minuten
- **Eintragssuche** mit Volltext **und Datumsbereich (von–bis)**
- **Projekte-Übersicht**: Suche, Sortierung (letzte Aktivität / Erstellt / Name /
  Minuten / Einträge), Filter nach Kunde, Bearbeiter und Aktivitätszeitraum
- **Auswertung**: Tabs `?view=week|month|year` + freier Zeitraum,
  Gesamtsummen pro Projekt und pro Periode

## Sicherheit
- PDO Prepared Statements
- CSRF-Token in allen Formularen
- `password_hash` / `password_verify`
- Output-Escaping via `htmlspecialchars()`
- Session-Cookies HttpOnly + SameSite=Lax
