# ROADMAP – Prossime funzionalità

---

## ~~Feature 1 – Email `customer_intent` (inviata subito dopo il click su "Conferma recesso qui")~~ ✅ COMPLETATA

### Contesto attuale

Il flusso attuale ha **due step** lato cliente:

1. **Step 1** (`euwb-btn-initiate`) – il cliente compila il modulo e clicca il primo pulsante. L'AJAX handler `ajax_initiate()` esegue solo validazione, **nessuna scrittura su DB**.
2. **Step 2** (`euwb-btn-confirm`) – il cliente clicca "Conferma recesso qui". L'AJAX handler `ajax_confirm()` chiama `EUWB_Withdrawal::create()` che scrive il record su DB con status `pending` e porta l'ordine in `pending-withdraw`.

~~Attualmente nessuna email viene inviata al cliente in questo momento. Le email (`send_customer_confirmation` + `send_admin_confirmation`) vengono inviate solo all'hook `euwb_withdrawal_confirmed`, che scatta quando l'**admin** conferma il recesso (non il cliente).~~

**Implementato:** `send_customer_intent()` + `send_admin_intent()` agganciate all'hook `euwb_withdrawal_intent_created`, che scatta in `EUWB_Withdrawal::create()`. Il flusso email è ora:
- `euwb_withdrawal_intent_created` → `send_email_intent()` → `send_customer_intent()` + `send_admin_intent()`
- `euwb_withdrawal_confirmed` → `send_email_confirmed()` → `send_customer_confirmation()` + `send_admin_confirmation()`

### Obiettivo

Aggiungere una **nuova email di tipo "intent"** (`customer_intent`) che viene inviata **immediatamente** dopo il click su `euwb-btn-confirm` (step 2), cioè nel momento in cui `EUWB_Withdrawal::create()` va a buon fine.

Questa email serve a:
- Confermare al cliente che la richiesta è stata **ricevuta e registrata**
- Informarlo che è **in attesa di elaborazione** da parte dell'amministratore
- Rispettare l'obbligo di comunicazione immediata previsto dalla Direttiva UE 2023/2673

### Cosa implementare

#### 1. Nuovo metodo `EUWB_Emails::send_customer_intent()` in `includes/class-euwb-emails.php`

Il metodo è analogo a `send_customer_confirmation()` ma con testo diverso:
- Oggetto: `Richiesta di recesso ricevuta – Ordine #<numero_ordine>`
- Il corpo deve comunicare che la richiesta è stata ricevuta e che verrà elaborata nei prossimi giorni lavorativi
- Status mostrato nella tabella: **"In attesa di conferma"** (non "Confirmed")
- Non includere la data `confirmed_at` (che non esiste ancora), usare solo `created_at`
- Applicare filter `euwb_customer_intent_email_subject` sull'oggetto
- Body: usare la stessa struttura HTML di `get_customer_email_body()` ma con testo "intent" (ricevuta, non ancora confermata)

#### 2. Nuovo hook `euwb_withdrawal_intent_created` in `includes/class-euwb-withdrawal.php`

Nel metodo `EUWB_Withdrawal::create()`, subito dopo il return `$withdrawal_id`, aggiungere:

```php
do_action( 'euwb_withdrawal_intent_created', $order_id );
```

Questo hook andrà inserito **dentro** il blocco `if ( $inserted )`, dopo `$order->update_status(...)`.

#### 3. Collegare hook → email in `includes/class-euwb-emails.php`

In `EUWB_Emails::init()`, aggiungere:

```php
add_action( 'euwb_withdrawal_intent_created', array( __CLASS__, 'send_customer_intent' ) );
```

#### 4. Implementare il corpo dell'email intent

Il metodo `send_customer_intent( $order_id )` deve:
1. Recuperare ordine e withdrawal record (`EUWB_Withdrawal::get_withdrawal( $order_id )`)
2. Usare `$withdrawal->created_at` come data (non `confirmed_at`)
3. Inviare email HTML con oggetto e corpo "intent" al `$withdrawal->email`

---

## ~~Feature 2 – Modalità "Flusso Diretto" (auto-confirm al click cliente)~~ ✅ COMPLETATA

### Stato finale

**Implementato:**
- Opzione `euwb_flow_mode` (`standard` | `direct`, default `standard`) nelle impostazioni (`EU Withdrawal > Impostazioni`), prima riga della form-table.
- `ajax_confirm()` in `includes/class-euwb-frontend.php` legge `euwb_flow_mode` e chiama:
  - `EUWB_Withdrawal::create_and_confirm()` in flusso `direct` → ordine passa in `pending-refund`, hook `euwb_withdrawal_confirmed` scatta, email `customer_confirmation` + `admin_notification` inviate immediatamente.
  - `EUWB_Withdrawal::create()` in flusso `standard` → comportamento invariato, ordine va in `pending-withdraw`, hook `euwb_withdrawal_intent_created` scatta → email intent al cliente.
