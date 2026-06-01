{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                <div class="btn-group pull-right">
                    {if in_array($_admin['user_type'],['SuperAdmin','Admin'])}
                    <a class="btn btn-warning btn-xs" title="refresh" onclick="location.reload()">
                        <span class="glyphicon glyphicon-refresh"></span>
                    </a>
                    {/if}
                </div>
                <i class="glyphicon glyphicon-wifi"></i> {Lang::T('WiFi Hotspot Users')}
            </div>
            <div class="panel-body">
                <form id="site-search" method="post" action="{Text::url('hotspot-users')}">
                    <input type="hidden" name="csrf_token" value="{$csrf_token}">
                    <div class="md-whiteframe-z1 mb20 text-center" style="padding: 15px">
                        <div class="col-lg-4">
                            <div class="input-group">
                                <span class="input-group-addon">{Lang::T('Search')} </span>
                                <input type="text" name="search" class="form-control" value="{$search}" placeholder="{Lang::T('Phone or Email')}">
                                <span class="input-group-btn">
                                    <button class="btn btn-primary" type="submit"><span class="glyphicon glyphicon-search"></span></button>
                                </span>
                            </div>
                        </div>
                        <div class="col-lg-4">
                            <div class="input-group">
                                <span class="input-group-addon">{Lang::T('Order')}</span>
                                <select class="form-control" name="order" onchange="$('#site-search').submit()">
                                    <option value="created_at" {if $order eq 'created_at' }selected{/if}>{Lang::T('Created Date')}</option>
                                    <option value="phone" {if $order eq 'phone' }selected{/if}>{Lang::T('Phone')}</option>
                                    <option value="id" {if $order eq 'id' }selected{/if}>{Lang::T('ID')}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="table table-striped table-bordered">
                        <thead>
                            <tr>
                                <th style="width: 5%">ID</th>
                                <th style="width: 15%">{Lang::T('Phone')}</th>
                                <th style="width: 15%">{Lang::T('Email')}</th>
                                <th style="width: 10%">{Lang::T('Trial Used')}</th>
                                <th style="width: 15%">{Lang::T('Last Login')}</th>
                                <th style="width: 15%">{Lang::T('Created')}</th>
                                <th style="width: 25%">{Lang::T('Action')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $d as $user}
                            <tr>
                                <td>#{$user['id']}</td>
                                <td>
                                    <strong>{$user['phone']}</strong>
                                </td>
                                <td>{$user['email']|default:'-'}</td>
                                <td>
                                    {if $user['trial_used'] == 1}
                                    <span class="label label-danger">Yes</span>
                                    {else}
                                    <span class="label label-success">No</span>
                                    {/if}
                                </td>
                                <td>{$user['last_login']|default:'-'}</td>
                                <td>{$user['created_at']}</td>
                                <td>
                                    <a class="btn btn-info btn-xs" title="view" href="{Text::url('hotspot-users/view/', $user['id'])}">
                                        <span class="glyphicon glyphicon-eye-open"></span> {Lang::T('View')}
                                    </a>
                                    {if in_array($_admin['user_type'],['SuperAdmin','Admin'])}
                                    <a class="btn btn-warning btn-xs" title="reset" href="{Text::url('hotspot-users/reset-password/', $user['id'], '&token=', $csrf_token)}" onclick="return confirm('{Lang::T('Reset trial status')}?')">
                                        <span class="glyphicon glyphicon-refresh"></span> {Lang::T('Reset')}
                                    </a>
                                    <a class="btn btn-danger btn-xs" title="delete" href="{Text::url('hotspot-users/delete/', $user['id'], '&token=', $csrf_token)}" onclick="return confirm('{Lang::T('Delete this user')}?')">
                                        <span class="glyphicon glyphicon-trash"></span> {Lang::T('Delete')}
                                    </a>
                                    {/if}
                                </td>
                            </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>

                {if count($d) == 0}
                <div class="alert alert-info">
                    <i class="glyphicon glyphicon-info-sign"></i> {Lang::T('No data found')}
                </div>
                {/if}

                <ul class="pagination">
                    {if isset($paginator['prev'])}<li><a href="{$paginator['prev']}">&laquo;</a></li>{/if}
                    {foreach $paginator['pages'] as $p}
                        {if isset($p['current'])}
                        <li class="active"><a href="#">{$p['number']}</a></li>
                        {else}
                        <li><a href="{$p['url']}">{$p['number']}</a></li>
                        {/if}
                    {/foreach}
                    {if isset($paginator['next'])}<li><a href="{$paginator['next']}">&raquo;</a></li>{/if}
                </ul>

            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
