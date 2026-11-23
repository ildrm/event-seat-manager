#!/usr/bin/env python3
"""Build a deterministic WordPress ZIP from an explicit runtime allowlist."""
import hashlib
import json
from pathlib import Path
import subprocess
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'event-seat-manager'
VERSION = '2.0.0'
BLOCKS = ('events', 'event', 'seat-map', 'my-tickets', 'gate-scanner')


def validate():
    subprocess.run(['node', str(ROOT / 'tools/build-assets.mjs'), '--check'], check=True, cwd=ROOT)
    subprocess.run([sys.executable, str(ROOT / 'tools/build-languages.py'), '--check'], check=True, cwd=ROOT)
    bootstrap = (ROOT / f'{SLUG}.php').read_text(encoding='utf-8')
    assert any(f'Plugin Name: {name}\n' in bootstrap for name in ('Event Seat Manager', SLUG))
    assert f'Version: {VERSION}\n' in bootstrap
    assert f"define('PESM_VERSION', '{VERSION}')" in bootstrap
    assert f'Text Domain: {SLUG}\n' in bootstrap
    assert f'Stable tag: {VERSION}\n' in (ROOT / 'readme.txt').read_text(encoding='utf-8')
    assert (ROOT / 'CHANGELOG.md').read_bytes() == b'', 'Release history must be empty'
    assert not (ROOT / 'persian-event-seat-manager.php').exists()
    for slug in BLOCKS:
        path = ROOT / 'blocks' / slug / 'block.json'
        block = json.loads(path.read_text(encoding='utf-8'))
        assert block['name'] == f'pesm/{slug}', path
        assert block['version'] == VERSION, path
        assert block['textdomain'] == SLUG, path
    assert (ROOT / 'assets/js/blocks-editor.js').is_file(), 'Missing block editor script'
    for path in (ROOT / 'languages').glob('*.json'):
        json.loads(path.read_text(encoding='utf-8'))
    for path in [*ROOT.glob('*.php'), *(ROOT / 'src').rglob('*.php'), *(ROOT / 'assets/js').glob('*.js')]:
        assert 'persian-event-seat-manager' not in path.read_text(encoding='utf-8'), path


def main():
    validate()
    files = [ROOT / name for name in (f'{SLUG}.php', 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE', 'CHANGELOG.md')]
    for directory in ('src', 'assets', 'blocks', 'languages'):
        files.extend(path for path in (ROOT / directory).rglob('*') if path.is_file() and not path.name.startswith('.')
                     and not (directory == 'assets' and ('src' in path.relative_to(ROOT / directory).parts or path.suffix == '.map')))
    files.extend(path for path in (ROOT / 'docs').rglob('*.md'))
    files.extend(path for path in (ROOT / 'design-system').rglob('*.md'))
    for name in ('integration-results.json', 'package-results.json', 'verification-summary.json', 'locale-results.json',
                 'venue-verification-results.json', 'venue-legacy-results.json', 'venue-generator-results.json',
                 'venue-allocation-results.json', 'venue-adapter-results.json', 'venue-load-results.json',
                 'venue-profile-results.json', 'venue-package-results.json', 'venue-hpos-results.json',
                 'venue-race-results.json', f'release-{VERSION}-results.json'):
        evidence = ROOT / 'docs' / 'qa' / name
        if evidence.is_file():
            files.append(evidence)
    destination = ROOT / 'dist' / f'{SLUG}-{VERSION}.zip'
    destination.parent.mkdir(exist_ok=True)
    with zipfile.ZipFile(destination, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for path in sorted(files):
            relative = path.relative_to(ROOT).as_posix()
            info = zipfile.ZipInfo(f'{SLUG}/{relative}', date_time=(2026, 11, 4, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())
    with zipfile.ZipFile(destination) as archive:
        assert archive.testzip() is None
        names = archive.namelist()
        assert len(names) == len(set(names))
        assert f'{SLUG}/{SLUG}.php' in names
        assert f'{SLUG}/assets/js/blocks-editor.js' in names
        assert all(f'{SLUG}/blocks/{slug}/block.json' in names for slug in BLOCKS)
        assert all(name.startswith(f'{SLUG}/') and '..' not in name.split('/') for name in names)
    digest = hashlib.sha256(destination.read_bytes()).hexdigest()
    destination.with_suffix('.zip.sha256').write_text(f'{digest}  {destination.name}\n')
    print(json.dumps({'archive': str(destination), 'files': len(files), 'bytes': destination.stat().st_size,
                      'sha256': digest}, indent=2))


if __name__ == '__main__':
    main()
