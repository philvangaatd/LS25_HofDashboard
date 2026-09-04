# Changelog

## 5.5.1 - 2026-09-05

### Fehlerbehebungen
- Haupt-App bleibt auf dem Startscreen zuverlässig ausgeblendet; die neue Grid-Shell wird erst nach Auswahl eines Spielstands aktiviert.
- Cache-Busting für die v5.5.1-UI-Ressourcen ergänzt, damit WebView2 sicher die korrigierten Styles und Skripte lädt.
- System-Icon im Schnellzugriff des Startscreens rendert jetzt in der vorgesehenen Größe statt als übergroße schwarze SVG-Fläche.

## 5.5.0 - 2026-09-05

### Neu
- Komplette Haupt-App auf die Designsprache des neuen Startscreens umgestellt.
- Permanente linke Navigation für Übersicht, Felder, Fuhrpark, Tiere, Vorräte, Produktionen, Markt, Verträge, AutoDrive, Backups und System.
- Aktiver Bereich wird in der Sidebar deutlich hervorgehoben; AutoDrive-Bereiche bleiben ohne AutoDrive sichtbar, aber deaktiviert.
- Header, Toolbars, Eingabefelder, Statistikkarten, Listen, Tabellen, Modals und Systemkarten visuell vereinheitlicht.
- Responsive Sidebar reduziert sich bei kleineren Fenstern automatisch auf eine kompakte Icon-Navigation.
- Systemstatus bleibt dauerhaft sichtbar und System jederzeit erreichbar.
- Fehlendes/überdimensioniertes System-Icon im Schnellzugriff des Startscreens behoben.
- WebView2 lädt die neuen UI-Skripte mit Versions-Cache-Busting.

## 5.4.0 - 2026-09-05

### Neu
- Startscreen vollständig im Stil des Hof-Dashboards neu gestaltet: permanente Seitenleiste, große Spielstandkarten, Schnellzugriff und deutlichere visuelle Hierarchie.
- **System** ist jetzt bereits vor der Auswahl eines Spielstands vollständig erreichbar.
- LS25-Integration und Mod-Verwaltung können direkt vom Startscreen geöffnet und bedient werden.
- Schnellzugriff zeigt Mod-Status, Dashboard-Version, Zeitpunkt des letzten Spielstandscans und Anzahl gefundener Spielstände.
- Alle spielstandsabhängigen Bereiche sind schon in der Navigation sichtbar, bis zur Auswahl jedoch bewusst deaktiviert.
- Spielstände werden mit Karte, Hofname, Manager, Speicherdatum und klarer Primäraktion dargestellt.
- Eigener Systemscreen auf der Startseite kombiniert lokale Systemchecks mit der integrierten Mod-Verwaltung.
- Neuer responsiver Startscreen passt sich auch schmaleren Fenstern an.

## 5.3.1 - 2026-09-05

### Verbesserungen
- Update-Fenster vollständig an das visuelle Design des Hof-Dashboards angepasst.
- Eigene rahmenlose Titelleiste mit Hof-Dashboard-Branding statt klassischem Windows-Dialog.
- Versionsvergleich zeigt installierte und neue Version direkt nebeneinander.
- Statusbereich mit farbigem Zustandspunkt und eigener Fortschrittsanzeige für Download und Prüfung.
- Primär-, Sekundär- und Release-Notes-Aktionen verwenden jetzt dieselbe Farbwelt wie das Dashboard.
- Fehler werden direkt im Update-Fenster dargestellt; die bisherige Windows-MessageBox entfällt für Updatefehler.
- Sicherheits- und Integritätsprüfung werden verständlich im Dialog kommuniziert.

## 5.3.0 - 2026-09-04

### Neu
- Integrierte Mod-Verwaltung direkt im Windows-Dashboard: installieren, aktualisieren, reparieren und neu installieren ohne manuellen ZIP-Download.
- Automatische Erkennung des LS25-Modordners über den Windows-Dokumente-Pfad, inklusive umgeleiteter Dokumente und OneDrive; manueller Ordner als Fallback speicherbar.
- Installierte Mod-Version wird direkt aus `modDesc.xml` gelesen und mit der im Update-Manifest veröffentlichten Version verglichen.
- Mod-Downloads werden vor der Installation anhand von Dateigröße und SHA-256 geprüft.
- Atomarer Austausch der Mod-Datei mit Rückfall auf die bisherige Installation, falls die Nachprüfung fehlschlägt.
- Schutz vor Änderungen, solange Landwirtschafts-Simulator 25 läuft.
- Benutzerfreundlicher Status im System-Bereich sowie Hinweisbanner, wenn die Live-Verbindung fehlt, veraltet oder beschädigt ist.
- Release-Pipeline erweitert das Update-Manifest automatisch um verifizierte Metadaten des passenden Mod-Releases.

## 5.2.0 - 2026-09-04

### Neu
- Live-Übersicht **Vorräte** für Silos, Silo-Erweiterungen, Güllebehälter, Misthaufen sowie Ballen- und Palettenlager.
- Zwei Ansichten: **Nach Lager** und **Nach Produkt** mit hofweiter Aggregation.
- Anzeige von Füllmengen, verfügbaren Kapazitäten und ObjectStorage-Stückzahlen.
- Generische Unterstützung kompatibler Mod-Lager über die standardisierten GIANTS-Storage-Spezialisierungen.
- Passende Live-Mod v5.2.0 und automatisierte GitHub-Release-Pipelines für Mod und Windows-Dashboard.
