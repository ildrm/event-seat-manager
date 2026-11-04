#!/usr/bin/env python3
"""Build a deterministic WordPress ZIP from an explicit runtime allowlist."""
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parents[1]
SLUG = 'event-seat-manager'
VERSION = '1.0.0'


def validate():
    subprocess.run(['python3', str(ROOT / 'tools/build-languages.py'), '--check'], check=True, cwd=ROOT)
    bootstrap = (ROOT / f'{SLUG}.php').read_text()
    assert f'Plugin Name: {SLUG}\n' in bootstrap
    assert f'Version: {VERSION}\n' in bootstrap
    assert f"define('PESM_VERSION', '{VERSION}')" in bootstrap
    assert f'Text Domain: {SLUG}\n' in bootstrap
    assert f'Stable tag: {VERSION}\n' in (ROOT / 'readme.txt').read_text()
    assert (ROOT / 'CHANGELOG.md').read_bytes() == b'', 'Release history must be empty'
    assert not (ROOT / 'persian-event-seat-manager.php').exists()
    for path in (ROOT / 'blocks').glob('*/block.json'):
        block = json.loads(path.read_text())
        assert block['version'] == VERSION, path
        assert block['textdomain'] == SLUG, path
    for path in (ROOT / 'languages').glob('*.json'):
        json.loads(path.read_text())
    for path in [*ROOT.glob('*.php'), *(ROOT / 'src').rglob('*.php'), *(ROOT / 'assets/js').glob('*.js')]:
        assert 'persian-event-seat-manager' not in path.read_text(), path


def main():
    validate()
    files = [ROOT / name for name in (f'{SLUG}.php', 'uninstall.php', 'readme.txt', 'README.md', 'LICENSE', 'CHANGELOG.md')]
    for directory in ('src', 'assets', 'blocks', 'languages'):
        files.extend(path for path in (ROOT / directory).rglob('*') if path.is_file() and not path.name.startswith('.'))
    files.extend(path for path in (ROOT / 'docs').rglob('*.md'))
    files.extend(path for path in (ROOT / 'design-system').rglob('*.md'))
    for name in ('integration-results.json', 'package-results.json', 'verification-summary.json', 'locale-results.json'):
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
        assert all(name.startswith(f'{SLUG}/') and '..' not in name.split('/') for name in names)
    digest = hashlib.sha256(destination.read_bytes()).hexdigest()
    destination.with_suffix('.zip.sha256').write_text(f'{digest}  {destination.name}\n')
    print(json.dumps({'archive': str(destination), 'files': len(files), 'bytes': destination.stat().st_size,
                      'sha256': digest}, indent=2))


if __name__ == '__main__':
    main()
