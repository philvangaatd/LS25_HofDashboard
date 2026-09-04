using System.Diagnostics;
using System.Drawing.Drawing2D;
using System.Runtime.InteropServices;

namespace HofDashboard.Launcher;

internal sealed class UpdateDialog : Form
{
    private static readonly Color BackgroundColor = ColorTranslator.FromHtml("#14160E");
    private static readonly Color PanelColor = ColorTranslator.FromHtml("#1B1E14");
    private static readonly Color BorderColor = ColorTranslator.FromHtml("#33381F");
    private static readonly Color TextColor = ColorTranslator.FromHtml("#ECE7D8");
    private static readonly Color MutedColor = ColorTranslator.FromHtml("#A9A48C");
    private static readonly Color AccentColor = ColorTranslator.FromHtml("#C9A227");
    private static readonly Color AccentHoverColor = ColorTranslator.FromHtml("#A9871F");
    private static readonly Color SecondaryColor = ColorTranslator.FromHtml("#5C7A52");
    private static readonly Color DangerColor = ColorTranslator.FromHtml("#A85539");

    private const int WmNclButtonDown = 0x00A1;
    private const int HtCaption = 0x0002;

    private readonly UpdateAvailability _update;
    private readonly UpdateService _service;
    private readonly AppPaths _paths;
    private readonly LauncherLog _log;
    private readonly CancellationTokenSource _cancellation = new();
    private readonly Label _statusLabel;
    private readonly Label _statusDetailLabel;
    private readonly StatusDot _statusDot;
    private readonly DashboardProgressBar _progressBar;
    private readonly Button _updateButton;
    private readonly Button _laterButton;
    private readonly Button _closeButton;
    private bool _isBusy;

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

        Text = "LS25 Hof-Dashboard · Update";
        StartPosition = FormStartPosition.CenterParent;
        FormBorderStyle = FormBorderStyle.None;
        ShowInTaskbar = false;
        ClientSize = new Size(700, 560);
        BackColor = BackgroundColor;
        ForeColor = TextColor;
        AutoScaleMode = AutoScaleMode.Dpi;
        DoubleBuffered = true;
        Padding = new Padding(1);

        var titleBar = new Panel
        {
            Dock = DockStyle.Top,
            Height = 58,
            BackColor = PanelColor,
            Padding = new Padding(22, 12, 14, 10),
        };
        AttachDragHandler(titleBar);

        var brandMark = new BrandMark
        {
            Location = new Point(22, 12),
            Size = new Size(34, 34),
        };
        AttachDragHandler(brandMark);

        var brandLabel = new Label
        {
            AutoSize = true,
            Location = new Point(68, 12),
            Font = new Font("Segoe UI", 9F, FontStyle.Bold),
            ForeColor = AccentColor,
            Text = "LS25 · HOF-DASHBOARD",
            BackColor = Color.Transparent,
        };
        AttachDragHandler(brandLabel);

        var windowLabel = new Label
        {
            AutoSize = true,
            Location = new Point(68, 31),
            Font = new Font("Segoe UI", 8.5F),
            ForeColor = MutedColor,
            Text = "Sicheres Anwendungsupdate",
            BackColor = Color.Transparent,
        };
        AttachDragHandler(windowLabel);

        _closeButton = new Button
        {
            Text = "×",
            Size = new Size(36, 34),
            Location = new Point(ClientSize.Width - 50, 12),
            Anchor = AnchorStyles.Top | AnchorStyles.Right,
            Font = new Font("Segoe UI", 16F, FontStyle.Regular),
            Cursor = Cursors.Hand,
            TabStop = false,
            BackColor = PanelColor,
            ForeColor = MutedColor,
            FlatStyle = FlatStyle.Flat,
            UseVisualStyleBackColor = false,
        };
        _closeButton.FlatAppearance.BorderSize = 0;
        _closeButton.FlatAppearance.MouseOverBackColor = BorderColor;
        _closeButton.FlatAppearance.MouseDownBackColor = BorderColor;
        _closeButton.Click += (_, _) => Close();

