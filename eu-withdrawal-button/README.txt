=== EU Withdrawal Button ===
Contributors: yourname
Tags: woocommerce, withdrawal, consumer rights, EU directive, right of withdrawal
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
WC requires at least: 7.0
Stable tag: 1.1.0
License: GPL-2.0+

Implements the mandatory EU withdrawal button as required by Directive (EU) 2023/2673, effective 19 June 2026.

== Description ==

This plugin adds a fully compliant electronic withdrawal function to your WooCommerce store, meeting the requirements of:

* **Directive (EU) 2023/2673** (amending Consumer Rights Directive 2011/83/EU)
* Effective from **19 June 2026** across all EU member states

=== Features ===

* Two-step withdrawal flow ("Withdraw from contract here" → "Confirm withdrawal here") as required by the directive
* Two flow modes: **standard** (admin confirms manually) or **direct** (auto-confirmed on customer click)
* 14-day withdrawal window (configurable)
* Full audit log of all withdrawal requests in the WP admin (with search, status filter, sorting, and pagination)
* Export withdrawal log to **CSV** or **XLS** directly from the admin
* Withdrawal meta box on the WooCommerce edit-order screen (classic + HPOS) with one-click admin confirmation
* Two custom order statuses: **Pending withdrawal confirmation** and **Pending refund**
* Automatic email sent to the customer on intent (standard flow) and on confirmation (both flows)
* Automatic notification sent to the shop admin on confirmed withdrawal
* Customisable email subject and body for both email types, with placeholders (`{order_number}`, `{order_date}`, `{customer_name}`, `{withdrawal_date}`)
* Customisable frontend texts: intro paragraph, form instructions, button label
* Works for both registered customers and **guest orders** (authenticated via WooCommerce order key)
* WooCommerce order status updated automatically after withdrawal
* Italian language included; fully translatable via .po/.mo files
* GDPR-friendly: collects only the minimum necessary data (name, email, optional reason, IP address)
* Works with standard WooCommerce (no page builder required)
* Compatible with WooCommerce HPOS (High-Performance Order Storage)

== Installation ==

1. Upload the `eu-withdrawal-button` folder to `/wp-content/plugins/`
2. Activate the plugin through **Plugins > Installed Plugins**
3. Go to **EU Withdrawal > Settings** to configure the plugin
4. The withdrawal button will automatically appear on the WooCommerce **View Order** page for all orders within the withdrawal window

== Configuration ==

Navigate to **EU Withdrawal > Settings** in your WordPress admin. Settings are organised in three tabs:

=== General ===

* **Withdrawal flow mode** — *Standard*: the customer submits a request and the admin confirms it manually; the customer receives an intent email first, then a confirmation email. *Direct*: the withdrawal is confirmed immediately on customer click; the order moves to "Pending refund" right away and the customer receives the confirmation email instantly.
* **Withdrawal window (days)** — default 14, minimum required by the directive. Only increase it, never decrease below 14.
* **Admin notification email** — address that receives a notification on every confirmed withdrawal.

=== Frontend texts ===

* **Withdrawal intro text** — paragraph shown above the form. Supports `%1$d` (window in days) and `%2$d` (days remaining) placeholders.
* **Form instructions** — text shown above the form fields.
* **Withdrawal button label** — text of the first-step button (default: "Withdraw from contract here").

=== Email ===

Customise subject and body for both customer emails:

* **Customer email – Request received** (standard flow only)
* **Customer email – Withdrawal confirmed** (both flows)

Available placeholders: `{order_number}`, `{order_date}`, `{customer_name}`, `{withdrawal_date}`.

== How it works ==

1. Customer visits their order page (registered or guest via the link in the WooCommerce order email)
2. If within the withdrawal window, they see the withdrawal section with a pre-filled form
3. They fill in name, email, and an optional reason, then click **"Withdraw from contract here"**
4. A second confirmation screen appears with the button **"Confirm withdrawal here"**
5. On confirmation the withdrawal is logged, the order status is updated, and the customer and admin receive the appropriate emails

=== Standard flow ===

Order status after customer confirms: **Pending withdrawal confirmation** → admin clicks "Confirm" in the log table or in the order meta box → order moves to **Pending refund**.

=== Direct flow ===

Order status after customer confirms: immediately **Pending refund**. No admin action required.

== Compliance notes ==

The two-step flow (intent + confirmation) mirrors the German "2-click Widerrufsbutton" model (§ 312k BGB) and satisfies the requirements of Art. 11a of the Consumer Rights Directive as amended by Directive (EU) 2023/2673.

**This plugin does not constitute legal advice.** You should verify compliance requirements with a qualified lawyer in each EU member state where you operate.

== Changelog ==

= 1.1.0 =
* Add dual flow mode: standard (admin-confirmed) and direct (auto-confirmed)
* Add export to CSV and XLS from the withdrawal log
* Add withdrawal meta box on the edit-order screen (classic + HPOS) with one-click confirmation
* Add search, sortable columns, and per-page screen option to the withdrawal log
* Add customisable email subject/body with placeholders
* Add customisable frontend texts (intro, instructions, button label)
* Add admin-confirm AJAX action directly from the log table row

= 1.0.0 =
* Initial release

== Frequently Asked Questions ==

= Does this work without WooCommerce? =
No. This plugin requires WooCommerce to function.

= Does it work for guest orders? =
Yes. Guest customers receive a View Order link in their WooCommerce order email. That link contains the order key which the plugin uses to authenticate the guest securely, exactly the same mechanism WooCommerce itself uses.

= Can I change the 14-day window? =
Yes, via the settings page — but the EU directive sets 14 days as the legal minimum. Only increase it, never decrease below 14.

= What is the difference between the standard and direct flow? =
In the standard flow the customer's request is logged as "pending" and the admin must confirm it manually (from the log table or the order meta box). Only then does the order move to "Pending refund" and the customer receive the confirmation email. In the direct flow the withdrawal is confirmed immediately when the customer clicks the second button, with no admin step required.

= Can I revoke a withdrawal? =
Yes. An admin can click "Revoke" on any non-refunded withdrawal in the log table. The record is deleted and an order note is added automatically.
