#!/usr/bin/env python3
"""Run isolated PHP regressions and syntax checks. Does not contact a live site."""
import argparse
import json
from pathlib import Path
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[1]
EXCLUDED = {'seed-wp-cli.php', 'generate-fixtures.php', 'integration-wp.php'}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report', type=Path, help='Write a machine-readable verification report')
    parser.add_argument('--php-only', action='store_true', help='Skip JavaScript syntax checks')
    args = parser.parse_args()
    checks = []
    started = time.monotonic()
    commands = [('catalog', ROOT / 'tools/build-languages.py', ['python3', 'tools/build-languages.py', '--check'])]
    commands.extend(('test', path, ['php', str(path)])
                for path in sorted((ROOT / 'tests').glob('*.php'))
                if path.name not in EXCLUDED and not path.name.startswith('integration-'))
    for path in [ROOT / 'event-seat-manager.php', ROOT / 'uninstall.php',
                 *sorted((ROOT / 'src').rglob('*.php')), *sorted((ROOT / 'tests').glob('*.php')),
                 *sorted((ROOT / 'tools').glob('*.php'))]:
        commands.append(('php-lint', path, ['php', '-l', str(path)]))
    if not args.php_only:
        commands.extend(('js-lint', path, ['node', '--check', str(path)])
                        for path in sorted((ROOT / 'assets').rglob('*.js')))
        commands.extend(('js-test', path, ['node', str(path)])
                        for path in sorted((ROOT / 'tests').glob('*.js')))
    for category, path, command in commands:
        label = str(path.relative_to(ROOT))
        try:
            result = subprocess.run(command, cwd=ROOT, capture_output=True, text=True, timeout=90)
            ok = result.returncode == 0
            output = result.stdout + result.stderr
        except (OSError, subprocess.TimeoutExpired) as error:
            ok, output = False, str(error)
        checks.append({'kind': category, 'file': label, 'passed': ok, 'output': output})
        if category in ('test', 'catalog', 'js-test') or not ok:
            print(f'{"PASS" if ok else "FAIL"} {category}: {label}', flush=True)
        if not ok:
            print(output, flush=True)
    report = {'checks': checks, 'passed': all(c['passed'] for c in checks),
              'count': len(checks), 'seconds': round(time.monotonic() - started, 2)}
    if args.report:
        args.report.parent.mkdir(parents=True, exist_ok=True)
        args.report.write_text(json.dumps(report, indent=2, ensure_ascii=False) + '\n')
    print(f'{len(checks)} checks; {sum(not c["passed"] for c in checks)} failures; {report["seconds"]} seconds')
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    sys.exit(main())