        titleBar.Controls.Add(brandMark);
        titleBar.Controls.Add(brandLabel);
        titleBar.Controls.Add(windowLabel);
        titleBar.Controls.Add(_closeButton);

        var content = new Panel
        {
            Dock = DockStyle.Fill,
            BackColor = BackgroundColor,
            Padding = new Padding(34, 28, 34, 28),
        };

        var badge = new RoundedBadge
        {
            Location = new Point(34, 28),
            Size = new Size(132, 26),
            Text = "UPDATE VERFÜGBAR",
        };

        var heading = new Label
        {
            AutoSize = false,
            Location = new Point(34, 67),
            Size = new Size(620, 38),
            Font = new Font("Segoe UI", 20F, FontStyle.Bold),
            ForeColor = TextColor,
            Text = $"Version {update.Version} ist bereit",
        };

        var description = new Label
        {
            AutoSize = false,
            Location = new Point(34, 108),
            Size = new Size(620, 47),
            Font = new Font("Segoe UI", 10F),
            ForeColor = MutedColor,
            Text = "Das Update wird sicher heruntergeladen, geprüft und anschließend automatisch installiert. "
                + "Deine Karten, Backups und Einstellungen bleiben erhalten.",
        };

        var versionCard = new RoundedPanel
        {
            Location = new Point(34, 169),
            Size = new Size(632, 92),
            BackColor = PanelColor,
            BorderColor = BorderColor,
            CornerRadius = 8,
        };

        var currentCaption = CreateCaption("INSTALLIERT", new Point(22, 17), new Size(190, 18));
        var currentVersion = CreateVersionLabel(SemanticVersion.Current.ToString(), new Point(22, 39), new Size(190, 32), MutedColor);
        var arrow = new Label
        {
            AutoSize = false,
            Location = new Point(267, 33),
            Size = new Size(60, 32),
            Font = new Font("Segoe UI", 16F, FontStyle.Regular),
            ForeColor = AccentColor,
            TextAlign = ContentAlignment.MiddleCenter,
            Text = "→",
            BackColor = Color.Transparent,
        };
        var targetCaption = CreateCaption("NEUE VERSION", new Point(384, 17), new Size(210, 18));
        var targetVersion = CreateVersionLabel(update.Version.ToString(), new Point(384, 39), new Size(210, 32), TextColor);
        versionCard.Controls.Add(currentCaption);
        versionCard.Controls.Add(currentVersion);
        versionCard.Controls.Add(arrow);
        versionCard.Controls.Add(targetCaption);
        versionCard.Controls.Add(targetVersion);

        var statusCard = new RoundedPanel
        {
            Location = new Point(34, 276),
            Size = new Size(632, 92),
            BackColor = PanelColor,
            BorderColor = BorderColor,
            CornerRadius = 8,
        };

        _statusDot = new StatusDot
        {
            Location = new Point(22, 23),
            Size = new Size(12, 12),
            DotColor = SecondaryColor,
        };

        _statusLabel = new Label
        {
            AutoSize = false,
            Location = new Point(47, 15),
            Size = new Size(550, 24),
            Font = new Font("Segoe UI", 9.5F, FontStyle.Bold),
            ForeColor = TextColor,
            Text = "Bereit zum Herunterladen",
            BackColor = Color.Transparent,
        };

        _statusDetailLabel = new Label
        {
            AutoSize = false,
            Location = new Point(47, 41),
            Size = new Size(550, 35),
            Font = new Font("Segoe UI", 8.5F),
            ForeColor = MutedColor,
            Text = "Downloadgröße und SHA-256 werden vor der Installation geprüft.",
            BackColor = Color.Transparent,
        };

        _progressBar = new DashboardProgressBar
        {
            Location = new Point(22, 75),
            Size = new Size(588, 5),
            Visible = false,
        };

        statusCard.Controls.Add(_statusDot);
        statusCard.Controls.Add(_statusLabel);
        statusCard.Controls.Add(_statusDetailLabel);
        statusCard.Controls.Add(_progressBar);

