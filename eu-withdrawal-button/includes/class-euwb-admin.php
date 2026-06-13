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
        if ( $is_withdrawal_page ) {
            wp_enqueue_script( 'euwb-table2csv',   EUWB_PLUGIN_URL . 'assets/js/jquery.table2csv.js',   array( 'jquery' ), EUWB_VERSION, true );
            wp_enqueue_script( 'euwb-table2excel', EUWB_PLUGIN_URL . 'assets/js/jquery.table2excel.js', array( 'jquery' ), EUWB_VERSION, true );
        }
        // SelectWoo for taxonomy exclusion selects (settings page only)
        if ( strpos( $hook, 'eu-withdrawal-settings' ) !== false ) {
            wp_enqueue_style( 'woocommerce_admin_styles' );
            wp_enqueue_script( 'selectWoo' );
        }
        $euwb_deps = array( 'jquery' );
        if ( strpos( $hook, 'eu-withdrawal-settings' ) !== false ) {
            $euwb_deps[] = 'selectWoo';
        }
        wp_enqueue_script( 'euwb-script', EUWB_PLUGIN_URL . 'assets/js/euwb.js', $euwb_deps, EUWB_VERSION, true );
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
        $per_page      = (int) get_user_meta( get_current_user_id(), 'euwb_withdrawals_per_page', true ) ?: 20;
        $current_page  = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $status_filter = sanitize_text_field( $_GET['status'] ?? '' );
        $search        = sanitize_text_field( $_GET['s'] ?? '' );
        $orderby       = in_array( $_GET['orderby'] ?? '', array( 'created_at', 'confirmed_at' ), true ) ? sanitize_key( $_GET['orderby'] ) : 'created_at';
        $order         = strtoupper( $_GET['order'] ?? '' ) === 'ASC' ? 'ASC' : 'DESC';
        $offset        = ( $current_page - 1 ) * $per_page;

        $items = EUWB_Withdrawal::get_all( array( 'limit' => $per_page, 'offset' => $offset, 'status' => $status_filter, 'search' => $search, 'orderby' => $orderby, 'order' => $order ) );
        $total = EUWB_Withdrawal::count( $status_filter, $search );
        $pages = ceil( $total / $per_page );

        $base_url = admin_url( 'admin.php?page=eu-withdrawal-log' );
        if ( $status_filter ) $base_url = add_query_arg( 'status', $status_filter, $base_url );
        if ( $search )        $base_url = add_query_arg( 's', $search, $base_url );

        $sort_url = function( $col ) use ( $base_url, $orderby, $order ) {
            $new_order = ( $orderby === $col && $order === 'ASC' ) ? 'DESC' : 'ASC';
            return esc_url( add_query_arg( array( 'orderby' => $col, 'order' => $new_order ), $base_url ) );
        };
        $sort_indicator = function( $col ) use ( $orderby, $order ) {
            if ( $orderby !== $col ) return '';
            return ' <span class="euwb-sort-arrow">' . ( $order === 'ASC' ? '&#9650;' : '&#9660;' ) . '</span>';
        };
        ?>
        <div class="wrap euwb-admin-wrap">
            <h1><?php esc_html_e( 'Registro Recessi EU', 'eu-withdrawal-button' ); ?>
                <span class="euwb-badge"><?php echo esc_html( EUWB_Withdrawal::count( 'pending' ) ); ?> <?php esc_html_e( 'In attesa', 'eu-withdrawal-button' ); ?></span>
            </h1>

            <div class="euwb-toolbar">
                <ul class="subsubsub">
                    <li><a href="<?php echo esc_url( $search ? add_query_arg( 's', $search, admin_url( 'admin.php?page=eu-withdrawal-log' ) ) : admin_url( 'admin.php?page=eu-withdrawal-log' ) ); ?>" <?php echo ! $status_filter ? 'class="current"' : ''; ?>><?php esc_html_e( 'Tutti', 'eu-withdrawal-button' ); ?> <span class="count">(<?php echo EUWB_Withdrawal::count( '', $search ); ?>)</span></a> |</li>
                    <li><a href="<?php echo esc_url( add_query_arg( 'status', 'pending', $search ? add_query_arg( 's', $search, admin_url( 'admin.php?page=eu-withdrawal-log' ) ) : admin_url( 'admin.php?page=eu-withdrawal-log' ) ) ); ?>" <?php echo $status_filter === 'pending' ? 'class="current"' : ''; ?>><?php esc_html_e( 'In attesa', 'eu-withdrawal-button' ); ?> <span class="count">(<?php echo EUWB_Withdrawal::count( 'pending', $search ); ?>)</span></a> |</li>
                    <li><a href="<?php echo esc_url( add_query_arg( 'status', 'confirmed', $search ? add_query_arg( 's', $search, admin_url( 'admin.php?page=eu-withdrawal-log' ) ) : admin_url( 'admin.php?page=eu-withdrawal-log' ) ) ); ?>" <?php echo $status_filter === 'confirmed' ? 'class="current"' : ''; ?>><?php esc_html_e( 'Confermati', 'eu-withdrawal-button' ); ?> <span class="count">(<?php echo EUWB_Withdrawal::count( 'confirmed', $search ); ?>)</span></a></li>
                </ul>
                <div>
                    <form method="get" class="euwb-search-form">
                        <input type="hidden" name="page" value="eu-withdrawal-log">
                        <?php if ( $status_filter ) : ?><input type="hidden" name="status" value="<?php echo esc_attr( $status_filter ); ?>"><?php endif; ?>
                            <p class="search-box">
                                <label class="screen-reader-text" for="euwb-search-input"><?php esc_html_e( 'Cerca recessi', 'eu-withdrawal-button' ); ?></label>
                            <input type="search" id="euwb-search-input" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Nome, email, n° ordine…', 'eu-withdrawal-button' ); ?>">
                            <input type="submit" class="button" value="<?php esc_attr_e( 'Cerca', 'eu-withdrawal-button' ); ?>">
                            <?php if ( $search ) : ?>
                            <a href="<?php echo esc_url( $status_filter ? add_query_arg( 'status', $status_filter, admin_url( 'admin.php?page=eu-withdrawal-log' ) ) : admin_url( 'admin.php?page=eu-withdrawal-log' ) ); ?>" class="button"><?php esc_html_e( '✕ Rimuovi filtro', 'eu-withdrawal-button' ); ?></a>
                            <?php endif; ?>
                        </p>
                    </form>
                    <div class="euwb-export-btns">
                        <button type="button" id="euwb-export-csv" class="button">
                            <span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e( 'Esporta CSV', 'eu-withdrawal-button' ); ?>
                        </button>
                        <button type="button" id="euwb-export-xls" class="button">
                            <span class="dashicons dashicons-media-spreadsheet"></span> <?php esc_html_e( 'Esporta XLS', 'eu-withdrawal-button' ); ?>
                        </button>
                    </div>
                </div>
            </div>

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
                        <th class="sortable"><a href="<?php echo $sort_url( 'created_at' ); ?>"><?php esc_html_e( 'Data richiesta', 'eu-withdrawal-button' ); echo $sort_indicator( 'created_at' ); ?></a></th>
                        <th class="sortable"><a href="<?php echo $sort_url( 'confirmed_at' ); ?>"><?php esc_html_e( 'Data conferma', 'eu-withdrawal-button' ); echo $sort_indicator( 'confirmed_at' ); ?></a></th>
                        <th class="euwb-actions-col" style="width:190px"><?php esc_html_e( 'Azioni', 'eu-withdrawal-button' ); ?></th>
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
                        <td class="euwb-reason-td">
                            <?php if ( ! empty( $row->reason ) ) : ?>
                                <button type="button"
                                    class="button euwb-reason-btn"
                                    data-reason="<?php echo esc_attr( $row->reason ); ?>">
                                    <span class="dashicons dashicons-visibility"></span> <?php esc_html_e( 'Mostra', 'eu-withdrawal-button' ); ?>
                                </button>
                            <?php else : ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td><span class="euwb-status euwb-status--<?php echo esc_attr( $row->status ); ?>"><?php echo esc_html( $row->status === 'confirmed' ? 'Confermato' : 'In attesa' ); ?></span></td>
                        <td><?php
                            if ( ! empty( $row->order_deleted ) ) {
                                echo '<span class="euwb-order-status euwb-order-status--deleted">' . esc_html__( 'Eliminato', 'eu-withdrawal-button' ) . '</span>';
                            } elseif ( $order_obj ) {
                                $slug         = $order_obj->get_status();
                                $order_status = $all_statuses[ 'wc-' . $slug ] ?? $slug;
                                if ( $order_status === 'trash' ){
                                    $order_status = __('Cestinato', 'eu-withdrawal-button');
                                }
                                echo '<span class="euwb-order-status euwb-order-status--' . esc_attr( $slug ) . '">' . esc_html( $order_status ) . '</span>';
                            } else {
                                echo '<span class="euwb-order-status">—</span>';
                            }
                        ?></td>
                        <td><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->created_at ) ) ); ?></td>
                        <td><?php echo $row->confirmed_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $row->confirmed_at ) ) ) : '—'; ?></td>
                        <td class="euwb-actions euwb-actions-col">
                            <?php if ( empty( $row->order_deleted ) ) : ?>
                                <?php if ( $row->status === 'pending' ) : ?>
                                <button type="button"
                                    class="button button-primary euwb-confirm-btn"
                                    data-order-id="<?php echo absint( $row->order_id ); ?>">
                                    <?php esc_html_e( 'Conferma', 'eu-withdrawal-button' ); ?>
                                </button>
                                <?php endif; ?>
                                <?php if ( ! $order_obj || ( $order_obj->get_status() !== 'refunded' && $order_obj->get_status() !== 'trash' ) ) : ?>
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

            <!-- Lightbox motivo recesso -->
            <div id="euwb-reason-modal" class="euwb-modal" role="dialog" aria-modal="true" aria-labelledby="euwb-reason-modal-title" hidden>
                <div class="euwb-modal__backdrop"></div>
                <div class="euwb-modal__box">
                    <div class="euwb-modal__header">
                        <h2 id="euwb-reason-modal-title"><?php esc_html_e( 'Motivo del recesso', 'eu-withdrawal-button' ); ?></h2>
                        <button type="button" class="euwb-modal__close" aria-label="<?php esc_attr_e( 'Chiudi', 'eu-withdrawal-button' ); ?>">
                            <span class="dashicons dashicons-no-alt"></span>
                        </button>
                    </div>
                    <div class="euwb-modal__body">
                        <p id="euwb-reason-modal-text"></p>
                    </div>
                </div>
            </div>

            <?php if ( $pages > 1 ) : ?>
            <div class="tablenav bottom">
                <div class="tablenav-pages">
                    <?php
                    $pagination_base = add_query_arg( array( 'orderby' => $orderby, 'order' => $order ), $base_url );
                    echo paginate_links( array(
                        'base'      => add_query_arg( 'paged', '%#%', $pagination_base ),
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
    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Returns the active brands taxonomy slug, or null if none is available.
     * Supports WooCommerce Brands (product_brand) and Perfect Brands for WooCommerce (pwb-brand).
     */
    public static function get_brands_taxonomy() {
        if ( taxonomy_exists( 'product_brand' ) )  return 'product_brand';
        if ( taxonomy_exists( 'pwb-brand' ) )       return 'pwb-brand';
        return null;
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
        $default_intro        = __( 'Hai il diritto di recedere dal presente contratto entro {withdrawal_window} giorni senza fornire alcuna motivazione. Il periodo di recesso scade tra {days_left} giorni.', 'eu-withdrawal-button' );
        $default_btn_label    = __( 'Recedi dal contratto qui', 'eu-withdrawal-button' );
        $default_form_instr   = __( 'Per esercitare il diritto di recesso, compilare il modulo sottostante e fare clic sul pulsante.', 'eu-withdrawal-button' );
        $default_intent_subj  = __( 'Richiesta di recesso ricevuta – Ordine #{order_number}', 'eu-withdrawal-button' );
        $default_intent_body  = __( "Gentile {customer_name},\n\nabbiamo ricevuto la tua richiesta di recesso per l'ordine #{order_number} del {order_date}.\n\nLa richiesta è attualmente in fase di elaborazione. Riceverai un'email di conferma non appena sarà processata.\n\nAi sensi dell'Art. 11a della Direttiva UE 2023/2673.", 'eu-withdrawal-button' );
        $default_confirm_subj = __( 'Conferma di recesso – Ordine #{order_number}', 'eu-withdrawal-button' );
        $default_confirm_body = __( "Gentile {customer_name},\n\nil tuo recesso per l'ordine #{order_number} del {order_date} è stato confermato.\n\nIl rimborso sarà elaborato nei prossimi {return_window} giorni lavorativi con lo stesso metodo di pagamento utilizzato all'acquisto.\n\nAi sensi dell'Art. 11a della Direttiva UE 2023/2673.", 'eu-withdrawal-button' );
        $default_success_standard = __( "Richiesta di recesso inviata con successo. Riceverai un'email di presa in carico. La tua richiesta sarà valutata dall'amministratore.", 'eu-withdrawal-button' );
        $default_success_direct   = __( "Recesso confermato con successo. Riceverai un'email di conferma. Il rimborso sarà elaborato nei prossimi giorni lavorativi.", 'eu-withdrawal-button' );

        $saved = false;
        if ( isset( $_POST['euwb_save_settings'] ) && check_admin_referer( 'euwb_settings' ) ) {
            update_option( 'euwb_flow_mode', in_array( $_POST['euwb_flow_mode'] ?? '', array( 'standard', 'direct' ), true ) ? $_POST['euwb_flow_mode'] : 'standard' );
            update_option( 'euwb_withdrawal_window', absint( $_POST['euwb_withdrawal_window'] ?? 14 ) );
            update_option( 'euwb_return_window', absint( $_POST['euwb_return_window'] ?? 14 ) );
            update_option( 'euwb_admin_email', sanitize_email( wp_unslash( $_POST['euwb_admin_email'] ?? get_option( 'admin_email' ) ) ) );
            update_option( 'euwb_return_instructions', wp_kses_post( wp_unslash( $_POST['euwb_return_instructions'] ?? '' ) ) );
            update_option( 'euwb_intro_text', wp_kses_post( wp_unslash( $_POST['euwb_intro_text'] ?? $default_intro ) ) );
            update_option( 'euwb_btn_label', sanitize_text_field( wp_unslash( $_POST['euwb_btn_label'] ?? $default_btn_label ) ) );
            update_option( 'euwb_form_instructions', sanitize_text_field( wp_unslash( $_POST['euwb_form_instructions'] ?? $default_form_instr ) ) );
            update_option( 'euwb_success_message_standard', sanitize_text_field( wp_unslash( $_POST['euwb_success_message_standard'] ?? $default_success_standard ) ) );
            update_option( 'euwb_success_message_direct',   sanitize_text_field( wp_unslash( $_POST['euwb_success_message_direct'] ?? $default_success_direct ) ) );
            update_option( 'euwb_intent_email_subject',       sanitize_text_field( wp_unslash( $_POST['euwb_intent_email_subject'] ?? $default_intent_subj ) ) );
            update_option( 'euwb_intent_email_body',          wp_kses_post( wp_unslash( $_POST['euwb_intent_email_body'] ?? $default_intent_body ) ) );
            update_option( 'euwb_confirmation_email_subject', sanitize_text_field( wp_unslash( $_POST['euwb_confirmation_email_subject'] ?? $default_confirm_subj ) ) );
            update_option( 'euwb_confirmation_email_body',    wp_kses_post( wp_unslash( $_POST['euwb_confirmation_email_body'] ?? $default_confirm_body ) ) );

            // Taxonomy exclusions — sanitize as arrays of integer IDs
            $excluded_cats   = array_map( 'absint', (array) ( $_POST['euwb_excluded_categories'] ?? array() ) );
            $excluded_tags   = array_map( 'absint', (array) ( $_POST['euwb_excluded_tags'] ?? array() ) );
            $excluded_brands = array_map( 'absint', (array) ( $_POST['euwb_excluded_brands'] ?? array() ) );
            update_option( 'euwb_excluded_categories', array_filter( $excluded_cats ) );
            update_option( 'euwb_excluded_tags',       array_filter( $excluded_tags ) );
            update_option( 'euwb_excluded_brands',     array_filter( $excluded_brands ) );

            $saved = true;
        }
        $window     = get_option( 'euwb_withdrawal_window', 14 );
        $email      = get_option( 'euwb_admin_email', get_option( 'admin_email' ) );
        $intro_text = get_option( 'euwb_intro_text', $default_intro );
        $btn_label  = get_option( 'euwb_btn_label', $default_btn_label );
        $form_instr       = get_option( 'euwb_form_instructions', $default_form_instr );
        $success_standard = get_option( 'euwb_success_message_standard', $default_success_standard );
        $success_direct   = get_option( 'euwb_success_message_direct', $default_success_direct );

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
                            <th><label for="euwb_return_window"><?php esc_html_e( 'Giorni per la restituzione del bene', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="number" id="euwb_return_window" name="euwb_return_window" value="<?php echo esc_attr( get_option( 'euwb_return_window', 14 ) ); ?>" min="1" max="30" class="small-text">
                                <p class="description"><?php esc_html_e( 'Giorni entro cui il cliente deve rispedire il prodotto dopo la conferma del recesso. Usato nel testo default delle istruzioni di restituzione.', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="euwb_admin_email"><?php esc_html_e( 'Email notifiche admin', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="email" id="euwb_admin_email" name="euwb_admin_email" value="<?php echo esc_attr( $email ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
                                <p class="description"><?php esc_html_e( 'Riceverà una notifica ad ogni recesso confermato.', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <h2><?php esc_html_e( 'Esclusioni per tassonomia prodotto', 'eu-withdrawal-button' ); ?></h2>
                    <p class="description"><?php esc_html_e( 'Se un prodotto nell\'ordine appartiene a una delle tassonomie selezionate, il box di recesso non sarà mostrato al cliente.', 'eu-withdrawal-button' ); ?></p>
                    <table class="form-table">
                        <?php
                        $exclusion_fields = array(
                            array(
                                'option'   => 'euwb_excluded_categories',
                                'taxonomy' => 'product_cat',
                                'id'       => 'euwb_excluded_categories',
                                'label'    => __( 'Categorie escluse', 'eu-withdrawal-button' ),
                                'desc'     => __( 'Ordini contenenti prodotti in queste categorie non potranno avviare il recesso.', 'eu-withdrawal-button' ),
                            ),
                            array(
                                'option'   => 'euwb_excluded_tags',
                                'taxonomy' => 'product_tag',
                                'id'       => 'euwb_excluded_tags',
                                'label'    => __( 'Tag esclusi', 'eu-withdrawal-button' ),
                                'desc'     => __( 'Ordini contenenti prodotti con questi tag non potranno avviare il recesso.', 'eu-withdrawal-button' ),
                            ),
                            array(
                                'option'   => 'euwb_excluded_brands',
                                'taxonomy' => EUWB_Admin::get_brands_taxonomy(),
                                'id'       => 'euwb_excluded_brands',
                                'label'    => __( 'Marchi esclusi', 'eu-withdrawal-button' ),
                                'desc'     => __( 'Ordini contenenti prodotti di questi marchi non potranno avviare il recesso.', 'eu-withdrawal-button' ),
                            ),
                        );
                        foreach ( $exclusion_fields as $field ) :
                            $saved_ids = (array) get_option( $field['option'], array() );
                            $terms     = $field['taxonomy'] ? get_terms( array( 'taxonomy' => $field['taxonomy'], 'hide_empty' => false ) ) : array();
                        ?>
                        <tr>
                            <th><label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
                            <td>
                                <?php if ( $field['taxonomy'] && ! is_wp_error( $terms ) && ! empty( $terms ) ) : ?>
                                <select id="<?php echo esc_attr( $field['id'] ); ?>"
                                        name="<?php echo esc_attr( $field['option'] ); ?>[]"
                                        multiple="multiple"
                                        class="wc-enhanced-select euwb-select2"
                                        style="min-width:350px;width:100%;max-width:600px;">
                                    <?php foreach ( $terms as $term ) : ?>
                                    <option value="<?php echo esc_attr( $term->term_id ); ?>"
                                        <?php selected( in_array( (int) $term->term_id, array_map( 'intval', $saved_ids ), true ), true ); ?>>
                                        <?php echo esc_html( $term->name ); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <?php elseif ( ! $field['taxonomy'] ) : ?>
                                <p class="description"><?php esc_html_e( 'Nessun plugin marchi attivo rilevato (WooCommerce Brands o Perfect Brands for WooCommerce).', 'eu-withdrawal-button' ); ?></p>
                                <?php else : ?>
                                <p class="description"><?php esc_html_e( 'Nessun termine trovato per questa tassonomia.', 'eu-withdrawal-button' ); ?></p>
                                <?php endif; ?>
                                <p class="description"><?php echo esc_html( $field['desc'] ); ?></p>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                </div><!-- /euwb-tab-generale -->

                <!-- TAB: Testi frontend -->
                <div id="euwb-tab-testi" class="euwb-tab-panel">
                    <table class="form-table">
                        <tr>
                            <th><label for="euwb_intro_text"><?php esc_html_e( 'Testo introduttivo recesso', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <textarea id="euwb_intro_text" name="euwb_intro_text" rows="4" class="large-text" placeholder="<?php echo esc_attr( $default_intro ); ?>"><?php echo esc_textarea( $intro_text ); ?></textarea>
                                <p class="description"><?php echo wp_kses_post( __( 'Segnaposto disponibili: <code>{withdrawal_window}</code> (finestra in giorni), <code>{days_left}</code> (giorni rimasti alla scadenza).', 'eu-withdrawal-button' ) ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="euwb_form_instructions"><?php esc_html_e( 'Istruzioni modulo recesso', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="text" id="euwb_form_instructions" name="euwb_form_instructions" value="<?php echo esc_attr( $form_instr ); ?>" class="large-text" placeholder="<?php echo esc_attr( $default_form_instr ); ?>">
                                <p class="description"><?php esc_html_e( 'Testo visualizzato sopra il modulo di recesso.', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="euwb_btn_label"><?php esc_html_e( 'Label pulsante di recesso', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="text" id="euwb_btn_label" name="euwb_btn_label" value="<?php echo esc_attr( $btn_label ); ?>" class="regular-text" placeholder="<?php echo esc_attr( $default_btn_label ); ?>">
                                <p class="description"><?php esc_html_e( 'Testo del pulsante che il cliente clicca per avviare la procedura di recesso.', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="euwb_success_message_standard"><?php esc_html_e( 'Messaggio di successo (flusso standard)', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="text" id="euwb_success_message_standard" name="euwb_success_message_standard" value="<?php echo esc_attr( $success_standard ); ?>" class="large-text" placeholder="<?php echo esc_attr( $default_success_standard ); ?>">
                                <p class="description"><?php esc_html_e( 'Mostrato al cliente dopo l\'invio della richiesta in attesa di approvazione (flusso standard).', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="euwb_success_message_direct"><?php esc_html_e( 'Messaggio di successo (flusso diretto)', 'eu-withdrawal-button' ); ?></label></th>
                            <td>
                                <input type="text" id="euwb_success_message_direct" name="euwb_success_message_direct" value="<?php echo esc_attr( $success_direct ); ?>" class="large-text" placeholder="<?php echo esc_attr( $default_success_direct ); ?>">
                                <p class="description"><?php esc_html_e( 'Mostrato al cliente quando il recesso viene confermato immediatamente (flusso diretto).', 'eu-withdrawal-button' ); ?></p>
                            </td>
                        </tr>
                    </table>
                </div><!-- /euwb-tab-testi -->

                <!-- TAB: Email -->
                <div id="euwb-tab-email" class="euwb-tab-panel">
                    <div class="euwb-email-block">
                        <h2 class="euwb-email-title"><?php esc_html_e( 'Email al cliente – Richiesta ricevuta (flusso standard)', 'eu-withdrawal-button' ); ?></h2>
                        <p class="description"><?php echo wp_kses_post( __( 'Segnaposto disponibili: <code>{order_number}</code>, <code>{order_date}</code>, <code>{customer_name}</code>, <code>{withdrawal_date}</code>, <code>{withdrawal_window}</code>', 'eu-withdrawal-button' ) ); ?></p>
                        <table class="form-table">
                            <tr>
                                <th><label for="euwb_intent_email_subject"><?php esc_html_e( 'Oggetto', 'eu-withdrawal-button' ); ?></label></th>
                                <td><input type="text" id="euwb_intent_email_subject" name="euwb_intent_email_subject" value="<?php echo esc_attr( get_option( 'euwb_intent_email_subject', $default_intent_subj ) ); ?>" class="large-text" placeholder="<?php echo esc_attr( $default_intent_subj ); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="euwb_intent_email_body"><?php esc_html_e( 'Corpo', 'eu-withdrawal-button' ); ?></label></th>
                                <td><textarea id="euwb_intent_email_body" name="euwb_intent_email_body" rows="7" class="large-text" placeholder="<?php echo esc_attr( $default_intent_body ); ?>"><?php echo esc_textarea( get_option( 'euwb_intent_email_body', $default_intent_body ) ); ?></textarea></td>
                            </tr>
                        </table>
                    </div>

                    <div class="euwb-email-block">
                        <h2 class="euwb-email-title"><?php esc_html_e( 'Email al cliente – Recesso confermato', 'eu-withdrawal-button' ); ?></h2>
                        <p class="description"><?php echo wp_kses_post( __( 'Segnaposto disponibili: <code>{order_number}</code>, <code>{order_date}</code>, <code>{customer_name}</code>, <code>{withdrawal_date}</code>, <code>{return_window}</code>', 'eu-withdrawal-button' ) ); ?></p>
                        <table class="form-table">
                            <tr>
                                <th><label for="euwb_confirmation_email_subject"><?php esc_html_e( 'Oggetto', 'eu-withdrawal-button' ); ?></label></th>
                                <td><input type="text" id="euwb_confirmation_email_subject" name="euwb_confirmation_email_subject" value="<?php echo esc_attr( get_option( 'euwb_confirmation_email_subject', $default_confirm_subj ) ); ?>" class="large-text" placeholder="<?php echo esc_attr( $default_confirm_subj ); ?>"></td>
                            </tr>
                            <tr>
                                <th><label for="euwb_confirmation_email_body"><?php esc_html_e( 'Corpo', 'eu-withdrawal-button' ); ?></label></th>
                                <td><textarea id="euwb_confirmation_email_body" name="euwb_confirmation_email_body" rows="7" class="large-text" placeholder="<?php echo esc_attr( $default_confirm_body ); ?>"><?php echo esc_textarea( get_option( 'euwb_confirmation_email_body', $default_confirm_body ) ); ?></textarea></td>
                            </tr>
                        </table>
                    </div>

                    <div class="euwb-email-block">
                        <h2 class="euwb-email-title"><?php esc_html_e( 'Istruzioni per la restituzione del bene (prodotti fisici)', 'eu-withdrawal-button' ); ?></h2>
                        <p class="description"><?php esc_html_e( 'Mostrato nel form di recesso e nelle email solo se l\'ordine contiene prodotti fisici che richiedono spedizione. Lasciare vuoto per non mostrarlo.', 'eu-withdrawal-button' ); ?></p>
                        <table class="form-table">
                            <tr>
                                <th><label for="euwb_return_instructions"><?php esc_html_e( 'Testo istruzioni', 'eu-withdrawal-button' ); ?></label></th>
                                <td>
                                    <?php
                                    $default_return = __( "Per completare il recesso è necessario restituire il prodotto entro {return_window} giorni dalla data di conferma del recesso.\n\nSpedire il pacco a:\n[Ragione sociale / Nome]\n[Indirizzo]\n[CAP – Città (Provincia)]\n\nLe spese di restituzione sono a carico del cliente, salvo diverso accordo. Si consiglia di utilizzare un servizio con tracciamento della spedizione.", 'eu-withdrawal-button' );
                                    ?>
                                    <p class="description"><?php echo wp_kses_post( __( 'Segnaposto disponibile: <code>{return_window}</code> (giorni per la restituzione).', 'eu-withdrawal-button' ) ); ?></p>
                                    <textarea id="euwb_return_instructions" name="euwb_return_instructions" rows="8" class="large-text" placeholder="<?php echo esc_attr( $default_return ); ?>"><?php echo esc_textarea( get_option( 'euwb_return_instructions', '' ) ); ?></textarea>
                                </td>
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
