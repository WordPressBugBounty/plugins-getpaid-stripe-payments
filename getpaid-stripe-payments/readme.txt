=== GetPaid Stripe Payments ===
Contributors: stiofansisland, paoltaia, ayecode, picocodes
Donate link: https://wpgetpaid.com/
Tags: stripe, stripe payments, stripe gateway, payment, payments, button, shortcode, digital goods, payment gateway, instant payment, commerce, digital downloads, downloads, e-commerce, e-store, ecommerce, stripe checkout, credit card payments
Requires at least: 4.9
Tested up to: 6.7
Stable tag: 2.3.12
License: GPLv2
License URI: http://www.gnu.org/licenses/gpl-2.0.txt

Stripe Payments for WordPress made easy. Accept credit cards on your WordPress site using the Stripe payments add-on for [GetPaid](https://wordpress.org/plugins/invoicing/).

== Description of the Stripe Payments plugin for GetPaid ==

Accepting Credit Cards on your website is easier than ever with the GetPaid plugin and its Stripe Payment Gateway. You can trust us, we are [Stripe Verified Partner](https://stripe.com/partners/wp-invoicing)!

= Why Stripe =

Stripe provides the easiest way to accept credit card payment directly on your website. The Stripe Payment form is frictionless, thus it tends to convert a lot better than the competition. Stripe is currently one of the most widely used and trusted payment gateway.

With the Stripe Payment Gateway for GetPaid, you will be able to immediately accept payments made with the following credit cards:

* Visa
* Mastercard
* American Express
* Discover 
* JCB 
* Diners Club

Stripe doesn't charge setup or monthly fees. You only pay [a small commission](https://stripe.com/pricing) when you Get Paid. No hidden costs! 

Stripe is available in 42 countries including the United States, United Kingdom, all EU countries, Brazil, India, Honk Kong and many more. See [this page for the complete list](https://stripe.com/global). 

= Stripe Verified Partner =

The GetPaid plugin is a Stripe verified partner. Being a Verified Partner, our obligations are to provide all the latest checkout enhancements and security features. Most importantly, the payments API updates are regular, meaning that users benefit from all the latest security enhancements.  

= Features of Stripe Payments for GetPaid  = 

* Accept single payments
* Accept recurring payments
* Allow customers to Manage subscription on your website
* Provide free trials
* Allow customers to name their price
* Automatically handles EU VAT and Taxes.

= Connect with Stripe =

With the Stripe Payment for GetPaid plugin you don't need to gather API and Secret keys on the payment gateway website, but you can simply connect your Stripe account from the plugin settings and automatically fetch the required information. 

== Installation ==

1. Upload 'wpinv-stripe-payment' directory to the '/wp-content/plugins/' directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WordPress Admin -> Invoicing -> Settings -> Gateways -> Stripe and customize behaviour as needed

== Frequently Asked Questions ==

= Ask a question... =

and you shall get an answer...

== Screenshots ==

1. Sample payments page.
2. Sample settings page.

== Changelog ==

= 2.3.12 - 2025-02-10 =
* Prevent PHP notice during webhook request execution - FIXED

= 2.3.11 - 2025-02-06 =
* Issue during  creating intents when invoice is empty - FIXED

= 2.3.10 - 2025-02-05 =
* Prevent creating unwanted incomplete payment intents - CHANGED

= 2.3.9 - 2024-11-28 =
* Payment via Stripe > iDEAL don't create subscription at Stripe site - FIXED
* Don't create invoice entry for cancelled invoice on IPN webhook event - FIXED

= 2.3.8 - 2024-06-11 =
* Set current_period_end as a subscription expiration - CHANGED

= 2.3.7 - 2024-01-24 =
* Non integer amount breaks subscription creation at Stripe - FIXED

= 2.3.6 =
* Add: Tool to manually process a Stripe event.

= 2.3.5 =
* Fix: Check empty invoice before retrieve secret key for the invoice.
* Add: Allow refunding a payment in Stripe.

= 2.3.4 =
* Fix: Subscription cancellation not working when using Stripe checkout sessions.

= 2.3.3 =
* Remove unsupported payment methods when form contains both recurring and non-recurring items.

= 2.3.2 =
* Subscription improvements

= 2.3.1 =
* Fix: Existing customers unable to pay when Stripe connection details are changed.
* Added hook to handle subscription cancel event - FIXED

= 2.3.0 =
* Test on WordPress 6.2

= 2.2.19 =
* Meta data character limit not being applied which can throw a stripe error when long textarea used - FIXED

= 2.2.18 =
* Show Stripe payment form even if email is not set.

= 2.2.17 =
* Add .distignore file

= 2.2.16 =
* Support Stripe checkout.

= 2.2.15 =
* Checkbox to enable different payment methods - ADDED

= 2.2.14 =
* Wrongly named the payment intent succeeded webhook processor - FIXED

= 2.2.13 =
* Listen to more webhooks

= 2.2.12 =
* Fix "The customer does not have a payment method with the ID X" error

= 2.2.11 =
* Switch to Stripe elements

= 2.2.10 =
* Cancel subscription in Stripe whenever it is deleted in GetPaid
* Automatically send all payment form data to Stripe

= 2.2.9 =
* Send shipping address to Stripe - ADDED
* Fix translation domain issues - FIXED
* Connection button displays raw JS - FIXED

= 2.2.8 =
* Warn if stripe is not set-up correctly

= 2.2.7 =
* WordPress 5.8 compatibility check

= 2.2.6 =
Do not redirect to settings if installed via GetPaid set-up wizard

= 2.2.5 =
* GetPaid 2.4.0 compatibility - ADDED

= 2.2.4 =
* Ability to filter stripe API args - ADDED

= 2.2.3 =
* Do not update payment details for confirmed payment intents - FIXED

= 2.2.2 =
* Show more error information when a card is declined - CHANGED
* Update payment intent when payment details change - FIXED

= 2.2.1 =
* Create a single invoice on checkout errors - CHANGED

= 2.2.0 =
* Stripe connect button not working if Stripe is disabled - FIXED
* Subscriptions with maximum renewals not automatically canceled - FIXED
* Support for the new GetPaid multiple subscriptions feature - ADDED
* Saved payment methods do not appear on payment form - FIXED

= 2.1.0 =
* Payment request buttons - ADDED

= 2.0.10 =
* Update packages

= 2.0.9 =
* WordPress 5.7 compatibility - ADDED

= 2.0.8 =
* Send email when Stripe payment fails (e.g missing payment method), with a way to update payment details - ADDED
* Tool to check expired subscriptions with Stripe - ADDED
* Save sandbox status when clicking on the connect button - ADDED

= 2.0.7 =
* GetPaid 2.1.2 compatibility - ADDED

= 2.0.6 =
* Customer IDs meta key not checking for old style key - FIXED

= 2.0.5 =
* Stripe Connect can't create webhooks and will re-try on each fail - FIXED

= 2.0.4 =
* Add ability to auto-create Stripe Webhooks on non-localhost environments.

= 2.0.2 =
* Add ability to disable Stripe connect.

= 2.0.1-beta =
* Add ability to disconnect from Stripe

= 2.0.0-beta =
* Update Stripe API - CHANGED
* Refactor code - CHANGED
* GetPaid 2.0.0 compatibility - ADDED

= 1.0.16 =
* Better error messages - CHANGED

= 1.0.15 =
* Non-recurring discount not respected - FIXED
* Incorrect plan names when item prices change - FIXED

= 1.0.14 =
* Sometimes free trials not working - FIXED

= 1.0.13 =
* Shipping details support - ADDED
* Upgrade Stripe API - IMPROVED
* Replaces charges with payment intents - CHANGED

= 1.0.12 =
* Payment forms compatibility - ADDED
* Show more specific error messages when a card is declined - ADDED
* Stripe scripts are only loaded if the gateway is active - CHANGED
* Sometimes not working with free trials - FIXED

= 1.0.11 =
* Validate minimum stripe amount for recurring item - ADDED

= 1.0.10 =
* Improve error handling
* Add partner id to all API requests

= 1.0.9 =
* Tested upto new API Version 2019-11-05 https://stripe.com/docs/upgrades#2019-11-05
* Minimum amount should not checked for recurring invoices - FIXED
* Missing Stripe token error for saved card - FIXED
* Problem in submitting the checkout form with full price discount - FIXED
* Display saved method to only invoice user - FIXED

= 1.0.8 =
* Support for Strong Customer Authentication (SCA) for user-initiated payments. - ADDED
* Allow to save card for future purchase. - ADDED
* Using PaymentIntent instead of Charge API. - CHANGED
* Stripe library updated to V7.0.2 - CHANGED
* Requires to use API Version 2019-09-09 https://stripe.com/docs/upgrades#2019-09-09 - CHANGED
* Improved refund handling from Webhook - CHANGED

= 1.0.7 =
Renewal payment generated sometimes for first time payment - FIXED
Product already exist stripe error when creating plan with discount applied - FIXED
Invalid Parameter error on cancel subscription due to param depreciated - FIXED
Uninstall functionality - ADDED

= 1.0.6 =
zero decimal currency showing amount multiplied by 100 in stripe popup - FIXED

= 1.0.3 =
Undefined name property error - FIXED
Update default card details for active subscriptions - ADDED

= 1.0.2 =
Plugin should not active if Invoicing plugin is active - CHANGED
Log errors when API keys changed or moved from Test mode to Live mode - CHANGED
Subscription functionality improved - CHANGED
Option added to specify Stripe checkout language - ADDED
Testing a webhook shows 500 error at Stripe site - CHANGED
Integrate Stripe API 2017-08-15 upgrade changes - CHANGED
JavaScript multiplying by 100 giving wrong decimal amount in Stripe popup - FIXED

= 1.0.1 =
Fix invoice status conflict with other plugin - CHANGED
Fail to create stripe customer if created & deleted earlier - FIXED
Changes in amount formatting - CHANGED
Invoice status "pending" changed to "wpi-pending" - CHANGED

= 1.0.0 =
* Initial release.
