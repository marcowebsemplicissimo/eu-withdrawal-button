<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class EUWB_Admin {

    public function __construct() {
        add_action( 'admin_menu',            array( $this, 'register_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
        add_filter( 'set-screen-option',     array( $this, 'set_screen_option' ), 10, 3 );
        add_action( 'wp_ajax_euwb_revoke',          array( $this, 'ajax_revoke' ) );
        add_action( 'wp_ajax_euwb_admin_confirm',   array( $this, 'ajax_admin_confirm' ) );

        // Meta box on the WooCommerce edit-order screen (classic + HPOS)
        add_action( 'add_meta_boxes', array( $this, 'add_order_meta_box' ) );
    }

    public function enqueue( $hook ) {
        $is_withdrawal_page = strpos( $hook, 'eu-withdrawal' ) !== false;
        $is_order_page      = in_array( $hook, array( 'post.php', 'post-new.php', 'woocommerce_page_wc-orders' ), true );

        if ( ! $is_withdrawal_page && ! $is_order_page ) return;

        wp_enqueue_style( 'euwb-admin', EUWB_PLUGIN_URL . 'assets/css/euwb-admin.css', array(), EUWB_VERSION );
        wp_enqueue_script( 'euwb-script', EUWB_PLUGIN_URL . 'assets/js/euwb.js', array( 'jquery' ), EUWB_VERSION, true );
        wp_localize_script( 'euwb-script', 'euwbAdminData', array(
            'ajaxUrl'               => admin_url( 'admin-ajax.php' ),
            'nonce'                 => wp_create_nonce( 'euwb_revoke_nonce' ),
            'nonceConfirm'          => wp_create_nonce( 'euwb_admin_confirm_nonce' ),
            'confirmMessage'        => __( 'Eliminare questo record di recesso e aggiungere una nota di revoca all\'ordine?', 'eu-withdrawal-button' ),
            'confirmWithdrawal'     => __( 'Confermare la richiesta di recesso? L\'ordine passerà in stato "In attesa di rimborso".', 'eu-withdrawal-button' ),
            'revokedLabel'          => __( 'Revocato', 'eu-withdrawal-button' ),
            'errorMessage'          => __( 'Errore durante la revoca. Riprova.', 'eu-withdrawal-button' ),
            'errorConfirmMessage'   => __( 'Errore durante la conferma del recesso. Riprova.', 'eu-withdrawal-button' ),
            'confirmedLabel'        => __( 'Recesso confermato. La pagina verrà ricaricata.', 'eu-withdrawal-button' ),
        ) );
    }

    // -----------------------------------------------------------------------
    // Meta box: withdrawal info + confirm button on edit-order page
    // -----------------------------------------------------------------------
    public function add_order_meta_box() {
        $screens = array( 'shop_order', 'woocommerce_page_wc-orders' );
        foreach ( $screens as $screen ) {
            add_meta_box(
                'euwb-withdrawal-meta-box',
                __( 'Recesso EU', 'eu-withdrawal-button' ),
                array( $this, 'render_order_meta_box' ),
                $screen,
                'side',
                'high'
            );
        }
    }

    public function render_order_meta_box( $post_or_order ) {
        $order_id = $post_or_order instanceof WP_Post ? $post_or_order->ID : $post_or_order->get_id();
        $order    = wc_get_order( $order_id );
        if ( ! $order ) return;

        $withdrawal = EUWB_Withdrawal::get_withdrawal( $order_id );

        if ( ! $withdrawal ) {
            echo '<p class="description">' . esc_html__( 'Nessuna richiesta di recesso per questo ordine.', 'eu-withdrawal-button' ) . '</p>';
            return;
        }

        $status_label = $withdrawal->status === 'confirmed'
            ? '<span style="color:#46b450;font-weight:600;">' . esc_html__( 'Confermato', 'eu-withdrawal-button' ) . '</span>'
            : '<span style="color:#f0a500;font-weight:600;">' . esc_html__( 'In attesa', 'eu-withdrawal-button' ) . '</span>';

        echo '<p><strong>' . esc_html__( 'Cliente:', 'eu-withdrawal-button' ) . '</strong> ' . esc_html( $withdrawal->first_name . ' ' . $withdrawal->last_name ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Email:', 'eu-withdrawal-button' ) . '</strong> ' . esc_html( $withdrawal->email ) . '</p>';
        echo '<p><strong>' . esc_html__( 'Richiesto il:', 'eu-withdrawal-button' ) . '</strong> ' . esc_html( date_i18n( get_option( 'date_format' ), strtotime( $withdrawal->created_at ) ) ) . '</p>';
        if ( $withdrawal->reason ) {
            echo '<p><strong>' . esc_html__( 'Motivo:', 'eu-withdrawal-button' ) . '</strong> ' . esc_html( $withdrawal->reason ) . '</p>';
        }
        echo '<p><strong>' . esc_html__( 'Stato:', 'eu-withdrawal-button' ) . '</strong> ' . $status_label . '</p>';

        if ( $withdrawal->status === 'pending' ) {
            echo '<hr style="margin:12px 0;">';
            echo '<button type="button" id="euwb-admin-confirm-btn" class="button button-primary" style="width:100%;" data-order-id="' . esc_attr( $order_id ) . '">';
            echo esc_html__( 'Conferma richiesta di recesso', 'eu-withdrawal-button' );
            echo '</button>';
            echo '<div id="euwb-admin-confirm-result" style="margin-top:8px;"></div>';
        }
    }

    // -----------------------------------------------------------------------
    // AJAX: admin confirms the withdrawal
    // -----------------------------------------------------------------------
    public function ajax_admin_confirm() {
        check_ajax_referer( 'euwb_admin_confirm_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permessi insufficienti.', 'eu-withdrawal-button' ) );
        }

        $order_id = absint( $_POST['order_id'] ?? 0 );
        if ( ! $order_id ) {
            wp_send_json_error( __( 'ID ordine non valido.', 'eu-withdrawal-button' ) );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_send_json_error( __( 'Ordine non trovato.', 'eu-withdrawal-button' ) );
        }

        $result = EUWB_Withdrawal::confirm( $order_id );
        if ( ! $result ) {
            wp_send_json_error( __( 'Impossibile confermare. La richiesta potrebbe non esistere o essere già stata confermata.', 'eu-withdrawal-button' ) );
        }

        wp_send_json_success();
    }

    public function register_menu() {
        $hook = add_menu_page(
            __( 'EU Withdrawal Button', 'eu-withdrawal-button' ),
            __( 'EU Withdrawal', 'eu-withdrawal-button' ),
            'manage_woocommerce',
            'eu-withdrawal-log',
            array( $this, 'render_log_page' ),
            'dashicons-undo',
            57
        );
        add_submenu_page(
            'eu-withdrawal-log',
            __( 'Registro Recessi', 'eu-withdrawal-button' ),
            __( 'Registro Recessi', 'eu-withdrawal-button' ),
            'manage_woocommerce',
            'eu-withdrawal-log',
            array( $this, 'render_log_page' )
        );
        add_submenu_page(
            'eu-withdrawal-log',
            __( 'Impostazioni', 'eu-withdrawal-button' ),
            __( 'Impostazioni', 'eu-withdrawal-button' ),
            'manage_options',
            'eu-withdrawal-settings',
            array( $this, 'render_settings_page' )
        );

        add_action( "load-$hook", array( $this, 'add_screen_options' ) );
    }

    public function add_screen_options() {
        add_screen_option( 'per_page', array(
            'label'   => __( 'Recessi per pagina', 'eu-withdrawal-button' ),
            'default' => 20,
            'option'  => 'euwb_withdrawals_per_page',
        ) );
    }

    public function set_screen_option( $status, $option, $value ) {
        if ( 'euwb_withdrawals_per_page' === $option ) return absint( $value );
        return $status;
    }

    // -----------------------------------------------------------------------
    // Log page
    // -----------------------------------------------------------------------
    public function render_log_page() {
        $per_page    = (int) get_user_meta( get_current_user_id(), 'euwb_withdrawals_per_page', true ) ?: 20;
        $current_page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $status_filter = sanitize_text_field( $_GET['status'] ?? '' );
        $offset      = ( $current_page - 1 ) * $per_page;

        $items = EUWB_Withdrawal::get_all( array( 'limit' => $per_page, 'offset' => $offset, 'status' => $status_filter ) );
        $total = EUWB_Withdrawal::count( $status_filter );
        $pages = ceil( $total / $per_page );
        ?>
        <div class="wrap euwb-admin-wrap">
            <h1><?php esc_html_e( 'Registro Recessi EU', 'eu-withdrawal-button' ); ?>
                <span class="euwb-badge"><?php echo esc_html( EUWB_Withdrawal::count( 'pending' ) ); ?> <?php esc_html_e( 'In attesa', 'eu-withdrawal-button' ); ?></span>
            </h1>

            <ul class="subsubsub">
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=eu-withdrawal-log' ) ); ?>" <?php echo ! $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Tutti', 'eu-withdrawal-button' ); ?> <span class="count">(<?php echo EUWB_Withdrawal::count(); ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=eu-withdrawal-log&status=pending' ) ); ?>" <?php echo $status_filter === 'pending' ? 'class="current"' : ''; ?>><?php esc_html_e( 'In attesa', 'eu-withdrawal-button' ); ?> <span class="count">(<?php echo EUWB_Withdrawal::count( 'pending' ); ?>)</span></a> |</li>
                <li><a href="<?php echo esc_url( admin_url( 'admin.php?page=eu-withdrawal-log&status=confirmed' ) ); ?>" <?php echo $status_filter === 'confirmed' ? 'class="current"' : ''; ?>><?php esc_html_e( 'Confermati', 'eu-withdrawal-button' ); ?> <span class="count">(<?php echo EUWB_Withdrawal::count( 'confirmed' ); ?>)</span></a></li>
            </ul>

            <table class="wp-list-table widefat striped euwb-table">
                <thead>
                    <tr>
                        <th><?php esc_html_e( 'ID', 'eu-withdrawal-button' ); ?></th>
                        <th><?php esc_html_e( 'Ordine', 'eu-withdrawal-button' ); ?></th>
                        <th><?php esc_html_e( 'Cliente', 'eu-withdrawal-button' ); ?></th>
                        <th><?php esc_html_e( 'Email', 'eu-withdrawal-button' ); ?></th>
                        <th><?php esc_html_e( 'Motivo', 'eu-withdrawal-button' ); ?></th>
                        <th><?php esc_html_e( 'Stato recesso', 'eu-withdrawal-button' ); ?></th>
                        <th><?php esc_html_e( 'Stato ordine', 'eu-withdrawal-button' ); ?></th>
                        <th><?php esc_html_e( 'Data richiesta', 'eu-withdrawal-button' ); ?></th>
                        <th><?php esc_html_e( 'Data conferma', 'eu-withdrawal-button' ); ?></th>
                        <th style="width:190px"><?php esc_html_e( 'Azioni', 'eu-withdrawal-button' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $all_statuses = wc_get_order_statuses();
                if ( $items ) : foreach ( $items as $row ) :
                    $order_obj = empty( $row->order_deleted ) ? wc_get_order( $row->order_id ) : null;
                ?>
                    <tr>
                        <td><?php echo absint( $row->id ); ?></td>
                        <td>
                            <?php if ( ! empty( $row->order_deleted ) ) : ?>
                                <span class="euwb-order-deleted" title="<?php esc_attr_e( 'Ordine eliminato definitivamente', 'eu-withdrawal-button' ); ?>">#<?php echo absint( $row->order_id ); ?> <em>(<?php esc_html_e( 'eliminato', 'eu-withdrawal-button' ); ?>)</em></span>
                            <?php else : ?>
                                <a target="_blank" href="<?php echo esc_url( admin_url( 'post.php?post=' . $row->order_id . '&action=edit' ) ); ?>">#<?php echo absint( $row->order_id ); ?></a>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row->first_name . ' ' . $row->last_name ); ?></td>
                        <td><?php echo esc_html( $row->email ); ?></td>
                        <td class="euwb-reason-td"><?php echo esc_html( $row->reason ?: '—' ); ?></td>
                        <td><span class="euwb-status euwb-status--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status === 'confirmed' ? 'Confermato' : 'In attesa' ); ?></span></td>
                        <td><?php
                            if ( ! empty( $row->order_deleted ) ) {
                                echo '<span class="euwb-order-status euwb-order-status--deleted">' . esc_html__( 'Eliminato', 'eu-withdrawal-button' ) . '</span>';
                            } elseif ( $order_obj ) {
                                $slug         = $order_obj->get_status();
                                $order_status = $all_statuses[ 'wc-' . $slug ] ?? $slug;
                                echo '<span class="euwb-order-status euwb-order-status--' . esc_attr( $slug ) . '">' . esc_html( $order_status ) . '</span>';
                            } else {
                                echo '<span class="euwb-order-status">—</span>';
                            }
                        ?></td>
                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->created_at ) ) ); ?></td>
                        <td><?php echo $row->confirmed_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->confirmed_at ) ) ) : '—'; ?></td>
                        <td class="euwb-actions">
                            <?php if ( empty( $row->order_deleted ) ) : ?>
                                <?php if ( $row->status === 'pending' ) : ?>
                                <button type="button"
                                    class="button button-primary euwb-confirm-btn"
                                    data-order-id="<?php echo absint( $row->order_id ); ?>">
                                    <?php esc_html_e( 'Conferma', 'eu-withdrawal-button' ); ?>
                                </button>
                                <?php endif; ?>
                                <?php if ( ! $order_obj || $order_obj->get_status() !== 'refunded' ) : ?>
                                <button type="button"
                                    class="button euwb-revoke-btn"
                                    data-id="<?php echo absint( $row->id ); ?>"
                                    data-order-id="<?php echo absint( $row->order_id ); ?>">
                                    <?php esc_html_e( 'Revoca', 'eu-withdrawal-button' ); ?>
                                </button>
                                <?php endif; ?>
                            <?php else : ?>
                                <span class="description">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else : ?>
                    <tr><td colspan="10"><?php esc_html_e( 'Nessun recesso trovato.', 'eu-withdrawal-button' ); ?></td></tr>
                <?php endif; ?>
                </tbody>
            </table>

            <?php if ( $pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%' ),
                        'format'    => '',
                        'current'   => $current_page,
                        'total'     => $pages,
                        'prev_text' => '&laquo;',
                        'next_text' => '&raquo;',
                    ) );
                    ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
        <?php
    }

    // -----------------------------------------------------------------------
    // AJAX: revoke a withdrawal record
    // -----------------------------------------------------------------------
    public function ajax_revoke() {
        check_ajax_referer( 'euwb_revoke_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permessi insufficienti.', 'eu-withdrawal-button' ) );
        }

        $id = absint( $_POST['id'] ?? 0 );
        if ( ! $id ) {
            wp_send_json_error( __( 'ID non valido.', 'eu-withdrawal-button' ) );
        }

        $row = EUWB_Withdrawal::delete( $id );
        if ( ! $row ) {
            wp_send_json_error( __( 'Record non trovato o già eliminato.', 'eu-withdrawal-button' ) );
        }

        $order = wc_get_order( $row->order_id );
        if ( $order ) {
            $order->add_order_note(
                sprintf(
                    __( 'Recesso (ID: %d) revocato manualmente dall\'amministratore. Cliente: %s %s <%s>. Richiesta originale del: %s.', 'eu-withdrawal-button' ),
                    $id,
                    $row->first_name,
                    $row->last_name,
                    $row->email,
                    date_i18n( get_option( 'date_format' ), strtotime( $row->created_at ) )
                )
            );
        }else{
            wp_send_json_error( __( 'Ordine non trovato.', 'eu-withdrawal-button' ) );
        }

        wp_send_json_success();
    }

    // -----------------------------------------------------------------------
    // Settings page
    // -----------------------------------------------------------------------
    public function render_settings_page() {
        $default_intro        = __( 'Hai il diritto di recedere dal presente contratto entro %1$d giorni senza fornire alcuna motivazione. Il periodo di recesso scade tra %2$d giorni.', 'eu-withdrawal-button' );
        $default_btn_label    = __( 'Recedi dal contratto qui', 'eu-withdrawal-button' );
        $default_form_instr   = __( 'Per esercitare il diritto di recesso, compilare il modulo sottostante e fare clic sul pulsante.', 'eu-withdrawal-button' );
        $default_intent_subj  = __( 'Richiesta di recesso ricevuta – Ordine #{order_number}', 'eu-withdrawal-button' );
        $default_intent_body  = __( "Gentile {customer_name},\n\nabbiamo ricevuto la tua richiesta di recesso per l'ordine #{order_number} del {order_date}.\n\nLa richiesta è attualmente in fase di elaborazione. Riceverai un'email di conferma non appena sarà processata.\n\nAi sensi dell'Art. 11a della Direttiva UE 2023/2673.", 'eu-withdrawal-button' );
        $default_confirm_subj = __( 'Conferma di recesso – Ordine #{order_number}', 'eu-withdrawal-button' );
        $default_confirm_body = __( "Gentile {customer_name},\n\nil tuo recesso per l'ordine #{order_number} del {order_date} è stato confermato.\n\nIl rimborso sarà elaborato nei prossimi 14 giorni lavorativi con lo stesso metodo di pagamento utilizzato all'acquisto.\n\nAi sensi dell'Art. 11a della Direttiva UE 2023/2673.", 'eu-withdrawal-button' );

        $saved = false;
        if ( isset( $_POST['euwb_save_settings'] ) && check_admin_referer( 'euwb_settings' ) ) {
            update_option( 'euwb_flow_mode', in_array( $_POST['euwb_flow_mode'] ?? '', array( 'standard', 'direct' ), true ) ? $_POST['euwb_flow_mode'] : 'standard' );
            update_option( 'euwb_withdrawal_window', absint( $_POST['euwb_withdrawal_window'] ?? 14 ) );
            update_option( 'euwb_admin_email', sanitize_email( $_POST['euwb_admin_email'] ?? get_option( 'admin_email' ) ) );
            update_option( 'euwb_intro_text', wp_kses_post( $_POST['euwb_intro_text'] ?? $default_intro ) );
            update_option( 'euwb_btn_label', sanitize_text_field( $_POST['euwb_btn_label'] ?? $default_btn_label ) );
            update_option( 'euwb_form_instructions', sanitize_text_field( $_POST['euwb_form_instructions'] ?? $default_form_instr ) );
            update_option( 'euwb_intent_email_subject',       sanitize_text_field( $_POST['euwb_intent_email_subject'] ?? $default_intent_subj ) );
            update_option( 'euwb_intent_email_body',          wp_kses_post( $_POST['euwb_intent_email_body'] ?? $default_intent_body ) );
            update_option( 'euwb_confirmation_email_subject', sanitize_text_field( $_POST['euwb_confirmation_email_subject'] ?? $default_confirm_subj ) );
            update_option( 'euwb_confirmation_email_body',    wp_kses_post( $_POST['euwb_confirmation_email_body'] ?? $default_confirm_body ) );
            $saved = true;
        }
        $window     = get_option( 'euwb_withdrawal_window', 14 );
        $email      = get_option( 'euwb_admin_email', get_option( 'admin_email' ) );
        $intro_text = get_option( 'euwb_intro_text', $default_intro );
        $btn_label  = get_option( 'euwb_btn_label', $default_btn_label );
        $form_instr = get_option( 'euwb_form_instructions', $default_form_instr );

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'generale';
        ?>
        <div class="wrap euwb-settings-wrap">
            <h1><?php esc_html_e( 'Impostazioni EU Withdrawal Button', 'eu-withdrawal-button' ); ?></h1>

            <?php if ( $saved ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Impostazioni salvate.', 'eu-withdrawal-button' ); ?></p></div>
            <?php endif; ?>

            <nav class="nav-tab-wrapper euwb-tab-wrapper" id="euwb-settings-tabs">
                <a href="#euwb-tab-generale"  class="nav-tab" data-tab="generale"><?php esc_html_e( 'Generale', 'eu-withdrawal-button' ); ?></a>
                <a href="#euwb-tab-testi"     class="nav-tab" data-tab="testi"><?php esc_html_e( 'Testi frontend', 'eu-withdrawal-button' ); ?></a>
                <a href="#euwb-tab-email"     class="nav-tab" data-tab="email"><?php esc_html_e( 'Email', 'eu-withdrawal-button' ); ?></a>
            </nav>

            <form method="post" class="euwb-settings-form">
                <?php wp_nonce_field( 'euwb_settings' ); ?>
                <input type="hidden" name="euwb_active_tab" id="euwb_active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

                <!-- TAB: Generale -->
                <div id="euwb-tab-generale" class="euwb-tab-panel">
                    <table class="form-table">
                        <tr>
                            <th><label for="euwb_flow_mode"><?php esc_html_e( 'Modalità flusso recesso', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <select id="euwb_flow_mode" name="euwb_flow_mode">
                                    <option value="standard" <?php selected( get_option( 'euwb_flow_mode', 'standard' ), 'standard' ); ?>>
                                        <?php esc_html_e( 'Flusso standard (richiede conferma admin)', 'eu-withdrawal-button' ); ?>
                                    </option>
                                    <option value="direct" <?php selected( get_option( 'euwb_flow_mode', 'standard' ), 'direct' ); ?>>
                                        <?php esc_html_e( 'Flusso diretto (auto-conferma immediata)', 'eu-withdrawal-button' ); ?>
                                    </option>
                                </select>
                                <p class="description">
                                    <strong><?php esc_html_e( 'Flusso standard:', 'eu-withdrawal-button' ); ?></strong>
                                    <?php esc_html_e( 'il cliente avvia la richiesta, l\'amministratore la conferma manualmente. Il cliente riceve un\'email di avviso (intent) e poi una di conferma.', 'eu-withdrawal-button' ); ?><br>
                                    <strong><?php esc_html_e( 'Flusso diretto:', 'eu-withdrawal-button' ); ?></strong>
                                    <?php esc_html_e( 'al click del cliente il recesso è immediatamente confermato. L\'ordine passa in "In attesa di rimborso" e il cliente riceve subito l\'email di conferma.', 'eu-withdrawal-button' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="euwb_withdrawal_window"><?php esc_html_e( 'Finestra di recesso (giorni)', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="number" id="euwb_withdrawal_window" name="euwb_withdrawal_window" value="<?php echo esc_attr( $window ); ?>" min="1" max="30" class="small-text">
                                <p class="description"><?php esc_html_e( 'La direttiva UE 2023/2673 prevede 14 giorni come minimo.', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="euwb_admin_email"><?php esc_html_e( 'Email notifiche admin', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="email" id="euwb_admin_email" name="euwb_admin_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e( 'Riceverà una notifica ad ogni recesso confermato.', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div><!-- /euwb-tab-generale -->

                <!-- TAB: Testi frontend -->
                <div id="euwb-tab-testi" class="euwb-tab-panel">
                    <table class="form-table">
                        <tr>
                            <th><label for="euwb_intro_text"><?php esc_html_e( 'Testo introduttivo recesso', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <textarea id="euwb_intro_text" name="euwb_intro_text" rows="4" class="large-text"><?php echo esc_textarea( $intro_text ); ?></textarea>
                                <p class="description"><?php echo wp_kses_post( sprintf( __( 'Usa <code>%%1$d</code> per la finestra in giorni (es. 14) e <code>%%2$d</code> per i giorni rimasti alla scadenza.', 'eu-withdrawal-button' ) ) ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="euwb_form_instructions"><?php esc_html_e( 'Istruzioni modulo recesso', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="text" id="euwb_form_instructions" name="euwb_form_instructions" value="<?php echo esc_attr( $form_instr ); ?>" class="large-text">
                                <p class="description"><?php esc_html_e( 'Testo visualizzato sopra il modulo di recesso.', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="euwb_btn_label"><?php esc_html_e( 'Label pulsante di recesso', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="text" id="euwb_btn_label" name="euwb_btn_label" value="<?php echo esc_attr( $btn_label ); ?>" class="regular-text">
                                <p class="description"><?php esc_html_e( 'Testo del pulsante che il cliente clicca per avviare la procedura di recesso.', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div><!-- /euwb-tab-testi -->

                <!-- TAB: Email -->
                <div id="euwb-tab-email" class="euwb-tab-panel">
                    <div class="euwb-email-block">
                        <h2 class="euwb-email-title"><?php esc_html_e( 'Email al cliente – Richiesta ricevuta (flusso standard)', 'eu-withdrawal-button' ); ?></h2>
                        <p class="description"><?php echo wp_kses_post( __( 'Segnaposto disponibili: <code>{order_number}</code>, <code>{order_date}</code>, <code>{customer_name}</code>, <code>{withdrawal_date}</code>', 'eu-withdrawal-button' ) ); ?></p>
                        <table class="form-table">
                            <tr>
                                <th><label for="euwb_intent_email_subject"><?php esc_html_e( 'Oggetto', 'eu-withdrawal-button' ); ?></label></th>
                                <td><input type="text" id="euwb_intent_email_subject" name="euwb_intent_email_subject" value="<?php echo esc_attr( get_option( 'euwb_intent_email_subject', $default_intent_subj ) ); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th><label for="euwb_intent_email_body"><?php esc_html_e( 'Corpo', 'eu-withdrawal-button' ); ?></label></th>
                                <td><textarea id="euwb_intent_email_body" name="euwb_intent_email_body" rows="7" class="large-text"><?php echo esc_textarea( get_option( 'euwb_intent_email_body', $default_intent_body ) ); ?></textarea></td>
                            </tr>
                        </table>
                    </div>

                    <div class="euwb-email-block">
                        <h2 class="euwb-email-title"><?php esc_html_e( 'Email al cliente – Recesso confermato', 'eu-withdrawal-button' ); ?></h2>
                        <p class="description"><?php echo wp_kses_post( __( 'Segnaposto disponibili: <code>{order_number}</code>, <code>{order_date}</code>, <code>{customer_name}</code>, <code>{withdrawal_date}</code>', 'eu-withdrawal-button' ) ); ?></p>
                        <table class="form-table">
                            <tr>
                                <th><label for="euwb_confirmation_email_subject"><?php esc_html_e( 'Oggetto', 'eu-withdrawal-button' ); ?></label></th>
                                <td><input type="text" id="euwb_confirmation_email_subject" name="euwb_confirmation_email_subject" value="<?php echo esc_attr( get_option( 'euwb_confirmation_email_subject', $default_confirm_subj ) ); ?>" class="large-text"></td>
                            </tr>
                            <tr>
                                <th><label for="euwb_confirmation_email_body"><?php esc_html_e( 'Corpo', 'eu-withdrawal-button' ); ?></label></th>
                                <td><textarea id="euwb_confirmation_email_body" name="euwb_confirmation_email_body" rows="7" class="large-text"><?php echo esc_textarea( get_option( 'euwb_confirmation_email_body', $default_confirm_body ) ); ?></textarea></td>
                            </tr>
                        </table>
                    </div>
                </div><!-- /euwb-tab-email -->

                <?php submit_button( __( 'Salva impostazioni', 'eu-withdrawal-button' ), 'primary', 'euwb_save_settings' ); ?>
            </form>
        </div>
        <?php
    }
}
