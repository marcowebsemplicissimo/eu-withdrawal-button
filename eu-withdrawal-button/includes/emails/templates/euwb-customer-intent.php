<?php
if ( ! defined( 'ABSPATH' ) ) exit;

$withdrawal   = EUWB_Withdrawal::get_withdrawal( $order->get_id() );
$default_body = __( "Gentile {customer_name},\n\nabbiamo ricevuto la tua richiesta di recesso per l'ordine #{order_number} del {order_date}.\n\nLa richiesta è attualmente in fase di elaborazione. Riceverai un'email di conferma non appena sarà processata.\n\nAi sensi dell'Art. 11a della Direttiva UE 2023/2673.", 'eu-withdrawal-button' );
$body         = EUWB_Emails::replace_placeholders(
    get_option( 'euwb_intent_email_body', $default_body ),
    $order,
    $withdrawal
);

do_action( 'woocommerce_email_header', $email_heading, $email );
?>

<p><?php echo nl2br( wp_kses_post( $body ) ); ?></p>

<table cellspacing="0" cellpadding="6" style="width:100%;border-collapse:collapse;margin:20px 0;">
    <tr>
        <th style="text-align:left;padding:10px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Ordine', 'eu-withdrawal-button' ); ?></th>
        <td style="padding:10px;border:1px solid #e5e5e5;">#<?php echo esc_html( $order->get_order_number() ); ?></td>
    </tr>
    <tr>
        <th style="text-align:left;padding:10px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Data richiesta', 'eu-withdrawal-button' ); ?></th>
        <td style="padding:10px;border:1px solid #e5e5e5;">
            <?php echo $withdrawal ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $withdrawal->created_at ) ) ) : '—'; ?>
        </td>
    </tr>
    <tr>
        <th style="text-align:left;padding:10px;border:1px solid #e5e5e5;background:#f8f8f8;"><?php esc_html_e( 'Stato', 'eu-withdrawal-button' ); ?></th>
        <td style="padding:10px;border:1px solid #e5e5e5;"><?php esc_html_e( 'In attesa di conferma', 'eu-withdrawal-button' ); ?></td>
    </tr>
</table>

<?php do_action( 'woocommerce_email_footer', $email ); ?>