- Messaggio di successo JSON differenziato per modalità.
- `euwb_withdrawal_intent_created` **non scatta** in flusso diretto (perché `create_and_confirm()` non lo chiama) — by design.

### Note operative

- In **flusso diretto**, il tab "In attesa di conferma" nel registro recessi non verrà usato: tutti i recessi entrano già come `confirmed`. Il meta box nell'edit-order non mostra mai il pulsante admin "Conferma richiesta".
- Lo status ordine dopo `create_and_confirm()` è `pending-refund` (modificato rispetto al default originale `cancelled`).

---

## Feature 3 – Email native WooCommerce per i nuovi status ordine

### Contesto attuale

Le email del plugin (`send_customer_confirmation`, `send_customer_intent`, `send_admin_confirmation`) sono implementate come semplici `wp_mail()` in `EUWB_Emails`. Non sono visibili nella sezione **WooCommerce > Email**, non ereditano il template grafico di WooCommerce e non hanno un'anteprima in back-office.

### Obiettivo

Creare **due classi email WooCommerce native** (`WC_Email`) e aggiungere nei **settings del plugin** (`EU Withdrawal > Impostazioni`) campi textarea per personalizzare il corpo di ciascuna email. I template leggono i valori tramite `get_option()`, così l'admin può modificare i testi senza toccare il codice.

Le due email:
1. **`EUWB_Email_Customer_Intent`** — inviata al cliente quando l'ordine passa in `wc-pending-withdraw` (flusso standard)
2. **`EUWB_Email_Customer_Confirmation`** — inviata al cliente quando il recesso è confermato definitivamente (hook `euwb_withdrawal_confirmed`, sia flusso standard che diretto)

### Cosa implementare

#### 1. Nuove opzioni di personalizzazione testo email in `includes/class-euwb-admin.php`

Aggiungere una nuova sezione nella settings page dopo le impostazioni esistenti, con 4 campi:

```php
// Defaults
$default_intent_subject = __( 'Richiesta di recesso ricevuta – Ordine #{order_number}', 'eu-withdrawal-button' );
$default_intent_body    = __( 'Abbiamo ricevuto la tua richiesta di recesso per l\'ordine #{order_number} del {order_date}. La richiesta è in attesa di elaborazione da parte dell\'amministratore. Riceverai una email di conferma non appena la richiesta sarà processata.', 'eu-withdrawal-button' );
$default_confirm_subject = __( 'Conferma di recesso – Ordine #{order_number}', 'eu-withdrawal-button' );
$default_confirm_body    = __( 'Il tuo recesso per l\'ordine #{order_number} del {order_date} è stato confermato. Il rimborso sarà elaborato nei prossimi 14 giorni lavorativi.', 'eu-withdrawal-button' );
```

Campi da aggiungere nella `<table class="form-table">`:

| Opzione WordPress | Campo | Note |
|---|---|---|
| `euwb_intent_email_subject` | `<input type="text">` | Oggetto email intent |
| `euwb_intent_email_body` | `<textarea>` | Corpo email intent (plain text + basic HTML) |
| `euwb_confirmation_email_subject` | `<input type="text">` | Oggetto email conferma |
| `euwb_confirmation_email_body` | `<textarea>` | Corpo email conferma |

I segnaposto disponibili (documentati nella `<p class="description">` di ciascun campo):
- `{order_number}` — numero ordine
- `{order_date}` — data ordine
- `{customer_name}` — nome e cognome cliente
- `{withdrawal_date}` — data richiesta recesso

Nel blocco di salvataggio POST aggiungere:
```php
update_option( 'euwb_intent_email_subject',      sanitize_text_field( $_POST['euwb_intent_email_subject'] ?? $default_intent_subject ) );
update_option( 'euwb_intent_email_body',         wp_kses_post( $_POST['euwb_intent_email_body'] ?? $default_intent_body ) );
update_option( 'euwb_confirmation_email_subject', sanitize_text_field( $_POST['euwb_confirmation_email_subject'] ?? $default_confirm_subject ) );
update_option( 'euwb_confirmation_email_body',    wp_kses_post( $_POST['euwb_confirmation_email_body'] ?? $default_confirm_body ) );
```

#### 2. Helper di sostituzione segnaposto

In `includes/class-euwb-emails.php`, aggiungere un metodo statico riutilizzabile da entrambi i template:

