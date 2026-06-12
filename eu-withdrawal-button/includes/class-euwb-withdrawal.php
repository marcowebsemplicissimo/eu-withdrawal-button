<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class EUWB_Withdrawal {

    /**
     * Check whether an order is still within the 14-day withdrawal window.
     */
    public static function is_within_window( $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order );
        }
        if ( ! $order ) return false;

        $completed_date = $order->get_date_completed() ?: $order->get_date_created();
        if ( ! $completed_date ) return false;

        $window_days = apply_filters( 'euwb_withdrawal_window_days', EUWB_WITHDRAWAL_WINDOW_DAYS );
        $deadline    = clone $completed_date;
        $deadline->modify( "+{$window_days} days" );

        return current_time( 'timestamp', true ) <= $deadline->getTimestamp();
    }

    /**
     * Check whether the order already has a withdrawal record.
     */
    public static function order_has_withdrawal( $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'euwb_withdrawals';
        return (bool) $wpdb->get_var(
            $wpdb->prepare( "SELECT id FROM $table WHERE order_id = %d LIMIT 1", $order_id )
        );
    }

    /**
     * Get the withdrawal record for an order.
     */
    public static function get_withdrawal( $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'euwb_withdrawals';
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM $table WHERE order_id = %d LIMIT 1", $order_id )
        );
    }

    /**
     * Register a new withdrawal request (step 1 – intent).
     */
    public static function create( $order_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'euwb_withdrawals';

        $inserted = $wpdb->insert(
            $table,
            array(
                'order_id'   => absint( $order_id ),
                'user_id'    => get_current_user_id(),
                'first_name' => sanitize_text_field( $data['first_name'] ?? '' ),
                'last_name'  => sanitize_text_field( $data['last_name'] ?? '' ),
                'email'      => sanitize_email( $data['email'] ?? '' ),
                'reason'     => sanitize_textarea_field( $data['reason'] ?? '' ),
                'status'     => 'pending',
                'ip_address' => self::get_client_ip(),
                'created_at' => current_time( 'mysql', true ),
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( $inserted ) {
            $withdrawal_id = $wpdb->insert_id;
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_status(
                    'pending-withdraw',
                    sprintf(
                        __( 'Recesso richiesto dal cliente (ID recesso: %d). In attesa di conferma da parte dell\'amministratore.', 'eu-withdrawal-button' ),
                        $withdrawal_id
                    )
                );
            }
            do_action( 'euwb_withdrawal_intent_created', $order_id );
            return $withdrawal_id;
        }

        return false;
    }

    /**
     * Create and immediately confirm a withdrawal in one automatic step (step 2).
     */
    public static function create_and_confirm( $order_id, $data ) {
        global $wpdb;
        $table = $wpdb->prefix . 'euwb_withdrawals';

        $now = current_time( 'mysql', true );

        $inserted = $wpdb->insert(
            $table,
            array(
                'order_id'     => absint( $order_id ),
                'user_id'      => get_current_user_id(),
                'first_name'   => sanitize_text_field( $data['first_name'] ?? '' ),
                'last_name'    => sanitize_text_field( $data['last_name'] ?? '' ),
                'email'        => sanitize_email( $data['email'] ?? '' ),
                'reason'       => sanitize_textarea_field( $data['reason'] ?? '' ),
                'status'       => 'confirmed',
                'ip_address'   => self::get_client_ip(),
                'created_at'   => $now,
                'confirmed_at' => $now,
            ),
            array( '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( ! $inserted ) return false;

        $withdrawal_id = $wpdb->insert_id;
        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_status(
                apply_filters( 'euwb_order_status_after_withdrawal', 'pending-refund' ),
                sprintf(
                    __( 'Recesso confermato dal cliente ai sensi della Direttiva UE 2023/2673 (ID recesso: %d).', 'eu-withdrawal-button' ),
                    $withdrawal_id
                )
            );
        }

        do_action( 'euwb_withdrawal_confirmed', $order_id );

        return $withdrawal_id;
    }

    /**
     * Confirm a pending withdrawal — called by the admin from the edit-order page.
     * Updates the withdrawal record to 'confirmed' and moves the order to pending_refund.
     */
    public static function confirm( $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'euwb_withdrawals';

        $updated = $wpdb->update(
            $table,
            array(
                'status'       => 'confirmed',
                'confirmed_at' => current_time( 'mysql', true ),
            ),
            array( 'order_id' => absint( $order_id ), 'status' => 'pending' ),
            array( '%s', '%s' ),
            array( '%d', '%s' )
        );

        if ( $updated ) {
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->update_status(
                    apply_filters( 'euwb_order_status_after_withdrawal', 'pending-refund' ),
                    __( 'Recesso confermato dall\'amministratore ai sensi della Direttiva UE 2023/2673. In attesa di rimborso.', 'eu-withdrawal-button' )
                );
            }
            do_action( 'euwb_withdrawal_confirmed', $order_id );
        }

        return (bool) $updated;
    }

    /**
     * Get all withdrawals (for admin list).
     */
    public static function get_all( $args = array() ) {
        global $wpdb;
        $table    = $wpdb->prefix . 'euwb_withdrawals';
        $defaults = array( 'limit' => 20, 'offset' => 0, 'status' => '', 'search' => '', 'orderby' => 'created_at', 'order' => 'DESC' );
        $args     = wp_parse_args( $args, $defaults );

        $allowed_orderby = array( 'created_at', 'confirmed_at' );
        $orderby = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order   = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $conditions = array();
        $values     = array();

        if ( $args['status'] ) {
            $conditions[] = 'status = %s';
            $values[]     = $args['status'];
        }

        if ( $args['search'] ) {
            $like         = '%' . $wpdb->esc_like( $args['search'] ) . '%';
            $conditions[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR CAST(order_id AS CHAR) LIKE %s)';
            $values       = array_merge( $values, array( $like, $like, $like, $like ) );
        }

        $where = $conditions ? 'WHERE ' . implode( ' AND ', $conditions ) : '';
        $values[] = $args['limit'];
        $values[] = $args['offset'];

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table $where ORDER BY $orderby $order LIMIT %d OFFSET %d",
                $values
            )
        );
    }

    /**
     * Count withdrawals.
     */
    public static function count( $status = '', $search = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'euwb_withdrawals';

        $conditions = array();
        $values     = array();

        if ( $status ) {
            $conditions[] = 'status = %s';
            $values[]     = $status;
        }

        if ( $search ) {
            $like         = '%' . $wpdb->esc_like( $search ) . '%';
            $conditions[] = '(first_name LIKE %s OR last_name LIKE %s OR email LIKE %s OR CAST(order_id AS CHAR) LIKE %s)';
            $values       = array_merge( $values, array( $like, $like, $like, $like ) );
        }

        if ( $conditions ) {
            $where = 'WHERE ' . implode( ' AND ', $conditions );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table $where", $values ) );
        }

        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
    }

    /**
     * Mark all withdrawal records for an order as orphaned when the order is permanently deleted.
     */
    public static function mark_order_deleted( $order_id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'euwb_withdrawals';
        $wpdb->update(
            $table,
            array( 'order_deleted' => 1 ),
            array( 'order_id' => absint( $order_id ) ),
            array( '%d' ),
            array( '%d' )
        );
    }

    /**
     * Delete a withdrawal record by its ID.
     * Returns the deleted row data (for logging), or false on failure.
     */
    public static function delete( $id ) {
        global $wpdb;
        $table = $wpdb->prefix . 'euwb_withdrawals';

        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d LIMIT 1", $id ) );
        if ( ! $row ) return false;

        $deleted = $wpdb->delete( $table, array( 'id' => absint( $id ) ), array( '%d' ) );
        return $deleted ? $row : false;
    }

    /**
     * Returns true if at least one item in the order requires physical shipping.
     */
    public static function order_needs_shipping( $order ) {
        if ( ! $order instanceof WC_Order ) {
            $order = wc_get_order( $order );
        }
        if ( ! $order ) return false;

        foreach ( $order->get_items() as $item ) {
            $product = $item->get_product();
            if ( $product && $product->needs_shipping() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns the return-instructions text if configured and the order needs shipping,
     * or an empty string otherwise.
     */
    public static function get_return_instructions( $order ) {
        if ( ! self::order_needs_shipping( $order ) ) return '';
        return (string) get_option( 'euwb_return_instructions', '' );
    }

    private static function get_client_ip() {
        $keys = array( 'HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' );
        foreach ( $keys as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                return sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
            }
        }
        return '';
    }
}
