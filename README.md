# EU Withdrawal Button

Free WooCommerce plugin implementing the mandatory EU withdrawal button as required by **Directive (EU) 2023/2673**, effective 19 June 2026.

## Description

Adds a fully compliant withdrawal (right of withdrawal / recesso) flow to WooCommerce order pages, including a two-step confirmation form, email notifications, and a complete audit log in the WordPress admin.

## Features

### Customer-facing
- **Withdrawal form** on the "View Order" page, visible within the configurable withdrawal window (default 14 days).
- **Two-step flow**: the customer fills in name/email/reason (step 1), then confirms (step 2) before any record is written.
- **Days remaining** counter shown dynamically in the intro text.
- **Return instructions box** (physical products only): shown automatically when the order contains at least one shippable product. Includes address, timeframe and shipping cost notes. Hidden for virtual/digital/service orders.
- Post-withdrawal status messages reflecting the current order state (pending confirm, pending refund, refunded, cancelled).

### Admin
- **Two workflow modes** (configurable):
  - *Standard*: customer submits a request → admin confirms manually → order moves to "In attesa di rimborso".
  - *Direct*: withdrawal is auto-confirmed on customer click, order moves immediately to "In attesa di rimborso".
- **Custom order statuses**: `wc-pending-withdraw` (In attesa di conferma recesso) and `wc-pending-refund` (In attesa di rimborso).
- **Withdrawal log** (`EU Withdrawal > Registro Recessi`): paginated table with search (name, email, order number), column sorting (request date, confirm date), status filter tabs, and CSV/XLS export.
- **Meta box** on the edit-order page: shows withdrawal details and a "Confirm" button for pending requests.
- **Revoke** action: removes the withdrawal record and adds an audit note to the order.
- Orphaned record handling when an order is permanently deleted.

### Emails (native WooCommerce)
Both emails extend `WC_Email` and appear in **WooCommerce > Emails** with full preview and toggle support.

| Email | Trigger | Recipient |
|---|---|---|
| Richiesta ricevuta | `euwb_withdrawal_intent_created` (standard flow) | Customer |
| Recesso confermato | `euwb_withdrawal_confirmed` (both flows) | Customer |

Admin notifications (intent and confirmation) are sent via `wp_mail()` to a configurable address.

All email bodies support placeholders: `{order_number}`, `{order_date}`, `{customer_name}`, `{withdrawal_date}`.

For orders with physical products, emails automatically include a styled **return instructions box** (HTML) or a delimited section (plain-text).

### Settings (`EU Withdrawal > Impostazioni`)

**Tab Generale**
| Option | Default | Description |
|---|---|---|
| `euwb_flow_mode` | `standard` | Workflow mode: standard or direct |
| `euwb_withdrawal_window` | 14 | Days the customer has to withdraw |
| `euwb_return_window` | 14 | Days the customer has to return the physical goods |
| `euwb_admin_email` | site admin email | Recipient for admin notifications |

**Tab Testi frontend**
- Intro text (supports `%1$d` window days, `%2$d` days remaining)
- Form instructions
- Button label

**Tab Email**
- Subject and body for both customer emails
- Return instructions textarea (shown only for physical product orders)

## Requirements

- WordPress 6.0+
- WooCommerce 7.0+
- PHP 7.4+

## Installation

1. Upload the `eu-withdrawal-button` folder to `/wp-content/plugins/`.
2. Activate the plugin through **Plugins > Installed Plugins**.
3. Configure it under **EU Withdrawal > Impostazioni**.

## Database

The plugin creates one table: `{prefix}euwb_withdrawals`, which stores all withdrawal records with order ID, customer data, status (`pending` / `confirmed`), timestamps, IP address, and an orphan flag for deleted orders.

## License

GPL-2.0+