```php
public static function replace_placeholders( $text, $order, $withdrawal = null ) {
    $placeholders = array(
        '{order_number}'   => $order->get_order_number(),
        '{order_date}'     => wc_format_datetime( $order->get_date_created() ),
        '{customer_name}'  => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
        '{withdrawal_date}' => $withdrawal ? date_i18n( get_option('date_format'), strtotime( $withdrawal->created_at ) ) : '',
    );
    return str_replace( array_keys( $placeholders ), array_values( $placeholders ), $text );
}
```

#### 3. Nuovo file `includes/emails/class-euwb-email-customer-intent.php`

```php
class EUWB_Email_Customer_Intent extends WC_Email {

    public function __construct() {
        $this->id             = 'euwb_customer_intent';
        $this->customer_email = true;
        $this->title          = __( 'Recesso EU – Richiesta ricevuta', 'eu-withdrawal-button' );
        $this->description    = __( 'Inviata al cliente quando la richiesta di recesso è registrata e in attesa di conferma admin.', 'eu-withdrawal-button' );
        $this->heading        = __( 'Abbiamo ricevuto la tua richiesta di recesso', 'eu-withdrawal-button' );
        $this->subject        = get_option( 'euwb_intent_email_subject', 'Richiesta di recesso ricevuta – Ordine #{order_number}' );

        add_action( 'woocommerce_order_status_pending-withdraw_notification', array( $this, 'trigger' ), 10, 2 );

        parent::__construct();
    }

    public function trigger( $order_id, $order = false ) {
        if ( $order_id && ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order_id );
        }
        if ( ! $order ) return;

        $this->object    = $order;
        $this->recipient = $order->get_billing_email();

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) return;

        $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
    }

    public function get_content_html() {
        return wc_get_template_html( 'euwb-customer-intent.php', array(
            'order'         => $this->object,
            'email_heading' => $this->get_heading(),
            'email'         => $this,
        ), '', EUWB_PLUGIN_DIR . 'includes/emails/templates/' );
    }

    public function get_content_plain() {
        return wc_get_template_html( 'euwb-customer-intent-plain.php', array(
            'order' => $this->object,
            'email' => $this,
        ), '', EUWB_PLUGIN_DIR . 'includes/emails/templates/' );
    }
}
```

#### 4. Nuovo file `includes/emails/class-euwb-email-customer-confirmation.php`

Struttura identica a `EUWB_Email_Customer_Intent`, con:
- `$this->id = 'euwb_customer_confirmation'`
- `$this->subject = get_option( 'euwb_confirmation_email_subject', ... )`
- Trigger: `add_action( 'euwb_withdrawal_confirmed', array( $this, 'trigger' ), 10, 1 )` — firma `trigger( $order_id )` senza `$order`
- Template: `euwb-customer-confirmation.php` / `euwb-customer-confirmation-plain.php`

#### 5. Template HTML e plain-text (`includes/emails/templates/`)

4 file da creare. Struttura comune per gli HTML:

```php
<?php
// euwb-customer-intent.php
if ( ! defined( 'ABSPATH' ) ) exit;
do_action( 'woocommerce_email_header', $email_heading, $email );

$withdrawal = EUWB_Withdrawal::get_withdrawal( $order->get_id() );
$body = get_option( 'euwb_intent_email_body', '' );
$body = EUWB_Emails::replace_placeholders( $body, $order, $withdrawal );

echo '<p>' . nl2br( wp_kses_post( $body ) ) . '</p>';

do_action( 'woocommerce_email_footer', $email );
```

Per i plain-text (`-plain.php`): stessa logica ma `echo wp_strip_all_tags( $body )` senza header/footer WC.

#### 6. Registrare le classi nel filtro `woocommerce_email_classes`

In `includes/class-euwb-emails.php`, nel metodo `init()`:

```php
add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_classes' ) );
```

```php
public static function register_email_classes( $email_classes ) {
    require_once EUWB_PLUGIN_DIR . 'includes/emails/class-euwb-email-customer-intent.php';
    require_once EUWB_PLUGIN_DIR . 'includes/emails/class-euwb-email-customer-confirmation.php';
    $email_classes['EUWB_Email_Customer_Intent']       = new EUWB_Email_Customer_Intent();
    $email_classes['EUWB_Email_Customer_Confirmation'] = new EUWB_Email_Customer_Confirmation();
    return $email_classes;
}
```

Le due email compaiono in **WooCommerce > Email** con anteprima e toggle attiva/disattiva.

#### 7. Rimuovere le chiamate manuali `wp_mail()` ora duplicate

