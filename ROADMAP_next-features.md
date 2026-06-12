# ROADMAP – Prossime funzionalità

---

## ~~Feature 1 – Email `customer_intent` (inviata subito dopo il click su "Conferma recesso qui")~~ ✅ COMPLETATA

### Contesto attuale

Il flusso attuale ha **due step** lato cliente:

1. **Step 1** (`euwb-btn-initiate`) – il cliente compila il modulo e clicca il primo pulsante. L'AJAX handler `ajax_initiate()` esegue solo validazione, **nessuna scrittura su DB**.
2. **Step 2** (`euwb-btn-confirm`) – il cliente clicca "Conferma recesso qui". L'AJAX handler `ajax_confirm()` chiama `EUWB_Withdrawal::create()` che scrive il record su DB con status `pending` e porta l'ordine in `pending-withdrawal`.

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
  - `EUWB_Withdrawal::create()` in flusso `standard` → comportamento invariato, ordine va in `pending-withdrawal`, hook `euwb_withdrawal_intent_created` scatta → email intent al cliente.
- Messaggio di successo JSON differenziato per modalità.
- `euwb_withdrawal_intent_created` **non scatta** in flusso diretto (perché `create_and_confirm()` non lo chiama) — by design.

### Note operative

- In **flusso diretto**, il tab "In attesa di conferma" nel registro recessi non verrà usato: tutti i recessi entrano già come `confirmed`. Il meta box nell'edit-order non mostra mai il pulsante admin "Conferma richiesta".
- Lo status ordine dopo `create_and_confirm()` è `pending-refund` (modificato rispetto al default originale `cancelled`).

---

## Feature 3 – Email native WooCommerce per i nuovi status ordine

### Contesto attuale

Le email del plugin (`send_customer_confirmation`, `send_customer_intent`, `send_admin_confirmation`) sono implementate come semplici `wp_mail()` in `EUWB_Emails`. Non sono visibili nella sezione **WooCommerce > Email**, non possono essere personalizzate dall'admin, non ereditano il template grafico di WooCommerce, e non hanno un'anteprima in back-office.

WooCommerce espone un sistema di email estendibile: basta registrare una classe che estende `WC_Email` e aggiungiarla al filtro `woocommerce_email_classes`. WooCommerce la gestirà automaticamente (anteprima, attivazione/disattivazione, personalizzazione oggetto/testo dall'interfaccia).

I due nuovi status ordine già registrati dal plugin sono:
- `wc-pending-withdrawal` – scatta quando il cliente completa lo step 2 (flusso standard)
- `wc-pending-refund` – scatta quando l'admin conferma il recesso (flusso standard) o già incluso nel flusso diretto

### Obiettivo

Creare **due classi email WooCommerce native** che sostituiscano (o affianchino in modo integrato) le chiamate manuali a `wp_mail()` esistenti:

1. **`EUWB_Email_Customer_Intent`** — inviata al cliente quando l'ordine passa in `wc-pending-withdrawal` (flusso standard, richiesta ricevuta ma non ancora confermata dall'admin)
2. **`EUWB_Email_Customer_Confirmation`** — inviata al cliente quando il recesso viene confermato definitivamente (hook `euwb_withdrawal_confirmed`, che scatta sia in flusso standard che in flusso diretto)

La email admin notification (`send_admin_confirmation`) può essere anch'essa convertita in una terza classe `EUWB_Email_Admin_Notification`, ma è opzionale e può essere aggiunta in un secondo momento.

### Cosa implementare

#### 1. Nuovo file `includes/emails/class-euwb-email-customer-intent.php`

Struttura base da seguire:

```php
class EUWB_Email_Customer_Intent extends WC_Email {

    public function __construct() {
        $this->id             = 'euwb_customer_intent';
        $this->customer_email = true;
        $this->title          = __( 'Recesso EU – Richiesta ricevuta', 'eu-withdrawal-button' );
        $this->description    = __( 'Email inviata al cliente quando la richiesta di recesso è registrata e in attesa di conferma admin.', 'eu-withdrawal-button' );
        $this->heading        = __( 'Abbiamo ricevuto la tua richiesta di recesso', 'eu-withdrawal-button' );
        $this->subject        = __( 'Richiesta di recesso ricevuta – Ordine #{order_number}', 'eu-withdrawal-button' );

        // Trigger: status ordine passa a pending-withdrawal
        $this->trigger_on_status = 'wc-pending-withdrawal';
        add_action( 'woocommerce_order_status_pending-withdrawal_notification', array( $this, 'trigger' ), 10, 2 );

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

    public function get_content_html() { /* template HTML */ }
    public function get_content_plain() { /* template plain text */ }
}
```

