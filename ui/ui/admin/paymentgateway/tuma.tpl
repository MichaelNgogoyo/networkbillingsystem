{include file="sections/header.tpl"}

<form method="post" class="form-horizontal">
    <div class="row">
        <div class="col-md-8 col-md-offset-2">
            <div class="panel panel-primary panel-hovered">
                <div class="panel-heading">Tuma STK - Test Configuration</div>
                <div class="panel-body">
                    <div class="alert alert-info">
                        <strong>Testing first:</strong> set provider to <strong>Tuma</strong>, save key/secret, then run one small-amount STK test.
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Hotspot Payment Provider</label>
                        <div class="col-md-9">
                            <select class="form-control" name="hotspot_payment_provider">
                                <option value="mpesa" {if $hotspot_payment_provider eq 'mpesa'}selected{/if}>M-Pesa (Daraja)</option>
                                <option value="tuma" {if $hotspot_payment_provider eq 'tuma'}selected{/if}>Tuma STK</option>
                            </select>
                            <p class="help-block">Only paid hotspot flows use this provider switch. Trial remains direct.</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Environment</label>
                        <div class="col-md-9">
                            <select class="form-control" name="tuma_env">
                                <option value="sandbox" {if $tuma_env eq 'sandbox'}selected{/if}>sandbox</option>
                                <option value="production" {if $tuma_env eq 'production'}selected{/if}>production</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">API Base URL</label>
                        <div class="col-md-9">
                            <input type="url" class="form-control" name="tuma_api_base" value="{$tuma_api_base|escape}" required>
                            <p class="help-block">Example: https://api.tuma.co.ke (adjust to official docs for your account).</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">STK Path</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="tuma_stk_path" value="{$tuma_stk_path|escape}" required>
                            <p class="help-block">Example: /v1/payments/stk-push (set exact path from Tuma docs).</p>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">API Key</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="tuma_api_key" value="{$tuma_api_key|escape}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">API Secret</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="tuma_api_secret" value="{$tuma_api_secret|escape}" required>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Shortcode/Till (optional)</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="tuma_shortcode" value="{$tuma_shortcode|escape}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label">Callback URL</label>
                        <div class="col-md-9">
                            <input type="url" class="form-control" name="tuma_callback_url" value="{$tuma_callback_url|escape}" required>
                            <p class="help-block">Set this in Tuma dashboard: {$tuma_callback_url|escape}</p>
                        </div>
                    </div>
                </div>
                <div class="panel-footer">
                    <button type="submit" class="btn btn-primary btn-block">Save Tuma Settings</button>
                </div>
            </div>
        </div>
    </div>
</form>

{include file="sections/footer.tpl"}
