## Migration auf v5

Seit v5 heißt der Live-Connector **FS25_HofDashboard** und schreibt nach:

```text
modSettings/LS25HofDashboard/liveData.json
```

Das Dashboard bevorzugt diesen neuen Pfad. Für die Übergangsphase kann es eine bereits vorhandene v4-Datei aus dem alten Settings-Verzeichnis weiterhin lesen. Sobald v5 einmal erfolgreich exportiert hat, wird automatisch der neue Pfad verwendet.

# LS25 Hof-Dashboard

Lokales Web-Dashboard für **Farming Simulator 25** mit Live-Daten aus dem laufenden Spiel, AutoDrive-Verwaltung und Werkzeugen für den aktuellen Spielstand.

Das Projekt verfolgt eine klare Datenarchitektur:

```text
Farming Simulator 25
        │
        ▼
FS25_HofDashboard (Lua-Mod)
        │
        ▼
liveData.json
        │
        ▼
PHP API
        │
        ▼
Browser-Frontend
```

Der Lua-Mod ist für Live-Zustände die autoritative Quelle. PHP normalisiert und validiert die Daten für das Frontend, erfindet aber keine zweite FS25-Spiellogik.

Zusätzlich liest und bearbeitet das Dashboard gezielt Spielstand-Dateien dort, wo Live-Daten nicht benötigt werden – insbesondere für **AutoDrive-Marker, Routen und Backups**.

---

## Funktionen

### 🏠 Übersicht

Zentrale Hofübersicht mit wichtigen Kennzahlen und Schnellzugriffen, unter anderem:

- Hofname und Kontostand
- Karte und aktiver Spielstand
- eigene Felder und deren aktueller Zustand
- erntereife Felder
- Fuhrpark
- Wartungs- und Waschbedarf
- Live-Status des Export-Mods

---

### 🌾 Felder – live

Die Felder werden aus dem laufenden Spiel ausgewertet.

Unterstützt werden unter anderem:

- Feldnummer und Besitz
- Fruchtart
- Wachstumsstufe
- Erntereife
- abgeerntete Bereiche
- gepflügte, gegrubberte und anderweitig bearbeitete Bereiche
- Mischzustände auf teilweise bearbeiteten Feldern
- Unkraut
- Kalk
- Düngung / Spray-Level
- Steine
- Pflugstatus
- Walzenstatus
- weitere von FS25 bereitgestellte Feldzustände

Die Feldanalyse basiert nicht nur auf einem einzelnen Punkt in der Feldmitte. Der Mod verteilt Messpunkte über das tatsächliche Feldpolygon und kann dadurch beispielsweise ein Feld erkennen, das teilweise abgeerntet und teilweise bereits gepflügt wurde.

---

### 🚜 Fuhrpark – live

Fahrzeuge, Anhänger und Anbaugeräte werden direkt aus den laufenden FS25-Fahrzeugobjekten gelesen.

Pro Eintrag können unter anderem angezeigt werden:

- Kategorie: Fahrzeug / Anhänger / Anbaugerät
- Marke
- Modell
- Anzeigename
- Betriebsstunden
- Shoppreis der aktuellen Konfiguration
- Verschleiß
- Dreck
- Diesel
- AdBlue
- weitere unterstützte Kraftstoffe
- Füllstand aller relevanten FillUnits
- Inhalt von Anhängern und Geräten
- Kapazität und Prozentfüllstand

Beispiele:

- Anhänger mit Weizen und aktuellem Füllstand
- Sämaschine mit Saatgut
- Düngerstreuer mit Mineraldünger
- Traktor mit Diesel und AdBlue
- Mähdrescher mit Korntank, Diesel und AdBlue

Der Tab ist filter- und sortierbar und unterscheidet Fahrzeuge, Anhänger und Anbaugeräte.

---

### 🐄 Tiere und Tierhaltungen – live

Tierhaltungen werden über die geladenen FS25-Placeables und Husbandry-Systeme ausgelesen.

Geplant bzw. im Live-Datenvertrag berücksichtigt sind unter anderem:

- Stall / Gehege
- Tierart
- aktuelle Tierzahl
- maximale Kapazität
- freie Plätze
- Rasse
- Alter in Monaten
- Anzahl je Rasse und Altersgruppe
- Gesundheit
- Reproduktion
- Trächtigkeit / Elterntier, sofern FS25 diese Information bereitstellt
- Produktivität
- Futter
- Futtergruppen
- Wasser
- Stroh
- Weide
- Mist
- Gülle
- Milch und andere flüssige Tierprodukte
- Wolle, Eier und weitere Palettenprodukte

Bienen werden separat über das Beehive-System ausgewertet. Dazu gehören unter anderem:

- Anzahl eigener Bienenstöcke
- aktive Bienenstöcke
- Honigproduktion
- wartender Honig am Palettenspawner
- fertige Honigpaletten
- Honigmenge auf Paletten

> Hinweis: Tierhaltungen und Produktionen können durch Mods eigene Spezialisierungen verwenden. Das Dashboard orientiert sich an den normalen GIANTS-FS25-Systemen und ist für standardskonforme Basegame- und Mod-Placeables ausgelegt.

---

### 🏭 Produktionen – live

Produktionsgebäude werden aus den laufenden Placeables ausgelesen.

Ziel ist, Produktionszustände direkt aus FS25 zu übernehmen, statt sie aus gespeicherten XML-Werten nachzubauen.

---

### 💰 Markt – echte Live-Verkaufspreise

Die Marktansicht zeigt nicht nur einen theoretischen Basispreis, sondern die **effektiven Verkaufspreise der aktuell im Spiel vorhandenen Verkaufsstationen**.

Pro Ware werden unter anderem angezeigt:

- bester aktuell erzielbarer Preis
- beste Verkaufsstation
- alle verfügbaren Verkaufsstationen
- jeweiliger Preis pro 1.000 Liter
- niedrigster Preis
- Preisspanne zwischen den Stationen

Sortierungen:

- empfohlen
- Bestpreis hoch → niedrig
- Bestpreis niedrig → hoch
- Name A → Z
- meiste Verkaufsstationen
- größte Preisspanne

Zusätzlich gibt es einen **Preis-Alarm**. Der Alarm wird gegen den aktuell besten tatsächlich erzielbaren Stationspreis geprüft.

Die Suche berücksichtigt sowohl Waren als auch Verkaufsstationen.

---

### 🤝 Verträge

Anzeige der im Spiel verfügbaren bzw. aktiven Verträge mit den von FS25 bereitgestellten Informationen, beispielsweise:

- Vertragstyp
- Feld
- Fortschritt
- Belohnung, sofern verfügbar
- Aktivstatus

Einzelne Werte werden von FS25 erst zur Laufzeit berechnet und können je nach Vertragstyp unterschiedlich vollständig sein.

---

### 📋 AutoDrive-Marker

Dieser Bereich ist nur sichtbar, wenn der ausgewählte Spielstand AutoDrive-Daten enthält.

Funktionen:

- Marker nach Gruppen anzeigen
- Gruppen ein- und ausklappen
- Marker umbenennen
- Gruppen ändern
- Mehrfachauswahl
- Marker löschen
- Gruppen auflösen
- JSON-Import und -Export
- Änderungen sicher speichern

---

### 🗺️ AutoDrive-Karte und Routen-Editor

Visueller Editor für das AutoDrive-Wegpunktnetz.

Unterstützt werden unter anderem:

- komplettes Wegpunktnetz anzeigen
- zoomen und verschieben
- zu Markern springen
- Route zeichnen
- an bestehende Punkte andocken
- Wegpunkte verschieben
- Verbindungen trennen
- Wegpunkte löschen
- Wegpunkt als Marker anlegen
- Rückgängig-Funktion
- getrennte Netzbereiche prüfen
- optionales Karten-Hintergrundbild
- manuelle Bildausrichtung

Änderungen werden erst mit **Route speichern** in die AutoDrive-Datei geschrieben.

---

### 💾 Backups

Vor schreibenden AutoDrive-Operationen werden Sicherungen angelegt.

Unterstützt werden:

- automatische Backups
- Backup-Liste
- Wiederherstellung
- Begrenzung der Anzahl alter Sicherungen

Backups liegen im lokalen Ordner:

```text
backups/
```

---

### 🧰 System

Der System-Tab hilft bei der Diagnose der lokalen PHP-Umgebung, Pfade und benötigten Komponenten.

---

## Welche Daten sind live?

| Bereich | Quelle |
|---|---|
| Felder | Lua-Mod / `liveData.json` |
| Fuhrpark | Lua-Mod / `liveData.json` |
| Tiere / Tierhaltungen | Lua-Mod / `liveData.json` |
| Bienen | Lua-Mod / `liveData.json` |
| Produktionen | Lua-Mod / `liveData.json` |
| Marktpreise | Lua-Mod / echte FS25-Verkaufsstationen |
| Verträge | FS25-Laufzeitdaten bzw. Spielstand, abhängig vom verfügbaren Wert |
| AutoDrive-Marker | `AutoDrive_config.xml` |
| AutoDrive-Routen | `AutoDrive_config.xml` |
| Karten-Hintergrund | lokales Dashboard-Asset |
| Backups | lokaler Dashboard-Ordner |

