#!/bin/sh
# SPDX-FileCopyrightText: 2026 MCP for Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
set -eu

# Fetch a public GitHub release asset. The caller checks the pinned SHA-256 before extraction.
url=$1
archive=$2
fetch() {
    curl -fsSL --connect-timeout 15 --max-time 120 --retry 2 --retry-max-time 150 "$@"
}
if fetch -o "$archive" "$url"; then
    exit 0
fi

# Release redirects can return 504 while GitHub's official asset API is available.
case "$url" in https://github.com/*/*/releases/download/*/*) ;; *) exit 1 ;; esac
path=${url#https://github.com/}
repository=${path%%/releases/download/*}
release=${path#*/releases/download/}
api="https://api.github.com/repos/$repository/releases"
asset_id=$(fetch "$api/tags/${release%/*}" | php -r '
    $release = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
    foreach ($release["assets"] as $asset) {
        if ($asset["name"] === $argv[1] && is_int($asset["id"])) {
            echo $asset["id"];
            exit(0);
        }
    }
    exit(1);
' "${release##*/}")
fetch -H 'Accept: application/octet-stream' -o "$archive" "$api/assets/$asset_id"
