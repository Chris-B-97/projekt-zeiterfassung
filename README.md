# Projektzeit – Projekt- und Zeiterfassungsanwendung

Webbasierte Anwendung zur Verwaltung von Projekten, Tätigkeiten, Arbeitszeiten und Benutzerzugriffen.

Das Projekt wurde mit PHP, MySQL, HTML, Tailwind CSS und JavaScript umgesetzt. Es ermöglicht Benutzern, Projekte und Tätigkeiten zentral zu erfassen und auszuwerten. Über ein Rechtekonzept wird gesteuert, welche Benutzer Zugriff auf welche Projekte haben.

## Funktionen

* Registrierung und Login
* Projektverwaltung
* Tätigkeits- und Zeiterfassung pro Projekt
* Benutzer- und Rechteverwaltung
* Projektzuweisung über eine Zwischentabelle
* Suche und Filterung von Einträgen
* Auswertung nach Woche, Monat, Jahr oder freiem Zeitraum
* Rollenbasierter Zugriff für Benutzer und Administratoren

## Technologien

* PHP 8.1+
* MySQL / MariaDB
* HTML
* Tailwind CSS
* JavaScript
* PDO für Datenbankzugriffe

## Voraussetzungen

* PHP 8.1 oder höher
* MySQL 5.7+ oder MariaDB 10.3+
* Webserver, zum Beispiel Apache oder Nginx
* Document-Root auf den Ordner `public/`

## Installation

1. Datenbank erstellen und `install.sql` importieren:

```bash
mysql -u root -p < install.sql
```

2. Die Datei `config.example.php` nach `config.php` kopieren.

3. Datenbankzugang in `config.php` eintragen.

4. Den Webserver auf den Ordner `public/` zeigen lassen.

5. Im Browser die Registrierung öffnen:

```text
https://deine-domain/register.php
```

6. Ersten Benutzer anlegen und die Anwendung verwenden.

## Sicherheit

Das Projekt enthält mehrere grundlegende Schutzmaßnahmen:

* PDO Prepared Statements gegen SQL Injection
* Passwort-Hashing mit `password_hash`
* Passwortprüfung mit `password_verify`
* CSRF-Token in Formularen
* Output-Escaping mit `htmlspecialchars`
* Session-Cookies mit HttpOnly und SameSite=Lax
* Serverseitige Rechteprüfung für Projektzugriffe

## Datenbankstruktur

Die Anwendung nutzt unter anderem folgende Tabellen:

* `users` – Benutzer und Rollen
* `projects` – Projekte
* `entries` – Tätigkeiten und Zeiteinträge
* `project_users` – Zuordnung zwischen Benutzern und Projekten

Die Tabelle `project_users` bildet die m:n-Beziehung zwischen Benutzern und Projekten ab.

## Hinweis

Dieses Repository enthält eine neutrale Version des Projekts ohne unternehmensbezogene Logos, Namen oder vertrauliche Daten.
