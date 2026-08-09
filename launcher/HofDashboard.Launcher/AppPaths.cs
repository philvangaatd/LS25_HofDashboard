namespace HofDashboard.Launcher;

internal sealed record AppPaths(
    string InstallDirectory,
    string RuntimeDirectory,
    string WebDirectory,
    string UserDataRoot,
    string DataDirectory,
    string LogDirectory,
    string SessionDirectory,
    string TempDirectory,
    string WebViewDirectory)
{
    public string PhpExecutable => Path.Combine(RuntimeDirectory, "php.exe");

    public string PhpConfiguration => Path.Combine(RuntimeDirectory, "php.ini");

    public string LauncherLog => Path.Combine(LogDirectory, "launcher.log");

    public string PhpLog => Path.Combine(LogDirectory, "php.log");

    public static AppPaths Create()
    {
        var installDirectory = Path.GetFullPath(AppContext.BaseDirectory);
        var localAppData = Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData);

        if (string.IsNullOrWhiteSpace(localAppData))
        {
            throw new InvalidOperationException("Der lokale App-Datenordner von Windows konnte nicht ermittelt werden.");
        }

        var userDataRoot = Path.Combine(localAppData, "HofDashboard");
        var paths = new AppPaths(
            installDirectory,
            Path.Combine(installDirectory, "runtime"),
            Path.Combine(installDirectory, "web"),
            userDataRoot,
            Path.Combine(userDataRoot, "data"),
            Path.Combine(userDataRoot, "logs"),
            Path.Combine(userDataRoot, "sessions"),
            Path.Combine(userDataRoot, "temp"),
            Path.Combine(userDataRoot, "webview"));

        paths.EnsureUserDirectories();
        return paths;
    }

    public void ValidatePackage()
    {
        var requiredFiles = new[]
        {
            PhpExecutable,
            PhpConfiguration,
            Path.Combine(WebDirectory, "index.html"),
            Path.Combine(WebDirectory, "health.php"),
            Path.Combine(WebDirectory, "app-manifest.json"),
        };

        var missing = requiredFiles.Where(path => !File.Exists(path)).ToArray();
        if (missing.Length > 0)
        {
            throw new FileNotFoundException(
                "Das Programmpaket ist unvollständig. Es fehlen:\n" + string.Join("\n", missing));
        }
    }

    private void EnsureUserDirectories()
    {
        Directory.CreateDirectory(UserDataRoot);
        Directory.CreateDirectory(DataDirectory);
        Directory.CreateDirectory(LogDirectory);
        Directory.CreateDirectory(SessionDirectory);
        Directory.CreateDirectory(TempDirectory);
        Directory.CreateDirectory(WebViewDirectory);
    }
}
