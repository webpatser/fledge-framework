#!/usr/bin/env bash
#
# verify-fledge-install.sh
#
# Run from the root of a Laravel project to confirm that the installed
# framework is webpatser/fledge-framework and not upstream laravel/framework.
#
# Exits 0 on Fledge, 1 on stock Laravel, 2 on missing/unknown.
#
# Usage:
#   bash vendor/webpatser/fledge-framework/bin/verify-fledge-install.sh
#

set -euo pipefail

if [ ! -d vendor ]; then
    echo "ERROR: no vendor/ directory. Run 'composer install' first." >&2
    exit 2
fi

if [ -d vendor/webpatser/fledge-framework ] && [ -f vendor/webpatser/fledge-framework/FLEDGE-CHANGELOG.md ]; then
    version=$(composer show webpatser/fledge-framework 2>/dev/null | awk '/^versions/ {print $NF}' || echo "unknown")
    echo "OK   running Fledge framework ${version}"
    echo "     vendor/webpatser/fledge-framework/FLEDGE-CHANGELOG.md present"
    exit 0
fi

if [ -d vendor/laravel/framework ]; then
    version=$(composer show laravel/framework 2>/dev/null | awk '/^versions/ {print $NF}' || echo "unknown")
    echo "WARN you are NOT running Fledge"
    echo "     vendor/laravel/framework is upstream Laravel ${version}"
    echo
    echo "To switch to Fledge, run:"
    echo "    composer require \"webpatser/fledge-framework:^13.7\" -W"
    echo
    echo "Note: 'composer require laravel/framework:...' will NOT install Fledge."
    echo "      The 'replace' mechanism only kicks in for transitive dependencies,"
    echo "      not top-level requires."
    exit 1
fi

echo "ERROR neither vendor/webpatser/fledge-framework nor vendor/laravel/framework found." >&2
echo "      This does not look like a Laravel project." >&2
exit 2
