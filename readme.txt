=== EU VAT Handling for EDD ===
Contributors: jeangalea
Tags: vat, easy digital downloads, eu vat, oss, reverse charge
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Requires Plugins: easy-digital-downloads
Stable tag: 0.1.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Free EU VAT for Easy Digital Downloads. Every order records why it was taxed the way it was, and checkout keeps working when VIES is down.

== Description ==

Every order gets a VAT decision and a record of why: the customer's VAT number, the registry's answer, the location evidence and the rule that applied. If a tax office asks how an order was taxed, the answer is on the order.

When VIES is down, checkout keeps working. By default VAT is charged, the order is flagged and the number is checked again later, so an outage is never recorded as an invalid number. When a customer's billing country and IP country disagree, the customer confirms their country instead of the plugin picking one.

The plugin applies each decision through EDD's own tax calculation. It's free, has no Pro version and shows no branding in your store. Stores moving from Barn2's EDD EU VAT can bring their settings and order history across in one import.

Settings live under Downloads > Settings > Taxes > EU VAT. The rates screen is under Downloads > EU VAT and the reports under Downloads > EU VAT reports.

It needs Easy Digital Downloads 3.1 or later with taxes turned on.

**What it does**

* Adds a VAT number field to the block checkout and the shortcode checkout. Numbers are checked for format first, then against the official registry, and the cart's tax updates as soon as the answer comes back. Logged-in customers get their last number filled in.
* Registries: VIES for EU numbers, HMRC for GB numbers (needs your own free HMRC developer credentials, see the FAQ), the Swiss UID register for CHE numbers and the Brønnøysund register for Norwegian numbers.
* Applies reverse charge to B2B sales with a valid number. When your prices include tax, the included VAT is taken off the price, so a business buyer pays the net amount. The legal wording and the customer's number go on the receipt, in the email tags {vat_number}, {vat_notice} and {vat_company}, and on EDD Invoices and EDD PDF Invoices when either add-on is active. A valid number from your own country never exempts unless you switch that on.
* Charges B2C customers your country's VAT below the EUR 10,000 OSS threshold and the destination country's VAT above it, switching automatically. You can also tell the plugin you're OSS-registered to always charge destination VAT.
* Uses the billing country as location evidence, plus the visitor's IP country when your site is behind Cloudflare or Amazon CloudFront. When the evidence conflicts, the customer can be asked to confirm their country.
* Imports standard and reduced rates from the EU's TEDB into EDD's tax rates. It shows a diff first and never changes a rate until you apply it. Rates you replace are kept as inactive rows, the way EDD keeps them. A weekly check tells you when the EU rates change.
* A VAT category on each download (standard, reduced or super-reduced), so e-books and similar products get their reduced rate in the same cart as standard-rate products.
* Records the decision on every order: the number, the registry answer, the VIES consultation number when you've entered your own VAT number, the location evidence and the rule that applied. It also corrects the tax rate EDD links to the order, so refunds and renewals use the right one. An EU VAT section on the order screen shows the decision, with a log and a button to re-check the number.
* Records orders that skip EDD's checkout checks too, such as wallet payments and manual orders added in the admin, and flags the ones it couldn't decide cleanly.
* Re-checks numbers that couldn't be validated at checkout and can email you when one validates after the order was placed.
* Quarterly OSS return and EC Sales List reports with CSV export, in EUR. Orders in other currencies are converted at the ECB reference rate. Refunds and revoked orders are recorded against the period they belong to.
* Reports for a quarter, a month or any date range, in the screen, the CSV and the REST endpoints, for countries that want the EC Sales List monthly.
* Emails the store admin when EU sales to consumers reach a share of the EUR 10,000 OSS threshold (80% by default) and again when they pass it, once a year per level. You can turn it off or change the share.
* A separate UK store VAT number: UK customers see it on receipts and invoices and HMRC checks use it, while everyone else gets the EU number and VIES checks keep using that one. With one number set, nothing changes.
* REST endpoints for both reports, restricted to users who can view shop reports.
* Works with EDD Recurring: renewals are recorded at the amount the gateway charged, with the VAT that decision calls for inside it. See the FAQ.
* A setup check lists what's missing: taxes turned on, your shop country and VAT number, tax rates for destination countries, the IP country source and HMRC credentials.
* Registers WordPress personal data exporters and erasers, and a retention setting for the stored data.

**Moving from Barn2's EDD EU VAT**

