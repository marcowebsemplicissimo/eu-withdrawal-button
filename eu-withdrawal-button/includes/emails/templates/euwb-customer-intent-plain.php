<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$withdrawal   = EUWB_Withdrawal::get_withdrawal( $order->get_id() );
$default_body = __( "Gentile {customer_name},\n\nabbiamo ricevuto la tua richiesta di recesso per l'ordine #{order_number} del {order_date}.\n\nLa richiesta è attualmente in fase di elaborazione. Riceverai un'email di conferma non appena sarà processata.\n\nAi sensi dell'Art. 11a della Direttiva UE 2023/2673.", 'eu-withdrawal-button' );
$body         = EUWB_Emails::replace_placeholders(
    get_option( 'euwb_intent_email_body', $default_body ),
    $order,
    $withdrawal
);

echo wp_strip_all_tags( $body ) . "\n\n";

echo esc_html__( 'Ordine', 'eu-withdrawal-button' ) . ': #' . $order->get_order_number() . "\n";

if ( $withdrawal ) {
    echo esc_html__( 'Data richiesta', 'eu-withdrawal-button' ) . ': ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $withdrawal->created_at ) ) . "\n";
}

echo esc_html__( 'Stato', 'eu-withdrawal-button' ) . ': ' . esc_html__( 'In attesa di conferma', 'eu-withdrawal-button' ) . "\n";
