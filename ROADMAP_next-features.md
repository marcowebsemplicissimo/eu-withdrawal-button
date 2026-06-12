# ROADMAP – Prossime funzionalità

---

## ~~Feature 1 – Email `customer_intent` (inviata subito dopo il click su "Conferma recesso qui")~~ ✅ COMPLETATA

**Implementato:** `send_customer_intent()` + `send_admin_intent()` agganciate all'hook `euwb_withdrawal_intent_created`, che scatta in `EUWB_Withdrawal::create()`. Il flusso email è:
- `euwb_withdrawal_intent_created` → `send_customer_intent_wc()` (via `EUWB_Email_Customer_Intent`) + `send_admin_intent_notification()` (via `wp_mail()`)
- `euwb_withdrawal_confirmed` → `send_customer_confirmation_wc()` (via `EUWB_Email_Customer_Confirmation`) + `send_admin_confirmed_notification()` (via `wp_mail()`)

---

## ~~Feature 2 – Modalità "Flusso Diretto" (auto-confirm al click cliente)~~ ✅ COMPLETATA

**Implementato:**
- Opzione `euwb_flow_mode` (`standard` | `direct`, default `standard`) nelle impostazioni.
- `ajax_confirm()` in `class-euwb-frontend.php` chiama `create_and_confirm()` in flusso diretto → ordine in `pending-refund`, hook `euwb_withdrawal_confirmed` scatta.
- In flusso diretto `euwb_withdrawal_intent_created` non scatta — by design.

---

## ~~Feature 3 – Email native WooCommerce per i nuovi status ordine~~ ✅ COMPLETATA

**Implementato:**

- `includes/emails/class-euwb-email-customer-intent.php` — `EUWB_Email_Customer_Intent extends WC_Email`, triggerata via hook `euwb_withdrawal_intent_created` (chiamata da `send_customer_intent_wc()`).
- `includes/emails/class-euwb-email-customer-confirmation.php` — `EUWB_Email_Customer_Confirmation extends WC_Email`, triggerata via hook `euwb_withdrawal_confirmed` (chiamata da `send_customer_confirmation_wc()`).
- Template HTML e plain-text in `includes/emails/templates/`: `euwb-customer-intent.php`, `euwb-customer-intent-plain.php`, `euwb-customer-confirmation.php`, `euwb-customer-confirmation-plain.php`.
- `EUWB_Emails::replace_placeholders()` — helper condiviso per i segnaposto `{order_number}`, `{order_date}`, `{customer_name}`, `{withdrawal_date}`.
- `EUWB_Emails::register_email_classes()` — registra entrambe le classi nel filtro `woocommerce_email_classes`; le email compaiono in **WooCommerce > Email** con anteprima e toggle.
- Campi di personalizzazione testo (`euwb_intent_email_subject`, `euwb_intent_email_body`, `euwb_confirmation_email_subject`, `euwb_confirmation_email_body`) nelle impostazioni plugin, tab **Email**.
- I metodi legacy `send_customer_confirmation()` e `send_customer_intent()` (basati su `wp_mail()`) rimangono in `class-euwb-emails.php` ma non sono più agganciati agli hook — mantenuti come fallback non attivi.
- Le notifiche admin (`send_admin_confirmed_notification`, `send_admin_intent_notification`) rimangono via `wp_mail()` diretta.

---

## Feature 4 – Ricerca e ordinamento nel registro recessi ✅ COMPLETATA

**Implementato (2026-06-12):**

- **Barra di ricerca** sopra la tabella: filtra su nome, cognome, email e numero ordine tramite parametro GET `s`. Include link "✕ Rimuovi filtro" quando attivo.
- **Ordinamento colonne** "Data richiesta" (`created_at`) e "Data conferma" (`confirmed_at`): intestazioni cliccabili con freccia `▲`/`▼`, parametri GET `orderby` e `order` (ASC/DESC). La direzione si inverte al secondo click sulla stessa colonna.
- `EUWB_Withdrawal::get_all()` — aggiornato per accettare `search`, `orderby`, `order`; colonna di ordinamento validata contro whitelist.
- `EUWB_Withdrawal::count()` — aggiunto secondo parametro `$search` per mantenere i contatori dei tab coerenti con la ricerca attiva.
- La paginazione mantiene tutti i parametri attivi (`s`, `orderby`, `order`).

---

## ~~Feature 5 – Export CSV / XLS del registro recessi~~ ✅ COMPLETATA

**Implementato (2026-06-12):**

- Pulsanti **"Esporta CSV"** e **"Esporta XLS"** posizionati a sinistra nella toolbar, affiancati alla barra di ricerca.
- Export interamente client-side tramite i plugin jQuery `jquery.table2csv.js` e `jquery.table2excel.js` (agiti sulla tabella DOM).
- I due script vengono caricati solo sulla pagina del registro (`enqueue()` con guard `$is_withdrawal_page`).
- La colonna **Azioni** è marcata con classe `euwb-actions-col` ed esclusa da entrambi gli export.
- Nome file generato dinamicamente in JS: `recessi-YYYY-MM-DD.csv` / `.xls`.
- Layout toolbar (`div.euwb-toolbar`) flex row: export a sinistra, search box a destra.

---

## Feature 6 – Email admin come WC_Email nativa

### Obiettivo

Portare le due notifiche admin (`send_admin_intent_notification`, `send_admin_confirmed_notification`) dallo stile `wp_mail()` diretto a classi `WC_Email` native, rendendole configurabili da **WooCommerce > Email** esattamente come le email cliente.

### Cosa implementare

- Nuovi file `includes/emails/class-euwb-email-admin-intent.php` e `class-euwb-email-admin-confirmed.php`.
- Registrazione in `register_email_classes()`.
- Rimozione degli hook `wp_mail()` in `EUWB_Emails::init()`.
- Campi destinatario admin configurabili (di default `get_option('euwb_admin_email')`).
