<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class EUWB_Emails {

    public static function init() {
        add_action( 'euwb_withdrawal_confirmed', array( __CLASS__, 'send_email_confirmed' ) );
        add_action( 'euwb_withdrawal_intent_created', array( __CLASS__, 'send_email_intent' ) );
    }

    /**
     * Send confirmation to the customer and notification to the admin.
     */
    public static function send_email_confirmed( $order_id ) {
        $order      = wc_get_order( $order_id );
        $withdrawal = EUWB_Withdrawal::get_withdrawal( $order_id );
        if ( ! $order || ! $withdrawal ) return;

        self::send_customer_confirmation( $order, $withdrawal );
        self::send_admin_confirmation( $order, $withdrawal );
    }

    public static function send_email_intent( $order_id ) {
        $order      = wc_get_order( $order_id );
        $withdrawal = EUWB_Withdrawal::get_withdrawal( $order_id );
        if ( ! $order || ! $withdrawal ) return;

        self::send_customer_intent( $order, $withdrawal );
        self::send_admin_intent( $order, $withdrawal );
    }

    // -----------------------------------------------------------------------
    // Customer intent e-mail (inviata subito dopo il click su "Conferma recesso")
    // -----------------------------------------------------------------------
    public static function send_customer_intent( $order, $withdrawal ) {

        $to      = $withdrawal->email ?: $order->get_billing_email();
        $subject = apply_filters(
            'euwb_customer_intent_email_subject',
            sprintf( __( 'Richiesta di recesso ricevuta – Ordine #%s', 'eu-withdrawal-button' ), $order->get_order_number() )
        );

        $body    = self::get_customer_intent_email_body( $order, $withdrawal );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        wp_mail( $to, $subject, $body, $headers );
    }

    private static function get_customer_intent_email_body( $order, $withdrawal ) {
        $site_name  = get_bloginfo( 'name' );
        $order_num  = $order->get_order_number();
        $first_name = $withdrawal->first_name;
        $last_name  = $withdrawal->last_name;
        $ts         = strtotime( $withdrawal->created_at );
        $date       = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
        $title      = sprintf( __( 'Richiesta di recesso ricevuta – Ordine #%s', 'eu-withdrawal-button' ), $order_num );

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title><?php echo esc_html( $title ); ?></title></head>
<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:24px;">
  <h2 style="color:#1a1a1a;"><?php echo esc_html( $site_name ); ?> – <?php esc_html_e( 'Richiesta di recesso ricevuta', 'eu-withdrawal-button' ); ?></h2>
  <p><?php printf( esc_html__( 'Gentile %s,', 'eu-withdrawal-button' ), esc_html( "$first_name $last_name" ) ); ?></p>
  <p><?php printf( esc_html__( 'Abbiamo ricevuto la tua richiesta di recesso relativa all\'ordine #%1$s, inviata il %2$s, ai sensi dell\'Art. 11a della Direttiva UE 2023/2673 (che modifica la Direttiva 2011/83/UE sui diritti dei consumatori).', 'eu-withdrawal-button' ), esc_html( $order_num ), esc_html( $date ) ); ?></p>
  <p><?php esc_html_e( 'La tua richiesta è attualmente in fase di elaborazione da parte del nostro team. Riceverai un\'email di conferma non appena sarà processata.', 'eu-withdrawal-button' ); ?></p>
  <table style="width:100%;border-collapse:collapse;margin:20px 0;">
    <tr style="background:#f5f5f5;">
      <td style="padding:10px;border:1px solid #ddd;"><strong><?php esc_html_e( 'Ordine', 'eu-withdrawal-button' ); ?></strong></td>
      <td style="padding:10px;border:1px solid #ddd;">#<?php echo esc_html( $order_num ); ?></td>
    </tr>
    <tr>
      <td style="padding:10px;border:1px solid #ddd;"><strong><?php esc_html_e( 'Data richiesta', 'eu-withdrawal-button' ); ?></strong></td>
      <td style="padding:10px;border:1px solid #ddd;"><?php echo esc_html( $date ); ?></td>
    </tr>
    <tr style="background:#f5f5f5;">
      <td style="padding:10px;border:1px solid #ddd;"><strong><?php esc_html_e( 'Stato', 'eu-withdrawal-button' ); ?></strong></td>
      <td style="padding:10px;border:1px solid #ddd;"><?php esc_html_e( 'In attesa di conferma', 'eu-withdrawal-button' ); ?></td>
    </tr>
  </table>
  <p style="font-size:12px;color:#999;margin-top:32px;">
    <?php esc_html_e( 'Questo messaggio è stato generato automaticamente ai sensi della Direttiva UE 2023/2673 e della Direttiva 2011/83/UE sui diritti dei consumatori.', 'eu-withdrawal-button' ); ?><br>
    <?php esc_html_e( 'Per ulteriori informazioni:', 'eu-withdrawal-button' ); ?> <?php echo esc_html( get_option( 'admin_email' ) ); ?>
  </p>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Customer e-mail
    // -----------------------------------------------------------------------
    public static function send_customer_confirmation( $order, $withdrawal ) {
        $to      = $withdrawal->email ?: $order->get_billing_email();
        $subject = apply_filters(
            'euwb_customer_email_subject',
            sprintf( __( 'Conferma di recesso – Ordine #%s', 'eu-withdrawal-button' ), $order->get_order_number() )
        );

        $body = self::get_customer_email_body( $order, $withdrawal );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        wp_mail( $to, $subject, $body, $headers );
    }

    private static function get_customer_email_body( $order, $withdrawal ) {
        $site_name   = get_bloginfo( 'name' );
        $order_num   = $order->get_order_number();
        $first_name  = $withdrawal->first_name;
        $last_name   = $withdrawal->last_name;
        $ts          = ! empty( $withdrawal->confirmed_at ) ? strtotime( $withdrawal->confirmed_at ) : strtotime( $withdrawal->created_at );
        $date        = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
        $refund_days = apply_filters( 'euwb_refund_days', 14 );
        $title       = sprintf( __( 'Conferma di recesso – Ordine #%s', 'eu-withdrawal-button' ), $order_num );

        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"><title><?php echo esc_html( $title ); ?></title></head>
<body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:24px;">
  <h2 style="color:#1a1a1a;"><?php echo esc_html( $site_name ); ?> – <?php esc_html_e( 'Withdrawal Confirmation', 'eu-withdrawal-button' ); ?></h2>
  <p><?php printf( esc_html__( 'Dear %s,', 'eu-withdrawal-button' ), esc_html( "$first_name $last_name" ) ); ?></p>
  <p><?php printf( esc_html__( 'We confirm that your withdrawal from order #%1$s has been successfully registered on %2$s, pursuant to Art. 11a of EU Directive 2023/2673 (amending Directive 2011/83/EU on consumer rights).', 'eu-withdrawal-button' ), esc_html( $order_num ), esc_html( $date ) ); ?></p>
  <table style="width:100%;border-collapse:collapse;margin:20px 0;">
    <tr style="background:#f5f5f5;">
      <td style="padding:10px;border:1px solid #ddd;"><strong><?php esc_html_e( 'Order', 'eu-withdrawal-button' ); ?></strong></td>
      <td style="padding:10px;border:1px solid #ddd;">#<?php echo esc_html( $order_num ); ?></td>
    </tr>
    <tr>
      <td style="padding:10px;border:1px solid #ddd;"><strong><?php esc_html_e( 'Withdrawal date', 'eu-withdrawal-button' ); ?></strong></td>
      <td style="padding:10px;border:1px solid #ddd;"><?php echo esc_html( $date ); ?></td>
    </tr>
    <tr style="background:#f5f5f5;">
      <td style="padding:10px;border:1px solid #ddd;"><strong><?php esc_html_e( 'Status', 'eu-withdrawal-button' ); ?></strong></td>
      <td style="padding:10px;border:1px solid #ddd;"><?php esc_html_e( 'Confirmed', 'eu-withdrawal-button' ); ?></td>
    </tr>
  </table>
  <p><strong><?php esc_html_e( 'What happens next?', 'eu-withdrawal-button' ); ?></strong></p>
  <ul>
    <li><?php printf( esc_html__( 'Our team will process your request within %d business days.', 'eu-withdrawal-button' ), absint( $refund_days ) ); ?></li>
    <li><?php esc_html_e( 'The refund will be issued using the same payment method used at the time of purchase, unless otherwise agreed.', 'eu-withdrawal-button' ); ?></li>
    <li><?php esc_html_e( 'We will provide return instructions for the product (if applicable).', 'eu-withdrawal-button' ); ?></li>
  </ul>
  <p style="font-size:12px;color:#999;margin-top:32px;">
    <?php esc_html_e( 'This message was automatically generated in accordance with EU Directive 2023/2673 and Directive 2011/83/EU on consumer rights.', 'eu-withdrawal-button' ); ?><br>
    <?php esc_html_e( 'For further information:', 'eu-withdrawal-button' ); ?> <?php echo esc_html( get_option( 'admin_email' ) ); ?>
  </p>
</body>
</html>
        <?php
        return ob_get_clean();
    }

    // -----------------------------------------------------------------------
    // Admin confirmation e-mail
    // -----------------------------------------------------------------------
    public static function send_admin_confirmation( $order, $withdrawal ) {
        $to      = apply_filters( 'euwb_admin_notification_email', get_option( 'admin_email' ) );
        $subject = sprintf( __( '[%s] Nuovo recesso – Ordine #%s', 'eu-withdrawal-button' ), get_bloginfo( 'name' ), $order->get_order_number() );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $order_url = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
        $ts        = ! empty( $withdrawal->confirmed_at ) ? strtotime( $withdrawal->confirmed_at ) : strtotime( $withdrawal->created_at );
        $date      = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );

        $body = '
<html><body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:24px;">
<h2>Nuovo Recesso Confermato</h2>
<table style="width:100%;border-collapse:collapse;">
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Ordine</strong></td><td style="padding:8px;border:1px solid #ddd;"><a href="' . esc_url( $order_url ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Cliente</strong></td><td style="padding:8px;border:1px solid #ddd;">' . esc_html( $withdrawal->first_name . ' ' . $withdrawal->last_name ) . '</td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Email</strong></td><td style="padding:8px;border:1px solid #ddd;">' . esc_html( $withdrawal->email ) . '</td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Data</strong></td><td style="padding:8px;border:1px solid #ddd;">' . esc_html( $date ) . '</td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Motivo</strong></td><td style="padding:8px;border:1px solid #ddd;">' . esc_html( $withdrawal->reason ?: '—' ) . '</td></tr>
</table>
<p><a href="' . esc_url( $order_url ) . '" style="background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">Visualizza ordine</a></p>
</body></html>';

        wp_mail( $to, $subject, $body, $headers );
    }

    public static function send_admin_intent( $order, $withdrawal ) {
        $to      = apply_filters( 'euwb_admin_notification_email', get_option( 'admin_email' ) );
        $subject = sprintf( __( '[%s] Nuova richiesta di recesso – Ordine #%s', 'eu-withdrawal-button' ), get_bloginfo( 'name' ), $order->get_order_number() );
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        $order_url = admin_url( 'post.php?post=' . $order->get_id() . '&action=edit' );
        $ts        = strtotime( $withdrawal->created_at );
        $date      = date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );

        $body = '
<html><body style="font-family:Arial,sans-serif;color:#333;max-width:600px;margin:0 auto;padding:24px;">
<h2>Nuova Richiesta di Recesso</h2>
<p>Un cliente ha avviato una richiesta di recesso. La richiesta è in attesa di conferma da parte dell\'amministratore.</p>
<table style="width:100%;border-collapse:collapse;">
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Ordine</strong></td><td style="padding:8px;border:1px solid #ddd;"><a href="' . esc_url( $order_url ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Cliente</strong></td><td style="padding:8px;border:1px solid #ddd;">' . esc_html( $withdrawal->first_name . ' ' . $withdrawal->last_name ) . '</td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Email</strong></td><td style="padding:8px;border:1px solid #ddd;">' . esc_html( $withdrawal->email ) . '</td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Data richiesta</strong></td><td style="padding:8px;border:1px solid #ddd;">' . esc_html( $date ) . '</td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Motivo</strong></td><td style="padding:8px;border:1px solid #ddd;">' . esc_html( $withdrawal->reason ?: '—' ) . '</td></tr>
  <tr><td style="padding:8px;border:1px solid #ddd;"><strong>Stato</strong></td><td style="padding:8px;border:1px solid #ddd;">In attesa di conferma</td></tr>
</table>
<p><a href="' . esc_url( $order_url ) . '" style="background:#0073aa;color:#fff;padding:10px 20px;text-decoration:none;border-radius:4px;">Conferma o gestisci il recesso</a></p>
</body></html>';

        wp_mail( $to, $subject, $body, $headers );
    }
}

EUWB_Emails::init();
