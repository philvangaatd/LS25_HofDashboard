using System.Drawing.Drawing2D;

namespace HofDashboard.Launcher;

internal sealed class LoadingView : Control
{
    private static readonly Color BackgroundColor = Color.FromArgb(20, 22, 14);
    private static readonly Color AccentColor = Color.FromArgb(201, 162, 39);
    private static readonly Color SecondaryColor = Color.FromArgb(92, 122, 82);
    private static readonly Color TextColor = Color.FromArgb(236, 231, 216);

    private readonly System.Windows.Forms.Timer _animationTimer;
    private readonly Font _messageFont;
    private float _spinnerAngle;
    private string _message = "Laden …";

    public LoadingView()
    {
        SetStyle(
            ControlStyles.AllPaintingInWmPaint
            | ControlStyles.OptimizedDoubleBuffer
            | ControlStyles.ResizeRedraw
            | ControlStyles.UserPaint,
            true);

        BackColor = BackgroundColor;
        _messageFont = new Font("Segoe UI", 15, FontStyle.Regular);
        _animationTimer = new System.Windows.Forms.Timer { Interval = 50 };
        _animationTimer.Tick += (_, _) =>
        {
            _spinnerAngle = (_spinnerAngle + 8) % 360;
            Invalidate();
        };
        _animationTimer.Start();
    }

    internal void SetMessage(string message)
    {
        _message = message;
        Invalidate();
    }

    public void StopAnimation()
    {
        _animationTimer.Stop();
    }

    protected override void OnPaint(PaintEventArgs e)
    {
        base.OnPaint(e);

        var scale = Math.Max(DeviceDpi / 96f, 1f);
        var centerX = ClientSize.Width / 2f;
        var centerY = ClientSize.Height / 2f - 24f * scale;
        var spinnerSize = 106f * scale;
        var spinnerRect = new RectangleF(
            centerX - spinnerSize / 2f,
            centerY - spinnerSize / 2f,
            spinnerSize,
            spinnerSize);

        e.Graphics.SmoothingMode = SmoothingMode.AntiAlias;

        using var spinnerTrack = new Pen(Color.FromArgb(45, AccentColor), 3f * scale);
        using var spinnerArc = new Pen(AccentColor, 3f * scale)
        {
            StartCap = LineCap.Round,
            EndCap = LineCap.Round,
        };
        e.Graphics.DrawEllipse(spinnerTrack, spinnerRect);
        e.Graphics.DrawArc(spinnerArc, spinnerRect, _spinnerAngle, 92f);

        DrawLogo(e.Graphics, centerX, centerY, scale);

        var textRect = new Rectangle(
            0,
            (int)Math.Round(spinnerRect.Bottom + 24f * scale),
            ClientSize.Width,
            (int)Math.Round(40f * scale));
        TextRenderer.DrawText(
            e.Graphics,
            _message,
            _messageFont,
            textRect,
            TextColor,
            TextFormatFlags.HorizontalCenter | TextFormatFlags.Top | TextFormatFlags.NoPadding);
    }

    protected override void Dispose(bool disposing)
    {
        if (disposing)
        {
            _animationTimer.Stop();
            _animationTimer.Dispose();
            _messageFont.Dispose();
        }

        base.Dispose(disposing);
    }

    private static void DrawLogo(Graphics graphics, float centerX, float centerY, float scale)
    {
        var logoScale = 1.45f * scale;
        PointF Transform(float x, float y) => new(centerX + x * logoScale, centerY + y * logoScale);

        using var shield = new GraphicsPath();
        shield.AddLines([
            Transform(0, -22),
            Transform(16, -16),
            Transform(16, -2),
            Transform(15, 6),
            Transform(11, 13),
            Transform(6, 18),
            Transform(0, 21),
            Transform(-6, 18),
            Transform(-11, 13),
            Transform(-15, 6),
            Transform(-16, -2),
            Transform(-16, -16),
        ]);
        shield.CloseFigure();

        using var shieldPen = new Pen(AccentColor, 1.35f * scale)
        {
            LineJoin = LineJoin.Round,
        };
        using var wheatPen = new Pen(SecondaryColor, 1.15f * scale)
        {
            StartCap = LineCap.Round,
            EndCap = LineCap.Round,
        };

        graphics.DrawPath(shieldPen, shield);
        graphics.DrawLine(wheatPen, Transform(0, -12), Transform(0, 12));

        foreach (var y in new[] { -8f, -3f, 2f, 7f })
        {
            graphics.DrawLine(wheatPen, Transform(0, y), Transform(-5, y - 3));
            graphics.DrawLine(wheatPen, Transform(0, y), Transform(5, y - 3));
        }
    }
}
