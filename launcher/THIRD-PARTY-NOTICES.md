# Third-party components in the Windows app

The generated Windows package contains these externally maintained runtime components:

- Microsoft .NET 10 self-contained runtime — Microsoft licensing information is
  available at <https://github.com/dotnet/runtime>.
- Microsoft Edge WebView2 SDK 1.0.4129.50 — package and license information is
  available at <https://www.nuget.org/packages/Microsoft.Web.WebView2/1.0.4129.50>.
- PHP 8.5.9 x64 NTS for Windows — the PHP license is included in the downloaded
  runtime archive and available at <https://www.php.net/license/>.

The package uses the installed Evergreen WebView2 Runtime. It does not redistribute
a fixed WebView2 browser runtime.