        var securityNote = new Label
        {
            AutoSize = false,
            Location = new Point(34, 382),
            Size = new Size(632, 24),
            Font = new Font("Segoe UI", 8.5F),
            ForeColor = MutedColor,
            Text = "✓ Verifizierter GitHub-Release · ✓ Integritätsprüfung · ✓ sichere Wiederherstellung bei Fehlern",
        };

        var notesButton = CreateActionButton("Änderungen ansehen", primary: false);
        notesButton.Location = new Point(34, 422);
        notesButton.Size = new Size(170, 42);
        notesButton.ForeColor = AccentColor;
        notesButton.FlatAppearance.BorderColor = BorderColor;
        notesButton.Click += (_, _) => OpenReleaseNotes();

        _laterButton = CreateActionButton("Später", primary: false);
        _laterButton.Location = new Point(407, 422);
        _laterButton.Size = new Size(105, 42);
        _laterButton.DialogResult = DialogResult.Cancel;

        _updateButton = CreateActionButton("Jetzt aktualisieren", primary: true);
        _updateButton.Location = new Point(522, 422);
        _updateButton.Size = new Size(144, 42);
        _updateButton.Click += DownloadUpdateAsync;

        content.Controls.Add(badge);
        content.Controls.Add(heading);
        content.Controls.Add(description);
        content.Controls.Add(versionCard);
        content.Controls.Add(statusCard);
        content.Controls.Add(securityNote);
        content.Controls.Add(notesButton);
        content.Controls.Add(_laterButton);
        content.Controls.Add(_updateButton);

        Controls.Add(content);
        Controls.Add(titleBar);

        AcceptButton = _updateButton;
        CancelButton = _laterButton;

