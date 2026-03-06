# Paystack WPForms Payment Gateway

Paystack payment support for WPForms Lite and WPForms Pro.

The plugin opens the Paystack popup during form submission and only completes the entry after the payment has been verified.

## Features

- One-time Paystack payments in WPForms
- Works with WPForms Lite and WPForms Pro
- Verifies payment before the form submission is completed
- Adds Paystack settings under `WPForms > Settings > Payments`
- Adds Paystack options to the WPForms builder
- Saves payment records in WPForms
- Handles Paystack callbacks and webhooks
- Supports `NGN`, `GHS`, `ZAR`, and `USD`

## Requirements

- WordPress `5.8` or newer
- PHP `7.4` or newer
- `WPForms Lite` or `WPForms Pro`
- Paystack API keys

## Installation

1. Upload the `wpfs-paystack` folder to `/wp-content/plugins/`.
2. Activate the plugin in WordPress.
3. Make sure `WPForms Lite` or `WPForms Pro` is active.
4. Go to `WPForms > Settings > Payments`.
5. Add your Paystack test or live keys.
6. Copy the webhook URL shown on the settings page into your Paystack dashboard.
7. Edit a form, open the `Payments` panel, and enable `Paystack`.

## FAQ

### Does it work with WPForms Lite?

Yes. It works with both WPForms Lite and WPForms Pro.

### Do I need to add a card field?

No. Payment is collected in the Paystack popup.

### Where do I add the webhook URL?

In `WPForms > Settings > Payments`.

## Notes

`README.md` is for GitHub. `readme.txt` is for the WordPress.org plugin directory.

## License

GPLv2 or later.
