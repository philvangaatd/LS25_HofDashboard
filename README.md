# LS25 Hof-Dashboard

Lokales Windows-Dashboard für Landwirtschafts-Simulator 25. Es zeigt Live-Daten aus der zugehörigen `FS25_HofDashboard`-Mod und bietet Werkzeuge für Hofübersicht, Felder, Fuhrpark, Tierhaltung, Produktion, Vorräte, Marktpreise, Verträge und AutoDrive.

## Aktuelle Version

- Dashboard: `5.6.0`
- kompatible Mod: `FS25_HofDashboard` ab `5.3.0`
- Protokollversion: `1`
- Dashboard-Polling: `1 Sekunde` im Vordergrund
- Mod-Export: `2 Sekunden`

## Installation

1. Den aktuellen Windows-Release `HofDashboard-win-x64-v5.6.0.zip` herunterladen.
2. ZIP vollständig entpacken.
3. `HofDashboard.exe` starten.
4. Falls die LS25-Mod fehlt oder veraltet ist, direkt unter **System** die LS25-Integration automatisch installieren bzw. aktualisieren.

Das Dashboard erkennt den normalen LS25-Modordner automatisch und lädt die passende Mod selbst herunter. Ein separater manueller Mod-Download ist normalerweise nicht mehr nötig.

Falls der Modordner nicht automatisch gefunden wird, kann er unter **System → LS25-Integration → Technische Details** einmalig ausgewählt werden. Der Systembereich ist bereits verfügbar, bevor ein Spielstand ausgewählt wurde.

## Near-Live-Daten

Seit v5.6.0 prüft die App im Vordergrund einmal pro Sekunde, ob ein neuer Live-Export vorliegt. Die passende Live-Mod v5.3.0 schreibt den vollständigen Hofzustand alle zwei Sekunden. Dadurch erscheinen Änderungen praktisch unmittelbar im Dashboard, ohne den kompletten Datenbestand auf jedem Spiel-Frame neu zu sammeln und zu serialisieren.

Wenn das Dashboard-Fenster nicht sichtbar ist, wird das Polling automatisch auf fünf Sekunden reduziert. Parallele Poll-Anfragen werden verhindert.

## Einheitliches App-Design

Seit v5.5.0 verwendet die gesamte Anwendung dieselbe Designsprache wie der mit v5.4.0 eingeführte Startscreen. Nach der Spielstandauswahl bleibt eine permanente linke Navigation sichtbar. Darüber sind Übersicht, Felder, Fuhrpark, Tiere, Vorräte, Produktionen, Markt, Verträge, AutoDrive, Backups und System direkt erreichbar.

Seit v5.6.0 entfällt der große, redundante Hof-Header innerhalb der einzelnen Ansichten. Spielstandwechsel erfolgt über **Start**, Backups über **Backups** und der Live-/Mod-Status über **System**. Die kompakte Kontextzeile mit Kartenname bleibt erhalten.

Seit v5.5.2 kann die linke Navigation manuell auf eine kompakte reine Icon-Ansicht reduziert werden. Die Auswahl wird lokal gespeichert und gilt für Startscreen und Haupt-App. Im kompakten Zustand bleiben aktive Bereiche, Systemstatus und die Fußzeile mit `© LS25` und Versionsnummer sichtbar.

## Startscreen

Der Startscreen besitzt eine permanente Navigation, große Spielstandkarten und Schnellzugriff auf wichtige Zustände. Spielstandsabhängige Bereiche werden bereits angezeigt, bleiben aber bis zur Auswahl eines Spielstands deaktiviert. **System** und die **LS25-Integration** sind von Anfang an nutzbar.

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

Verfügbare Dashboard-Updates werden beim Start automatisch angeboten. Das Update-Fenster verwendet ebenfalls die Designsprache des Dashboards und zeigt installierte sowie neue Version, Downloadstatus und Integritätsprüfung direkt im Dialog.

## Releases

- Dashboard: https://github.com/philvangaatd/LS25_HofDashboard/releases
- Mod: https://github.com/philvangaatd/LS25_HofDashboardMod/releases

## Hinweis

Das Dashboard ist eine lokale Anwendung. Savegames, Einstellungen, Kartenbilder und Backups bleiben lokal auf dem Rechner.