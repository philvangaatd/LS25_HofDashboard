using System.Diagnostics;
using System.Text.Json;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace HofDashboard.Launcher;

internal sealed class MainForm : Form
{
    private static readonly JsonSerializerOptions WebMessageJsonOptions = new(JsonSerializerDefaults.Web);

    private readonly CancellationTokenSource _shutdown = new();
    private readonly WebView2 _webView;
    private readonly LoadingView _loadingPanel;
    private readonly Icon? _applicationIcon;
    private PhpServer? _phpServer;
    private LauncherLog? _log;
    private AppPaths? _paths;
    private ModManager? _modManager;

    public MainForm()
    {
        Text = "LS25 Hof-Dashboard";
        _applicationIcon = Icon.ExtractAssociatedIcon(Application.ExecutablePath);
        if (_applicationIcon is not null)
        {
            Icon = _applicationIcon;
        }

        StartPosition = FormStartPosition.CenterScreen;
        MinimumSize = new Size(1000, 700);
        Size = new Size(1440, 900);
        BackColor = Color.FromArgb(15, 23, 42);

        _webView = new WebView2
        {
            Dock = DockStyle.Fill,
            Visible = false,
        };

        _loadingPanel = new LoadingView
        {
            Dock = DockStyle.Fill,
        };

        Controls.Add(_webView);
        Controls.Add(_loadingPanel);
    }

