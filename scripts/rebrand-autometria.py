#!/usr/bin/env python3
"""AUTOMETRIA ERP rebrand: namespaces, copyright headers, license paths."""

from __future__ import annotations

import os
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

SKIP_DIRS = {
    "vendor",
    "node_modules",
    "storage",
    ".git",
    "dist",
    "mid",
    "html",
    "database/migrations.disabled",
}

COPYRIGHT = """/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */
"""

OLD_HEADER_RE = re.compile(
    r"^<\?php\s*/\*\*.*?(?:LASTIK|Lastik|AUTOMETRIA).*?\*/\s*(?:<\?php\s*)?",
    re.DOTALL,
)

# Also match duplicate php opening after header without full match
DOUBLE_PHP_RE = re.compile(r"^<\?php\s+<\?php\s+", re.MULTILINE)


def should_skip(rel: str) -> bool:
    parts = rel.replace("\\", "/").split("/")
    if parts[0] in SKIP_DIRS:
        return True
    if rel.startswith("storage/"):
        return True
    return False


def rewrite_namespaces(content: str) -> str:
    # Order matters: longer/more specific first
    content = content.replace("Lastik\\", "Autometria\\")
    content = content.replace("namespace App\\", "namespace Autometria\\")
    content = content.replace("use App\\", "use Autometria\\")
    content = content.replace("@var App\\", "@var Autometria\\")
    content = content.replace("@param App\\", "@param Autometria\\")
    content = content.replace("@return App\\", "@return Autometria\\")
    content = content.replace("@throws App\\", "@throws Autometria\\")
    content = content.replace("'App\\", "'Autometria\\")
    content = content.replace('"App\\', '"Autometria\\')
    content = content.replace("App\\Http\\", "Autometria\\Http\\")
    content = content.replace("App\\Models\\", "Autometria\\Models\\")
    content = content.replace("App\\Services\\", "Autometria\\Services\\")
    content = content.replace("App\\Exceptions\\", "Autometria\\Exceptions\\")
    content = content.replace("App\\Providers\\", "Autometria\\Providers\\")
    content = content.replace("App\\DTOs\\", "Autometria\\DTOs\\")
    content = content.replace("App\\Enums\\", "Autometria\\Enums\\")
    content = content.replace("App\\Policies\\", "Autometria\\Policies\\")
    content = content.replace("App\\Support\\", "Autometria\\Support\\")
    content = content.replace("App\\Jobs\\", "Autometria\\Jobs\\")
    content = content.replace("App\\Events\\", "Autometria\\Events\\")
    content = content.replace("App\\Listeners\\", "Autometria\\Listeners\\")
    content = content.replace("App\\Console\\", "Autometria\\Console\\")
    content = content.replace("App\\Rules\\", "Autometria\\Rules\\")
    content = content.replace("App\\View\\", "Autometria\\View\\")
    # Remaining App\ references (FQCN in strings etc.)
    content = re.sub(r"(?<![A-Za-z0-9_])App\\", lambda _m: "Autometria\\", content)
    return content


def normalize_header(content: str) -> str:
    content = content.lstrip("\ufeff")

    # Strip shebang
    content = re.sub(r"^#!.*\n", "", content)

    # Remove any number of leading <?php + LASTIK/old copyright blocks
    while True:
        m = re.match(
            r"^<\?php\s*(?:/\*\*.*?\*/\s*|/\*.*?\*/\s*)?(?:<\?php\s*)?",
            content,
            re.DOTALL,
        )
        if not m:
            break
        # Only strip if it's a copyright-ish block or bare php open before declare/namespace
        chunk = m.group(0)
        if "LASTIK" in chunk or "Lastik" in chunk or "AUTOMETRIA" in chunk or "Себиев" in chunk or chunk.strip() in ("<?php", "<?php\n"):
            content = content[m.end() :]
            continue
        break

    # If still starts with <?php, strip once
    if content.startswith("<?php"):
        content = content[5:].lstrip("\n")

    # Ensure declare/namespace body intact
    body = content.lstrip()

    return "<?php\n\n" + COPYRIGHT + "\n" + body


def process_php(path: Path) -> bool:
    rel = str(path.relative_to(ROOT))
    if should_skip(rel):
        return False

    original = path.read_text(encoding="utf-8")
    content = rewrite_namespaces(original)
    content = normalize_header(content)

    # License path / class renames inside content
    content = content.replace("lastik.lic", "autometria.lic")
    content = content.replace("SEBIEV_AHMED_LASTIK_", "SEBIEV_AHMED_AUTOMETRIA_")
    content = content.replace("EnforceSystemLicense", "EnforceAutometriaLicense")

    if content != original:
        path.write_text(content, encoding="utf-8")
        return True
    return False


def main() -> None:
    changed = 0
    for dirpath, dirnames, filenames in os.walk(ROOT):
        rel_dir = str(Path(dirpath).relative_to(ROOT))
        dirnames[:] = [
            d
            for d in dirnames
            if d not in SKIP_DIRS and not should_skip(str(Path(rel_dir) / d))
        ]
        for name in filenames:
            if not name.endswith(".php"):
                continue
            path = Path(dirpath) / name
            if process_php(path):
                changed += 1
                print(f"OK {path.relative_to(ROOT)}")

    print(f"Updated {changed} PHP files")


if __name__ == "__main__":
    main()
