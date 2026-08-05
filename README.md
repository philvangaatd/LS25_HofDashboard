# AutoDrive Flurkarte – Hof-Dashboard & Routen-Editor

Web-Tool für Farming Simulator 25: Hof-Übersicht, Feld-Dashboard, Fuhrpark, Marktpreise,
Vertrags-Feed sowie Verwaltung deiner AutoDrive-Marker und visuelles Bearbeiten des
Wegpunkt-Netzes – ohne das fummelige In-Game-Menü.

## Voraussetzung
PHP muss installiert sein (Windows: z. B. über [XAMPP](https://www.apachefriends.org/) oder
den PHP-Download von [windows.php.net](https://windows.php.net/download/) – reicht die
CLI-Version, im PATH eingetragen). Für den Hintergrundbild-Upload im Karte-Tab wird
zusätzlich die PHP-Erweiterung **GD** benötigt (in den meisten PHP-Distributionen,
inkl. XAMPP, standardmäßig aktiv).

## Setup
1. Ordner liegt bereits unter `C:\Projekte\LS25\AutoDrive`.
2. **Wichtig:** LS25 vorher beenden bzw. sicherstellen, dass der Spielstand nicht gerade
   gespeichert wird, während du hier speicherst.
3. Der Basis-Ordner mit allen Spielständen wird automatisch erkannt
   (`%USERPROFILE%\Documents\My Games\FarmingSimulator2025`).

## Starten
```
cd C:\Projekte\LS25\AutoDrive
php -S localhost:8000
```
Dann im Browser: http://localhost:8000

Beim ersten Start (bzw. nach "⇄ Spielstand wechseln") erscheint eine Auswahlmaske mit
allen gefundenen Spielständen (Hofname, Karte, letztes Speicherdatum). Spielstände ohne
AutoDrive-Daten sind weiterhin auswählbar (Hof-Übersicht, Felder, Fuhrpark, Markt und
Verträge funktionieren auch ohne AutoDrive), zeigen aber den Hinweis "Kein AutoDrive" –
die Tabs "Marker" und "Karte" bleiben dann ausgeblendet. Die Auswahl wird für die
laufende Sitzung gemerkt.

## Funktionen

### Tab "🏠 Übersicht"
Startseite mit den wichtigsten Kennzahlen auf einen Blick: Kontostand, Kredit, Spieltag/
Jahreszeit, Spielzeit, erntereife Felder und Fuhrpark-Anzahl. Aktualisiert sich automatisch
alle 30 Sekunden. Darunter ein Schnellzugriff-Bereich, der direkt auf Handlungsbedarf
hinweist (erntereife Felder, Fahrzeuge mit Wartungs-/Waschbedarf, heute ablaufende
Verträge) – ein Klick springt in den passenden Tab.

### Tab "🌾 Felder"
Alle eigenen Felder als Karten mit Fruchtart, Bodenzustand, Wachstumsstufe, Unkraut-,
Kalk- und Pflug-Level. Erntereife Felder sind hervorgehoben. Pro Feld eine
Checklisten-Vorschlagsliste der nächsten Arbeitsschritte (Pflügen → Kalken → Säen →
Düngen/Spritzen → Unkraut entfernen), abhakbar und lokal im Browser gemerkt. Filterbar
nach Fruchtart oder Feldnummer.

### Tab "📋 Marker" *(nur mit AutoDrive)*
- Alle Marker mit Wegpunkt-ID, Name und Gruppe, gruppiert wie Flurstücke/Parzellen
- Gruppen ein-/ausklappen, umbenennen, auflösen; Filtern nach Name/Gruppe
- Mehrfachauswahl: mehrere Marker auf einmal einer Gruppe zuweisen oder löschen
- Export/Import als JSON, Backup-Verwaltung mit Wiederherstellen-Funktion

### Tab "🗺️ Karte" *(nur mit AutoDrive)*
Zeigt das komplette AutoDrive-Wegpunktnetz als Karte (Scrollen = Zoom, Ziehen = Verschieben,
Dropdown = zu einem Marker springen). Vier Modi über den Schalter oben:

- **👁 Ansehen**: nur Betrachten, keine Bearbeitung möglich
- **✏️ Route zeichnen**: Klick auf leere Fläche setzt einen neuen Wegpunkt und verbindet
  ihn automatisch mit dem zuletzt gesetzten Punkt (fortlaufende Kette). Klick auf einen
  bestehenden Punkt dockt die Kette dort an. Liegt der Klick nah genug an einem
  bestehenden Punkt (Einrasten/Snap), wird kein neuer Punkt erzeugt, sondern direkt
  angedockt. Bestehende Punkte lassen sich per Ziehen verschieben. `Esc` beendet die
  aktuelle Kette.
- **🔗✕ Trennen**: Zwei verbundene Punkte nacheinander anklicken entfernt nur die
  Verbindung zwischen ihnen – beide Punkte bleiben erhalten. Danach direkt weiterklicken
  für weitere Trennungen vom selben Punkt aus.
- **🗑️ Löschen**: Klick auf einen Wegpunkt löscht ihn samt aller Verbindungen

Weitere Werkzeuge in der Toolbar bzw. unter "⋯ Mehr":
- **↺ Rückgängig** (auch per Strg+Z): macht den letzten Bearbeitungsschritt rückgängig,
  bis zu 50 Schritte zurück, innerhalb der laufenden Sitzung
- **📍 Als Marker anlegen**: verwandelt den zuletzt angeklickten Wegpunkt direkt in einen
  neuen Marker (Name wird abgefragt) – landet im Marker-Tab, muss dort noch gespeichert werden
- **🔍 Stränge prüfen**: prüft, ob die gesamte Route ein zusammenhängendes Netz bildet.
  Findet isolierte/abgetrennte Abschnitte und markiert sie rot auf der Karte
- **🖼️ Hintergrundbild hochladen**: legt einen Ingame-Screenshot (PNG/JPEG/WEBP, max.
  25 MB) als Kartenhintergrund für den aktuellen Spielstand ab. Große Bilder werden
  automatisch auf max. 2048 px Kantenlänge herunterskaliert. Das Bild wird unter
  `assets/terrain_<spielstand>.png` gespeichert und ersetzt ein zuvor hochgeladenes Bild.
- **🗑️ Hintergrundbild entfernen**: löscht das Hintergrundbild für den aktuellen
  Spielstand wieder
- **Ausrichtung: Normal/Z gespiegelt/X gespiegelt/X-Z vertauscht**: passt Spiegelung und
  Rotation des Hintergrundbilds an, falls die Bildorientierung nicht zur Kartenausrichtung
  passt
- **Bild-Ausrichtung (manuell)** – Versatz X, Versatz Z (in Metern) und Skalierung: feine
  Nachjustierung, falls der Screenshot nicht exakt auf die Wegpunkt-Bounding-Box passt
  (z. B. weil der Screenshot nicht die komplette Kartengröße zeigt). Wird je Spielstand
  im Browser gemerkt; über "↺ Ausrichtung zurücksetzen" wieder auf Standard (0/0/1)
  zurücksetzbar.

Änderungen an der Route werden erst mit **"💾 Route speichern"** in die Spielstand-Datei
geschrieben. Bis dahin lässt sich alles gefahrlos ausprobieren (Tab wechseln/Browser
schließen warnt bei ungespeicherten Änderungen).

### Tab "🚜 Fuhrpark"
Alle Fahrzeuge, Anhänger und Anbaugeräte als Karten mit Verschleiß- und Dreck-Anzeige
(Balken, farblich gestuft), Wert und Betriebsstunden. Sortierbar nach Verschleiß, Dreck,
Betriebsstunden, Wert oder Name; filterbar nach Marke/Modell. Fahrzeuge mit Verschleiß
oder Dreck über 50 % sind rot hervorgehoben.

### Tab "💰 Markt"
Aktuelle Marktpreise je Kultur für die laufende Saisonperiode, inkl. Trendpfeil, Jahres-
Preisspanne und bestem Verkaufszeitpunkt. Eigene angebaute Kulturen sind markiert und
werden zuerst angezeigt. Filterbar nach Kultur.

### Tab "🤝 Verträge"
Alle aktiven Verträge mit Typ, betroffenem Feld, verbleibenden Tagen und – sofern vom
Spiel bereits berechnet – Belohnung. Heute ablaufende Verträge sind hervorgehoben.

## Datensicherheit
- **Dirty-Tracking** für Marker UND Route: Warnung beim Spielstand-Wechsel, Tab-Wechsel,
  Neu-Laden oder Schließen des Browser-Tabs
- **Marker-Schutz**: Ein Wegpunkt, der von einem Marker referenziert wird, kann nicht
  gelöscht werden – die Route-Speicherung wird mit klarer Fehlermeldung abgelehnt
- **Automatisches Backup** vor jedem Speichern und jeder Wiederherstellung, im
  Unterordner `backups/` (max. 20 pro Spielstand, ältere werden automatisch gelöscht,
  kollisionssicher durch Millisekunden-Zeitstempel)
- **Backup-Verwaltung** über den Button "🕓 Backups": Liste aller Sicherungen mit
  Datum/Größe, einzelne Stände per Klick wiederherstellen

## Sicherheit
- Jeder Speichervorgang validiert Daten serverseitig (Wegpunkt-IDs, Marker-Referenzen)
- Beim Marker-Speichern wird nur der `<mapmarker>`-Block neu geschrieben; beim
  Routen-Speichern nur der `<waypoints>`-Block – nichts anderes wird angefasst
- `incoming`-Verbindungen werden beim Speichern automatisch aus `out` neu berechnet,
  das verhindert inkonsistente Daten
- Backup-Dateinamen werden serverseitig streng geprüft (kein Path-Traversal möglich)
- Hintergrundbild-Uploads werden serverseitig auf Dateityp (nur PNG/JPEG/WEBP) und
  Größe (max. 25 MB) geprüft, per GD neu kodiert (kein Durchreichen fremden Codes) und
  ausschließlich unter einem aus dem Spielstand-Namen abgeleiteten Dateinamen in
  `assets/` abgelegt
- Falls etwas schiefgeht: über "🕓 Backups" den letzten Stand zurückspielen, oder
  manuell die neueste Datei aus `backups/` nach `AutoDrive_config.xml` kopieren

## Bekannte Einschränkungen
- Vertragsbelohnungen werden im Spielstand teils erst zur Laufzeit vom Spiel berechnet
  und sind dann im Verträge-Tab nicht sichtbar (kein Fehler, sondern Spielverhalten)
- Die Feld-Arbeitsschritte im Felder-Tab sind eine grobe, verallgemeinerte Annäherung
  anhand der gespeicherten Werte, keine exakte Simulation der Spiellogik
