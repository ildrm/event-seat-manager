#!/usr/bin/env python3
"""Build reproducible bundled gettext/JS catalogs and check source-message coverage."""
from __future__ import annotations

import argparse
import gettext
import io
import json
import re
import struct
import subprocess
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
LANG = ROOT / "languages"
ARABIC = re.compile(r"[\u0622-\u06cc]")
SPAN = re.compile(r'''[^<>"']*[\u0600-\u06ff][^<>"']*''')


def source_messages() -> dict[str, list[str]]:
    paths = sorted((ROOT / "src").rglob("*.php"))
    process = subprocess.run(["php", str(ROOT / "tools/extract-i18n.php"), *map(str, paths)],
                             check=True, capture_output=True, text=True)
    messages: dict[str, list[str]] = {}
    for literal, locations in json.loads(process.stdout).items():
        for key in SPAN.findall(literal) if "<" in literal else [literal]:
            if re.search(r"[آ-ی]", key) and not re.fullmatch(r"[٠-٩۰-۹]+", key):
                messages.setdefault(key, []).extend(str(Path(p.rsplit(":", 1)[0]).relative_to(ROOT)) + ":" + p.rsplit(":", 1)[1] for p in locations)
    for path in sorted((ROOT / "assets/js").glob("*.js")):
        source = path.read_text()
        # Client wrappers deliberately use JSON double-quoted static source literals.
        for match in re.finditer(r'PESM_I18N\.text\(("(?:\\.|[^"\\])*")\)', source):
            value = json.loads(match[1])
            if re.search(r"[آ-ی]", value):
                messages.setdefault(value, []).append(str(path.relative_to(ROOT)))
        if path.name == "i18n.js":
            statuses = re.search(r"const statuses = \{([^}]+)\}", source)
            if statuses:
                for value in re.findall(r":\s*'([^']+)'", statuses[1]):
                    messages.setdefault(value, []).append("assets/js/i18n.js")
    return messages


def quote(value: str) -> str:
    return json.dumps(value, ensure_ascii=False)


def po_content(messages: dict[str, str], locale: str, references: dict[str, list[str]], template: bool = False) -> str:
    header = ("Project-Id-Version: event-seat-manager 1.0.0\n"
              "Report-Msgid-Bugs-To: \n"
              "POT-Creation-Date: 2026-11-04 00:00+0000\n"
              "PO-Revision-Date: 2026-11-04 00:00+0000\n"
              f"Language: {locale}\n"
              "MIME-Version: 1.0\n"
              "Content-Type: text/plain; charset=UTF-8\n"
              "Content-Transfer-Encoding: 8bit\n"
              "Plural-Forms: nplurals=2; plural=(n != 1);\n"
              "X-Domain: event-seat-manager\n")
    lines = ["# Event Seat Manager bundled translations. GPL-2.0-or-later.",
             'msgid ""', f"msgstr {quote(header)}", ""]
    for message, translation in sorted(messages.items()):
        if message in references:
            lines.append("#: " + " ".join(sorted(set(references[message]))))
        if re.search(r"%(?:\d+\$)?[sd]", message):
            lines.append("#, php-format")
        lines += [f"msgid {quote(message)}", f"msgstr {quote('' if template else translation)}", ""]
    return "\n".join(lines)


def mo_content(messages: dict[str, str], locale: str) -> bytes:
    header = (f"Project-Id-Version: event-seat-manager 1.0.0\nLanguage: {locale}\n"
              "Content-Type: text/plain; charset=UTF-8\nContent-Transfer-Encoding: 8bit\n"
              "Plural-Forms: nplurals=2; plural=(n != 1);\n")
    entries = {"": header, **messages}
    keys = sorted(entries)
    originals = [key.encode() for key in keys]
    translations = [entries[key].encode() for key in keys]
    count = len(keys)
    original_start = 28 + count * 16
    translated_start = original_start + sum(len(value) + 1 for value in originals)
    original_index = []
    translated_index = []
    offset = original_start
    for value in originals:
        original_index.append(struct.pack("<II", len(value), offset))
        offset += len(value) + 1
    offset = translated_start
    for value in translations:
        translated_index.append(struct.pack("<II", len(value), offset))
        offset += len(value) + 1
    return (struct.pack("<7I", 0x950412DE, 0, count, 28, 28 + count * 8, 0, 0)
            + b"".join(original_index) + b"".join(translated_index)
            + b"\0".join(originals) + b"\0" + b"\0".join(translations) + b"\0")


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Validate sources and compare generated files without writing")
    args = parser.parse_args()
    english: dict[str, str] = {}
    for filename in ["backend-en.json", "client-en.json", "admin-en.json", "ui-en.json"]:
        english.update(json.loads((LANG / filename).read_text()))
    persian_bootstrap = json.loads((LANG / "bootstrap-fa.json").read_text())
    references = source_messages()
    missing = sorted(set(references) - set(english))
    if missing:
        raise SystemExit("Untranslated source messages:\n" + json.dumps(missing, ensure_ascii=False, indent=2))
    for message, translation in english.items():
        if not isinstance(translation, str) or not translation:
            raise SystemExit(f"Empty translation: {message}")
        # Preserve placeholder identity, permitting a language-specific order.
        placeholders = lambda value: sorted(re.findall(r"%(?:\d+\$)?[sd]", value))
        if placeholders(message) != placeholders(translation):
            raise SystemExit(f"Placeholder mismatch: {message}")
    persian = {message: message for message in english}
    persian.update(persian_bootstrap)
    outputs = {
        "en.json": (json.dumps(dict(sorted(english.items())), ensure_ascii=False, indent=2) + "\n").encode(),
        "event-seat-manager.pot": po_content({**english, **persian_bootstrap}, "", references, True).encode(),
    }
    for locale, messages in [("en_US", english), ("fa_IR", persian)]:
        outputs[f"event-seat-manager-{locale}.po"] = po_content(messages, locale, references).encode()
        compiled = mo_content(messages, locale)
        loaded = gettext.GNUTranslations(io.BytesIO(compiled))
        if any(loaded.gettext(key) != value for key, value in messages.items()):
            raise SystemExit(f"Compiled gettext catalog failed independent round-trip: {locale}")
        outputs[f"event-seat-manager-{locale}.mo"] = compiled
    for name, content in outputs.items():
        path = LANG / name
        if args.check:
            if not path.exists() or path.read_bytes() != content:
                raise SystemExit(f"Outdated language artifact: languages/{name}; run python3 tools/build-languages.py")
        else:
            path.write_bytes(content)
    print(f"PASS: {len(references)} source messages covered; {len(english)} English translations; gettext catalogs {'checked' if args.check else 'built'}.")


if __name__ == "__main__":
    main()
