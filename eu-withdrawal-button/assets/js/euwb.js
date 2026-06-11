/* EU Withdrawal Button – frontend interactions */
(function ($) {
    'use strict';

    var orderId     = null;
    var $section    = null;
    var $step1      = null;
    var $step2      = null;
    var $result     = null;
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

            $btnInitiate.prop('disabled', true).text(euwbData.i18n.processing);

            $.ajax({
                url:    euwbData.ajaxUrl,
                method: 'POST',
                data: {
                    action:     'euwb_initiate',
                    nonce:      euwbData.nonce,
                    order_id:   orderId,
                    first_name: firstName,
                    last_name:  lastName,
                    email:      email,
                    reason:     $('#euwb_reason').val()
                },
                success: function (response) {
                    if (response.success) {
                        // Move to step 2
                        $step1.fadeOut(200, function () {
                            $step2.fadeIn(200);
                        });
                    } else {
                        showResult('error', response.data || euwbData.i18n.error_generic);
                        $btnInitiate.prop('disabled', false).text(euWithdrawalLabel('initiate'));
                    }
                },
                error: function () {
                    showResult('error', euwbData.i18n.error_generic);
                    $btnInitiate.prop('disabled', false).text(euWithdrawalLabel('initiate'));
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
                    action:   'euwb_confirm',
                    nonce:    euwbData.nonce,
                    order_id: orderId
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
            $step2.fadeOut(200, function () {
                $step1.fadeIn(200);
                $btnInitiate.prop('disabled', false);
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

/* EU Withdrawal Button – admin log page */
(function ($) {
    'use strict';

    if ( typeof euwbAdminData === 'undefined' ) return;

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
