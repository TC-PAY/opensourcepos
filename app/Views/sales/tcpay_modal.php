<?php

use App\Libraries\Tcpay_lib;

/**
 * TC Pay payment window for the register. Included by app/Views/sales/register.php.
 *
 * @var bool   $tcpay_test_buttons
 * @var string $tcpay_environment
 */
?>
<div class="modal fade" id="tcpay_modal" tabindex="-1" role="dialog" aria-labelledby="tcpay_modal_title" data-backdrop="static" data-keyboard="false">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <h4 class="modal-title" id="tcpay_modal_title"><?= lang('Tcpay.window_title') ?></h4>
            </div>
            <div class="modal-body">
                <?php if ($tcpay_environment === 'sandbox'): ?>
                    <div class="alert alert-warning"><span class="glyphicon glyphicon-wrench"></span> <?= lang('Tcpay.sandbox_notice') ?></div>
                <?php endif; ?>
                <div id="tcpay_error" class="alert alert-danger" style="display: none;"></div>
                <table class="table table-condensed" id="tcpay_details" style="display: none;">
                    <tr><th style="width: 40%;"><?= lang('Tcpay.amount') ?></th><td id="tcpay_amount"></td></tr>
                    <tr><th><?= lang('Tcpay.reference') ?></th><td><code id="tcpay_reference" style="font-size: 1.4em;"></code></td></tr>
                    <tr><th><?= lang('Tcpay.status') ?></th><td id="tcpay_status"></td></tr>
                </table>
                <p id="tcpay_instruction" class="text-muted" style="display: none;"><?= lang('Tcpay.instruction') ?></p>
                <?php if ($tcpay_test_buttons): ?>
                    <div id="tcpay_test_controls" class="well well-sm" style="display: none;">
                        <p><strong><?= lang('Tcpay.test_controls') ?></strong></p>
                        <button type="button" class="btn btn-success btn-sm tcpay-simulate" data-outcome="success"><?= lang('Tcpay.simulate_success') ?></button>
                        <button type="button" class="btn btn-danger btn-sm tcpay-simulate" data-outcome="failure"><?= lang('Tcpay.simulate_failure') ?></button>
                        <button type="button" class="btn btn-default btn-sm tcpay-simulate" data-outcome="expire"><?= lang('Tcpay.simulate_expiry') ?></button>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" id="tcpay_cancel"><?= lang('Tcpay.cancel_payment') ?></button>
                <button type="button" class="btn btn-primary" id="tcpay_done" style="display: none;"><?= lang('Tcpay.back_to_sale') ?></button>
            </div>
        </div>
    </div>
</div>

<div id="tcpay_unattached" class="alert alert-danger" style="display: none;"></div>

