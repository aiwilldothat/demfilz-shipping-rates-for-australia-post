=== Demfilz Shipping Rates for Australia Post ===
Contributors: demfilz
Tags: woocommerce, shipping, australia post, auspost, shipping rates
Requires at least: 5.6
Tested up to: 7.1
Requires PHP: 7.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Live Australia Post shipping rates at checkout, with an admin settings page for your API key and options.

== Description ==

This plugin adds an "Australia Post" shipping method to WooCommerce that fetches
live parcel rates (domestic and international) from Australia Post.

Two rate sources are supported, switchable on the settings page:

* PAC API — standard retail postage, using a single free API key.
* Shipping & Tracking API — the negotiated rates on your Australia Post charge
  account (eParcel / MyPost Business / Enterprise), using your account number,
  API key and password.

Features:

* Dedicated admin settings page (WooCommerce > Settings > Australia Post) for credentials and all options.
* One-click "Test credentials" button for whichever rate source is selected.
* Live domestic and international parcel rates.
* Service picker — choose exactly which services to offer (Parcel Post, Express Post, Courier, satchels, international, etc.).
* Optional delivery-time estimate shown beside each service.
* Optional "Remove GST" to strip the 10% GST from quoted prices.
* Automatic parcel sizing from product dimensions, with a configurable default box.
* Handling fee and percentage markup.
* Optional free-shipping threshold.
* Optional fallback rate so checkout is never blocked if the API is unavailable.
* Admin-only test mode to preview live rates before going live.
* Response caching and debug logging.
* Credentials are stored masked and never rendered back into the page.
* Compatible with WooCommerce High-Performance Order Storage (HPOS) and the cart/checkout blocks.

== Getting credentials ==

For the PAC rate source:
1. Register for a free account at https://developers.auspost.com.au
2. Request access to the "Postage Assessment Calculation (PAC)" API.
3. Copy the API key into WooCommerce > Settings > Australia Post.

For the Shipping & Tracking (account) rate source:
1. You need an Australia Post business/charge account.
2. Request Shipping & Tracking API access and credentials from Australia Post.
3. Enter your account number, API key and password into the settings page.

== External services ==

This plugin calculates live postage by sending parcel details to Australia Post's
developer APIs. No data is sent until you enter API credentials and a shipping quote
is requested.

What it connects to:

* The Postage Assessment Calculation (PAC) API and/or the Shipping & Tracking API,
  hosted at digitalapi.auspost.com.au.

When data is sent:

* When a customer requests a shipping quote at the cart or checkout, and when you use
  the "Test credentials" button in the admin.

What is sent:

* Origin postcode, destination postcode and/or country, and the parcel weight and
  dimensions. For the Shipping & Tracking API, your Australia Post account number and
  API credentials are also sent to authenticate. No customer name, address lines,
  email or payment details are sent.

This is a third-party service provided by Australia Post; by using it you agree to
their terms and privacy policy:

* Australia Post Developer Centre: https://developers.auspost.com.au
* Australia Post API terms & conditions: https://developers.auspost.com.au/terms-conditions
* Australia Post privacy policy: https://auspost.com.au/privacy

This plugin is independent and is not affiliated with, endorsed by, or sponsored by
Australia Post or WooCommerce. "Australia Post" and "WooCommerce" are trademarks of
their respective owners.

== Installation ==

1. Upload the `demfilz-shipping-rates-for-australia-post` folder to `/wp-content/plugins/`, or install the zip via Plugins > Add New > Upload Plugin.
2. Activate the plugin through the "Plugins" menu in WordPress (WooCommerce must be active).
3. Go to WooCommerce > Settings > Australia Post and enter your credentials, then click "Test credentials".
4. Go to WooCommerce > Settings > Shipping, open a shipping zone, and add the
   "Australia Post" method.

== Frequently Asked Questions ==

= Do I need an Australia Post account? =
You need a free Australia Post Developer Centre account to obtain a PAC API key.

= Why are rates not showing? =
Make sure (1) your credentials are saved and the "Test credentials" button succeeds,
(2) an origin postcode is set, (3) the Australia Post method has been added to the
customer's shipping zone, and (4) products have a weight.

= Are prices GST inclusive? =
Australia Post domestic prices already include GST, so the method's tax status
defaults to "None" to avoid double-charging tax.

== Screenshots ==

1. Choose your rate source (PAC API or your Australia Post account) and verify the connection with one click.
2. Pick exactly which services to offer, with handling fee, markup, GST and delivery-time options.
3. Automatic parcel sizing from product dimensions, with a configurable default box.

== Changelog ==

= 1.0.0 =
* Initial release.
