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
                            <dt>{Lang::T('Phone')}</dt>
                            <dd><strong>{$user['phone']}</strong></dd>

                            <dt>{Lang::T('Email')}</dt>
                            <dd>{$user['email']|default:'-'}</dd>

                            <dt>{Lang::T('Trial Status')}</dt>
                            <dd>
                                {if $user['trial_used'] == 1}
                                <span class="label label-danger">Used</span>
                                {else}
                                <span class="label label-success">Available</span>
                                {/if}
                            </dd>

                            <dt>{Lang::T('Status')}</dt>
                            <dd>
                                {if $user['status'] == 'active'}
                                <span class="label label-success">Active</span>
                                {else}
                                <span class="label label-danger">Inactive</span>
                                {/if}
                            </dd>

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
                            <a class="btn btn-warning btn-sm" href="{Text::url('hotspot-users/reset-password/', $user['id'], '&token=', $csrf_token)}" onclick="return confirm('{Lang::T('This will reset trial usage. Continue')}?')">
                                <span class="glyphicon glyphicon-refresh"></span> {Lang::T('Reset Trial')}
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

{if count($sessions) > 0}
<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-info">
            <div class="panel-heading">
                <i class="glyphicon glyphicon-link"></i> {Lang::T('Login Sessions')} ({count($sessions)})
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>{Lang::T('Session ID')}</th>
                            <th>{Lang::T('IP Address')}</th>
                            <th>{Lang::T('Expires At')}</th>
                            <th>{Lang::T('Created')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $sessions as $session}
                        <tr>
                            <td><code style="font-size: 11px">{$session['session_id']|truncate:30}</code></td>
                            <td>{$session['ip_address']}</td>
                            <td>{$session['expires_at']}</td>
                            <td>{$session['created_at']}</td>
                        </tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
{/if}

{if count($guest_sessions) > 0}
<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-warning">
            <div class="panel-heading">
                <i class="glyphicon glyphicon-refresh"></i> {Lang::T('WiFi Sessions')} ({count($guest_sessions)})
            </div>
            <div class="table-responsive">
                <table class="table table-striped table-bordered">
                    <thead>
                        <tr>
                            <th>{Lang::T('Username')}</th>
                            <th>{Lang::T('Plan')}</th>
                            <th>{Lang::T('Duration')}</th>
                            <th>{Lang::T('MAC Address')}</th>
                            <th>{Lang::T('Created')}</th>
                            <th>{Lang::T('Expires')}</th>
                            <th>{Lang::T('Status')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $guest_sessions as $gs}
                        <tr>
                            <td><code>{$gs['username']}</code></td>
                            <td>
                                <span class="label label-primary">{$gs['plan']}</span>
                            </td>
                            <td>{$gs['duration']}</td>
                            <td><code style="font-size: 11px">{$gs['mac_address']|default:'-'}</code></td>
                            <td>{$gs['created_at']}</td>
                            <td>{$gs['expires_at']}</td>
                            <td>
                                {if $gs['status'] == 'active'}
                                <span class="label label-success">Active</span>
                                {else}
                                <span class="label label-default">Inactive</span>
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
        <a class="btn btn-primary" href="{Text::url('hotspot-users')}">
            <span class="glyphicon glyphicon-arrow-left"></span> {Lang::T('Back to List')}
        </a>
    </div>
</div>

{include file="sections/footer.tpl"}