<script type="text/javascript">
    const tcpayPayment = (function() {
        const paymentType = <?= json_encode(Tcpay_lib::PAYMENT_TYPE) ?>;
        const urls = {
            create: <?= json_encode(site_url('tcpay/create')) ?>,
            status: <?= json_encode(site_url('tcpay/status')) ?>,
            simulate: <?= json_encode(site_url('tcpay/simulate')) ?>,
            cancel: <?= json_encode(site_url('tcpay/cancel')) ?>,
            unattached: <?= json_encode(site_url('tcpay/unattached')) ?>,
            register: <?= json_encode(site_url('sales')) ?>
        };
        const text = {
            PENDING: <?= json_encode(lang('Tcpay.status_pending')) ?>,
            PROCESSING: <?= json_encode(lang('Tcpay.status_pending')) ?>,
            AUTHORIZED: <?= json_encode(lang('Tcpay.status_pending')) ?>,
            SUCCESS: <?= json_encode(lang('Tcpay.status_success')) ?>,
            FAILED: <?= json_encode(lang('Tcpay.status_failed')) ?>,
            EXPIRED: <?= json_encode(lang('Tcpay.status_expired')) ?>,
            creating: <?= json_encode(lang('Tcpay.creating')) ?>,
            unattached: <?= json_encode(lang('Tcpay.unattached_warning')) ?>
        };
        let current = null;
        let pollTimer = null;
        let succeeded = false;

        function requestId() {
            const bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            return Array.from(bytes, function(b) { return b.toString(16).padStart(2, '0'); }).join('');
        }

        function showError(message) {
            $('#tcpay_error').text(message).show();
        }

        function render(intent) {
            current = intent;
            $('#tcpay_error').hide();
            $('#tcpay_details').show();
            $('#tcpay_amount').text(intent.amount);
            $('#tcpay_reference').text(intent.reference);
            $('#tcpay_status').text(text[intent.status] || intent.status);
            $('#tcpay_instruction').toggle(!intent.final);
            $('#tcpay_test_controls').toggle(!intent.final);
            $('#tcpay_cancel').toggle(!intent.final);
            $('#tcpay_done').toggle(intent.final);
            if (intent.warning) {
                showError(intent.warning);
            }
            if (intent.final) {
                stopPolling();
                succeeded = intent.status === 'SUCCESS';
                if (succeeded) {
                    window.location.href = urls.register;
                }
            }
        }

        function stopPolling() {
            if (pollTimer) {
                clearTimeout(pollTimer);
                pollTimer = null;
            }
        }

        function poll() {
            if (!current || current.final) {
                return;
            }
            $.getJSON(urls.status + '/' + encodeURIComponent(current.id))
                .done(render)
                .always(function() {
                    if (current && !current.final) {
                        pollTimer = setTimeout(poll, 3000);
                    }
                });
        }

        function fail(xhr) {
            const body = xhr && xhr.responseJSON;
            showError(body && body.error ? body.error : xhr.statusText);
        }

        function open(amount) {
            current = null;
            succeeded = false;
            stopPolling();
            $('#tcpay_error').hide();
            $('#tcpay_details, #tcpay_instruction, #tcpay_test_controls, #tcpay_done').hide();
            $('#tcpay_cancel').show();
            $('#tcpay_status').text(text.creating);
            $('#tcpay_modal').modal('show');

            $.post(urls.create, { amount: amount, request_id: requestId() }, null, 'json')
                .done(function(intent) {
                    render(intent);
                    pollTimer = setTimeout(poll, 2000);
                })
                .fail(function(xhr) {
                    fail(xhr);
                    $('#tcpay_cancel').hide();
                    $('#tcpay_done').show();
                });
        }

        $('#tcpay_modal').on('click', '.tcpay-simulate', function() {
            if (!current) {
                return;
            }
            $('.tcpay-simulate').prop('disabled', true);
            $.post(urls.simulate + '/' + encodeURIComponent(current.id), { outcome: $(this).data('outcome') }, null, 'json')
                .done(render)
                .fail(fail)
                .always(function() { $('.tcpay-simulate').prop('disabled', false); });
        });

        $('#tcpay_cancel').on('click', function() {
            if (!current) {
                $('#tcpay_modal').modal('hide');
                return;
            }
            $.post(urls.cancel + '/' + encodeURIComponent(current.id), {}, null, 'json')
                .done(render)
                .fail(fail);
        });

        $('#tcpay_done').on('click', function() {
            stopPolling();
            if (succeeded) {
                window.location.href = urls.register;
            } else {
                $('#tcpay_modal').modal('hide');
            }
        });

        $.getJSON(urls.unattached).done(function(rows) {
            const missing = rows.filter(function(row) { return !row.in_cart; });
            if (missing.length === 0) {
                return;
            }
            const list = missing.map(function(row) { return row.reference + ' (' + row.amount + ')'; }).join(', ');
            $('#tcpay_unattached').text(text.unattached.replace('{0}', list)).prependTo('#register_wrapper').show();
        });

        return {
            handles: function(type) { return type === paymentType; },
            open: open
        };
    })();
</script>
