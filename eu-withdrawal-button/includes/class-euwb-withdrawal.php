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
            // Log on the WC order
            $order = wc_get_order( $order_id );
            if ( $order ) {
                $order->add_order_note(
                    sprintf(
                        __( 'Recesso avviato dal cliente (ID recesso: %d). In attesa di conferma.', 'eu-withdrawal-button' ),
                        $withdrawal_id
                    )
                );
            }
            return $withdrawal_id;
        }

        return false;
    }

    /**
     * Confirm a withdrawal (step 2 – confirmation click).
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
                    apply_filters( 'euwb_order_status_after_withdrawal', 'refund-requested' ),
                    __( 'Recesso confermato dal cliente ai sensi della Direttiva UE 2023/2673.', 'eu-withdrawal-button' )
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
        $defaults = array( 'limit' => 20, 'offset' => 0, 'status' => '' );
        $args     = wp_parse_args( $args, $defaults );

        $where = '';
        if ( $args['status'] ) {
            $where = $wpdb->prepare( 'WHERE status = %s', $args['status'] );
        }

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $table $where ORDER BY created_at DESC LIMIT %d OFFSET %d",
                $args['limit'],
                $args['offset']
            )
        );
    }

    /**
     * Count withdrawals.
     */
    public static function count( $status = '' ) {
        global $wpdb;
        $table = $wpdb->prefix . 'euwb_withdrawals';
        if ( $status ) {
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE status = %s", $status ) );
        }
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
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
