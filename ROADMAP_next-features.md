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

## ~~Feature 6 – Istruzioni di restituzione per prodotti fisici~~ ✅ COMPLETATA

**Implementato (2026-06-12):**

- `EUWB_Withdrawal::order_needs_shipping($order)` — verifica se almeno un prodotto dell'ordine richiede spedizione fisica (tramite `WC_Product::needs_shipping()`).
- `EUWB_Withdrawal::get_return_instructions($order)` — restituisce il testo delle istruzioni solo se l'ordine ha prodotti fisici, altrimenti stringa vuota.
- **Impostazioni admin** — tab **Email**, nuovo blocco "Istruzioni per la restituzione del bene": textarea con testo default precompilato (giorni, indirizzo placeholder, spese, tracciamento). I giorni si leggono dalla nuova option `euwb_return_window` (configurabile in tab Generale, separata da `euwb_withdrawal_window`).
- **Frontend** — box giallo-ambra con titolo in grassetto visualizzato nel form di recesso tra l'intro e lo step 1, solo per ordini con prodotti fisici.
- **Email HTML** (intent + confirmation) — box stilizzato (`background:#fff8e1`, bordo `#f0a500`) inserito tra il corpo del messaggio e la tabella riepilogativa.
- **Email plain-text** (intent + confirmation) — sezione delimitata da `---` con titolo in maiuscolo.
- Il box non compare affatto per ordini con soli prodotti virtuali/digitali/servizi.

---

## Feature 7 – Esclusione tassonomie prodotto dal diritto di recesso ✅ COMPLETATA

**Implementato (2026-06-12):**

- **Impostazioni admin** — tab **Generale**, nuovo blocco "Esclusioni per tassonomia prodotto": tre select multipli (`select2` WooCommerce style) per selezionare categorie (`product_cat`), tag (`product_tag`) e marchi (`product_brands`/`pwb-brand` a seconda del plugin installato).
- Tre option salvate: `euwb_excluded_categories` (array di term IDs), `euwb_excluded_tags` (array), `euwb_excluded_brands` (array).
- `EUWB_Frontend::order_has_excluded_taxonomy( $order )` — controlla tutti i prodotti dell'ordine: se almeno uno ha una tassonomia appartenente agli array esclusi, restituisce `true`.
- Il box recesso **non viene mostrato** affatto lato frontend se `order_has_excluded_taxonomy()` ritorna `true`.
- La stessa verifica è applicata nei metodi AJAX `ajax_initiate()` e `ajax_confirm()` per prevenire submit diretti.

---

## Feature 8 – Email admin come WC_Email nativa

### Obiettivo

Portare le due notifiche admin (`send_admin_intent_notification`, `send_admin_confirmed_notification`) dallo stile `wp_mail()` diretto a classi `WC_Email` native, rendendole configurabili da **WooCommerce > Email** esattamente come le email cliente.

### Cosa implementare

- Nuovi file `includes/emails/class-euwb-email-admin-intent.php` e `class-euwb-email-admin-confirmed.php`.
- Registrazione in `register_email_classes()`.
- Rimozione degli hook `wp_mail()` in `EUWB_Emails::init()`.
- Campi destinatario admin configurabili (di default `get_option('euwb_admin_email')`).
