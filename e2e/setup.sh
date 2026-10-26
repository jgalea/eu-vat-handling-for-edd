#!/usr/bin/env bash
set -euo pipefail

npx wp-env run cli wp plugin activate eu-vat-handling-for-edd
npx wp-env run cli wp eval-file wp-content/plugins/eu-vat-handling-for-edd/e2e/seed.php
