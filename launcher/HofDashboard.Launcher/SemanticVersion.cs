namespace HofDashboard.Launcher;

internal readonly record struct SemanticVersion(
    int Major,
    int Minor,
    int Patch,
    string? PreRelease = null) : IComparable<SemanticVersion>
{
    public static SemanticVersion Current
    {
        get
        {
            var version = typeof(SemanticVersion).Assembly.GetName().Version
                ?? throw new InvalidOperationException("Die installierte App-Version konnte nicht ermittelt werden.");
            return new SemanticVersion(version.Major, version.Minor, Math.Max(version.Build, 0));
        }
    }

    public static SemanticVersion Parse(string value)
    {
        if (!TryParse(value, out var version))
        {
            throw new FormatException($"Ungültige Versionsnummer: {value}");
        }

        return version;
    }

    public static bool TryParse(string? value, out SemanticVersion version)
    {
        version = default;
        if (string.IsNullOrWhiteSpace(value))
        {
            return false;
        }

        var withoutBuildMetadata = value.Split('+', 2)[0];
        var versionParts = withoutBuildMetadata.Split('-', 2);
        var numbers = versionParts[0].Split('.');
        if (numbers.Length != 3
            || !int.TryParse(numbers[0], out var major)
            || !int.TryParse(numbers[1], out var minor)
            || !int.TryParse(numbers[2], out var patch)
            || major < 0
            || minor < 0
            || patch < 0)
        {
            return false;
        }

        var preRelease = versionParts.Length == 2 ? versionParts[1] : null;
        if (preRelease is not null
            && (preRelease.Length == 0
                || preRelease.Split('.').Any(part => part.Length == 0
                    || part.Any(character => !char.IsAsciiLetterOrDigit(character) && character != '-'))))
        {
            return false;
        }

        version = new SemanticVersion(major, minor, patch, preRelease);
        return true;
    }

    public int CompareTo(SemanticVersion other)
    {
        var coreComparison = Major.CompareTo(other.Major);
        if (coreComparison == 0)
        {
            coreComparison = Minor.CompareTo(other.Minor);
        }
        if (coreComparison == 0)
        {
            coreComparison = Patch.CompareTo(other.Patch);
        }
        if (coreComparison != 0)
        {
            return coreComparison;
        }

        if (PreRelease is null)
        {
            return other.PreRelease is null ? 0 : 1;
        }
        if (other.PreRelease is null)
        {
            return -1;
        }

        var left = PreRelease.Split('.');
        var right = other.PreRelease.Split('.');
        for (var index = 0; index < Math.Max(left.Length, right.Length); index++)
        {
            if (index >= left.Length)
            {
                return -1;
            }
            if (index >= right.Length)
            {
                return 1;
            }

            var leftIsNumber = int.TryParse(left[index], out var leftNumber);
            var rightIsNumber = int.TryParse(right[index], out var rightNumber);
            int comparison;
            if (leftIsNumber && rightIsNumber)
            {
                comparison = leftNumber.CompareTo(rightNumber);
            }
            else if (leftIsNumber)
            {
                comparison = -1;
            }
            else if (rightIsNumber)
            {
                comparison = 1;
            }
            else
            {
                comparison = string.Compare(left[index], right[index], StringComparison.Ordinal);
            }

            if (comparison != 0)
            {
                return comparison;
            }
        }

        return 0;
    }

    public override string ToString() => PreRelease is null
        ? $"{Major}.{Minor}.{Patch}"
        : $"{Major}.{Minor}.{Patch}-{PreRelease}";
}