    protected override async void OnShown(EventArgs e)
    {
        base.OnShown(e);

        try
        {
            await StartDashboardAsync(_shutdown.Token);
        }
        catch (OperationCanceledException) when (_shutdown.IsCancellationRequested)
        {
            // The window was closed while startup was still running.
        }
        catch (Exception exception)
        {
            _log?.Error("Start des Hof-Dashboards fehlgeschlagen.", exception);
            _phpServer?.Dispose();
            _phpServer = null;
            ShowStartupFailure(exception);
        }
    }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        _shutdown.Cancel();
        _webView.Dispose();
        _phpServer?.Dispose();
        _shutdown.Dispose();
        base.OnFormClosing(e);
    }

    protected override void OnFormClosed(FormClosedEventArgs e)
    {
        base.OnFormClosed(e);
        _applicationIcon?.Dispose();
    }

    private async Task StartDashboardAsync(CancellationToken cancellationToken)
    {
        var paths = AppPaths.Create();
        _paths = paths;
        _log = new LauncherLog(paths.LauncherLog);
        _modManager = new ModManager(paths, _log);
        _log.Info($"HofDashboard Launcher {Application.ProductVersion} startet.");
        _log.Info($"Installationsordner: {paths.InstallDirectory}");
        _log.Info($"App-Datenordner: {paths.UserDataRoot}");

        _log.Info("Integrierter PHP-Server wird gestartet.");
        _phpServer = await PhpServer.StartAsync(paths, _log, cancellationToken);

        _log.Info("Dashboard-Fenster wird vorbereitet.");
        var environment = await CoreWebView2Environment.CreateAsync(
            browserExecutableFolder: null,
            userDataFolder: paths.WebViewDirectory);
        await _webView.EnsureCoreWebView2Async(environment);

        ConfigureWebView(_phpServer);
        _webView.Source = _phpServer.RootUri;
        _webView.Visible = true;
        _loadingPanel.StopAnimation();
        _loadingPanel.Visible = false;
        _log.Info("Dashboard-Fenster ist bereit.");

        await CheckForUpdatesAsync(paths, cancellationToken);
    }

    private async Task CheckForUpdatesAsync(AppPaths paths, CancellationToken cancellationToken)
    {
        try
        {
            using var updateService = new UpdateService(_log!);
            var update = await updateService.CheckAsync(cancellationToken);
            if (update is null)
            {
                return;
            }

            _log!.Info($"Update {update.Version} ist verfügbar.");
            using var dialog = new UpdateDialog(update, updateService, paths, _log);
            if (dialog.ShowDialog(this) != DialogResult.OK || dialog.PreparedUpdate is null)
            {
                _log.Info("Das verfügbare Update wurde auf später verschoben.");
                return;
            }

            UpdateApplier.Start(dialog.PreparedUpdate, paths, _log);
            Close();
        }
        catch (OperationCanceledException) when (cancellationToken.IsCancellationRequested)
        {
            // Normal shutdown while a background update check is running.
        }
        catch (Exception exception)
        {
            _log?.Warning($"Updateprüfung übersprungen: {exception.Message}");
        }
    }

    private void ConfigureWebView(PhpServer server)
    {
        var core = _webView.CoreWebView2;
        core.Settings.AreDevToolsEnabled = false;
        core.Settings.AreDefaultScriptDialogsEnabled = true;
        core.Settings.AreBrowserAcceleratorKeysEnabled = true;
        core.Settings.IsStatusBarEnabled = false;
        core.Settings.IsZoomControlEnabled = true;

        core.NavigationStarting += (_, args) =>
        {
            if (IsLocalDashboardUri(args.Uri, server))
            {
                return;
            }

            args.Cancel = true;
            OpenExternalUri(args.Uri);
        };

        core.NavigationCompleted += async (_, args) =>
        {
            if (!args.IsSuccess)
            {
                return;
            }

            try
            {
                await InjectModManagerClientAsync();
            }
            catch (Exception exception)
            {
                _log?.Warning($"Dashboard-Erweiterungen konnten nicht in die Oberfläche eingebunden werden: {exception.Message}");
            }
        };

        core.WebMessageReceived += HandleWebMessageReceived;

        core.NewWindowRequested += (_, args) =>
        {
            args.Handled = true;
            OpenExternalUri(args.Uri);
        };
    }

    private async Task InjectModManagerClientAsync()
    {
        const string script = """
            (() => {
                if (window.__hofDashboardClientScriptsRequested) return;
                window.__hofDashboardClientScriptsRequested = true;

                const loadStyle = (href) => {
                    const style = document.createElement('link');
                    style.rel = 'stylesheet';
                    style.href = href;
                    document.head.appendChild(style);
                };

                loadStyle('assets/css/start-screen-icon-fix.css?v=5.6.1');
                loadStyle('assets/css/sidebar-collapse.css?v=5.6.1');
                loadStyle('assets/css/header-cleanup.css?v=5.6.1');
                loadStyle('assets/css/mod-manager.css?v=5.6.1');

                const load = (src) => {
                    const script = document.createElement('script');
                    script.src = src;
                    script.async = false;
                    document.head.appendChild(script);
                };

                load('assets/js/mod-manager.js?v=5.6.1');
                load('assets/js/app-shell.js?v=5.6.1');
                load('assets/js/app-shell-sync.js?v=5.6.1');
                load('assets/js/sidebar-collapse.js?v=5.6.1');
                load('assets/js/near-live.js?v=5.6.1');
                load('assets/js/live-refresh-ux.js?v=5.6.1');
            })();
            """;
        await _webView.CoreWebView2.ExecuteScriptAsync(script);
    }

    private async void HandleWebMessageReceived(
        object? sender,
        CoreWebView2WebMessageReceivedEventArgs args)
    {
        if (_modManager is null || _paths is null || _log is null)
        {
            return;
        }

        try
        {
            using var document = JsonDocument.Parse(args.WebMessageAsJson);
            if (document.RootElement.ValueKind != JsonValueKind.Object
                || !document.RootElement.TryGetProperty("action", out var actionElement))
            {
                return;
            }

            var action = actionElement.GetString();
            switch (action)
            {
                case "mod-status":
                    await SendModStatusAsync();
                    break;

                case "mod-install":
                    await InstallModAsync();
                    break;

                case "mod-select-folder":
                    SelectModDirectory();
                    await SendModStatusAsync();
                    break;

                case "mod-open-folder":
                    OpenModDirectory();
                    break;
            }
        }
        catch (OperationCanceledException) when (_shutdown.IsCancellationRequested)
        {
            // Normal application shutdown.
        }
        catch (Exception exception)
        {
            _log.Error("Aktion der integrierten Mod-Verwaltung fehlgeschlagen.", exception);
            PostWebMessage(new
            {
                type = "mod-manager-error",
                message = exception.Message,
            });
        }
    }

    private async Task SendModStatusAsync(string? notice = null)
    {
        if (_modManager is null)
        {
            return;
        }

        var status = await _modManager.GetStatusAsync(_shutdown.Token);
        PostWebMessage(new
        {
            type = "mod-manager-status",
            status,
            notice,
        });
    }

    private async Task InstallModAsync()
    {
        if (_modManager is null)
        {
            return;
        }

        var progress = new Progress<ModInstallProgress>(value =>
        {
            PostWebMessage(new
            {
                type = "mod-manager-progress",
                progress = value,
            });
        });

        await _modManager.InstallOrUpdateAsync(progress, _shutdown.Token);
        await SendModStatusAsync("Die LS25-Mod wurde erfolgreich installiert und geprüft.");
    }

    private void SelectModDirectory()
    {
        if (_modManager is null)
        {
            return;
        }

        using var dialog = new FolderBrowserDialog
        {
            Description = "Wähle den Mod-Ordner von Landwirtschafts-Simulator 25 aus.",
            UseDescriptionForTitle = true,
            ShowNewFolderButton = true,
            InitialDirectory = _modManager.GetPreferredModDirectory(),
        };

        if (dialog.ShowDialog(this) == DialogResult.OK
            && !string.IsNullOrWhiteSpace(dialog.SelectedPath))
        {
            _modManager.SetCustomModDirectory(dialog.SelectedPath);
        }
    }

    private void OpenModDirectory()
    {
        if (_modManager is null)
        {
            return;
        }

        var directory = _modManager.GetPreferredModDirectory();
        Directory.CreateDirectory(directory);
        Process.Start(new ProcessStartInfo("explorer.exe", $"\"{directory}\"")
        {
            UseShellExecute = true,
        });
    }

    private void PostWebMessage(object message)
    {
        try
        {
            if (_webView.IsDisposed || _webView.CoreWebView2 is null)
            {
                return;
            }

            var json = JsonSerializer.Serialize(message, WebMessageJsonOptions);
            _webView.CoreWebView2.PostWebMessageAsJson(json);
        }
        catch (Exception exception)
        {
            _log?.Warning($"Status konnte nicht an die Dashboard-Oberfläche gesendet werden: {exception.Message}");
        }
    }

    private static bool IsLocalDashboardUri(string rawUri, PhpServer server)
    {
        return Uri.TryCreate(rawUri, UriKind.Absolute, out var uri)
            && uri.Scheme == Uri.UriSchemeHttp
            && uri.Host == "127.0.0.1"
            && uri.Port == server.Port;
    }

    private void OpenExternalUri(string rawUri)
    {
        if (!Uri.TryCreate(rawUri, UriKind.Absolute, out var uri)
            || (uri.Scheme != Uri.UriSchemeHttp && uri.Scheme != Uri.UriSchemeHttps))
        {
            _log?.Warning($"Externe Navigation wurde blockiert: {rawUri}");
            return;
        }

        try
        {
            Process.Start(new ProcessStartInfo(uri.AbsoluteUri) { UseShellExecute = true });
        }
        catch (Exception exception)
        {
            _log?.Warning($"Externer Link konnte nicht geöffnet werden: {exception.Message}");
        }
    }

    private void ShowStartupFailure(Exception exception)
    {
        var logPath = string.Empty;
        try
        {
            logPath = AppPaths.Create().LauncherLog;
        }
        catch
        {
            // Keep the original startup exception visible even if path detection failed.
        }

        _loadingPanel.StopAnimation();
        _loadingPanel.SetMessage("Das Hof-Dashboard konnte nicht gestartet werden.");
        var message = $"Das Hof-Dashboard konnte nicht gestartet werden.\n\n{exception.Message}";
        if (!string.IsNullOrWhiteSpace(logPath))
        {
            message += $"\n\nProtokoll: {logPath}";
        }

        MessageBox.Show(this, message, "LS25 Hof-Dashboard", MessageBoxButtons.OK, MessageBoxIcon.Error);
    }
}