Downloads > Import from Barn2, which appears while Barn2's plugin is active or its settings are still in the database, copies Barn2's settings across and imports your order history into the OSS and EC Sales List reports, so the threshold and past quarters are right from the first day. Run the dry run first: it counts what would be imported per quarter and scheme and writes nothing. The same import runs from WP-CLI with `wp eu-vat-handling-for-edd import-barn2`. Settings are copied on the server and never shown on screen or in logs. Barn2's VATSense key isn't copied, because this plugin checks GB numbers with HMRC instead.

While Barn2's plugin is active, this plugin only offers the import and doesn't touch checkout or orders. Deactivate Barn2's plugin once the import is done.

== Installation ==

1. Make sure Easy Digital Downloads is active, then install and activate EU VAT Handling for EDD.
2. Go to Downloads > Settings > Taxes and turn on taxes. EDD only shows the billing address fields, which the plugin needs, when taxes are on.
3. Go to Downloads > Settings > Taxes > EU VAT and read the setup check.
4. Enter your store VAT number so VIES can return a consultation number for each check.
5. Apply the EU rates under Downloads > EU VAT.
6. If you used Barn2's EDD EU VAT, run the import under Downloads > Import from Barn2.

== Frequently Asked Questions ==

= What happens when VIES is down? =

Checkout still works. By default the order is accepted, VAT is charged and the order is flagged as pending validation. The plugin checks the number again later and, if it validates, marks the order and can email you so you can refund the VAT. The setting "When a registry is unavailable" offers two alternatives: accept the order, exempt the VAT and re-check later, or don't accept the order. Registry requests time out after a few seconds. When VIES turns your store away for sending too many requests, or the plugin's own hourly lookup limit is reached, VAT is charged whatever that setting says, because visitors can cause either. Exempting while a registry is down lets anyone enter a made-up number for that country, so the VAT is yours to recover if the re-check fails.

= How do I validate UK VAT numbers? =

HMRC only answers requests that carry credentials from a registered application, so you need your own. They're free.

1. Create an account at the [HMRC Developer Hub](https://developer.service.hmrc.gov.uk/).
2. Register an application and subscribe it to the VAT Registered Companies API (check a UK VAT number).
3. Copy the client ID and client secret from the application.
4. Paste them into Downloads > Settings > Taxes > EU VAT.

Without credentials, GB numbers are only format-checked and a business customer in the UK is treated as a consumer.

= Does it work with EDD Recurring? =

Yes. At signup the cart's VAT treatment carries over to the subscription, including the reduced rate for downloads in a reduced category. A renewal is decided as a new sale: a recent registry answer is reused, otherwise the number is checked again. The plugin never changes what the gateway charges. It records the renewal at the amount charged, with the VAT the decision calls for inside that amount. If the treatment has changed since signup (the rate moved, you crossed the threshold, or the customer's number stopped being valid), the renewal is flagged on the order so you can update the subscription yourself. This has been tested with EDD Recurring 2.14.

= Where does the IP country come from? =

From a country header your CDN adds to each request: `CF-IPCountry` from Cloudflare or `CloudFront-Viewer-Country` from Amazon CloudFront. Pick yours under "IP country source". Only pick a CDN your site is really behind, because without one a visitor could send the header themselves. With no source set, the plugin decides on the billing country and, when that conflicts with other evidence, the customer's self-certification. Developers can supply the country from elsewhere through the `vatright_edd_ip_country` filter. The plugin doesn't call any IP lookup service.

= What personal data does the plugin store, and for how long? =

It stores the customer's VAT number, the registry's name, address and consultation number for it, and the IP country evidence used for the decision, on the order, in the plugin's ledger table and, for logged-in customers, in their customer record. After the retention period (10 years by default, counted from the end of the year of the sale, which matches the OSS record-keeping period) the plugin removes the personal data from the decision record and ledger rows and keeps the scheme, rule, amounts and country evidence. The same applies to the VAT fields Barn2's plugin left on imported orders. Exporters and erasers are registered with the WordPress privacy tools. All plugin data is kept when you delete the plugin, unless you tick "Delete all plugin data when the plugin is deleted".

= Which services does the plugin call? =

See "External services" below. Nothing else is contacted, and the plugin makes no request unless a VAT number, a rate update or a currency conversion needs it.

= Are there known limitations? =

The plugin is built for stores established in the EU. The setup check warns when your shop country is outside it. The points below each err on the side of charging VAT.