        Resize += (_, _) => UpdateWindowRegion();
        Shown += (_, _) => UpdateWindowRegion();
        Paint += PaintWindowBorder;
    }

    public PreparedUpdate? PreparedUpdate { get; private set; }

    protected override void OnFormClosing(FormClosingEventArgs e)
    {
        if (_isBusy && PreparedUpdate is null)
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
        SetBusy(true);
        SetStatus(
            "Update wird heruntergeladen …",
            "Bitte das Hof-Dashboard geöffnet lassen, bis der Download abgeschlossen ist.",
            TextColor,
            AccentColor);
        _progressBar.Visible = true;
        _progressBar.Value = 0;

        try
        {
            var progress = new Progress<int>(value =>
            {
                var safeValue = Math.Clamp(value, 0, 100);
                _progressBar.Value = safeValue;
                if (safeValue < 100)
                {
                    SetStatus(
                        $"Update wird heruntergeladen … {safeValue} %",
                        "Download und Paketintegrität werden automatisch geprüft.",
                        TextColor,
                        AccentColor);
                }
                else
                {
                    SetStatus(
                        "Download abgeschlossen · Paket wird geprüft …",
                        "SHA-256 und Paketmanifest werden validiert.",
                        TextColor,
                        AccentColor);
                }
            });

            PreparedUpdate = await _service.DownloadAndPrepareAsync(
                _update,
                _paths,
                progress,
                _cancellation.Token);

            SetStatus(
                "Update ist bereit",
                "Die App wird jetzt neu gestartet und das Update sicher angewendet.",
                TextColor,
                SecondaryColor);
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
            SetBusy(false);
            _progressBar.Visible = false;
            SetStatus(
                "Update konnte nicht installiert werden",
                $"Die aktuelle App bleibt unverändert. {exception.Message}",
                DangerColor,
                DangerColor);
            _updateButton.Text = "Erneut versuchen";
        }
    }

    private void SetBusy(bool busy)
    {
        _isBusy = busy;
        _updateButton.Enabled = !busy;
        _laterButton.Enabled = !busy;
        _closeButton.Enabled = !busy;
        UseWaitCursor = busy;
    }

    private void SetStatus(string title, string detail, Color titleColor, Color indicatorColor)
    {
        _statusLabel.ForeColor = titleColor;
        _statusLabel.Text = title;
        _statusDetailLabel.Text = detail;
        _statusDot.DotColor = indicatorColor;
        _statusDot.Invalidate();
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
            SetStatus(
                "Änderungen konnten nicht geöffnet werden",
                exception.Message,
                DangerColor,
                DangerColor);
        }
    }

    private static Label CreateCaption(string text, Point location, Size size)
    {
        return new Label
        {
            AutoSize = false,
            Location = location,
            Size = size,
            Font = new Font("Segoe UI", 8F, FontStyle.Bold),
            ForeColor = MutedColor,
            Text = text,
            BackColor = Color.Transparent,
        };
    }

    private static Label CreateVersionLabel(string text, Point location, Size size, Color color)
    {
        return new Label
        {
            AutoSize = false,
            Location = location,
            Size = size,
            Font = new Font("Segoe UI", 16F, FontStyle.Bold),
            ForeColor = color,
            Text = text,
            BackColor = Color.Transparent,
        };
    }

    private static Button CreateActionButton(string text, bool primary)
    {
        var button = new Button
        {
            Text = text,
            Font = new Font("Segoe UI", 9F, FontStyle.Bold),
            Cursor = Cursors.Hand,
            FlatStyle = FlatStyle.Flat,
            UseVisualStyleBackColor = false,
            BackColor = primary ? AccentColor : PanelColor,
            ForeColor = primary ? BackgroundColor : TextColor,
        };
        button.FlatAppearance.BorderSize = 1;
        button.FlatAppearance.BorderColor = primary ? AccentColor : BorderColor;
        button.FlatAppearance.MouseOverBackColor = primary ? AccentHoverColor : BorderColor;
        button.FlatAppearance.MouseDownBackColor = primary ? AccentHoverColor : BorderColor;
        button.Resize += (_, _) => ApplyRoundedRegion(button, 6);
        return button;
    }

    private void AttachDragHandler(Control control)
    {
        control.MouseDown += (_, e) =>
        {
            if (e.Button != MouseButtons.Left)
            {
                return;
            }

            ReleaseCapture();
            SendMessage(Handle, WmNclButtonDown, (nint)HtCaption, 0);
        };
    }

    private void UpdateWindowRegion()
    {
        using var path = CreateRoundedPath(new Rectangle(0, 0, Width, Height), 10);
        Region?.Dispose();
        Region = new Region(path);
    }

    private void PaintWindowBorder(object? sender, PaintEventArgs e)
    {
        e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
        using var path = CreateRoundedPath(new Rectangle(0, 0, Width - 1, Height - 1), 10);
        using var pen = new Pen(BorderColor, 1F);
        e.Graphics.DrawPath(pen, path);
    }

    private static void ApplyRoundedRegion(Control control, int radius)
    {
        if (control.Width <= 0 || control.Height <= 0)
        {
            return;
        }

        using var path = CreateRoundedPath(new Rectangle(0, 0, control.Width, control.Height), radius);
        control.Region?.Dispose();
        control.Region = new Region(path);
    }

    private static GraphicsPath CreateRoundedPath(Rectangle bounds, int radius)
    {
        var diameter = Math.Max(1, radius * 2);
        var arc = new Rectangle(bounds.Location, new Size(diameter, diameter));
        var path = new GraphicsPath();
        path.AddArc(arc, 180, 90);
        arc.X = bounds.Right - diameter;
        path.AddArc(arc, 270, 90);
        arc.Y = bounds.Bottom - diameter;
        path.AddArc(arc, 0, 90);
        arc.X = bounds.Left;
        path.AddArc(arc, 90, 90);
        path.CloseFigure();
        return path;
    }

    [DllImport("user32.dll")]
    private static extern bool ReleaseCapture();

    [DllImport("user32.dll")]
    private static extern nint SendMessage(nint hWnd, int msg, nint wParam, nint lParam);

    private sealed class RoundedPanel : Panel
    {
        public Color BorderColor { get; set; } = UpdateDialog.BorderColor;
        public int CornerRadius { get; set; } = 8;

        public RoundedPanel()
        {
            DoubleBuffered = true;
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            base.OnPaint(e);
            e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
            using var path = CreateRoundedPath(new Rectangle(0, 0, Width - 1, Height - 1), CornerRadius);
            using var pen = new Pen(BorderColor, 1F);
            e.Graphics.DrawPath(pen, path);
        }

        protected override void OnResize(EventArgs eventargs)
        {
            base.OnResize(eventargs);
            ApplyRoundedRegion(this, CornerRadius);
        }
    }

    private sealed class RoundedBadge : Control
    {
        public RoundedBadge()
        {
            DoubleBuffered = true;
            Font = new Font("Segoe UI", 7.5F, FontStyle.Bold);
            ForeColor = BackgroundColor;
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
            using var path = CreateRoundedPath(new Rectangle(0, 0, Width - 1, Height - 1), 4);
            using var brush = new SolidBrush(AccentColor);
            e.Graphics.FillPath(brush, path);
            TextRenderer.DrawText(
                e.Graphics,
                Text,
                Font,
                ClientRectangle,
                ForeColor,
                TextFormatFlags.HorizontalCenter | TextFormatFlags.VerticalCenter | TextFormatFlags.NoPadding);
        }
    }

    private sealed class StatusDot : Control
    {
        public Color DotColor { get; set; } = SecondaryColor;

        public StatusDot()
        {
            DoubleBuffered = true;
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
            using var brush = new SolidBrush(DotColor);
            e.Graphics.FillEllipse(brush, 1, 1, Math.Max(1, Width - 2), Math.Max(1, Height - 2));
        }
    }

    private sealed class DashboardProgressBar : Control
    {
        private int _value;

        public int Value
        {
            get => _value;
            set
            {
                _value = Math.Clamp(value, 0, 100);
                Invalidate();
            }
        }

        public DashboardProgressBar()
        {
            DoubleBuffered = true;
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
            using var trackPath = CreateRoundedPath(new Rectangle(0, 0, Width, Height), Math.Max(1, Height / 2));
            using var trackBrush = new SolidBrush(BorderColor);
            e.Graphics.FillPath(trackBrush, trackPath);

            var fillWidth = (int)Math.Round(Width * (_value / 100D));
            if (fillWidth <= 0)
            {
                return;
            }

            using var fillPath = CreateRoundedPath(
                new Rectangle(0, 0, Math.Max(Height, fillWidth), Height),
                Math.Max(1, Height / 2));
            using var fillBrush = new SolidBrush(AccentColor);
            e.Graphics.FillPath(fillBrush, fillPath);
        }
    }

    private sealed class BrandMark : Control
    {
        public BrandMark()
        {
            DoubleBuffered = true;
        }

        protected override void OnPaint(PaintEventArgs e)
        {
            e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;
            var width = Width - 2F;
            var height = Height - 2F;
            var points = new[]
            {
                new PointF(width * 0.50F, 1F),
                new PointF(width * 0.88F, height * 0.18F),
                new PointF(width * 0.82F, height * 0.66F),
                new PointF(width * 0.50F, height * 0.96F),
                new PointF(width * 0.18F, height * 0.66F),
                new PointF(width * 0.12F, height * 0.18F),
            };

            using var accentPen = new Pen(AccentColor, 1.5F);
            using var secondaryPen = new Pen(SecondaryColor, 1.2F)
            {
                StartCap = LineCap.Round,
                EndCap = LineCap.Round,
            };
            e.Graphics.DrawPolygon(accentPen, points);
            e.Graphics.DrawLine(secondaryPen, width * 0.50F, height * 0.28F, width * 0.50F, height * 0.76F);
            e.Graphics.DrawLine(secondaryPen, width * 0.50F, height * 0.38F, width * 0.34F, height * 0.30F);
            e.Graphics.DrawLine(secondaryPen, width * 0.50F, height * 0.48F, width * 0.66F, height * 0.40F);
            e.Graphics.DrawLine(secondaryPen, width * 0.50F, height * 0.58F, width * 0.34F, height * 0.50F);
            e.Graphics.DrawLine(secondaryPen, width * 0.50F, height * 0.68F, width * 0.66F, height * 0.60F);
        }
    }
}
