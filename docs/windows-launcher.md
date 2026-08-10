# Windows-App

Die Windows-App verpackt das PHP-Dashboard als lokale, selbstenthaltene Anwendung.
Sie verwaltet den internen Webserver, die WebView2-Oberfläche und sichere Updates.

## Paketaufbau

```text
HofDashboard-win-x64-v5.0.0/
├─ HofDashboard.exe
├─ launcher-manifest.json
├─ package-files.json
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

1. Die App verhindert eine zweite parallele Instanz.
2. Sie legt die veränderlichen Benutzerordner unter `%LOCALAPPDATA%\HofDashboard` an.
3. Sie wählt einen freien lokalen Port.
4. Sie startet das mitgelieferte PHP ausschließlich auf `127.0.0.1`.
5. Sie setzt die PHP-Verzeichnisse für Daten, Sessions, Uploads und Protokolle.
6. Sie wartet auf einen erfolgreichen Aufruf von `health.php`.
7. Sie öffnet die lokale URL in einem WebView2-Fenster.
8. Sie prüft das neueste öffentliche GitHub-Release auf eine höhere App-Version.
9. Beim Schließen beendet sie den gesamten eingebetteten PHP-Prozessbaum.

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
├─ updates\
│  ├─ downloads\
│  ├─ staging\
│  ├─ helper\
│  └─ backup\
└─ webview\
```

Der Installationsordner kann versionsweise ausgetauscht werden, ohne Backups,
Kartenbilder, spielstandsbezogene Einstellungen oder WebView2-Daten zu löschen.

## Sicherer Updateablauf

Das Update-Manifest wird ausschließlich vom neuesten öffentlichen GitHub-Release
des Dashboard-Repositories geladen. Eine höhere Version wird nur auf ausdrückliche
Bestätigung installiert.

1. Das vollständige Windows-ZIP wird in den App-Datenordner geladen.
2. Dateigröße und veröffentlichte SHA-256-Prüfsumme werden geprüft.
3. Das ZIP wird gegen Pfadmanipulation geschützt entpackt.
4. Jede enthaltene Datei wird zusätzlich anhand von `package-files.json` geprüft.
5. Eine Kopie der laufenden EXE arbeitet außerhalb des Installationsordners als
   Update-Helfer weiter.
6. Der Helfer wartet, bis App und PHP vollständig beendet sind.
7. Er sichert die bisher verwalteten Paketdateien, ersetzt sie einzeln und startet
   die neue Version.
8. Bei einem Schreibfehler werden die gesicherten Dateien wiederhergestellt.

Unbekannte Dateien im Installationsordner werden nicht gelöscht. Die Mod wird als
separates GitHub-Release angeboten und nicht automatisch in den LS25-Mod-Ordner
geschrieben.

## Build und Test

Auf einem Windows-System mit dem .NET-10-SDK:

```powershell
./launcher/scripts/build-prototype.ps1
```

Das Skript erstellt das offizielle Windows-ZIP und das dazugehörige
`update-manifest.json`. GitHub Actions prüft zusätzlich PHP, Healthcheck,
spielstandsbezogene Einstellungen, Paketprüfsummen, den Update-Helfer und den Erhalt
unbekannter Dateien. Ein echter Fenster- und Neustart-Test bleibt vor dem Merge
zusätzlich erforderlich.

## Noch nicht enthalten

- Windows-Installer und Deinstallation
- Desktop- und Startmenü-Verknüpfungen
- WebView2-Bootstrapper für seltene Windows-10-Systeme ohne Runtime
- Codesignatur