- Il trigger è `woocommerce_order_status_pending-withdrawal_notification` — WooCommerce genera automaticamente questo hook quando registri lo status custom con `show_in_admin_all_list => true` e l'ordine cambia status.
- L'hook ha la forma `woocommerce_order_status_{status_slug}_notification` dove lo slug non ha il prefisso `wc-`.

#### 2. Nuovo file `includes/emails/class-euwb-email-customer-confirmation.php`

```php
class EUWB_Email_Customer_Confirmation extends WC_Email {

    public function __construct() {
        $this->id             = 'euwb_customer_confirmation';
        $this->customer_email = true;
        $this->title          = __( 'Recesso EU – Conferma', 'eu-withdrawal-button' );
        $this->description    = __( 'Email inviata al cliente quando il recesso è confermato definitivamente.', 'eu-withdrawal-button' );
        $this->heading        = __( 'Il tuo recesso è stato confermato', 'eu-withdrawal-button' );
        $this->subject        = __( 'Conferma di recesso – Ordine #{order_number}', 'eu-withdrawal-button' );

        // Trigger: hook custom del plugin (sia flusso standard che diretto)
        add_action( 'euwb_withdrawal_confirmed', array( $this, 'trigger' ), 10, 1 );

        parent::__construct();
    }

    public function trigger( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $this->object    = $order;
        $this->recipient = $order->get_billing_email();

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) return;

        $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
    }

    public function get_content_html() { /* template HTML */ }
    public function get_content_plain() { /* template plain text */ }
}
```

- Usa l'hook `euwb_withdrawal_confirmed` già esistente (identico sia per flusso standard che diretto).
- Non dipende dal cambio di status ordine ma dall'evento business del plugin, così funziona in entrambi i flussi senza logica condizionale aggiuntiva.

#### 3. Template HTML e plain-text per ciascuna email

Creare la cartella `includes/emails/templates/` con 4 file:

- `euwb-customer-intent.php` — corpo HTML email intent (stessa struttura di `get_customer_email_body()` in `EUWB_Emails`, ma come template WooCommerce che riceve `$order` e `$email` come variabili)
- `euwb-customer-intent-plain.php` — versione plain text
- `euwb-customer-confirmation.php` — corpo HTML email conferma (migrazione di `get_customer_email_body()` attuale)
- `euwb-customer-confirmation-plain.php` — versione plain text

I template chiamano `wc_get_template_html()` internamente. Per renderli sovrascrivibili dal tema, usare il meccanismo standard WooCommerce: caricare da `woocommerce/emails/` nella cartella del tema se esiste, altrimenti dal plugin. Questo si ottiene registrando la path nel filtro `woocommerce_locate_template` oppure usando `wc_locate_template()` passando la path del plugin come fallback.

Struttura base di un template:

```php
<?php
// euwb-customer-intent.php
if ( ! defined( 'ABSPATH' ) ) exit;
do_action( 'woocommerce_email_header', $email_heading, $email );
// ... contenuto ...
do_action( 'woocommerce_email_footer', $email );
```

#### 4. Registrare le classi nel filtro `woocommerce_email_classes`

In `includes/class-euwb-emails.php`, nel metodo `init()`, aggiungere:

```php
add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_classes' ) );
```

E il metodo:

```php
public static function register_email_classes( $email_classes ) {
    require_once EUWB_PLUGIN_DIR . 'includes/emails/class-euwb-email-customer-intent.php';
    require_once EUWB_PLUGIN_DIR . 'includes/emails/class-euwb-email-customer-confirmation.php';
    $email_classes['EUWB_Email_Customer_Intent']        = new EUWB_Email_Customer_Intent();
    $email_classes['EUWB_Email_Customer_Confirmation']  = new EUWB_Email_Customer_Confirmation();
    return $email_classes;
}
```

Dopo questa registrazione, le due email compaiono in **WooCommerce > Email** con la possibilità di:
- Abilitare/disabilitare ciascuna separatamente
- Personalizzare oggetto, intestazione e testo aggiuntivo dall'interfaccia
- Visualizzare l'anteprima HTML

#### 5. Rimuovere le chiamate manuali `wp_mail()` ora duplicate

Una volta che le classi WC_Email gestiscono l'invio:

- Rimuovere `add_action( 'euwb_withdrawal_confirmed', array( __CLASS__, 'send_email_confirmed' ) )` da `EUWB_Emails::init()` (o almeno `send_customer_confirmation()` al suo interno), poiché `EUWB_Email_Customer_Confirmation` ascolta già `euwb_withdrawal_confirmed`.
- Rimuovere (o disabilitare) la chiamata a `send_customer_intent()` dall'hook `euwb_withdrawal_intent_created`, poiché `EUWB_Email_Customer_Intent` ascolta il cambio di status `pending-withdrawal`.
- Mantenere `send_admin_confirmation()` fino a quando non viene creata la classe `EUWB_Email_Admin_Notification`.

#### 6. Aggiungere la template path al loader di WooCommerce

In `eu-withdrawal-button.php` (file principale del plugin), aggiungere:

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

Questo permette al tema di sovrascrivere i template copiandoli in `wp-content/themes/<tema>/woocommerce/emails/`.

---

## Riepilogo file da modificare

| File | Modifica |
|------|----------|
| ~~`includes/class-euwb-withdrawal.php`~~ ✅ | ~~Aggiungere `do_action( 'euwb_withdrawal_intent_created', $order_id )` in `create()`~~ — fatto |
| `includes/class-euwb-emails.php` | ✅ `send_customer_intent()` + `send_admin_intent()` completate; hook `init()` aggiornato. Da fare (Feature 3): registrazione classi WC_Email, rimozione `wp_mail()` duplicate |
| ~~`includes/class-euwb-admin.php`~~ ✅ | ~~Aggiungere select `euwb_flow_mode` nella settings page + salvataggio~~ — fatto |
| ~~`includes/class-euwb-frontend.php`~~ ✅ | ~~Modificare `ajax_confirm()` per usare `create_and_confirm()` in flusso diretto~~ — fatto |
| `includes/emails/class-euwb-email-customer-intent.php` | Nuova classe `EUWB_Email_Customer_Intent extends WC_Email` |
| `includes/emails/class-euwb-email-customer-confirmation.php` | Nuova classe `EUWB_Email_Customer_Confirmation extends WC_Email` |
| `includes/emails/templates/euwb-customer-intent.php` | Template HTML email intent |
| `includes/emails/templates/euwb-customer-intent-plain.php` | Template plain-text email intent |
| `includes/emails/templates/euwb-customer-confirmation.php` | Template HTML email conferma (migrazione da `get_customer_email_body()`) |
| `includes/emails/templates/euwb-customer-confirmation-plain.php` | Template plain-text email conferma |
| `eu-withdrawal-button.php` | Aggiungere filter `woocommerce_locate_template` per la template path |

---

## Note importanti

- In **flusso diretto**, il tab "In attesa di conferma" nel registro recessi non verrà più usato (tutti i recessi entrano già come `confirmed`). Il meta box nell'edit-order non mostrerà mai il pulsante admin "Conferma richiesta".
- La email `customer_intent` (Feature 1) deve essere inviata **solo in flusso standard**. Verificare che l'hook `euwb_withdrawal_intent_created` sia chiamato esclusivamente da `create()` e non da `create_and_confirm()`.
- Entrambe le feature sono **indipendenti** ma correlate: la Feature 1 completa il flusso standard, la Feature 2 aggiunge il flusso alternativo.
- La Feature 3 **dipende** dalle Feature 1 e 2: va implementata dopo aver definito i trigger dei due flussi. Una volta completata, i metodi `send_customer_confirmation()`, `send_customer_intent()` e `send_admin_confirmation()` in `EUWB_Emails` possono essere rimossi o mantenuti solo come fallback.
- L'hook WooCommerce per il cambio status ha la forma `woocommerce_order_status_{slug}_notification` dove `{slug}` è lo status **senza prefisso `wc-`** (es. `pending-withdrawal`, non `wc-pending-withdrawal`). Verificare che WooCommerce generi questo hook per gli status custom: è necessario che lo status sia registrato con `register_post_status()` **prima** che venga aggiunto l'action listener, quindi va bene perché il plugin li registra su `init`.
- `EUWB_Email_Customer_Intent` si trigga sul cambio di status ordine (`wc-pending-withdrawal`), quindi funziona automaticamente in flusso standard. In flusso diretto l'ordine non passa mai per `wc-pending-withdrawal`, quindi questa email non verrà inviata — comportamento corretto by design.
