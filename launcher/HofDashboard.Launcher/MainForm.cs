using System.Diagnostics;
using Microsoft.Web.WebView2.Core;
using Microsoft.Web.WebView2.WinForms;

namespace HofDashboard.Launcher;

internal sealed class MainForm : Form
{
    private readonly CancellationTokenSource _shutdown = new();
    private readonly WebView2 _webView;
    private readonly Panel _loadingPanel;
    private readonly Label _statusLabel;
    private PhpServer? _phpServer;
    private LauncherLog? _log;

    public MainForm()
    {
        Text = "LS25 Hof-Dashboard · Prototyp";
        StartPosition = FormStartPosition.CenterScreen;
        MinimumSize = new Size(1000, 700);
        Size = new Size(1440, 900);
        BackColor = Color.FromArgb(15, 23, 42);

        _webView = new WebView2
        {
            Dock = DockStyle.Fill,
            Visible = false,
        };

        _statusLabel = new Label
        {
            AutoSize = false,
            Dock = DockStyle.Fill,
            ForeColor = Color.FromArgb(226, 232, 240),
            Font = new Font("Segoe UI", 16, FontStyle.Regular),
            Text = "Hof-Dashboard wird gestartet …",
            TextAlign = ContentAlignment.MiddleCenter,
        };

        _loadingPanel = new Panel
        {
            BackColor = Color.FromArgb(15, 23, 42),
            Dock = DockStyle.Fill,
        };
        _loadingPanel.Controls.Add(_statusLabel);

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

    private async Task StartDashboardAsync(CancellationToken cancellationToken)
    {
        var paths = AppPaths.Create();
        _log = new LauncherLog(paths.LauncherLog);
        _log.Info($"HofDashboard Launcher {Application.ProductVersion} startet.");
        _log.Info($"Installationsordner: {paths.InstallDirectory}");
        _log.Info($"App-Datenordner: {paths.UserDataRoot}");

        SetStatus("Integrierter PHP-Server wird gestartet …");
        _phpServer = await PhpServer.StartAsync(paths, _log, cancellationToken);

        SetStatus("Dashboard-Fenster wird vorbereitet …");
        var environment = await CoreWebView2Environment.CreateAsync(
            browserExecutableFolder: null,
            userDataFolder: paths.WebViewDirectory);
        await _webView.EnsureCoreWebView2Async(environment);

        ConfigureWebView(_phpServer);
        _webView.Source = _phpServer.RootUri;
        _webView.Visible = true;
        _loadingPanel.Visible = false;
        _log.Info("Dashboard-Fenster ist bereit.");
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

        core.NewWindowRequested += (_, args) =>
        {
            args.Handled = true;
            OpenExternalUri(args.Uri);
        };
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

    private void SetStatus(string status)
    {
        _statusLabel.Text = status;
        _log?.Info(status);
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

        _statusLabel.Text = "Das Hof-Dashboard konnte nicht gestartet werden.";
        var message = $"Das Hof-Dashboard konnte nicht gestartet werden.\n\n{exception.Message}";
        if (!string.IsNullOrWhiteSpace(logPath))
        {
            message += $"\n\nProtokoll: {logPath}";
        }

        MessageBox.Show(this, message, "LS25 Hof-Dashboard", MessageBoxButtons.OK, MessageBoxIcon.Error);
    }
}
