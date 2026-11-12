# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Closed test selection and per-method evidence; no fixture effects."""
import json
from pathlib import Path
import re
import sys
import xml.etree.ElementTree as ET

HERE = Path(__file__).resolve().parent
INTEGRATION = HERE.parent.parent
PHASES = ('legacy', 'core', 'extras', 'regression', 'clean', 'source', 'projection')

def load():
    return json.loads((HERE / 'catalog.json').read_text())

def methods(path):
    return sorted(re.findall(r'public\s+function\s+(test\w+)\s*\(', path.read_text()))

def validate(catalog):
    actual = {p.name: methods(p) for p in (INTEGRATION / 'Mail').glob('*Test.php')}
    tests = catalog['tests']
    if catalog.get('schemaVersion') != 1 or set(tests) != set(actual):
        raise ValueError('Mail file coverage differs from the reviewed catalog')
    for name, entry in tests.items():
        if set(entry) != {'phase', 'stock', 'methods'} or entry['phase'] not in PHASES or type(entry['stock']) is not bool:
            raise ValueError('Unknown qualification phase or stock classification')
        if not entry['methods'] or entry['methods'] != actual[name]:
            raise ValueError('Mail method coverage differs: ' + name)
    suites = ET.parse(INTEGRATION / 'phpunit.xml').getroot()
    generic = suites.find("./testsuites/testsuite[@name='integration']")
    if 'Mail' not in [p.text for p in generic.findall('exclude')]:
        raise ValueError('Stock discovery loads the qualified directory')
    stock = suites.find("./testsuites/testsuite[@name='mail-stock']")
    declared = {p.text for p in stock.findall('file')}
    if declared != {'Mail/' + name for name, entry in tests.items() if entry['stock']}:
        raise ValueError('Stock suite and catalog differ')
    if any(stock.findall(tag) for tag in ('directory', 'exclude')):
        raise ValueError('Stock suite must list files explicitly')
    return catalog

def expected(catalog, phase):
    return {name[:-4] + '::' + method for name, entry in catalog['tests'].items() if entry['phase'] == phase for method in entry['methods']}

def result(path, required):
    root = ET.parse(path).getroot()
    cases = list(root.iter('testcase'))
    if not cases:
        raise ValueError('Empty qualification evidence')
    observed = set()
    records = []
    for case in cases:
        method = case.attrib.get('name', '').split(' with data set', 1)[0]
        name = case.attrib.get('class', case.attrib.get('classname', '')).rsplit('\\', 1)[-1].rsplit('.', 1)[-1] + '::' + method
        if any(case.find(tag) is not None for tag in ('error', 'failure', 'skipped', 'incomplete')):
            raise ValueError('Qualification contains failure, skip or incomplete: ' + name)
        observed.add(name)
        # Never publish failure bodies, test output, arguments or fixture payloads.
        records.append({'method': name, 'assertions': int(case.attrib.get('assertions', 0)), 'status': 'passed'})
    if observed != required:
        raise ValueError('Executed qualification methods differ from catalog')
    return {'tests': len(cases), 'methods': len(observed), 'assertions': sum(r['assertions'] for r in records), 'coverage': records}

def suite(catalog, phase, destination):
    if phase not in PHASES:
        raise ValueError('Unknown phase')
    root = ET.Element('phpunit', bootstrap='/mcp-it-src/mcp/tests/Integration/ci/mail-qualified/bootstrap.php', colors='false', cacheResult='false', failOnWarning='true', failOnRisky='true', failOnSkipped='true', failOnIncomplete='true', beStrictAboutOutputDuringTests='true')
    tests = ET.SubElement(ET.SubElement(root, 'testsuites'), 'testsuite', name=phase)
    for name, entry in catalog['tests'].items():
        if entry['phase'] == phase:
            ET.SubElement(tests, 'file').text = '/mcp-it-src/mcp/tests/Integration/Mail/' + name
    if not len(tests):
        raise ValueError('Empty phase')
    ET.ElementTree(root).write(destination, encoding='utf-8', xml_declaration=True)

if __name__ == '__main__':
    c = validate(load())
    print(json.dumps({'files': len(c['tests']), 'methods': sum(len(e['methods']) for e in c['tests'].values()), 'phases': {p: len(expected(c, p)) for p in PHASES}}))