Der Live-Export wird standardmäßig alle **15 Sekunden** aktualisiert.

---

## Vorbereitung für Windows-App und Updates

Der aktuelle Browserbetrieb bleibt unverändert. Zusätzlich besitzt das Projekt jetzt
stabile Schnittstellen für den späteren Windows-Launcher:

- `app-manifest.json` ist die lokale, maschinenlesbare Quelle für Dashboard-Version,
  Datenlayout und unterstütztes Mod-Protokoll.
- `health.php` liefert einen vom Spielstand unabhängigen Healthcheck für den Launcher.
- `HOF_DASHBOARD_DATA_DIR` kann vom Launcher auf einen beschreibbaren Benutzerordner
  gesetzt werden. Ohne die Variable liegen Backups weiterhin wie bisher im Projektordner.
- Die Live-Daten enthalten eine `protocolVersion`; das Dashboard meldet dazu einen
  Kompatibilitätsstatus, ohne bestehende API-Felder zu verändern.

Das eigentliche automatische Update und der Installer folgen in einem späteren Schritt.
Der vorgesehene Remote-Vertrag ist in `docs/update-manifest.schema.json` dokumentiert.

---

## Voraussetzungen

### Farming Simulator 25

Das Dashboard ist für die PC-Version von Farming Simulator 25 ausgelegt.

### PHP

Benötigt wird eine lokale PHP-Installation mit CLI-Unterstützung.

Für den Upload bzw. die Verarbeitung von Karten-Hintergrundbildern wird zusätzlich die PHP-Erweiterung **GD** benötigt.

Prüfen:

```powershell
php -v
php -m
```

### Live-Export-Mod

Für die Live-Bereiche wird der separate Mod **FS25_HofDashboard** benötigt.

Der Mod schreibt seine Daten nach:

```text
<My Games>/FarmingSimulator2025/modSettings/LS25HofDashboard/liveData.json
```

### AutoDrive

AutoDrive ist **nicht** für die allgemeinen Live-Dashboard-Funktionen erforderlich.

Nur die Bereiche **Marker** und **Karte / Routen-Editor** benötigen eine AutoDrive-Konfiguration im ausgewählten Spielstand.

---

## Installation

### 1. Repository klonen

```powershell
git clone https://github.com/philvangaatd/LS25_HofDashboard.git
cd LS25_HofDashboard
```

Oder einen bereits vorhandenen lokalen Checkout aktualisieren:

```powershell
git pull
```

### 2. FS25-Basisordner prüfen

Das Dashboard versucht standardmäßig folgenden Pfad zu verwenden:

```text
%USERPROFILE%\Documents\My Games\FarmingSimulator2025
```

Falls dein Ordner an einer anderen Stelle liegt – beispielsweise durch OneDrive oder eine manuelle Verlagerung – kannst du ihn in `config.php` setzen:

```php
define('FS_BASE_DIR_OVERRIDE', 'D:\\Spiele\\FarmingSimulator2025');
```

### 3. Optional: FS25-Installationsordner

Für Funktionen, die auf Dateien offizieller Karten zugreifen müssen, versucht das Tool übliche Steam-Pfade automatisch zu erkennen.

Bei Bedarf kann der Pfad ebenfalls in `config.php` überschrieben werden.

### 4. Server starten

Im Projektordner:

```powershell
php -S localhost:8000
```

Dann im Browser öffnen:

```text
http://localhost:8000
```

---

## Spielstand auswählen

Beim Start bzw. über **Spielstand wechseln** zeigt das Dashboard die gefundenen Spielstände an.

Ein Spielstand ohne AutoDrive-Konfiguration kann trotzdem für die normalen Dashboard-Funktionen verwendet werden. Marker und Karteneditor werden in diesem Fall ausgeblendet.

---

## Live-Datenfluss

Der gewünschte Architektur-Grundsatz des Projekts lautet:

```text
Lua -> liveData.json -> PHP -> Frontend
```

### Lua

Der Mod spricht direkt mit den FS25-Systemen und sammelt den aktuellen Zustand.

### `liveData.json`

Transportiert die bereits ermittelten Live-Daten in einem lokalen JSON-Format.

### PHP

PHP übernimmt unter anderem:

- Datei lesen
- Daten validieren
- Werte normalisieren
- auf den aktuellen Hof filtern
- API-Antworten für das Frontend erzeugen

PHP soll keine konkurrierende Interpretation der FS25-Spiellogik aufbauen.

### Frontend

Das Frontend ist für Darstellung, Filterung, Sortierung und Interaktion zuständig.

---

## Map-Kompatibilität

Das Dashboard ist **map-agnostisch** konzipiert.

