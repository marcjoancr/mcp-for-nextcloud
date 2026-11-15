# SPDX-License-Identifier: AGPL-3.0-or-later
"""Pure admission for the pinned classic Apache image, not a claim docker start has no effects."""
class Refused(RuntimeError):pass

def admit(actual,pins):
    keys={'command_exact','image_diff','hooks','version_equal','literal_versions','default_paths','dangerous_env_absent','apache_defaults','no_preload','stop_signal','restart_policy','sources'}
    if not isinstance(actual,dict) or set(actual)!=keys:raise Refused('Startup profile incomplete')
    for key in ('command_exact','version_equal','literal_versions','default_paths','dangerous_env_absent','apache_defaults','no_preload'):
        if actual[key] is not True:raise Refused('Startup profile unqualified')
    if actual['image_diff']!=[] or actual['hooks']!=[] or actual['stop_signal']!='SIGWINCH' or actual['restart_policy']!='no' or actual['sources']!=pins:raise Refused('Startup source profile changed')
    return True
