# OpenAI Ads (ChatGPT) Pixel for Magento 2

Conversion measurement for ChatGPT advertising. The module installs the OpenAI
Measurement Pixel, fires the full set of standard e-commerce events, matches
conversions with hashed customer data, and mirrors the same events to the
Conversions API from the server so nothing is lost to ad blockers.

Free and open source. No paid edition, no license key.

## Features

- **Measurement Pixel** loaded from `bzrcdn.openai.com`, initialised with your Pixel ID.
- **All relevant standard events**, each one switchable per store view:

  | Event | Fired on |
  |---|---|
  | `page_viewed` | every storefront page |
  | `contents_viewed` | product detail page |
  | `items_added` | add to cart (survives the redirect) |
  | `checkout_started` | checkout page, once per cart |
  | `order_created` | success page, with items, totals and currency |
  | `registration_completed` | new customer account |
  | `lead_created` | newsletter subscription |
  | `search` | catalog search, sent as a custom event |

- **Advanced matching.** E-mail, phone, first and last name are normalised and
  SHA-256 hashed **in PHP** before they reach the browser or the API — raw
  values never leave the server. Phone numbers get the country calling code
  added so local numbers stay matchable. Logged-in customers are identified by
  customer ID, guests by a first-party cookie.
- **Conversions API.** The same event, with the same event ID, is sent from the
  server and deduplicated by OpenAI against the browser event. Amounts are
  converted to ISO 4217 minor units, including the zero-decimal and
  three-decimal currencies.
- **Full page cache safe.** The cached page carries only the pixel bootstrap and
  public page context. Events and hashed customer data come from
  `openaiads/data/index`, an uncached request, so Varnish or the built-in FPC
  can never serve one visitor's identity to another. No cache exclusions, no
  `cacheable="false"` on checkout.
- **Cookie consent aware.** The command queue is created immediately, but the
  SDK is only injected once your consent cookie is set — events fired before
  consent are buffered and flushed afterwards, or dropped if consent never
  comes. Works with Magento's cookie restriction mode or any third-party banner.
- **CSP whitelisted** out of the box (`etc/csp_whitelist.xml`), and the inline
  snippet carries a CSP nonce where the store provides one, so it survives a
  strict policy.
- **Works with Hyvä.** No RequireJS, no Knockout, no jQuery - the snippet is
  plain JavaScript in the standard `head.additional` container, which Hyvä
  keeps. Nothing to port, no Alpine component needed.
- **Attribution kept server-side.** The `oppref` reference from the ad click is
  stored in a first-party cookie and attached to server-side events.
- **Dry run mode** (`validate_only`) so you can verify the integration without
  writing conversions.

## Requirements

- Magento Open Source or Adobe Commerce 2.4.x
- PHP 8.1 – 8.4

## Installation

```bash
composer require degriz/module-openai-ads
bin/magento module:enable Degriz_OpenAiAds
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

Installing from a clone instead: put the files in `app/code/Degriz/OpenAiAds`
and run the same commands from `module:enable` onwards.

Then open **Stores / Configuration / Degriz / OpenAI Ads (ChatGPT) Pixel**.

## Configuration

**General** — enable the pixel and paste the Pixel ID from
Ads Manager → Conversions → Pixels. Turn on Debug Mode while testing: the SDK
logs to the browser console and server calls go to `var/log/debug.log`.

**Events** — switch individual events on or off per store view.

**Advanced Matching** — on by default. Turn off the address fields if you do not
want city, region, postal code and country sent (these are sent unhashed, as the
API requires).

**Conversions API** — paste an API key from Ads Manager → Conversions → Manage
conversion keys, then pick which events are mirrored server-side. `order_created`,
`checkout_started` and `items_added` are a sensible set; mirroring `page_viewed`
adds an HTTP call to every page load. Use **Validate Only** for the first test
run.

**Cookie Consent** — set the cookie your banner writes. Magento's own cookie
restriction mode uses `user_allowed_save_cookie`; Cookiebot uses `CookieConsent`.
Leave the value empty to accept any non-empty cookie.

### Setting it up in Ads Manager

1. Create the conversion event in Ads Manager so it counts toward campaign reporting.
2. Enter the Pixel ID here and enable the pixel.
3. Walk the funnel — product → cart → checkout → purchase — with Debug Mode on
   and confirm each event in the console.
4. Enable the Conversions API and confirm the events arrive on both paths with
   the same event ID.

## Notes

- Always test on a development copy before going live.
- The pixel block lives in the `head.additional` container. A theme that keeps
  the standard page structure needs no changes.
- Tracking never blocks a purchase: every server-side call is wrapped and
  failures are logged, not thrown.

## Author

Anže Voh
[Magento eCommerce Development](https://www.degriz.net/) at Degriz

## License

This project is licensed under the [MIT License](LICENSE).

## Disclaimer

- This module is provided "as is", without warranty of any kind, express or implied. Use it at your own risk.
- The author takes no responsibility for any problems or issues that arise from using this module.
- You are free to use and modify this module, but you cannot resell it.

## Additional Information

I specialize in custom Magento development and have successfully completed numerous projects on the Magento platform. My services include:

- **Custom Theme Development:** Creating unique, responsive, and user-friendly themes tailored to your specific business needs.
- **Theme Customization:** Modifying existing themes to enhance functionality, improve aesthetics, and ensure compatibility with various extensions.
- **Tracking & Analytics Setup:** Correct measurement across OpenAI Ads, Meta, Google Ads and GA4, including server-side conversions and consent handling.
- **Performance Optimization:** Ensuring that your Magento store runs smoothly and efficiently, providing a seamless shopping experience for your customers.
- **SEO Optimization:** Implementing best practices to improve your store's visibility on search engines and drive more traffic to your site.
- **Ongoing Support and Maintenance:** Providing continuous support to ensure your Magento store remains up-to-date and secure.

For more information or inquiries, please visit [Magento eCommerce Development](https://www.degriz.net/).

## See also

- [OpenAI Ads Pixel for OpenMage / Magento 1](https://github.com/DegrizNet/m1_openai_ads)
- [OpenAI Measurement Pixel documentation](https://developers.openai.com/ads/measurement-pixel)
- [OpenAI Conversions API documentation](https://developers.openai.com/ads/conversions-api)
