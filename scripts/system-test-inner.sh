#!/usr/bin/env bash
set -euo pipefail

export COMPOSER_ALLOW_SUPERUSER=1

fail_deprecation_output() {
    local label="$1"
    local file="$2"
    local stripped
    stripped="$(mktemp)"
    sed -E 's/\x1b\[[0-9;]*[[:alpha:]]//g' "${file}" >"${stripped}"
    if grep -E '(^|[[:space:]])(PHP[[:space:]]+)?(Warning|Deprecated):' "${stripped}" >/dev/null; then
        echo "${label}: output contains Warning or Deprecated lines" >&2
        grep -E '(^|[[:space:]])(PHP[[:space:]]+)?(Warning|Deprecated):' "${stripped}" >&2 || true
        rm -f "${stripped}"
        exit 1
    fi
    rm -f "${stripped}"
}

php -v | tee /tmp/php-version.txt
php -r '
$v = PHP_VERSION;
if (version_compare($v, "8.1.31", "<")) {
    fwrite(STDERR, "PHP {$v} is older than required 8.1.31\n");
    exit(1);
}
'

for ext in bcmath ctype curl dom intl json mbstring phar simplexml tokenizer xml xmlwriter; do
    if ! php -m | grep -qi "^${ext}$"; then
        echo "Missing required PHP extension: ${ext}" >&2
        exit 1
    fi
done

COMPOSER_URL="https://getcomposer.org/download/2.8.10/composer.phar"
COMPOSER_SHA256="28dbb6bd8bef31479c7985b774c130a8bda37dbe63c35b56f6cb6bc377427573"
curl -fsSL -o /tmp/composer.phar "${COMPOSER_URL}"
echo "${COMPOSER_SHA256}  /tmp/composer.phar" | sha256sum -c -

composer_out="$(mktemp)"
set +e
php /tmp/composer.phar update --no-interaction --prefer-dist --no-progress >"${composer_out}" 2>&1
composer_status=$?
set -e
cat "${composer_out}"
fail_deprecation_output "composer update" "${composer_out}"
if [[ ${composer_status} -ne 0 ]]; then
    rm -f "${composer_out}"
    exit "${composer_status}"
fi
rm -f "${composer_out}"

php scripts/check-cluster-pins.php

audit_out="$(mktemp)"
set +e
php /tmp/composer.phar audit >"${audit_out}" 2>&1
audit_status=$?
set -e
cat "${audit_out}"
fail_deprecation_output "composer audit" "${audit_out}"
if [[ ${audit_status} -ne 0 ]]; then
    exit "${audit_status}"
fi
if ! grep -Fxq 'No security vulnerability advisories found.' "${audit_out}"; then
    echo 'composer audit: missing exact success line' >&2
    exit 1
fi
rm -f "${audit_out}"

phpunit_out="$(mktemp)"
set +e
vendor/bin/phpunit --colors=never --no-coverage >"${phpunit_out}" 2>&1
phpunit_status=$?
set -e
cat "${phpunit_out}"
fail_deprecation_output "phpunit" "${phpunit_out}"
if [[ ${phpunit_status} -ne 0 ]]; then
    exit "${phpunit_status}"
fi
if ! grep -E '^OK \([0-9]+ tests?, [0-9]+ assertions\)$' "${phpunit_out}" >/dev/null; then
    echo 'phpunit: missing strict OK success line' >&2
    exit 1
fi
rm -f "${phpunit_out}"

phpcs_out="$(mktemp)"
set +e
vendor/bin/phpcs >"${phpcs_out}" 2>&1
phpcs_status=$?
set -e
cat "${phpcs_out}"
fail_deprecation_output "phpcs" "${phpcs_out}"
if [[ ${phpcs_status} -ne 0 ]]; then
    exit "${phpcs_status}"
fi
if grep -E 'FOUND [1-9][0-9]* ERRORS|FOUND [1-9][0-9]* ERRORS AND [1-9][0-9]* WARNINGS|AND [1-9][0-9]* WARNINGS AFFECTING' "${phpcs_out}" >/dev/null; then
    echo 'phpcs: report contains errors or warnings' >&2
    exit 1
fi
rm -f "${phpcs_out}"

phpstan_out="$(mktemp)"
set +e
vendor/bin/phpstan analyse --memory-limit=-1 --no-progress --no-ansi >"${phpstan_out}" 2>&1
phpstan_status=$?
set -e
cat "${phpstan_out}"
fail_deprecation_output "phpstan" "${phpstan_out}"
if [[ ${phpstan_status} -ne 0 ]]; then
    exit "${phpstan_status}"
fi
if ! grep -F '[OK] No errors' "${phpstan_out}" >/dev/null; then
    echo 'phpstan: missing [OK] No errors' >&2
    exit 1
fi
rm -f "${phpstan_out}"

psalm_out="$(mktemp)"
set +e
vendor/bin/psalm --no-cache --no-progress >"${psalm_out}" 2>&1
psalm_status=$?
set -e
cat "${psalm_out}"
fail_deprecation_output "psalm" "${psalm_out}"
if [[ ${psalm_status} -ne 0 ]]; then
    exit "${psalm_status}"
fi
if ! grep -Fq 'No errors found!' "${psalm_out}"; then
    echo 'psalm: missing No errors found!' >&2
    exit 1
fi
rm -f "${psalm_out}"

echo 'system-test: all steps passed.'
