#!/bin/sh
# Rebuilds vendor/ (the Anthropic PHP SDK and Guzzle, for the back office's AI features), kept in the
# repository so that the host needs nothing installed: a git pull is enough. Run on a computer with
# Composer, then commit vendor/.
set -e
cd "$(dirname "$0")/.."
rm -rf vendor
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
# What a site never runs: the libraries' tests, docs and git folders.
find vendor -mindepth 3 -maxdepth 3 \( -name .git -o -name tests -o -name test -o -name Tests -o -name docs -o -name examples -o -name scripts -o -name fixtures -o -name .github -o -name "*.md" -o -name ".*" \) -exec rm -rf {} +
php -r 'require "vendor/autoload.php"; new Anthropic\Client(apiKey: "check"); echo "vendor ok\n";'
