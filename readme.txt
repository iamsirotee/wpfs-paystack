=== Paystack WPForms Payment Gateway ===
Contributors: iamsirotee
Tags: wpforms, paystack, payments, checkout, forms
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Accept Paystack payments in WPForms Lite and WPForms Pro with Paystack popup checkout.

== Description ==

Paystack WPForms Payment Gateway adds Paystack payment support to WPForms Lite and WPForms Pro.

* One-time Paystack payments in WPForms
* Works with WPForms Lite and WPForms Pro
* Verifies payment before the form submission is completed
* Adds Paystack settings under `WPForms > Settings > Payments`
* Adds Paystack options to the WPForms builder
* Saves payment records in WPForms
* Handles Paystack callbacks and webhooks
* Supports NGN, GHS, ZAR, and USD

Supported currencies:

* NGN
* GHS
* ZAR
* USD

== Installation ==

1. Upload the `wpfs-paystack` folder to the `/wp-content/plugins/` directory.
2. Activate `Paystack WPForms Payment Gateway` from the `Plugins` screen in WordPress.
3. Make sure `WPForms Lite` or `WPForms Pro` is installed and active.
4. Go to `WPForms > Settings > Payments`.
5. Add your Paystack test or live keys.
6. Copy the webhook URL shown on the settings page into your Paystack dashboard.
7. Edit a form, open the `Payments` panel, and enable `Paystack`.

== Frequently Asked Questions ==

= Does this work with WPForms Lite? =

Yes. It works with both WPForms Lite and WPForms Pro.

= Do I need to add a credit card field? =

No. Payment is collected in the Paystack popup.

= Where do I configure the webhook? =

Go to `WPForms > Settings > Payments` and copy the `Webhook Endpoint` into your Paystack dashboard.

== Changelog ==

= 1.0.0 =

* Initial release.
