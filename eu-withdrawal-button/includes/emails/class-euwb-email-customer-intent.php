<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class EUWB_Email_Customer_Intent extends WC_Email {

    public function __construct() {
        $this->id             = 'euwb_customer_intent';
        $this->customer_email = true;
        $this->title          = __( 'Recesso EU – Richiesta ricevuta', 'eu-withdrawal-button' );
        $this->description    = __( 'Inviata al cliente quando la richiesta di recesso è registrata e in attesa di conferma admin.', 'eu-withdrawal-button' );
        $this->heading        = __( 'Abbiamo ricevuto la tua richiesta di recesso', 'eu-withdrawal-button' );
        $this->subject        = get_option( 'euwb_intent_email_subject', __( 'Richiesta di recesso ricevuta – Ordine #{order_number}', 'eu-withdrawal-button' ) );

        parent::__construct();
    }

    public function trigger( int $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $this->object    = $order;
        $this->recipient = $order->get_billing_email();

        if ( ! $this->is_enabled() || ! $this->get_recipient() ) return;

        $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
    }

    public function get_subject() {
        $order = $this->object;
        if ( ! $order ) return parent::get_subject();

        $withdrawal = EUWB_Withdrawal::get_withdrawal( $order->get_id() );
        $raw        = get_option( 'euwb_intent_email_subject', parent::get_subject() );
        return EUWB_Emails::replace_placeholders( $raw, $order, $withdrawal );
    }

    public function get_content_html() {
        return wc_get_template_html(
            'euwb-customer-intent.php',
            array(
                'order'         => $this->object,
                'email_heading' => $this->get_heading(),
                'email'         => $this,
            ),
            '',
            EUWB_PLUGIN_DIR . 'includes/emails/templates/'
        );
    }

    public function get_content_plain() {
        return wc_get_template_html(
            'euwb-customer-intent-plain.php',
            array(
                'order' => $this->object,
                'email' => $this,
            ),
            '',
            EUWB_PLUGIN_DIR . 'includes/emails/templates/'
        );
    }
}
