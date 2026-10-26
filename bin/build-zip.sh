#!/usr/bin/env bash
# Builds dist/eu-vat-handling-for-edd/ and dist/eu-vat-handling-for-edd.zip.
#
# LICENSED_ZIPS must list the commercial EDD add-on zips (Recurring, Invoices,
# PDF Invoices) the plugin integrates with, separated by spaces. The build
# fails if any of their files, by path or by content, ends up in the zip.

set -euo pipefail

slug=eu-vat-handling-for-edd
root="$( cd "$( dirname "${BASH_SOURCE[0]}" )/.." && pwd )"
dist="$root/dist"
work="$( mktemp -d )"
trap 'rm -rf "$work"' EXIT

if [ -z "${LICENSED_ZIPS:-}" ]; then
	echo "Set LICENSED_ZIPS to the EDD Recurring, Invoices and PDF Invoices zips." >&2
	exit 1
fi

rsync -a --exclude-from="$root/.distignore" --exclude=/vendor --exclude=/dist "$root/" "$work/$slug/"
composer install --working-dir="$work/$slug" --no-dev --optimize-autoloader --classmap-authoritative --no-interaction --quiet
rm -f "$work/$slug/composer.lock"

fail=0

forbidden="$( cd "$work/$slug" && find . -type f | grep -E '(^|/)(tests?|e2e|node_modules)/|/\.wp-env|/phpunit|/phpcs|/phpstan|/playwright|/package(-lock)?\.json$|/\.git|/\.distignore$|/\.wordpress-org/' || true )"
if [ -n "$forbidden" ]; then
	echo "Development files in the build:" >&2
	echo "$forbidden" >&2
	fail=1
fi

# Composer's generated autoloader is the same in every project that uses it.
composer_files='^\./(vendor/autoload\.php|vendor/composer/)'
( cd "$work/$slug" && find . -type f -size +0 | grep -vE "$composer_files" | xargs shasum ) | awk '{ print $1 }' | sort -u > "$work/build.sha"
: > "$work/licensed.sha"
# Paths that any plugin may have; their content is still compared below.
generic_paths='uninstall\.php|index\.php|src/Plugin\.php'
for zip in $LICENSED_ZIPS; do
	if [ ! -f "$zip" ]; then
		echo "Missing licensed zip: $zip" >&2
		exit 1
	fi
	name="$( basename "$zip" .zip )"
	mkdir "$work/licensed-$name"
	unzip -q "$zip" -d "$work/licensed-$name"
	count="$( find "$work/licensed-$name" -type f | wc -l | tr -d ' ' )"
	if [ "$count" -lt 10 ]; then
		echo "$zip has only $count files, which doesn't look like the add-on." >&2
		exit 1
	fi
	echo "Checking against $name ($count files)."
	( cd "$work/licensed-$name" && find . -type f -size +0 -exec shasum {} + ) | awk '{ print $1 }' >> "$work/licensed.sha"
	( cd "$work/licensed-$name" && find . -type f -name '*.php' ) | sed 's|^\./[^/]*/||' | sort -u > "$work/licensed-$name.paths"
	( cd "$work/$slug" && find . -type f -name '*.php' | grep -vE "$composer_files" ) | sed 's|^\./||' | sort -u > "$work/build.paths"
	same_path="$( comm -12 "$work/build.paths" "$work/licensed-$name.paths" | grep -vxE "$generic_paths" || true )"
	if [ -n "$same_path" ]; then
		echo "Files with the same path as in $name:" >&2
		echo "$same_path" >&2
		fail=1
	fi
done
sort -u -o "$work/licensed.sha" "$work/licensed.sha"
same_content="$( comm -12 "$work/build.sha" "$work/licensed.sha" )"
if [ -n "$same_content" ]; then
	echo "Files identical to a licensed add-on file:" >&2
	( cd "$work/$slug" && find . -type f -exec shasum {} + ) | grep -F "$same_content" >&2 || true
	fail=1
fi
if ( cd "$work/$slug" && find . | grep -qiE 'edd-recurring|edd-invoices|edd-pdf-invoices' ); then
	echo "A path in the build names a licensed add-on." >&2
	fail=1
fi

if [ "$fail" -ne 0 ]; then
	echo "Build failed." >&2
	exit 1
fi

rm -rf "$dist/$slug" "$dist/$slug.zip"
mkdir -p "$dist"
cp -R "$work/$slug" "$dist/$slug"
( cd "$dist" && zip -qr "$slug.zip" "$slug" )
echo "Built $dist/$slug.zip ($( find "$dist/$slug" -type f | wc -l | tr -d ' ' ) files, $( du -h "$dist/$slug.zip" | cut -f1 ))."
