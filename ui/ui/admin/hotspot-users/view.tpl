{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-primary mb20">
            <div class="panel-heading">
                <a class="btn btn-primary btn-xs pull-right" href="{Text::url('hotspot-users')}">
                    <span class="glyphicon glyphicon-arrow-left"></span> {Lang::T('Back')}
                </a>
                <i class="glyphicon glyphicon-user"></i> {Lang::T('WiFi Hotspot User Details')}
            </div>
            <div class="panel-body">
                <div class="row">
                    <div class="col-md-6">
                        <dl class="dl-horizontal">
                            <dt>{Lang::T('Phone Number')}</dt>
                            <dd><strong>{$user['username']}</strong></dd>

                            <dt>{Lang::T('Full Name')}</dt>
                            <dd>{$user['fullname']|default:'-'}</dd>

                            <dt>{Lang::T('Email')}</dt>
                            <dd>{$user['email']|default:'-'}</dd>

                            <dt>{Lang::T('Account Type')}</dt>
                            <dd>{$user['account_type']|default:'Personal'}</dd>

                            <dt>{Lang::T('Status')}</dt>
                            <dd>
                                {if $user['status'] == 'Active'}
                                <span class="label label-success">Active</span>
                                {else}
                                <span class="label label-danger">{$user['status']}</span>
                                {/if}
                            </dd>

                            <dt>{Lang::T('Account Balance')}</dt>
                            <dd><strong>${$user['balance']|number_format:2}</strong></dd>

                            <dt>{Lang::T('Created At')}</dt>
                            <dd>{$user['created_at']}</dd>

                            <dt>{Lang::T('Last Login')}</dt>
                            <dd>{$user['last_login']|default:'Never'}</dd>
                        </dl>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-info">
                            <h4>Quick Actions</h4>
                            {if in_array($_admin['user_type'],['SuperAdmin','Admin'])}
                            <a class="btn btn-warning btn-sm" href="{Text::url('hotspot-users/reset-trial/', $user['id'], '&token=', $csrf_token)}" onclick="return confirm('{Lang::T('Clear trial MAC list to allow re-use. Continue')}?')">
                                <span class="glyphicon glyphicon-refresh"></span> {Lang::T('Clear Trial MAC List')}
                            </a>
                            <a class="btn btn-danger btn-sm" href="{Text::url('hotspot-users/delete/', $user['id'], '&token=', $csrf_token)}" onclick="return confirm('{Lang::T('This action cannot be undone. Delete')}?')">
                                <span class="glyphicon glyphicon-trash"></span> {Lang::T('Delete User')}
                            </a>
                            {/if}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

{if count($recharges) > 0}
<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-info">
            <div class="panel-heading">
                <i class="glyphicon glyphicon-credit"></i> {Lang::T('Active Plans')} ({count($recharges)})
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>{Lang::T('Plan Name')}</th>
                            <th>{Lang::T('Plan Type')}</th>
                            <th>{Lang::T('Recharged On')}</th>
                            <th>{Lang::T('Expiration')}</th>
                            <th>{Lang::T('Router')}</th>
                            <th>{Lang::T('Status')}</th>
                            <th>{Lang::T('Method')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $recharges as $recharge}
                        <tr>
                            <td><strong>{$recharge['namebp']}</strong></td>
                            <td><span class="label label-primary">{$recharge['type']}</span></td>
                            <td>{$recharge['recharged_on']}</td>
                            <td>
                                {if $recharge['status'] eq 'on' && strtotime($recharge['expiration']) < time()}
                                <span class="label label-danger">Expired</span>
                                {else}
                                {$recharge['expiration']}
                                {/if}
                            </td>
                            <td><code style="font-size: 11px">{$recharge['routers']}</code></td>
                            <td>
                                {if $recharge['status'] == 'on'}
                                <span class="label label-success">Active</span>
                                {else}
                                <span class="label label-default">{$recharge['status']}</span>
                                {/if}
                            </td>
                            <td>{$recharge['method']}</td>
                        </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
{/if}

{if count($macUsage) > 0}
<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-warning">
            <div class="panel-heading">
                <i class="glyphicon glyphicon-hdd"></i> {Lang::T('Trial MAC Address History')} ({count($macUsage)}) - 30 Day Cooldown
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>{Lang::T('MAC Address')}</th>
                            <th>{Lang::T('Used Date')}</th>
                            <th>{Lang::T('Cooldown Expires')}</th>
                            <th>{Lang::T('Status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $macUsage as $mac}
                        <tr>
                            <td><code>{$mac['mac_address']}</code></td>
                            <td>{$mac['used_date']}</td>
                            <td>{$mac['expires_date']}</td>
                            <td>
                                {if $mac['status'] eq 'active' && strtotime($mac['expires_date']) >= time()}
                                <span class="label label-danger">In Cooldown</span>
                                {else}
                                <span class="label label-success">Expired</span>
                                {/if}
                            </td>
                        </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
{/if}

<div class="row">
    <div class="col-sm-12">
        <a class="btn btn-primary" href="{Text::url('hotspot-users')}">>
            <span class="glyphicon glyphicon-arrow-left"></span> {Lang::T('Back to List')}
        </a>
    </div>
</div>

{include file="sections/footer.tpl"}
