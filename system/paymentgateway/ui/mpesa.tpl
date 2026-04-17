{include file="sections/header.tpl"}

<form method="post" class="form-horizontal">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel panel-primary panel-hovered">
                <div class="panel-heading">M-Pesa (Daraja) - Test Environment First</div>
                <div class="panel-body">
                    <div class="alert alert-info">
                        <strong>Sandbox first:</strong> keep environment as <strong>sandbox</strong> until your callbacks and STK flow are verified.
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Environment</label>
                        <div class="col-md-9">
                            <select class="form-control" name="mpesa_env">
                                <option value="sandbox" {if $mpesa_env eq 'sandbox'}selected{/if}>sandbox</option>
                                <option value="production" {if $mpesa_env eq 'production'}selected{/if}>production</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Consumer Key</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="mpesa_consumer_key" value="{$mpesa_consumer_key|escape}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Consumer Secret</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="mpesa_consumer_secret" value="{$mpesa_consumer_secret|escape}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Shortcode</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="mpesa_shortcode" value="{$mpesa_shortcode|escape}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Passkey</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="mpesa_passkey" value="{$mpesa_passkey|escape}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Callback URL</label>
                        <div class="col-md-9">
                            <input type="url" class="form-control" name="mpesa_callback_url" value="{$mpesa_callback_url|escape}" required>
                            <p class="help-block">Must be publicly reachable in sandbox test (for example through a tunnel).</p>
                        </div>
                    </div>
                </div>
                <div class="panel-footer">
                    <button type="submit" class="btn btn-primary btn-block">Save M-Pesa Settings</button>
                </div>
            </div>
        </div>
    </div>
</form>

{include file="sections/footer.tpl"}
