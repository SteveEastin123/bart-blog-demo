"""Check the plugin header, runtime version constant, and readme Stable tag."""
from __future__ import annotations

import argparse
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'wordpress-plugin/ehrman-blog-discovery'


def single_value(pattern: str, text: str, label: str) -> str:
    values = re.findall(pattern, text, re.MULTILINE)
    if len(values) != 1:
        raise ValueError(f'{label}: expected exactly one value, found {len(values)}.')
    return values[0]


def check_versions(plugin_dir: Path) -> str:
    source = (plugin_dir / 'ehrman-blog-discovery.php').read_text(encoding='utf-8-sig')
    readme = (plugin_dir / 'readme.txt').read_text(encoding='utf-8-sig')
    header = re.search(r'/\*\*(.*?)\*/', source, re.DOTALL)
    if header is None:
        raise ValueError('Plugin header is missing.')
    versions = {
        'Plugin header Version': single_value(
            r'^\s*\*\s*Version:[ \t]*([^\s]+)[ \t]*$', header.group(1), 'Plugin header Version'),
        'EHRMAN_DISCOVERY_VERSION': single_value(
            r'''^[ \t]*define\(\s*['"]EHRMAN_DISCOVERY_VERSION['"]\s*,\s*['"]([^'"\s]+)['"]\s*\);[ \t]*$''',
            source, 'EHRMAN_DISCOVERY_VERSION'),
        'Readme Stable tag': single_value(
            r'^Stable tag:[ \t]*([^\s]+)[ \t]*$', readme, 'Readme Stable tag'),
    }
    if len(set(versions.values())) != 1:
        details = '; '.join(f'{label}={value}' for label, value in versions.items())
        raise ValueError(f'Plugin versions do not match: {details}')
    return versions['Plugin header Version']


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--plugin-dir', type=Path, default=PLUGIN,
                        help='Plugin directory to check (defaults to the repository plugin).')
    args = parser.parse_args()
    try:
        version = check_versions(args.plugin_dir)
    except (ValueError, OSError) as error:
        print(f'FAIL: {error}')
        return 1
    print(f'PASS: plugin header, EHRMAN_DISCOVERY_VERSION, and readme Stable tag all match {version}.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
