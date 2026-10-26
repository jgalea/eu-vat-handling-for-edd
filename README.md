<h1 align="center">EU VAT Handling for EDD</h1>

<p align="center">
  <a href="LICENSE"><img alt="License" src="https://img.shields.io/badge/LICENSE-GPL-5C9E31?style=for-the-badge"></a>
  <a href="https://github.com/jgalea"><img alt="Built by" src="https://img.shields.io/badge/BUILT%20BY-JEAN%20GALEA-8A2BE2?style=for-the-badge"></a>
</p>

<p align="center"><b>Free EU VAT handling for Easy Digital Downloads: number validation, reverse charge, the OSS threshold and a record on every order of why it was taxed that way.</b></p>

## What it does

Every order gets a VAT decision and a record of why: the customer's VAT number, the registry's answer, the location evidence and the rule that applied. If a tax office asks how an order was taxed, the answer is on the order.

When VIES is down, checkout keeps working. By default VAT is charged, the order is flagged and the number is checked again later, so an outage is never recorded as an invalid number. When the billing and IP countries disagree, the customer confirms their country instead of the plugin picking one.

The decision is applied through EDD's own tax calculation.

- VAT number field on the block and shortcode checkouts, checked against VIES (EU), HMRC (GB, with your own credentials), the Swiss UID register and the Brønnøysund register (Norway).
- Reverse charge for valid B2B numbers, with the legal wording on receipts, emails, EDD Invoices and EDD PDF Invoices.
- Origin VAT below the EUR 10,000 OSS threshold, destination VAT above it, switching automatically.
- Location evidence from the billing country and, behind Cloudflare or CloudFront, the IP country, with self-certification when they conflict.
- Standard and reduced rates imported from the EU's TEDB, with a diff before anything changes, and a VAT category per download.
- Orders that skip EDD's checkout checks (wallet payments, Buy Now, admin orders) are recorded too.
- EDD Recurring renewals are decided as new sales and flagged when the treatment changed since signup.
- Outage policy: by default an unavailable registry means the order is accepted, VAT is charged and the number is re-checked later.
- Correct an order's VAT number, country of evidence and treatment by hand, with a required reason kept in the decision log. The reports follow; amounts and refunds are untouched.
- Optional automatic refund of the VAT, through EDD's refund, when a number that couldn't be checked at checkout validates later. Off by default; skips and flags anything unusual.
- OSS return and EC Sales List reports with CSV export, plus REST endpoints.
- Reports for a quarter, a month or any date range, on screen, as CSV and over REST.
- Threshold warning email at a configurable share of the EUR 10,000 OSS threshold (80% by default) and again at 100%, once a year per level.
- Separate EU and UK store VAT numbers, chosen per order for receipts and invoices, with the right one sent to VIES and HMRC.
- An importer for Barn2's EDD EU VAT that copies settings and order history, with a dry run.
- Personal data exporters, erasers and a retention setting.

Settings are under Downloads > Settings > Taxes > EU VAT. `readme.txt` has the FAQ and the list of external services the plugin calls.

## Requirements

- WordPress 6.6 or later
- Easy Digital Downloads 3.1 or later, with taxes turned on
- PHP 7.4 or later

## Install

Download the zip from the releases page, upload it under Plugins > Add New > Upload Plugin and activate it. Turn on taxes under Downloads > Settings > Taxes, then open the EU VAT section and read the setup check. Coming from Barn2's plugin, run Downloads > Import from Barn2 before deactivating it.

## Known limitations

Each of these errs on the side of charging VAT.

- Consulting, legal, advertising and similar services to consumers outside the EU (Article 59 of the VAT Directive) are charged the store's VAT.
- Business customers in the UK are treated as consumers unless the store adds its own HMRC API credentials. Swiss and Norwegian numbers that can't be checked at checkout are charged VAT until they're re-checked.
- In a cart that's partly exempt because of a domestic exemption, the exempt line is charged VAT.
- Wallet and Buy Now checkouts can't ask the customer to confirm their country, so a conflict is flagged on the order instead.

## Development

```
composer install
npm install
npx wp-env start
```

Tests never call live services; registry responses come from recorded fixtures.

```
composer test:unit
composer lint
composer stan
npx wp-env run tests-cli --env-cwd=wp-content/plugins/eu-vat-handling-for-edd vendor/bin/phpunit --bootstrap tests/bootstrap-integration.php --testsuite integration
npx playwright test
```

`bin/build-zip.sh` builds the release zip.

## Disclaimer

This plugin is a tool, not tax advice. You, the store owner, are responsible for your VAT treatment and your filings. VAT rates and registry results come from third parties and can be wrong or out of date, so check them with an accountant. The plugin is provided without warranty and without liability, under the GPL.