In `EUWB_Emails::init()`:
- Rimuovere `add_action( 'euwb_withdrawal_confirmed', array( __CLASS__, 'send_email_confirmed' ) )` — gestito da `EUWB_Email_Customer_Confirmation`.
- Rimuovere `add_action( 'euwb_withdrawal_intent_created', array( __CLASS__, 'send_email_intent' ) )` — gestito da `EUWB_Email_Customer_Intent` tramite cambio status.
- Mantenere `send_admin_confirmation()` (o `send_admin_intent()`) finché non viene creata `EUWB_Email_Admin_Notification`.

#### 8. Aggiungere la template path al loader di WooCommerce

In `eu-withdrawal-button.php`:

```php
add_filter( 'woocommerce_locate_template', 'euwb_locate_template', 10, 3 );

function euwb_locate_template( $template, $template_name, $template_path ) {
    $plugin_template = EUWB_PLUGIN_DIR . 'includes/emails/templates/' . $template_name;
    if ( file_exists( $plugin_template ) ) {
        return $plugin_template;
    }
    return $template;
}
```

Permette al tema di sovrascrivere i template copiandoli in `wp-content/themes/<tema>/woocommerce/emails/`.

---

## Riepilogo file da modificare

| File | Modifica |
|------|----------|
| ~~`includes/class-euwb-withdrawal.php`~~ ✅ | ~~Aggiungere `do_action( 'euwb_withdrawal_intent_created', $order_id )` in `create()`~~ — fatto |
| `includes/class-euwb-emails.php` | ✅ `send_customer_intent()` + `send_admin_intent()` completate; hook `init()` aggiornato. Da fare (Feature 3): aggiungere `replace_placeholders()`, registrazione classi WC_Email, rimozione `wp_mail()` duplicate |
| ~~`includes/class-euwb-admin.php`~~ ✅ | ~~Aggiungere select `euwb_flow_mode` nella settings page + salvataggio~~ — fatto. Da fare (Feature 3): aggiungere 4 campi textarea/input per testi email intent e conferma |
| ~~`includes/class-euwb-frontend.php`~~ ✅ | ~~Modificare `ajax_confirm()` per usare `create_and_confirm()` in flusso diretto~~ — fatto |
| `includes/emails/class-euwb-email-customer-intent.php` | Nuova classe `EUWB_Email_Customer_Intent extends WC_Email` |
| `includes/emails/class-euwb-email-customer-confirmation.php` | Nuova classe `EUWB_Email_Customer_Confirmation extends WC_Email` |
| `includes/emails/templates/euwb-customer-intent.php` | Template HTML — legge `euwb_intent_email_body` via `get_option()` + `replace_placeholders()` |
| `includes/emails/templates/euwb-customer-intent-plain.php` | Template plain-text email intent |
| `includes/emails/templates/euwb-customer-confirmation.php` | Template HTML — legge `euwb_confirmation_email_body` via `get_option()` + `replace_placeholders()` |
| `includes/emails/templates/euwb-customer-confirmation-plain.php` | Template plain-text email conferma |
| `eu-withdrawal-button.php` | Aggiungere filter `woocommerce_locate_template` per la template path |

---

## Note importanti

- In **flusso diretto**, il tab "In attesa di conferma" nel registro recessi non verrà più usato (tutti i recessi entrano già come `confirmed`). Il meta box nell'edit-order non mostrerà mai il pulsante admin "Conferma richiesta".
- La email `customer_intent` (Feature 1) deve essere inviata **solo in flusso standard**. Verificare che l'hook `euwb_withdrawal_intent_created` sia chiamato esclusivamente da `create()` e non da `create_and_confirm()`.
- Entrambe le feature sono **indipendenti** ma correlate: la Feature 1 completa il flusso standard, la Feature 2 aggiunge il flusso alternativo.
- La Feature 3 **dipende** dalle Feature 1 e 2: va implementata dopo aver definito i trigger dei due flussi. Una volta completata, i metodi `send_customer_confirmation()`, `send_customer_intent()` e `send_admin_confirmation()` in `EUWB_Emails` possono essere rimossi o mantenuti solo come fallback.
- L'hook WooCommerce per il cambio status ha la forma `woocommerce_order_status_{slug}_notification` dove `{slug}` è lo status **senza prefisso `wc-`** (es. `pending-withdraw`, non `wc-pending-withdraw`). Verificare che WooCommerce generi questo hook per gli status custom: è necessario che lo status sia registrato con `register_post_status()` **prima** che venga aggiunto l'action listener, quindi va bene perché il plugin li registra su `init`.
- `EUWB_Email_Customer_Intent` si trigga sul cambio di status ordine (`wc-pending-withdraw`), quindi funziona automaticamente in flusso standard. In flusso diretto l'ordine non passa mai per `wc-pending-withdraw`, quindi questa email non verrà inviata — comportamento corretto by design.