* Consulting, legal, advertising and similar services to consumers outside the EU (Article 59 of the VAT Directive) are charged your store's VAT.
* Business customers in the UK are treated as consumers unless you add your own HMRC API credentials. Swiss and Norwegian numbers that can't be checked at checkout are charged VAT until they're re-checked.
* In a cart that's partly exempt because of a domestic exemption, the exempt line is charged VAT.
* Wallet payments and Buy Now buttons skip EDD's checkout checks, so a customer there can't be asked to confirm their country. The plugin decides from the order's address and the IP country, and flags any conflict on the order.
* A valid VAT number removes VAT without checking that the buyer is that company, so a published number belonging to someone else would pass.
* The EDD Invoices and EDD PDF Invoices integrations have been tested against stand-ins that fire those add-ons' hooks, not against the add-ons themselves.

== External services ==

The plugin contacts the services below. It never sends them an order's contents, amounts or the customer's name.

**VIES (European Commission).** Checks EU VAT numbers. Sent when a customer enters a number: the VAT number and, if you've set one, your store VAT number as requester. Used on the checkout, the admin order screen and in scheduled re-checks. [VIES](https://ec.europa.eu/taxation_customs/vies/), [legal notice](https://commission.europa.eu/legal-notice_en), [privacy policy](https://commission.europa.eu/privacy-policy-websites-managed-european-commission_en).

**HMRC VAT API (UK).** Checks GB VAT numbers, only when you've entered HMRC credentials. Sent: the VAT number, your HMRC client ID and secret to obtain a token, and your GB VAT number if you've set one. [Terms of use](https://developer.service.hmrc.gov.uk/api-documentation/docs/terms-of-use), [privacy notice](https://www.gov.uk/government/organisations/hm-revenue-customs/about/personal-information-charter).

**Swiss UID register (Swiss Federal Statistical Office).** Checks CHE numbers. Sent: the number. [UID register](https://www.uid.admin.ch/), [terms](https://www.admin.ch/en/terms-and-conditions).

**Brønnøysund Register Centre (Norway).** Checks Norwegian organisation numbers. Sent: the number. [Terms of use](https://www.brreg.no/en/use-of-data-from-the-bronnoysund-register-centre/), [privacy statement](https://www.brreg.no/en/privacy-statement/).

**EU Taxes in Europe Database, TEDB (European Commission).** Source of the standard and reduced VAT rates. Sent when you fetch rates or the weekly check runs: a rate query with no customer data. [TEDB](https://ec.europa.eu/taxation_customs/tedb/), [legal notice](https://commission.europa.eu/legal-notice_en), [privacy policy](https://commission.europa.eu/privacy-policy-websites-managed-european-commission_en).

**European Central Bank.** Source of the euro reference rates used to convert orders in other currencies for the reports, and for the Barn2 history import. Sent: a currency code and a date range. [Disclaimer](https://www.ecb.europa.eu/services/disclaimer/html/index.en.html), [privacy](https://www.ecb.europa.eu/services/data-protection/html/index.en.html).

The plugin doesn't use an IP geolocation service. The IP country comes from your CDN's request header, see the FAQ.

== Disclaimer ==

EU VAT Handling for EDD is a tool, not tax advice. You, the store owner, are responsible for your VAT treatment and your filings. VAT rates and registry results come from third parties and can be wrong or out of date, so check them with an accountant. The plugin is provided without warranty and without liability, under the GPL.

== Screenshots ==

1. Block checkout with a valid VAT number and the reverse charge notice.
2. Shortcode checkout with a VAT number the registry says isn't valid.
3. The EU VAT section under Downloads > Settings > Taxes, with the setup check.
4. The EU VAT section on an order, with the decision, a re-check and the decision log.
5. The EU VAT reports: OSS return and EC Sales List.

== Changelog ==

= 0.1.3 =
* OSS return and EC Sales List reports for a month or any date range, as well as by quarter, in the screen, CSV and REST.
* Email warning when cross-border EU consumer sales near the EUR 10,000 threshold, and again when they pass it.
* A separate UK store VAT number, used on receipts and HMRC checks for UK customers. The Barn2 import copies it.

= 0.1.2 =
* Fix: on hosts where WordPress fetches over cURL with OpenSSL 3, VIES checks failed and business customers were charged VAT. Registry requests now ask for uncompressed answers.

= 0.1.1 =
* Orders outside the EU are no longer flagged as a tax mismatch, and a non-EU order charged VAT by mistake now is.
* EDD Invoices shows your seller details when its own company fields are empty, as after moving from Barn2's plugin.
* The Barn2 import recommends turning on "I'm OSS-registered" when your old setup charged destination VAT.

= 0.1.0 =
* First release.
