using System.Diagnostics;

namespace HofDashboard.Launcher;

internal sealed class UpdateDialog : Form
{
    private readonly UpdateAvailability _update;
    private readonly UpdateService _service;
    private readonly AppPaths _paths;
    private readonly LauncherLog _log;
    private readonly CancellationTokenSource _cancellation = new();
    private readonly Label _statusLabel;
    private readonly ProgressBar _progressBar;
    private readonly Button _updateButton;
    private readonly Button _laterButton;

    public UpdateDialog(
        UpdateAvailability update,
        UpdateService service,
        AppPaths paths,
        LauncherLog log)
    {
        _update = update;
        _service = service;
        _paths = paths;
        _log = log;

        Text = "Update verfügbar";
        StartPosition = FormStartPosition.CenterParent;
        FormBorderStyle = FormBorderStyle.FixedDialog;
        MaximizeBox = false;
        MinimizeBox = false;
        ShowInTaskbar = false;
        ClientSize = new Size(520, 260);
        BackColor = Color.FromArgb(15, 23, 42);
        ForeColor = Color.FromArgb(226, 232, 240);
        Padding = new Padding(24);

        var titleLabel = new Label
        {
            AutoSize = false,
            Dock = DockStyle.Top,
            Height = 38,
            Font = new Font("Segoe UI", 16, FontStyle.Bold),
            ForeColor = Color.FromArgb(245, 158, 11),
            Text = $"Version {update.Version} ist verfügbar",
        };

        var descriptionLabel = new Label
        {
            AutoSize = false,
            Dock = DockStyle.Top,
            Height = 58,
            Font = new Font("Segoe UI", 10),
            Text = "Das Update kann jetzt sicher heruntergeladen und installiert werden. "
                + "Deine Karten, Backups und Einstellungen bleiben erhalten.",
        };

        _statusLabel = new Label
        {
            AutoSize = false,
            Dock = DockStyle.Top,
            Height = 28,
            Font = new Font("Segoe UI", 9),
            Text = "Bereit zum Herunterladen.",
        };

        _progressBar = new ProgressBar
        {
            Dock = DockStyle.Top,
            Height = 18,
            Minimum = 0,
            Maximum = 100,
            Visible = false,
        };

        _updateButton = new Button
        {
            AutoSize = true,
            Text = "Jetzt aktualisieren",
            BackColor = Color.FromArgb(245, 158, 11),
            ForeColor = Color.FromArgb(15, 23, 42),
            FlatStyle = FlatStyle.Flat,
            Padding = new Padding(12, 5, 12, 5),
        };
        _updateButton.FlatAppearance.BorderSize = 0;
        _updateButton.Click += DownloadUpdateAsync;

        _laterButton = new Button
        {
            AutoSize = true,
            Text = "Später",
            DialogResult = DialogResult.Cancel,
            FlatStyle = FlatStyle.Flat,
            ForeColor = ForeColor,
            Padding = new Padding(12, 5, 12, 5),
        };
        _laterButton.FlatAppearance.BorderColor = Color.FromArgb(71, 85, 105);

        var notesButton = new Button
        {
            AutoSize = true,
            Text = "Änderungen ansehen",
            FlatStyle = FlatStyle.Flat,
            ForeColor = Color.FromArgb(147, 197, 253),
            Padding = new Padding(12, 5, 12, 5),
        };
        notesButton.FlatAppearance.BorderSize = 0;
        notesButton.Click += (_, _) => OpenReleaseNotes();

        var buttonPanel = new FlowLayoutPanel
        {
            Dock = DockStyle.Bottom,
            Height = 48,
            FlowDirection = FlowDirection.RightToLeft,
            WrapContents = false,
            Padding = new Padding(0, 6, 0, 0),
        };
        buttonPanel.Controls.Add(_updateButton);
        buttonPanel.Controls.Add(_laterButton);
        buttonPanel.Controls.Add(notesButton);

        Controls.Add(buttonPanel);
        Controls.Add(_progressBar);
        Controls.Add(_statusLabel);
        Controls.Add(descriptionLabel);
        Controls.Add(titleLabel);
        AcceptButton = _updateButton;
        CancelButton = _laterButton;
    }

    public PreparedUpdate? PreparedUpdate { get; private set; }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        if (!_updateButton.Enabled && PreparedUpdate is null)
        {
            _cancellation.Cancel();
        }
        base.OnFormClosing(e);
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _cancellation.Dispose();
        }
        base.Dispose(disposing);
    }

    private async void DownloadUpdateAsync(object? sender, EventArgs e)
    {
        _updateButton.Enabled = false;
        _laterButton.Enabled = false;
        ControlBox = false;
        _progressBar.Visible = true;
        _statusLabel.Text = "Update wird heruntergeladen …";

        try
        {
            var progress = new Progress<int>(value =>
            {
                _progressBar.Value = Math.Clamp(value, 0, 100);
                _statusLabel.Text = value < 100
                    ? $"Update wird heruntergeladen … {value} %"
                    : "Update wird geprüft …";
            });
            PreparedUpdate = await _service.DownloadAndPrepareAsync(
                _update,
                _paths,
                progress,
                _cancellation.Token);
            _statusLabel.Text = "Update ist bereit. Die App wird neu gestartet …";
            DialogResult = DialogResult.OK;
            Close();
        }
        catch (OperationCanceledException)
        {
            DialogResult = DialogResult.Cancel;
            Close();
        }
        catch (Exception exception)
        {
            _log.Error("Update konnte nicht vorbereitet werden.", exception);
            MessageBox.Show(
                this,
                $"Das Update konnte nicht installiert werden. Die aktuelle App bleibt unverändert.\n\n{exception.Message}",
                "Update fehlgeschlagen",
                MessageBoxButtons.OK,
                MessageBoxIcon.Warning);
            _updateButton.Enabled = true;
            _laterButton.Enabled = true;
            ControlBox = true;
            _progressBar.Visible = false;
            _statusLabel.Text = "Update fehlgeschlagen. Du kannst es erneut versuchen.";
        }
    }

    private void OpenReleaseNotes()
    {
        try
        {
            Process.Start(new ProcessStartInfo(_update.ReleaseNotesUri.AbsoluteUri)
            {
                UseShellExecute = true,
            });
        }
        catch (Exception exception)
        {
            _log.Warning($"Release-Hinweise konnten nicht geöffnet werden: {exception.Message}");
        }
    }
}
