from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / 'scripts'))
from check_wordpress_versions import check_versions

SOURCE = """<?php
/**
 * Plugin Name: Ehrman Blog Discovery
 * Version:     1.2.3
 */
define( 'EHRMAN_DISCOVERY_VERSION', '1.2.3' );
define( 'EHRMAN_DISCOVERY_SCHEMA_VERSION', '9.0.0' );
"""
README = '=== Ehrman Blog Discovery ===\nStable tag: 1.2.3\n'


class WordPressVersionTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.plugin = Path(temporary.name)
        self.write_files()

    def write_files(self, source=SOURCE, readme=README):
        (self.plugin / 'ehrman-blog-discovery.php').write_text(source, encoding='utf-8')
        (self.plugin / 'readme.txt').write_text(readme, encoding='utf-8')

    def test_matching_versions_allow_independent_schema_version(self):
        self.assertEqual(check_versions(self.plugin), '1.2.3')

    def test_each_version_mismatch_is_reported(self):
        for source, readme in (
            (SOURCE.replace('Version:     1.2.3', 'Version:     1.2.4'), README),
            (SOURCE.replace("'EHRMAN_DISCOVERY_VERSION', '1.2.3'", "'EHRMAN_DISCOVERY_VERSION', '1.2.4'"), README),
            (SOURCE, README.replace('1.2.3', '1.2.4')),
        ):
            with self.subTest(source=source, readme=readme):
                self.write_files(source, readme)
                with self.assertRaisesRegex(ValueError, 'Plugin versions do not match:'):
                    check_versions(self.plugin)

    def test_missing_or_empty_values_are_rejected(self):
        for source, readme in (
            (SOURCE.replace(' * Version:     1.2.3\n', ''), README),
            (SOURCE.replace('Version:     1.2.3', 'Version:     '), README),
            (SOURCE.replace("define( 'EHRMAN_DISCOVERY_VERSION', '1.2.3' );\n", ''), README),
            (SOURCE, README.replace('Stable tag: 1.2.3', 'Stable tag:')),
        ):
            with self.subTest(source=source, readme=readme):
                self.write_files(source, readme)
                with self.assertRaisesRegex(ValueError, 'expected exactly one value, found 0'):
                    check_versions(self.plugin)

    def test_duplicate_values_are_rejected_even_when_equal(self):
        for source, readme in (
            (SOURCE.replace(' * Version:     1.2.3', ' * Version:     1.2.3\n * Version:     1.2.3'), README),
            (SOURCE + "define( 'EHRMAN_DISCOVERY_VERSION', '1.2.3' );\n", README),
            (SOURCE, README + 'Stable tag: 1.2.3\n'),
        ):
            with self.subTest(source=source, readme=readme):
                self.write_files(source, readme)
                with self.assertRaisesRegex(ValueError, 'expected exactly one value, found 2'):
                    check_versions(self.plugin)

    def test_header_is_required(self):
        self.write_files("<?php\ndefine( 'EHRMAN_DISCOVERY_VERSION', '1.2.3' );")
        with self.assertRaisesRegex(ValueError, 'Plugin header is missing'):
            check_versions(self.plugin)

    def test_crlf_and_bom_are_supported(self):
        self.write_files('\ufeff' + SOURCE.replace('\n', '\r\n'), '\ufeff' + README.replace('\n', '\r\n'))
        self.assertEqual(check_versions(self.plugin), '1.2.3')

    def test_cli_returns_failure_for_mismatch_and_missing_file(self):
        for mode in ('match', 'mismatch', 'missing'):
            with self.subTest(mode=mode):
                self.write_files(readme=README if mode == 'match' else README.replace('1.2.3', '1.2.4'))
                if mode == 'missing':
                    (self.plugin / 'readme.txt').unlink()
                result = subprocess.run(
                    [sys.executable, '-B', str(ROOT / 'scripts/check_wordpress_versions.py'),
                     '--plugin-dir', str(self.plugin)], capture_output=True, text=True, timeout=10)
                self.assertEqual(result.returncode, 0 if mode == 'match' else 1)
                self.assertIn('PASS:' if mode == 'match' else 'FAIL:', result.stdout)

    def test_repository_versions_match(self):
        check_versions(ROOT / 'wordpress-plugin/ehrman-blog-discovery')


if __name__ == '__main__':
    unittest.main()
