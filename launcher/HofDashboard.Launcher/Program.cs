namespace HofDashboard.Launcher;

internal static class Program
{
    private const string SingleInstanceMutex = "Local\\LS25HofDashboard.Launcher";

    [STAThread]
    private static int Main(string[] args)
    {
        if (args.Contains("--smoke-test", StringComparer.OrdinalIgnoreCase))
        {
            return SmokeTest.RunAsync().GetAwaiter().GetResult();
        }

        using var mutex = new Mutex(true, SingleInstanceMutex, out var isFirstInstance);
        if (!isFirstInstance)
        {
            MessageBox.Show(
                "Das LS25 Hof-Dashboard läuft bereits.",
                "LS25 Hof-Dashboard",
                MessageBoxButtons.OK,
                MessageBoxIcon.Information);
            return 0;
        }

        ApplicationConfiguration.Initialize();
        Application.SetUnhandledExceptionMode(UnhandledExceptionMode.CatchException);
        Application.ThreadException += (_, eventArgs) => LogUnhandled(eventArgs.Exception);
        AppDomain.CurrentDomain.UnhandledException += (_, eventArgs) =>
            LogUnhandled(eventArgs.ExceptionObject as Exception
                ?? new InvalidOperationException("Unbekannter nicht behandelter Fehler."));

        try
        {
            Application.Run(new MainForm());
            return 0;
        }
        catch (Exception exception)
        {
            LogUnhandled(exception);
            MessageBox.Show(
                $"Das LS25 Hof-Dashboard wurde unerwartet beendet.\n\n{exception.Message}",
                "LS25 Hof-Dashboard",
                MessageBoxButtons.OK,
                MessageBoxIcon.Error);
            return 1;
        }
    }

    private static void LogUnhandled(Exception exception)
    {
        try
        {
            var paths = AppPaths.Create();
            new LauncherLog(paths.LauncherLog).Error("Nicht behandelter Launcher-Fehler.", exception);
        }
        catch
        {
            // There is no safe logging fallback left at this point.
        }
    }
}