Es verwendet die von FS25 zur Laufzeit registrierten Systeme statt feste Listen für bestimmte Karten. Dadurch funktionieren grundsätzlich auch Mod-Maps mit beispielsweise:

- eigenen Feldern
- zusätzlichen Fruchtarten
- eigenen FillTypes
- zusätzlichen Verkaufsstellen
- eigenen Produktionen
- eigenen Tierhaltungen

Die praktische Voraussetzung ist, dass die Map bzw. der verwendete Mod die normalen GIANTS-FS25-Systeme und Registries verwendet.

Eine pauschale Garantie für jede beliebige Mod-Map kann es nicht geben. Komplett selbst entwickelte Systeme, die die üblichen FS25-APIs umgehen, können zusätzliche Adapter benötigen.

---

## Mod-Kompatibilität

Dasselbe Prinzip gilt für Fahrzeuge und Placeables.

Standardskonforme Mod-Fahrzeuge und Geräte können automatisch über ihre laufenden Vehicle-Objekte ausgewertet werden. Dadurch funktionieren auch zusätzliche:

- Marken
- Fahrzeuge
- Anhänger
- Geräte
- FillTypes
- Kraftstoffarten

Spezielle Mods können eigene Logik verwenden, die nicht über die üblichen FS25-Schnittstellen erreichbar ist. Für solche Fälle enthält der Live-Export Diagnosedaten, damit fehlende Objekte gezielt untersucht werden können.

---

## Datensicherheit

Der Live-Mod liest den Spielzustand und schreibt ausschließlich seine eigene `liveData.json`.

Das Dashboard verändert den Spielstand nur bei ausdrücklich ausgelösten schreibenden Funktionen, insbesondere im AutoDrive-Bereich.

Sicherheitsmechanismen umfassen unter anderem:

- Dirty-Tracking für ungespeicherte Änderungen
- Warnungen vor dem Verlassen bearbeiteter Ansichten
- Validierung von Marker- und Wegpunktdaten
- Schutz referenzierter Marker-Wegpunkte
- automatische Backups
- eingeschränkte und validierte Dateinamen
- kontrollierte Bild-Uploads

---

## Projektstruktur

```text
LS25_HofDashboard/
├─ api.php          # PHP-API und Dateizugriffe
├─ app-manifest.json # lokale Versions- und Protokollinformationen
├─ config.php       # Pfade und lokale Konfiguration
├─ health.php       # Healthcheck für den späteren Windows-Launcher
├─ version.php      # validierter Zugriff auf das App-Manifest
├─ index.html       # Dashboard-Frontend
├─ assets/          # Kartenbilder und weitere Assets
├─ backups/         # lokale Sicherungen
└─ README.md
```

Der Live-Mod befindet sich in einem separaten Repository.

---

## Fehlerdiagnose

### Dashboard zeigt keine Live-Daten

Prüfen:

1. Ist der Live-Mod im aktuellen Spielstand aktiviert?
2. Läuft der Spielstand bereits vollständig?
3. Existiert `modSettings/LS25HofDashboard/liveData.json`?
4. Wird die Datei etwa alle 15 Sekunden aktualisiert?
5. Zeigt der System-Tab den korrekten FS25-Basisordner?

### `liveData.json` ist leer oder unvollständig

Zusätzlich die FS25-Datei `log.txt` prüfen.

Der Mod exportiert für mehrere Bereiche Diagnosedaten, beispielsweise für Fahrzeuge und Tierhaltungen. Dadurch lässt sich unterscheiden, ob ein Objekt:

- gar nicht von FS25 registriert wurde,
- gefunden, aber übersprungen wurde,
- oder bei der Verarbeitung einen Fehler ausgelöst hat.

### Spielstand-Ordner wird nicht gefunden

`FS_BASE_DIR_OVERRIDE` in `config.php` setzen.

### Marker / Karte fehlen

Der ausgewählte Spielstand besitzt wahrscheinlich keine AutoDrive-Konfiguration. Die Live-Funktionen des Dashboards können trotzdem verwendet werden.

---

## Entwicklungsstatus

Das Projekt wird aktiv weiterentwickelt. Die Architektur wird schrittweise so vereinheitlicht, dass Live-Zustände möglichst ausschließlich über den Mod geliefert werden und alte bzw. doppelte Interpretationen in PHP entfernt werden.

Aktueller Schwerpunkt:

- robuste Live-Erkennung über unterschiedliche Basegame- und Mod-Maps
- vollständige Tier- und Produktionsdaten
- saubere Diagnose exotischer Mod-Fahrzeuge und Placeables
- weitere Bereinigung alter Savegame-Fallbacks, sobald der jeweilige Live-Datenpfad verifiziert ist
