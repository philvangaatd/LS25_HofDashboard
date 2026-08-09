from pathlib import Path

path = Path('index.html')
html = path.read_text(encoding='utf-8')

if '.bar-row { display: flex;' in html:
    raise SystemExit('shared bar styles already present')

marker = '    /* Tiere */\n'
if marker not in html:
    raise SystemExit('animal CSS marker not found')

shared = r'''    /* Gemeinsame Fortschrittsbalken */
    .bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; font-size: 12px; }
    .bar-label { width: 70px; flex-shrink: 0; color: var(--muted); font-family: var(--font-mono); font-size: 11px; }
    .bar-track { flex: 1; height: 8px; background: var(--ink-950); border-radius: 4px; overflow: hidden; border: 1px solid var(--ink-700); }
    .bar-fill { height: 100%; border-radius: 4px; }
    .bar-fill-progress { height: 100%; border-radius: 4px; background: var(--wheat-500); }
    .bar-fill.ok { background: var(--moss-500); }
    .bar-fill.warn { background: var(--wheat-500); }
    .bar-fill.bad { background: var(--rust-500); }
    .bar-value { width: 38px; text-align: right; font-family: var(--font-mono); font-size: 11px; }

    /* Wettervorschau (Hof-Übersicht) */
    .weather-row { display: flex; gap: 12px; flex-wrap: wrap; }
    .weather-day {
        background: var(--panel);
        border: 1px solid var(--border);
        border-radius: 10px;
        padding: 16px 20px;
        text-align: center;
        min-width: 118px;
    }
    .weather-day-label {
        font-family: var(--font-mono);
        font-size: 12.5px;
        color: var(--muted);
        margin-bottom: 8px;
        text-transform: uppercase;
        letter-spacing: 0.04em;
    }
    .weather-day-icon { font-size: 40px; margin-bottom: 8px; line-height: 1; }
    .weather-day-season { font-family: var(--font-mono); font-size: 13px; color: var(--muted); }
    .weather-day-precip {
        font-family: var(--font-mono);
        font-size: 11.5px;
        color: var(--moss-400);
        margin-top: 6px;
    }

'''

html = html.replace(marker, shared + marker, 1)
path.write_text(html, encoding='utf-8')

for required in ['.bar-row { display: flex;', '.bar-track { flex: 1;', '.bar-fill-progress', '.weather-row { display: flex;']:
    if required not in html:
        raise SystemExit(f'missing restored style: {required}')

print('shared bar/weather styles restored')
