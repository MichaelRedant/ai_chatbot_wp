#!/usr/bin/env python3
from __future__ import annotations

import argparse
import os
from pathlib import Path
import zipfile


EXCLUDE_TOP_LEVEL = {
    "dist",
    ".git",
    "wordpress-stubs",
    ".gitignore",
    "AGENTS.md",
    "prompt.md",
    "build-release.ps1",
    "build-flat-release.ps1",
    "build-release-posix.py",
    "composer.json",
    "composer.lock",
    "roadmap.md",
    "Woordenlijst Octopus.pdf",
}

EXCLUDE_SUBPATHS = {
    "vendor/php-stubs",
    "vendor/bin",
}


def is_excluded(rel_posix: str) -> bool:
    rel_posix = rel_posix.strip("/")
    if not rel_posix:
        return False

    parts = rel_posix.split("/")
    if parts[0] in EXCLUDE_TOP_LEVEL:
        return True

    if parts[0].startswith(".build-release") or parts[0].startswith(".build-flat-release") or parts[0].startswith(".build-posix-release"):
        return True

    for excluded in EXCLUDE_SUBPATHS:
        if rel_posix == excluded or rel_posix.startswith(excluded + "/"):
            return True

    return False


def sanitize_folder_name(name: str) -> str:
    cleaned = "".join(ch if ch.isalnum() or ch in "._-" else "-" for ch in name).strip(".")
    return cleaned or "ai-chatbot-posix"


def iter_files(root: Path):
    for path in root.rglob("*"):
        if path.is_dir():
            continue
        rel = path.relative_to(root).as_posix()
        if is_excluded(rel):
            continue
        yield path, rel


def build_zip(plugin_root: Path, output_zip: Path, package_folder: str, flat: bool) -> int:
    if output_zip.exists():
        output_zip.unlink()
    output_zip.parent.mkdir(parents=True, exist_ok=True)

    package_folder = sanitize_folder_name(package_folder)
    added = 0

    with zipfile.ZipFile(output_zip, "w", compression=zipfile.ZIP_DEFLATED) as zf:
        for file_path, rel in iter_files(plugin_root):
            if flat:
                arcname = rel
            else:
                arcname = f"{package_folder}/{rel}"
            # For safety, force forward slashes in zip entry names.
            arcname = arcname.replace("\\", "/")
            zf.write(file_path, arcname)
            added += 1

    return added


def main() -> int:
    plugin_root = Path(__file__).resolve().parent

    parser = argparse.ArgumentParser(description="Build WordPress plugin release zip with POSIX paths.")
    parser.add_argument("--output", default=str(plugin_root / "dist" / "ai-chatbot-posix-release.zip"))
    parser.add_argument("--package-folder", default="ai-chatbot-posix")
    parser.add_argument("--flat", action="store_true")
    args = parser.parse_args()

    output_zip = Path(args.output)
    if not output_zip.is_absolute():
        output_zip = (plugin_root / output_zip).resolve()

    added = build_zip(
        plugin_root=plugin_root,
        output_zip=output_zip,
        package_folder=args.package_folder,
        flat=bool(args.flat),
    )

    size_mb = round(output_zip.stat().st_size / (1024 * 1024), 2)
    mode = "flat" if args.flat else "folder"
    print(f"POSIX release ZIP gemaakt ({mode}): {output_zip} ({size_mb} MB, {added} files)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
