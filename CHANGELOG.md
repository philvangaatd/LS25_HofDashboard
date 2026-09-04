# Changelog

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
