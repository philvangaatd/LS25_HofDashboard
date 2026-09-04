# LS25 Hof-Dashboard

Lokales Windows-Dashboard für Landwirtschafts-Simulator 25. Es zeigt Live-Daten aus der zugehörigen `FS25_HofDashboard`-Mod und bietet Werkzeuge für Hofübersicht, Felder, Fuhrpark, Tierhaltung, Produktion, Vorräte, Marktpreise, Verträge und AutoDrive.

## Aktuelle Version

- Dashboard: `5.4.0`
- kompatible Mod: `FS25_HofDashboard` ab `5.2.0`
- Protokollversion: `1`

## Installation

1. Den aktuellen Windows-Release `HofDashboard-win-x64-v5.4.0.zip` herunterladen.
2. ZIP vollständig entpacken.
3. `HofDashboard.exe` starten.
4. Falls die LS25-Mod noch fehlt, direkt auf dem Startscreen **System** öffnen und die LS25-Integration automatisch einrichten.

Das Dashboard erkennt den normalen LS25-Modordner automatisch und lädt die passende Mod selbst herunter. Ein separater manueller Mod-Download ist normalerweise nicht mehr nötig.

Falls der Modordner nicht automatisch gefunden wird, kann er unter **System → LS25-Integration → Technische Details** einmalig ausgewählt werden. Der Systembereich ist bereits verfügbar, bevor ein Spielstand ausgewählt wurde.

## Startscreen

Seit v5.4.0 besitzt das Dashboard einen neu gestalteten Startscreen mit permanenter Navigation, großen Spielstandkarten und Schnellzugriff auf wichtige Zustände. Spielstandsabhängige Bereiche werden bereits angezeigt, bleiben aber bis zur Auswahl eines Spielstands deaktiviert. **System** und die **LS25-Integration** sind von Anfang an nutzbar.

Der Schnellzugriff zeigt unter anderem:

- Mod-Status und installierte Mod-Version
- Dashboard-Version
- Zeitpunkt des letzten Spielstandscans
- Anzahl gefundener Spielstände

## Mod-Verwaltung

Das Dashboard kann die zugehörige FS25-Mod direkt verwalten:

- fehlende Mod automatisch installieren
- verfügbare Mod-Version erkennen
- Mod aktualisieren
- beschädigte Installation reparieren
- Mod neu installieren
- Mod-Ordner öffnen oder manuell auswählen

Vor jeder Installation werden Dateigröße und SHA-256 des veröffentlichten Mod-Pakets geprüft. Läuft Landwirtschafts-Simulator 25 gerade, wird die Mod nicht überschrieben.

## Updates

Verfügbare Dashboard-Updates werden beim Start automatisch angeboten. Seit v5.3.1 verwendet auch das Update-Fenster die Designsprache des Dashboards und zeigt installierte sowie neue Version, Downloadstatus und Integritätsprüfung direkt im Dialog.

## Releases

- Dashboard: https://github.com/philvangaatd/LS25_HofDashboard/releases
- Mod: https://github.com/philvangaatd/LS25_HofDashboardMod/releases

## Hinweis

Das Dashboard ist eine lokale Anwendung. Savegames, Einstellungen, Kartenbilder und Backups bleiben lokal auf dem Rechner.
