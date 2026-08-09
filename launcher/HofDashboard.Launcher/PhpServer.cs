using System.Diagnostics;
using System.Net;
using System.Net.Http.Json;
using System.Net.Sockets;
using System.Text.Json.Serialization;

namespace HofDashboard.Launcher;

internal sealed class PhpServer : IDisposable
{
    private readonly Process _process;
    private readonly LauncherLog _log;
    private bool _disposed;

    private PhpServer(Process process, LauncherLog log, int port)
    {
        _process = process;
        _log = log;
        Port = port;
        RootUri = new Uri($"http://127.0.0.1:{port}/", UriKind.Absolute);
    }

    public int Port { get; }

    public Uri RootUri { get; }

    public DashboardHealth? Health { get; private set; }

    public static async Task<PhpServer> StartAsync(
        AppPaths paths,
        LauncherLog log,
        CancellationToken cancellationToken)
    {
        paths.ValidatePackage();

        var port = AllocateLoopbackPort();
        var startInfo = new ProcessStartInfo
        {
            FileName = paths.PhpExecutable,
            WorkingDirectory = paths.WebDirectory,
            UseShellExecute = false,
            CreateNoWindow = true,
            RedirectStandardOutput = true,
            RedirectStandardError = true,
        };

        startInfo.ArgumentList.Add("-c");
        startInfo.ArgumentList.Add(paths.PhpConfiguration);
        startInfo.ArgumentList.Add("-d");
        startInfo.ArgumentList.Add($"error_log={paths.PhpLog}");
        startInfo.ArgumentList.Add("-d");
        startInfo.ArgumentList.Add($"session.save_path={paths.SessionDirectory}");
        startInfo.ArgumentList.Add("-d");
        startInfo.ArgumentList.Add($"upload_tmp_dir={paths.TempDirectory}");
        startInfo.ArgumentList.Add("-d");
        startInfo.ArgumentList.Add($"sys_temp_dir={paths.TempDirectory}");
        startInfo.ArgumentList.Add("-S");
        startInfo.ArgumentList.Add($"127.0.0.1:{port}");
        startInfo.ArgumentList.Add("-t");
        startInfo.ArgumentList.Add(paths.WebDirectory);
        startInfo.Environment["HOF_DASHBOARD_DATA_DIR"] = paths.DataDirectory;
        startInfo.Environment["HOF_DASHBOARD_LAUNCHER_VERSION"] = Application.ProductVersion;

        var process = new Process
        {
            StartInfo = startInfo,
            EnableRaisingEvents = true,
        };

        var server = new PhpServer(process, log, port);

        try
        {
            log.Info($"Starte PHP auf 127.0.0.1:{port}.");
            if (!process.Start())
            {
                throw new InvalidOperationException("Der eingebettete PHP-Server konnte nicht gestartet werden.");
            }

            _ = server.PumpOutputAsync(process.StandardOutput, "PHP");
            _ = server.PumpOutputAsync(process.StandardError, "PHP-ERR");
            server.Health = await server.WaitForHealthAsync(TimeSpan.FromSeconds(20), cancellationToken);
            log.Info($"Healthcheck erfolgreich: Dashboard {server.Health.Version}.");
            return server;
        }
        catch
        {
            server.Dispose();
            throw;
        }
    }

    public void Dispose()
    {
        if (_disposed)
        {
            return;
        }

        _disposed = true;

        try
        {
            if (!_process.HasExited)
            {
                _log.Info("Beende den eingebetteten PHP-Server.");
                _process.Kill(entireProcessTree: true);
                _process.WaitForExit(5_000);
            }
        }
        catch (Exception exception)
        {
            _log.Warning($"PHP konnte nicht vollständig beendet werden: {exception.Message}");
        }
        finally
        {
            _process.Dispose();
        }
    }

    private static int AllocateLoopbackPort()
    {
        using var listener = new TcpListener(IPAddress.Loopback, 0);
        listener.Start();
        return ((IPEndPoint)listener.LocalEndpoint).Port;
    }

    private async Task<DashboardHealth> WaitForHealthAsync(
        TimeSpan timeout,
        CancellationToken cancellationToken)
    {
        using var handler = new SocketsHttpHandler { UseProxy = false };
        using var client = new HttpClient(handler) { Timeout = TimeSpan.FromSeconds(2) };
        var deadline = DateTimeOffset.UtcNow + timeout;
        Exception? lastError = null;

        while (DateTimeOffset.UtcNow < deadline)
        {
            cancellationToken.ThrowIfCancellationRequested();

            if (_process.HasExited)
            {
                throw new InvalidOperationException(
                    $"PHP wurde während des Starts mit Exitcode {_process.ExitCode} beendet.",
                    lastError);
            }

            try
            {
                var healthUri = new Uri(RootUri, "health.php");
                using var response = await client.GetAsync(healthUri, cancellationToken);
                if (response.IsSuccessStatusCode)
                {
                    var health = await response.Content.ReadFromJsonAsync<DashboardHealth>(
                        cancellationToken: cancellationToken);

                    if (health is { Status: "ok", Component: "dashboard" }
                        && !string.IsNullOrWhiteSpace(health.Version))
                    {
                        return health;
                    }
                }
            }
            catch (Exception exception) when (
                (exception is HttpRequestException or TaskCanceledException)
                && !cancellationToken.IsCancellationRequested)
            {
                lastError = exception;
            }

            await Task.Delay(250, cancellationToken);
        }

        throw new TimeoutException("Der Dashboard-Healthcheck hat nicht rechtzeitig geantwortet.", lastError);
    }

    private async Task PumpOutputAsync(StreamReader reader, string source)
    {
        try
        {
            while (await reader.ReadLineAsync() is { } line)
            {
                if (!string.IsNullOrWhiteSpace(line))
                {
                    _log.Info($"[{source}] {line}");
                }
            }
        }
        catch (ObjectDisposedException)
        {
            // Expected during application shutdown.
        }
        catch (InvalidOperationException)
        {
            // Expected when the process exits while a stream read is pending.
        }
    }
}

internal sealed record DashboardHealth(
    [property: JsonPropertyName("status")] string Status,
    [property: JsonPropertyName("component")] string Component,
    [property: JsonPropertyName("version")] string Version);
