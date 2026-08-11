# LS25 Hof-Dashboard Technical Roadmap

This roadmap covers both repositories:

- `philvangaatd/LS25_HofDashboard`
- `philvangaatd/LS25_HofDashboardMod`

The goal is to stabilize the architecture first, then add high-value planning
features, and only then refine the UI. Work should stay split into small pull
requests with green CI before the next phase starts.

## Release Automation

The Windows environment has GitHub CLI installed at:

```text
C:\Program Files\GitHub CLI\gh.exe
```

Use the full path when `gh` is not available through `PATH`.

Release publishing should happen only after build artifacts are verified:

1. Build the dashboard Windows package.
2. Validate the generated ZIP, update manifest, and SHA-256 hash.
3. Build or assemble the FS25 mod ZIP.
4. Create a GitHub release with the finished ZIP artifacts.
5. Attach the dashboard ZIP, update manifest, and mod ZIP.

Do not publish manually uploaded or unverified artifacts.

## Phase 0: Working Baseline

Priority: P0

1. Create working branches in both repositories.
2. Keep this roadmap as the central checklist.
3. Verify local tooling:
   - PHP syntax and tests for the dashboard.
   - Lua 5.1 syntax/tests for the mod where available.
   - GitHub CLI for later PR, check, and release operations.

Done when:

- Both repositories have a clean working tree before implementation work.
- This roadmap exists in the dashboard repository.
- Tooling gaps are known and documented.

## Phase 1: Architecture Before Features

Priority: P0

### Dashboard API split

Refactor `api.php` into focused services:

1. `LiveDataService`
2. `SavegameService`
3. `BackupService`
4. `AutoDriveService`
5. `MapAssetService`
6. A small router/endpoint layer

Done when:

- Endpoint behavior is unchanged.
- Existing tests pass.
- New service-level tests cover the moved logic.
- `api.php` no longer owns parsing, normalization, backup, map asset, and
  routing responsibilities at the same time.

### Frontend split

Refactor `index.html` into focused assets:

1. CSS file
2. API client
3. App state/tab controller
4. Live dashboard views
5. Marker editor
6. Map editor

Done when:

- The browser UI behaves the same as before.
- Inline event handling is reduced for newly touched areas.
- The map and marker dirty-state protections still work.

### Mod collector split

Refactor `scripts/HofDashboard.lua` into collector modules:

1. Fields
2. Vehicles
3. Animals
4. Beehives
5. Productions
6. Contracts
7. Market
8. JSON/write helpers

Done when:

- `modDesc.xml` load order is explicit and tested.
- The exported `liveData.json` contract stays compatible.
- Existing Lua tests pass in CI.

## Phase 2: Tests And Safety

Priority: P0

Add fixtures and tests before adding new features:

1. Live data normalization tests for fields, vehicles, animals, productions,
   market, and contracts.
2. AutoDrive XML write tests for marker save, invalid waypoint rejection,
   backup creation, restore, and delete.
3. User settings tests for per-savegame persistence and sanitizing.
4. Map asset tests for upload, file type validation, and safe delete paths.
5. Launcher/update tests stay mandatory.

Done when:

- CI blocks regressions in live data, Savegame writes, and update packaging.
- Every write path has at least one fixture-backed test.

## Phase 3: Remove Or Simplify Legacy Paths

Priority: P1

1. Isolate or delete legacy savegame parsers that no longer provide canonical
   live data.
2. Remove misleading mission deadline placeholders or export real data from the
   mod.
3. Deprecate the `AutoDriveFlurkarte/liveData.json` fallback:
   - Warn in the next minor release.
   - Remove in the next major release.
4. Fix outdated UI copy, especially production storage wording.
5. Move direct farm-name editing behind a clearer advanced action.

Done when:

- Placeholder values are not presented as real data.
- Legacy paths are either documented as temporary or removed.
- The UI copy matches the current data model.

## Phase 4: High-Value Features

Priority: P1

1. Setup and compatibility assistant:
   - Mod active
   - Mod version
   - Protocol version
   - Live file age
   - Selected savegame
   - AutoDrive availability
   - Key paths
2. Task cockpit on the overview page:
   - Harvest-ready fields
   - Field work steps
   - Production bottlenecks
   - Full outputs
   - Animal feed/water/straw
   - Vehicle maintenance and washing
3. Production planner:
   - Active chains
   - Missing inputs
   - Full outputs
   - Water demand
   - Bottleneck sorting
4. Vehicle maintenance planner:
   - Wear
   - Dirt
   - Operating hours
   - Value
   - Fuel and cargo state

Done when:

- The overview answers what needs attention now.
- New features use the existing live data contract or extend it deliberately.

## Phase 5: Extended Features

Priority: P2

1. Animal feed forecast.
2. Sales assistant that combines own crops/products with current best prices.
3. AutoDrive network validation:
   - Isolated segments
   - Dead ends
   - Duplicate markers
   - Long edges
   - Unnamed markers

Done when:

- Features are useful without making the main dashboard noisy.
- Advanced checks live in dedicated panels or filters.

## Phase 6: Design And UX

Priority: P1/P2

1. Group navigation into:
   - Live
   - Planning
   - AutoDrive
   - System
2. Rebuild the overview as an operations cockpit.
3. Clarify map editor modes:
   - Move
   - Draw
   - Disconnect
   - Set marker
4. Replace emoji-heavy UI labels with a consistent icon system.
5. Review mobile layouts for tab bar, toolbars, cards, and the map editor.

Done when:

- The first screen is useful during repeated play sessions.
- Controls are easier to scan and less visually noisy.
- No important controls overlap at mobile or desktop widths.
