# Windows-Launcher-Prototyp

Der Launcher verpackt das bestehende PHP-Dashboard als lokale Windows-Anwendung.
Er ersetzt weder die PHP-Anwendung noch die LS25-Mod, sondern verwaltet ihren Start
und ihre Laufzeit.

## Paketaufbau

```text
HofDashboard-prototype-win-x64/
├─ HofDashboard.exe
├─ launcher-manifest.json
├─ runtime/
│  ├─ php.exe
│  └─ php.ini
└─ web/
   ├─ index.html
   ├─ api.php
   ├─ health.php
   └─ app-manifest.json
```

Die .NET-Laufzeit wird selbstenthalten veröffentlicht. PHP 8.5 x64 NTS wird aus
dem offiziellen Windows-Archiv geladen und vor dem Paketbau per SHA-256 geprüft.
WebView2 läuft im Evergreen-Modus, damit Windows Sicherheitsupdates für die
Browser-Engine zentral einspielen kann.

## Startablauf

1. Der Launcher verhindert eine zweite parallele Instanz.
2. Er legt die veränderlichen Benutzerordner unter `%LOCALAPPDATA%\HofDashboard` an.
3. Er wählt einen freien lokalen Port.
4. Er startet das mitgelieferte PHP ausschließlich auf `127.0.0.1`.
5. Er setzt `HOF_DASHBOARD_DATA_DIR` und die PHP-Verzeichnisse für Sessions,
   temporäre Uploads und Protokolle.
6. Er wartet auf einen erfolgreichen Aufruf von `health.php`.
7. Er öffnet die lokale URL in einem WebView2-Fenster.
8. Beim Schließen beendet er den gesamten eingebetteten PHP-Prozessbaum.

Navigation innerhalb der lokalen Dashboard-Adresse bleibt im App-Fenster. Externe
HTTP- und HTTPS-Links werden an den Windows-Standardbrowser übergeben; andere
Protokolle werden blockiert.

## Veränderliche Daten

```text
%LOCALAPPDATA%\HofDashboard\
├─ data\
│  ├─ assets\
│  ├─ backups\
│  └─ settings\
│     └─ savegameN.json
├─ logs\
├─ sessions\
├─ temp\
└─ webview\
```

Damit können `web/`, `runtime/` und der Launcher später versionsweise ausgetauscht
werden, ohne Backups, Kartenbilder, spielstandsbezogene Einstellungen oder die
WebView2-Sitzung des Benutzers zu löschen. Karten-Ausrichtung, Preisalarme und
abgehakte Feldaufgaben werden je `savegameN` getrennt gespeichert.

## Build und Test

Auf einem Windows-System mit dem .NET-10-SDK:

```powershell
./launcher/scripts/build-prototype.ps1
```

Das Skript erstellt `dist/HofDashboard-prototype-win-x64.zip`. Der CI-Smoke-Test
startet anschließend die fertig gepackte EXE mit `--smoke-test`, prüft PHP und
`health.php` und beendet den Server wieder. Ein echter Fenster-Test bleibt vor dem
Merge zusätzlich erforderlich.

## Noch nicht Bestandteil des Prototyps

- Windows-Installer und Deinstallation
- Desktop- und Startmenü-Verknüpfungen
- WebView2-Bootstrapper für seltene Windows-10-Systeme ohne Runtime
- automatischer Download und atomarer Wechsel auf neue Releases
- Rollback und Update-UI
- Codesignatur

Diese Punkte bauen auf dem funktionsfähigen Prototyp auf und werden getrennt ergänzt.
