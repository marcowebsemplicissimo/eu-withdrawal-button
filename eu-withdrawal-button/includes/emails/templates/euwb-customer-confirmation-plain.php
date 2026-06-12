<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$withdrawal   = EUWB_Withdrawal::get_withdrawal( $order->get_id() );
$default_body = __( "Gentile {customer_name},\n\nil tuo recesso per l'ordine #{order_number} del {order_date} è stato confermato.\n\nIl rimborso sarà elaborato nei prossimi 14 giorni lavorativi con lo stesso metodo di pagamento utilizzato all'acquisto.\n\nAi sensi dell'Art. 11a della Direttiva UE 2023/2673.", 'eu-withdrawal-button' );
$body         = EUWB_Emails::replace_placeholders(
    get_option( 'euwb_confirmation_email_body', $default_body ),
    $order,
    $withdrawal
);

echo wp_strip_all_tags( $body ) . "\n\n";

$return_instructions = EUWB_Withdrawal::get_return_instructions( $order );
if ( $return_instructions !== '' ) {
    echo str_repeat( '-', 40 ) . "\n";
    echo strtoupper( esc_html__( 'Istruzioni per la restituzione del bene', 'eu-withdrawal-button' ) ) . "\n";
    echo str_repeat( '-', 40 ) . "\n";
    echo wp_strip_all_tags( $return_instructions ) . "\n";
    echo str_repeat( '-', 40 ) . "\n\n";
}

echo esc_html__( 'Ordine', 'eu-withdrawal-button' ) . ': #' . $order->get_order_number() . "\n";

if ( $withdrawal ) {
    $ts = ! empty( $withdrawal->confirmed_at ) ? strtotime( $withdrawal->confirmed_at ) : strtotime( $withdrawal->created_at );
    echo esc_html__( 'Data conferma', 'eu-withdrawal-button' ) . ': ' . date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts ) . "\n";
}

echo esc_html__( 'Stato', 'eu-withdrawal-button' ) . ': ' . esc_html__( 'Confermato', 'eu-withdrawal-button' ) . "\n";
