# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
import importlib.util
from pathlib import Path
import tempfile
import sys
sys.dont_write_bytecode = True
import unittest
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent

class CatalogTest(unittest.TestCase):
    def test_stock_discovery_cannot_load_qualified_tests(self):
        root = ET.parse(HERE.parent.parent / 'phpunit.xml').getroot()
        suite = root.find("./testsuites/testsuite[@name='integration']")
        self.assertIn('Mail', [p.text for p in suite.findall('exclude')])

    def module(self):
        spec = importlib.util.spec_from_file_location('catalog', HERE / 'catalog.py')
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_every_real_method_has_one_qualified_phase(self):
        module = self.module()
        catalog = module.load()
        self.assertEqual(45, len(catalog['tests']))
        module.validate(catalog)
        name = next(iter(catalog['tests']))
        catalog['tests'][name]['methods'].pop()
        with self.assertRaises(ValueError):
            module.validate(catalog)

    def test_unknown_file_or_phase_is_not_silently_excluded(self):
        module = self.module()
        for mutation in ('unknown', 'phase'):
            catalog = module.load()
            if mutation == 'unknown':
                catalog['tests']['UnknownTest.php'] = next(iter(catalog['tests'].values()))
            else:
                next(iter(catalog['tests'].values()))['phase'] = 'optional'
            with self.assertRaises(ValueError):
                module.validate(catalog)

    def test_junit_requires_every_method_and_refuses_skips_errors_and_empty(self):
        module = self.module()
        expected = {'MailTest::testRead', 'MailTest::testWrite'}
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'result.xml'
            good = '<testcase classname="MailTest" name="testRead"/><testcase classname="MailTest" name="testWrite with data set one"/>'
            path.write_text('<testsuites><testsuite>' + good + '</testsuite></testsuites>')
            self.assertEqual(2, module.result(path, expected)['tests'])
            for bad in ('', good.replace('testWrite with data set one', 'testOther'), good.replace('/>', '><skipped/></testcase>', 1), good.replace('/>', '><error>private</error></testcase>', 1)):
                path.write_text('<testsuites><testsuite>' + bad + '</testsuite></testsuites>')
                with self.assertRaises(ValueError):
                    module.result(path, expected)

if __name__ == '__main__':
    unittest.main()
