<?php
/**
 * TC Pay settings. Included by app/Views/configs/integrations_config.php.
 *
 * @var array $tcpay
 * @var string $controller_name
 */
?>

<?= form_open('config/saveTcpay/', ['id' => 'tcpay_config_form', 'class' => 'form-horizontal']) ?>
    <div id="config_wrapper">
        <fieldset id="config_tcpay">

            <div id="integrations_header"><?= lang('Tcpay.configuration') ?>
                <span class="glyphicon glyphicon-info-sign" data-toggle="tooltip" data-placement="right" title="<?= esc(lang('Tcpay.tooltip')) ?>"></span>
            </div>
            <ul id="tcpay_error_message_box" class="error_message_box"></ul>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Tcpay.enabled'), 'tcpay_enabled', ['class' => 'control-label col-xs-3 col-sm-2']) ?>
                <div class="col-xs-1">
                    <?= form_checkbox(['name' => 'tcpay_enabled', 'id' => 'tcpay_enabled', 'value' => '1', 'checked' => $tcpay['enabled']]) ?>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Tcpay.environment'), 'tcpay_environment', ['class' => 'control-label col-xs-3 col-sm-2']) ?>
                <div class="col-xs-9 col-sm-6 col-md-5 col-lg-4">
                    <?= form_dropdown(
                        'tcpay_environment',
                        ['sandbox' => lang('Tcpay.environment_sandbox'), 'live' => lang('Tcpay.environment_live')],
                        $tcpay['environment'],
                        'id="tcpay_environment" class="form-control input-sm"'
                    ) ?>
                </div>
            </div>

            <?php foreach (['sandbox' => 'sandbox_tc_…', 'live' => 'live_tc_…'] as $environment => $hint): ?>
                <div class="form-group form-group-sm">
                    <?= form_label(lang("Tcpay.{$environment}_key"), "tcpay_{$environment}_key", ['class' => 'control-label col-xs-3 col-sm-2']) ?>
                    <div class="col-xs-9 col-sm-6 col-md-5 col-lg-4">
                        <div class="input-group">
                            <span class="input-group-addon input-sm"><span class="glyphicon glyphicon-lock"></span></span>
                            <input type="password"
                                   name="tcpay_<?= $environment ?>_key"
                                   id="tcpay_<?= $environment ?>_key"
                                   class="form-control input-sm"
                                   placeholder="<?= esc($tcpay["{$environment}_key_set"] ? lang('Tcpay.key_saved') : $hint) ?>"
                                   autocomplete="off">
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Tcpay.api_url'), 'tcpay_api_url', ['class' => 'control-label col-xs-3 col-sm-2']) ?>
                <div class="col-xs-9 col-sm-6 col-md-5 col-lg-4">
                    <?= form_input(['name' => 'tcpay_api_url', 'id' => 'tcpay_api_url', 'class' => 'form-control input-sm', 'value' => $tcpay['api_url']]) ?>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Tcpay.expiry_minutes'), 'tcpay_expiry_minutes', ['class' => 'control-label col-xs-3 col-sm-2']) ?>
                <div class="col-xs-3 col-sm-2">
                    <?= form_input(['name' => 'tcpay_expiry_minutes', 'id' => 'tcpay_expiry_minutes', 'type' => 'number', 'min' => '5', 'max' => '1440', 'class' => 'form-control input-sm', 'value' => (string) $tcpay['expiry_minutes']]) ?>
                </div>
            </div>

            <div class="form-group form-group-sm">
                <?= form_label(lang('Tcpay.test_buttons'), 'tcpay_test_buttons', ['class' => 'control-label col-xs-3 col-sm-2']) ?>
                <div class="col-xs-1">
                    <?= form_checkbox(['name' => 'tcpay_test_buttons', 'id' => 'tcpay_test_buttons', 'value' => '1', 'checked' => $tcpay['test_buttons']]) ?>
                </div>
            </div>

            <button type="button" id="tcpay_test" class="btn btn-default btn-sm"><?= lang('Tcpay.test_connection') ?></button>
            <?= form_submit([
                'name'  => 'submit_tcpay',
                'id'    => 'submit_tcpay',
                'value' => lang('Common.submit'),
                'class' => 'btn btn-primary btn-sm pull-right'
            ]) ?>

        </fieldset>
    </div>
<?= form_close() ?>

<script type="text/javascript">
    $(document).ready(function() {
        const notify = function(response) {
            $.notify({ message: response.message }, { type: response.success ? 'success' : 'danger' });
        };

        $('#tcpay_test').click(function() {
            $.post(<?= json_encode(site_url('config/testTcpay')) ?>, {}, notify, 'json');
        });

        $('#tcpay_config_form').validate($.extend(form_support.handler, {
            submitHandler: function(form) {
                $(form).ajaxSubmit({
                    success: function(response) {
                        notify(response);
                        if (response.success) {
                            $('#tcpay_sandbox_key, #tcpay_live_key').val('');
                        }
                    },
                    dataType: 'json'
                });
            },

            errorLabelContainer: '#tcpay_error_message_box'
        }));
    });
</script>
