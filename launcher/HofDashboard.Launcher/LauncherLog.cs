using System.Text;

namespace HofDashboard.Launcher;

internal sealed class LauncherLog
{
    private readonly object _sync = new();
    private readonly string _path;

    public LauncherLog(string path)
    {
        _path = path;
    }

    public void Info(string message) => Write("INFO", message);

    public void Warning(string message) => Write("WARN", message);

    public void Error(string message, Exception? exception = null)
    {
        Write("ERROR", exception is null ? message : $"{message}{Environment.NewLine}{exception}");
    }

    private void Write(string level, string message)
    {
        try
        {
            var normalized = message.Replace("\r\n", "\n", StringComparison.Ordinal).Replace('\r', '\n');
            var line = $"{DateTimeOffset.Now:yyyy-MM-dd HH:mm:ss.fff zzz} [{level}] {normalized}{Environment.NewLine}";

            lock (_sync)
            {
                Directory.CreateDirectory(Path.GetDirectoryName(_path)!);
                File.AppendAllText(_path, line, new UTF8Encoding(false));
            }
        }
        catch
        {
            // Logging must never hide the actual startup error.
        }
    }
}
