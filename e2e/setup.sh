#!/usr/bin/env bash
set -euo pipefail

npx wp-env run cli wp plugin activate vat-right-for-edd
npx wp-env run cli wp eval-file wp-content/plugins/vat-right-for-edd/e2e/seed.php
