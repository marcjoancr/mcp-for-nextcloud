# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Reject incomplete, skipped or failed native-suite evidence."""
import json
import sys
import xml.etree.ElementTree as ET


def validate(filename):
    root = ET.parse(filename).getroot()
    cases = list(root.iter("testcase"))
    if not cases or any(list(case) for case in cases):
        raise ValueError("Missing tests or non-passing native test cases")
    assertions = sum(int(case.get("assertions", "0")) for case in cases)
    if assertions < len(cases):
        raise ValueError("Native suite has unasserted cases")
    return {"tests": len(cases), "assertions": assertions, "errors": 0, "failures": 0, "skipped": 0}


if __name__ == "__main__":
    print(json.dumps(validate(sys.argv[1])))
