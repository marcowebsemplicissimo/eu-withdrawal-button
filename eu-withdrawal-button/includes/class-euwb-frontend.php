<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class EUWB_Frontend {

    public function __construct() {
        add_action( 'wp_enqueue_scripts',                    array( $this, 'enqueue' ) );
        add_action( 'woocommerce_view_order',                array( $this, 'render_withdrawal_section' ), 20 );
        add_action( 'wp_ajax_euwb_initiate',                 array( $this, 'ajax_initiate' ) );
        add_action( 'wp_ajax_euwb_confirm',                  array( $this, 'ajax_confirm' ) );
        // Guest / not-logged-in users can also withdraw
        add_action( 'wp_ajax_nopriv_euwb_initiate',          array( $this, 'ajax_initiate' ) );
        add_action( 'wp_ajax_nopriv_euwb_confirm',           array( $this, 'ajax_confirm' ) );
    }

    public function enqueue() {
        if ( ! is_wc_endpoint_url( 'view-order' ) ) return;

        wp_enqueue_style(
            'euwb-style',
            EUWB_PLUGIN_URL . 'assets/css/euwb.css',
            array(),
            EUWB_VERSION
        );
        wp_enqueue_script(
            'euwb-script',
            EUWB_PLUGIN_URL . 'assets/js/euwb.js',
            array( 'jquery' ),
            EUWB_VERSION,
            true
        );
        $order      = wc_get_order( absint( get_query_var( 'view-order' ) ) );
        $order_key  = ( $order instanceof WC_Order ) ? $order->get_order_key() : '';

        wp_localize_script( 'euwb-script', 'euwbData', array(
            'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'euwb_nonce' ),
            'orderKey' => $order_key,
            'i18n'     => array(
                'confirm_prompt' => __( 'Confermare il recesso dal contratto? Questa azione non può essere annullata.', 'eu-withdrawal-button' ),
                'processing'     => __( 'Elaborazione in corso…', 'eu-withdrawal-button' ),
                'error_generic'  => __( 'Si è verificato un errore. Riprova o contatta il supporto.', 'eu-withdrawal-button' ),
                'btn_initiate'   => get_option( 'euwb_btn_label', __( 'Recedi dal contratto qui', 'eu-withdrawal-button' ) ),
            ),
        ) );
    }

    /**
     * Render the withdrawal box on the "View Order" page.
     */
    public function render_withdrawal_section( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        // Only show for the order owner
        if ( get_current_user_id() && $order->get_customer_id() !== get_current_user_id() ) return;

        $already_withdrawn = EUWB_Withdrawal::order_has_withdrawal( $order_id );
        $within_window     = EUWB_Withdrawal::is_within_window( $order );

        echo '<section class="euwb-section" id="euwb-withdrawal-section" aria-label="' . esc_attr__( 'Recesso dal contratto', 'eu-withdrawal-button' ) . '">';
        echo '<h2 class="euwb-title">' . esc_html__( 'Diritto di recesso (UE 2023/2673)', 'eu-withdrawal-button' ) . '</h2>';

        if ( $already_withdrawn ) {
            $withdrawal    = EUWB_Withdrawal::get_withdrawal( $order_id );
            $date          = $withdrawal ? date_i18n( get_option( 'date_format' ), strtotime( $withdrawal->confirmed_at ?: $withdrawal->created_at ) ) : '';
            $order_status  = $order->get_status();

            if ( in_array( $order_status, array( 'refunded' ), true ) ) {
                echo '<div class="euwb-notice euwb-notice--success">';
                echo '<p>' . sprintf(
                    esc_html__( 'Hai esercitato il recesso per questo ordine il %s. Il rimborso è stato effettuato.', 'eu-withdrawal-button' ),
                    esc_html( $date )
                ) . '</p>';
                echo '</div>';
            } elseif ( in_array( $order_status, array( 'cancelled' ), true ) ) {
                echo '<div class="euwb-notice euwb-notice--success">';
                echo '<p>' . sprintf(
                    esc_html__( 'Hai esercitato il recesso per questo ordine il %s. L\'ordine è stato annullato. Il rimborso sarà elaborato nei prossimi 14 giorni lavorativi.', 'eu-withdrawal-button' ),
                    esc_html( $date )
                ) . '</p>';
                echo '</div>';
            } elseif ( in_array( $order_status, array( 'pending-refund' ), true ) ) {
                echo '<div class="euwb-notice euwb-notice--success">';
                echo '<p>' . sprintf(
                    esc_html__( 'Hai esercitato il recesso per questo ordine il %s. La richiesta è stata confermata dall\'amministratore e il rimborso sarà elaborato nei prossimi 14 giorni lavorativi.', 'eu-withdrawal-button' ),
                    esc_html( $date )
                ) . '</p>';
                echo '</div>';
            } elseif ( in_array( $order_status, array( 'pending-withdraw' ), true ) ) {
                echo '<div class="euwb-notice euwb-notice--warning">';
                echo '<p>' . sprintf(
                    esc_html__( 'Hai richiesto il recesso per questo ordine il %s. La richiesta è in attesa di conferma da parte dell\'amministratore.', 'eu-withdrawal-button' ),
                    esc_html( $date )
                ) . '</p>';
                echo '</div>';
            } else {
                echo '<div class="euwb-notice euwb-notice--success">';
                echo '<p>' . sprintf(
                    esc_html__( 'Hai già esercitato il recesso per questo ordine il %s. Il rimborso sarà elaborato nei prossimi 14 giorni lavorativi.', 'eu-withdrawal-button' ),
                    esc_html( $date )
                ) . '</p>';
                echo '</div>';
            }

        } elseif ( ! $within_window ) {
            echo '<div class="euwb-notice euwb-notice--expired">';
            echo '<p>' . esc_html__( 'Il periodo di recesso di 14 giorni per questo ordine è scaduto.', 'eu-withdrawal-button' ) . '</p>';
            echo '</div>';

        } else {
            // Calculate days left
            $completed_date = $order->get_date_completed() ?: $order->get_date_created();
            $deadline       = clone $completed_date;
            $deadline->modify( '+' . EUWB_WITHDRAWAL_WINDOW_DAYS . ' days' );
            $days_left      = ceil( ( $deadline->getTimestamp() - current_time( 'timestamp', true ) ) / DAY_IN_SECONDS );

            $intro_default = __( 'Hai il diritto di recedere dal presente contratto entro %1$d giorni senza fornire alcuna motivazione. Il periodo di recesso scade tra %2$d giorni.', 'eu-withdrawal-button' );
            $intro_text    = get_option( 'euwb_intro_text', $intro_default );
            echo '<p class="euwb-intro">' . wp_kses_post( sprintf(
                $intro_text,
                (int) get_option( 'euwb_withdrawal_window', 14 ),
                max( 1, (int) $days_left )
            ) ) . '</p>';

            // Step 1 form
            echo '<div id="euwb-step-1">';
            echo '<p>' . esc_html( get_option( 'euwb_form_instructions', __( 'Per esercitare il diritto di recesso, compilare il modulo sottostante e fare clic sul pulsante.', 'eu-withdrawal-button' ) ) ) . '</p>';
            echo '<div class="euwb-form">';
            echo '<div class="euwb-row">';
            echo '<div class="euwb-field"><label for="euwb_first_name">' . esc_html__( 'Nome *', 'eu-withdrawal-button' ) . '</label>';
            echo '<input type="text" id="euwb_first_name" name="first_name" value="' . esc_attr( $order->get_billing_first_name() ) . '" required></div>';
            echo '<div class="euwb-field"><label for="euwb_last_name">' . esc_html__( 'Cognome *', 'eu-withdrawal-button' ) . '</label>';
            echo '<input type="text" id="euwb_last_name" name="last_name" value="' . esc_attr( $order->get_billing_last_name() ) . '" required></div>';
            echo '</div>';
            echo '<div class="euwb-field"><label for="euwb_email">' . esc_html__( 'Indirizzo e-mail *', 'eu-withdrawal-button' ) . '</label>';
            echo '<input type="email" id="euwb_email" name="email" value="' . esc_attr( $order->get_billing_email() ) . '" required></div>';
            echo '<div class="euwb-field"><label for="euwb_reason">' . esc_html__( 'Motivo del recesso (facoltativo)', 'eu-withdrawal-button' ) . '</label>';
            echo '<textarea id="euwb_reason" name="reason" rows="3" placeholder="' . esc_attr__( 'Puoi lasciare vuoto questo campo.', 'eu-withdrawal-button' ) . '"></textarea></div>';
            echo '</div>'; // .euwb-form

            echo '<button type="button" id="euwb-btn-initiate" class="euwb-btn euwb-btn--primary" data-order-id="' . esc_attr( $order_id ) . '">';
            echo esc_html( get_option( 'euwb_btn_label', __( 'Recedi dal contratto qui', 'eu-withdrawal-button' ) ) );
            echo '</button>';
            echo '<p class="euwb-legal">' . esc_html__( 'Cliccando sopra avvierai la procedura di recesso. Nel passaggio successivo ti verrà chiesta conferma.', 'eu-withdrawal-button' ) . '</p>';
            echo '</div>'; // #euwb-step-1

            // Step 2 confirmation (hidden initially)
            echo '<div id="euwb-step-2" style="display:none;">';
            echo '<div class="euwb-notice euwb-notice--warning">';
            echo '<p><strong>' . esc_html__( 'Sei sicuro di voler recedere dal contratto?', 'eu-withdrawal-button' ) . '</strong></p>';
            echo '<p>' . esc_html__( 'Cliccando "Conferma recesso qui" invierai la dichiarazione di recesso. Riceverai un\'email di conferma.', 'eu-withdrawal-button' ) . '</p>';
            echo '</div>';
            echo '<button type="button" id="euwb-btn-confirm" class="euwb-btn euwb-btn--danger" data-order-id="' . esc_attr( $order_id ) . '">';
            echo esc_html__( 'Conferma recesso qui', 'eu-withdrawal-button' );
            echo '</button>';
            echo ' <button type="button" id="euwb-btn-cancel" class="euwb-btn euwb-btn--secondary">';
            echo esc_html__( 'Annulla', 'eu-withdrawal-button' );
            echo '</button>';
            echo '</div>'; // #euwb-step-2

            // Result message placeholder
            echo '<div id="euwb-result" style="display:none;" role="alert" aria-live="polite"></div>';
        }

        echo '</section>';
    }

    // -----------------------------------------------------------------------
    // AJAX: step 1 – validate only, no DB write
    // -----------------------------------------------------------------------
    public function ajax_initiate() {
        check_ajax_referer( 'euwb_nonce', 'nonce' );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) wp_send_json_error( __( 'Ordine non trovato.', 'eu-withdrawal-button' ) );

        $current_user_id = get_current_user_id();
        if ( $current_user_id && (int) $order->get_customer_id() !== $current_user_id ) {
            wp_send_json_error( __( 'Non autorizzato.', 'eu-withdrawal-button' ) );
        }
        if ( ! $current_user_id ) {
            $order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
            if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
                wp_send_json_error( __( 'Non autorizzato.', 'eu-withdrawal-button' ) );
            }
        }

        if ( ! EUWB_Withdrawal::is_within_window( $order ) ) wp_send_json_error( __( 'Il periodo di recesso è scaduto.', 'eu-withdrawal-button' ) );
        if ( EUWB_Withdrawal::order_has_withdrawal( $order_id ) ) wp_send_json_error( __( 'Hai già richiesto il recesso per questo ordine.', 'eu-withdrawal-button' ) );

        $first_name = sanitize_text_field( $_POST['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $_POST['last_name'] ?? '' );
        $email      = sanitize_email( $_POST['email'] ?? '' );

        if ( empty( $first_name ) || empty( $last_name ) || empty( $email ) ) {
            wp_send_json_error( __( 'Compila tutti i campi obbligatori.', 'eu-withdrawal-button' ) );
        }

        // Validation passed — no DB write yet. The confirm step will create the record.
        wp_send_json_success();
    }

    // -----------------------------------------------------------------------
    // AJAX: step 2 – create the pending withdrawal record (admin confirms later)
    // -----------------------------------------------------------------------
    public function ajax_confirm() {
        check_ajax_referer( 'euwb_nonce', 'nonce' );

        $order_id = absint( $_POST['order_id'] ?? 0 );
        $order    = wc_get_order( $order_id );

        if ( ! $order ) wp_send_json_error( __( 'Ordine non trovato.', 'eu-withdrawal-button' ) );

        $current_user_id = get_current_user_id();
        if ( $current_user_id && (int) $order->get_customer_id() !== $current_user_id ) {
            wp_send_json_error( __( 'Non autorizzato.', 'eu-withdrawal-button' ) );
        }
        if ( ! $current_user_id ) {
            $order_key = sanitize_text_field( wp_unslash( $_POST['order_key'] ?? '' ) );
            if ( ! hash_equals( $order->get_order_key(), $order_key ) ) {
                wp_send_json_error( __( 'Non autorizzato.', 'eu-withdrawal-button' ) );
            }
        }

        if ( ! EUWB_Withdrawal::is_within_window( $order ) ) wp_send_json_error( __( 'Il periodo di recesso è scaduto.', 'eu-withdrawal-button' ) );
        if ( EUWB_Withdrawal::order_has_withdrawal( $order_id ) ) wp_send_json_error( __( 'Hai già richiesto il recesso per questo ordine.', 'eu-withdrawal-button' ) );

        $data = array(
            'first_name' => sanitize_text_field( $_POST['first_name'] ?? '' ),
            'last_name'  => sanitize_text_field( $_POST['last_name'] ?? '' ),
            'email'      => sanitize_email( $_POST['email'] ?? '' ),
            'reason'     => sanitize_textarea_field( $_POST['reason'] ?? '' ),
        );

        if ( empty( $data['first_name'] ) || empty( $data['last_name'] ) || empty( $data['email'] ) ) {
            wp_send_json_error( __( 'Compila tutti i campi obbligatori.', 'eu-withdrawal-button' ) );
        }

        $flow_mode = get_option( 'euwb_flow_mode', 'standard' );

        if ( $flow_mode === 'direct' ) {
            $withdrawal_id  = EUWB_Withdrawal::create_and_confirm( $order_id, $data );
            $success_message = __( 'Recesso confermato con successo. Riceverai un\'email di conferma. Il rimborso sarà elaborato nei prossimi giorni lavorativi.', 'eu-withdrawal-button' );
        } else {
            $withdrawal_id  = EUWB_Withdrawal::create( $order_id, $data );
            $success_message = __( 'Richiesta di recesso inviata con successo. Riceverai un\'email di conferma. L\'amministratore elaborerà il rimborso nei prossimi giorni lavorativi.', 'eu-withdrawal-button' );
        }

        if ( ! $withdrawal_id ) wp_send_json_error( __( 'Impossibile registrare la richiesta di recesso. Riprova o contatta il supporto.', 'eu-withdrawal-button' ) );

        wp_send_json_success( array(
            'message' => $success_message,
        ) );
    }
}
