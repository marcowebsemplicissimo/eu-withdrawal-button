/* EU Withdrawal Button – frontend interactions */
(function ($) {
    'use strict';

    var orderId      = null;
    var formData     = {};
    var $section     = null;
    var $step1       = null;
    var $step2       = null;
    var $result      = null;
    var $btnInitiate = null;
    var $btnConfirm  = null;
    var $btnCancel   = null;

    $(document).ready(function () {
        $section     = $('#euwb-withdrawal-section');
        $step1       = $('#euwb-step-1');
        $step2       = $('#euwb-step-2');
        $result      = $('#euwb-result');
        $btnInitiate = $('#euwb-btn-initiate');
        $btnConfirm  = $('#euwb-btn-confirm');
        $btnCancel   = $('#euwb-btn-cancel');

        if ( ! $section.length ) return;

        orderId = $btnInitiate.data('order-id') || $btnConfirm.data('order-id');

        // ----------------------------------------------------------------
        // Step 1: click "Recedi dal contratto qui"
        // ----------------------------------------------------------------
        $btnInitiate.on('click', function () {
            var firstName = $.trim($('#euwb_first_name').val());
            var lastName  = $.trim($('#euwb_last_name').val());
            var email     = $.trim($('#euwb_email').val());

            if ( ! firstName || ! lastName || ! email ) {
                showResult('error', euwbData.i18n.error_generic);
                return;
            }

            // Snapshot form data here so step 2 confirm can send the same values
            // even after step 1 is hidden.
            formData = {
                first_name: firstName,
                last_name:  lastName,
                email:      email,
                reason:     $('#euwb_reason').val()
            };

            $btnInitiate.prop('disabled', true).text(euwbData.i18n.processing);

            $.ajax({
                url:    euwbData.ajaxUrl,
                method: 'POST',
                data: {
                    action:     'euwb_initiate',
                    nonce:      euwbData.nonce,
                    order_id:   orderId,
                    order_key:  euwbData.orderKey,
                    first_name: formData.first_name,
                    last_name:  formData.last_name,
                    email:      formData.email,
                    reason:     formData.reason
                },
                success: function (response) {
                    if (response.success) {
                        $step1.fadeOut(200, function () {
                            $step2.fadeIn(200);
                        });
                    } else {
                        showResult('error', response.data || euwbData.i18n.error_generic);
                        $btnInitiate.prop('disabled', false).text(euWithdrawalLabel('initiate'));
                        formData = {};
                    }
                },
                error: function () {
                    showResult('error', euwbData.i18n.error_generic);
                    $btnInitiate.prop('disabled', false).text(euWithdrawalLabel('initiate'));
                    formData = {};
                }
            });
        });

        // ----------------------------------------------------------------
        // Step 2: click "Conferma recesso qui"
        // ----------------------------------------------------------------
        $btnConfirm.on('click', function () {
            $btnConfirm.prop('disabled', true).text(euwbData.i18n.processing);
            $btnCancel.prop('disabled', true);

            $.ajax({
                url:    euwbData.ajaxUrl,
                method: 'POST',
                data: {
                    action:     'euwb_confirm',
                    nonce:      euwbData.nonce,
                    order_id:   orderId,
                    order_key:  euwbData.orderKey,
                    first_name: formData.first_name,
                    last_name:  formData.last_name,
                    email:      formData.email,
                    reason:     formData.reason
                },
                success: function (response) {
                    $step2.fadeOut(200);
                    if (response.success) {
                        showResult('success', response.data.message);
                    } else {
                        showResult('error', response.data || euwbData.i18n.error_generic);
                        $btnConfirm.prop('disabled', false).text(euWithdrawalLabel('confirm'));
                        $btnCancel.prop('disabled', false);
                    }
                },
                error: function () {
                    showResult('error', euwbData.i18n.error_generic);
                    $btnConfirm.prop('disabled', false).text(euWithdrawalLabel('confirm'));
                    $btnCancel.prop('disabled', false);
                }
            });
        });

        // ----------------------------------------------------------------
        // Cancel: go back to step 1
        // ----------------------------------------------------------------
        $btnCancel.on('click', function () {
            formData = {};
            $step2.fadeOut(200, function () {
                $step1.fadeIn(200);
                $btnInitiate.prop('disabled', false).text(euWithdrawalLabel('initiate'));
            });
        });
    });

    function showResult(type, message) {
        $result
            .removeClass('euwb-notice--success euwb-notice--error')
            .addClass('euwb-notice euwb-notice--' + type)
            .html('<p>' + message + '</p>')
            .fadeIn(300);
        $result[0].scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function euWithdrawalLabel(btn) {
        if (btn === 'initiate') return 'Recedi dal contratto qui';
        if (btn === 'confirm')  return 'Conferma recesso qui';
        return '';
    }

}(jQuery));

/* EU Withdrawal Button – admin log page + edit-order meta box */
(function ($) {
    'use strict';

    if ( typeof euwbAdminData === 'undefined' ) return;

    // ----------------------------------------------------------------
    // Edit-order: "Conferma richiesta di recesso" button
    // ----------------------------------------------------------------
    $(document).on('click', '#euwb-admin-confirm-btn', function () {
        var $btn     = $(this);
        var orderId  = $btn.data('order-id');
        var $result  = $('#euwb-admin-confirm-result');

        if ( ! window.confirm( euwbAdminData.confirmWithdrawal ) ) return;

        $btn.prop('disabled', true).text('…');
        $result.html('');

        $.ajax({
            url:    euwbAdminData.ajaxUrl,
            method: 'POST',
            data: {
                action:   'euwb_admin_confirm',
                nonce:    euwbAdminData.nonceConfirm,
                order_id: orderId
            },
            success: function (response) {
                if ( response.success ) {
                    $result.html('<p style="color:#46b450;font-weight:600;">' + euwbAdminData.confirmedLabel + '</p>');
                    setTimeout(function () { window.location.reload(); }, 1500 );
                } else {
                    $result.html('<p style="color:#dc3232;">' + ( response.data || euwbAdminData.errorConfirmMessage ) + '</p>');
                    $btn.prop('disabled', false).text( euwbAdminData.confirmWithdrawalLabel || 'Conferma richiesta di recesso' );
                }
            },
            error: function () {
                $result.html('<p style="color:#dc3232;">' + euwbAdminData.errorConfirmMessage + '</p>');
                $btn.prop('disabled', false).text( euwbAdminData.confirmWithdrawalLabel || 'Conferma richiesta di recesso' );
            }
        });
    });

    // ----------------------------------------------------------------
    // Admin log: "Conferma" button
    // ----------------------------------------------------------------
    $(document).on('click', '.euwb-confirm-btn', function () {
        var $btn    = $(this);
        var orderId = $btn.data('order-id');

        if ( ! window.confirm( euwbAdminData.confirmWithdrawal ) ) return;

        $btn.prop('disabled', true).text('…');

        $.ajax({
            url:    euwbAdminData.ajaxUrl,
            method: 'POST',
            data: {
                action:   'euwb_admin_confirm',
                nonce:    euwbAdminData.nonceConfirm,
                order_id: orderId
            },
            success: function (response) {
                if ( response.success ) {
                    var $row = $btn.closest('tr');
                    $row.find('.euwb-status')
                        .removeClass('euwb-status--pending')
                        .addClass('euwb-status--confirmed')
                        .text('Confermato');
                    $row.find('td:nth-child(8)').text( new Date().toLocaleDateString() );
                    $btn.remove();
                } else {
                    alert( response.data || euwbAdminData.errorConfirmMessage );
                    $btn.prop('disabled', false).text('Conferma');
                }
            },
            error: function () {
                alert( euwbAdminData.errorConfirmMessage );
                $btn.prop('disabled', false).text('Conferma');
            }
        });
    });

    // ----------------------------------------------------------------
    // Admin log: "Revoca" button
    // ----------------------------------------------------------------
    $(document).on('click', '.euwb-revoke-btn', function () {
        var $btn     = $(this);
        var id       = $btn.data('id');
        var orderId  = $btn.data('order-id');

        if ( ! window.confirm( euwbAdminData.confirmMessage ) ) return;

        $btn.prop('disabled', true).text('…');

        $.ajax({
            url:    euwbAdminData.ajaxUrl,
            method: 'POST',
            data: {
                action:   'euwb_revoke',
                nonce:    euwbAdminData.nonce,
                id:       id,
                order_id: orderId
            },
            success: function (response) {
                if ( response.success ) {
                    $btn.closest('tr').fadeOut(300, function () {
                        $(this).remove();
                    });
                } else {
                    alert( response.data || euwbAdminData.errorMessage );
                    $btn.prop('disabled', false).text( euwbAdminData.revokedLabel );
                }
            },
            error: function () {
                alert( euwbAdminData.errorMessage );
                $btn.prop('disabled', false).text('Revoca');
            }
        });
    });

}(jQuery));
