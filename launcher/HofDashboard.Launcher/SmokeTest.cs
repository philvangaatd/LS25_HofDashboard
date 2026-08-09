namespace HofDashboard.Launcher;

internal static class SmokeTest
{
    public static async Task<int> RunAsync()
    {
        try
        {
            var paths = AppPaths.Create();
            var log = new LauncherLog(paths.LauncherLog);
            log.Info("Starte automatischen Launcher-Smoke-Test.");

            using var timeout = new CancellationTokenSource(TimeSpan.FromSeconds(30));
            using var server = await PhpServer.StartAsync(paths, log, timeout.Token);
            log.Info($"Smoke-Test erfolgreich: {server.Health?.Version ?? "unbekannte Version"}.");
            return 0;
        }
        catch (Exception exception)
        {
            try
            {
                var paths = AppPaths.Create();
                new LauncherLog(paths.LauncherLog).Error("Launcher-Smoke-Test fehlgeschlagen.", exception);
            }
            catch
            {
                // The exit code remains the authoritative result for CI.
            }

            return 1;
        }
    }
}
