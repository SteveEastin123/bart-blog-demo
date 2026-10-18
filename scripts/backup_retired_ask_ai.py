"""Create and verify a private, offline recovery archive before retiring Ask AI.

No network/API calls. Credentials, dependency caches and existing backups are
excluded. The output belongs under the ignored wordpress-production/backups/.
"""

import argparse
import hashlib
import json
from pathlib import Path
import subprocess
import zipfile


ROOT = Path(__file__).resolve().parents[1]
SOURCES = (
    "experiments", "wordpress-plugin", "wordpress-theme", "wordpress-production",
    "scripts", "data/index", "data/audits", "data/vectors",
)
EXCLUDED = {"backups", "vendor", "node_modules", "__pycache__", ".git"}


def included(path):
    return not (
        any(part in EXCLUDED for part in path.relative_to(ROOT).parts)
        or path.name == ".env" or path.name.endswith((".env", ".pyc", ".session"))
    )


def digest(stream):
    result = hashlib.sha256()
    for block in iter(lambda: stream.read(1024 * 1024), b""):
        result.update(block)
    return result.hexdigest()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--name", default="ask-ai-retirement-2026-10-18")
    args = parser.parse_args()
    if Path(args.name).name != args.name or args.name in (".", ".."):
        parser.error("Use a directory name, not a path.")
    output = ROOT / "wordpress-production" / "backups" / args.name
    output.mkdir(parents=True, exist_ok=False)
    paths = sorted({
        path for source in SOURCES for path in (ROOT / source).rglob("*")
        if path.is_file() and included(path)
    })
    for name in (".gitignore", ".dockerignore"):
        paths.append(ROOT / name)
    manifest = {
        "gitHead": subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(),
        "note": "Private working-tree snapshot; credentials excluded. Not a GitHub backup.",
        "files": {},
    }
    archive = output / "recovery.zip"
    with zipfile.ZipFile(archive, "x", zipfile.ZIP_DEFLATED, compresslevel=3) as bundle:
        for path in paths:
            name = path.relative_to(ROOT).as_posix()
            with path.open("rb") as stream:
                checksum = digest(stream)
            manifest["files"][name] = {"sha256": checksum, "bytes": path.stat().st_size}
            bundle.write(path, name)
        bundle.writestr("RECOVERY-MANIFEST.json", json.dumps(manifest, indent=2))
    with zipfile.ZipFile(archive) as bundle:
        for name, entry in manifest["files"].items():
            with bundle.open(name) as stream:
                if digest(stream) != entry["sha256"]:
                    raise RuntimeError(f"Recovery verification failed: {name}")
    with archive.open("rb") as stream:
        checksum = digest(stream)
    receipt = {
        "archive": archive.name, "sha256": checksum, "bytes": archive.stat().st_size,
        "files": len(paths), "uncompressedBytes": sum(e["bytes"] for e in manifest["files"].values()),
        "verified": True, "gitHead": manifest["gitHead"],
    }
    (output / "receipt.json").write_text(json.dumps(receipt, indent=2) + "\n", encoding="utf-8")
    print(json.dumps(receipt, indent=2))


if __name__ == "__main__":
    main()
