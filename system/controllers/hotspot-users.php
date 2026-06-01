<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  Hotspot Users Management
 **/

_admin();
$ui->assign('_title', Lang::T('Hotspot Users'));
$ui->assign('_system_menu', 'hotspot-users');
$ui->assign('_admin', $admin);

$action = $routes['1'];

if (empty($action)) {
    $action = 'list';
}

switch ($action) {
    case 'list':
        $search = _req('search');
        $order = _req('order', 'created_at');
        $order_pos = ['id' => 'asc', 'created_at' => 'desc'];

        if (!array_key_exists($order, $order_pos)) {
            $order = 'created_at';
        }

        $query = ORM::for_table('hotspot_users');

        if ($search) {
            $query->where_raw(
                "(phone LIKE ? OR email LIKE ?)",
                ["%$search%", "%$search%"]
            );
        }

        if ($order_pos[$order] === 'asc') {
            $query->order_by_asc($order);
        } else {
            $query->order_by_desc($order);
        }

        $d = Paginator::findMany($query, ['search' => $search], 50, '&order=' . $order);

        $ui->assign('d', $d);
        $ui->assign('search', $search);
        $ui->assign('order', $order);
        $ui->assign('order_pos', $order_pos[$order]);
        $ui->assign('csrf_token', Csrf::generateAndStoreToken());
        $ui->display('admin/hotspot-users/list.tpl');
        break;

    case 'view':
        $user_id = $routes['2'];
        $user = ORM::for_table('hotspot_users')->find_one($user_id);

        if (!$user) {
            r2(getUrl('hotspot-users'), 'e', Lang::T('User not found'));
        }

        // Get user sessions
        $sessions = ORM::for_table('hotspot_user_sessions')
            ->where('user_id', $user_id)
            ->order_by_desc('created_at')
            ->find_many();

        // Get guest sessions
        $guest_sessions = ORM::for_table('hotspot_guest_sessions')
            ->where('phone', $user['phone'])
            ->order_by_desc('created_at')
            ->find_many();

        $ui->assign('user', $user);
        $ui->assign('sessions', $sessions);
        $ui->assign('guest_sessions', $guest_sessions);
        $ui->assign('csrf_token', Csrf::generateAndStoreToken());
        $ui->display('admin/hotspot-users/view.tpl');
        break;

    case 'delete':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }

        $user_id = $routes['2'];
        $csrf_token = _req('token');

        if (!Csrf::check($csrf_token)) {
            r2(getUrl('hotspot-users'), 'e', Lang::T('Invalid or Expired CSRF Token'));
        }

        $user = ORM::for_table('hotspot_users')->find_one($user_id);
        if ($user) {
            // Delete related sessions
            ORM::for_table('hotspot_user_sessions')
                ->where('user_id', $user_id)
                ->delete_many();

            ORM::for_table('hotspot_guest_sessions')
                ->where('phone', $user['phone'])
                ->delete_many();

            // Delete user
            $user->delete();

            r2(getUrl('hotspot-users'), 's', Lang::T('User deleted successfully'));
        } else {
            r2(getUrl('hotspot-users'), 'e', Lang::T('User not found'));
        }
        break;

    case 'reset-password':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }

        $user_id = $routes['2'];
        $csrf_token = _req('token');

        if (!Csrf::check($csrf_token)) {
            r2(getUrl('hotspot-users'), 'e', Lang::T('Invalid or Expired CSRF Token'));
        }

        $user = ORM::for_table('hotspot_users')->find_one($user_id);
        if ($user) {
            // Clear trial usage
            $user->trial_used = 0;
            $user->save();

            r2(getUrl('hotspot-users/view/' . $user_id), 's', Lang::T('Trial reset successfully'));
        } else {
            r2(getUrl('hotspot-users'), 'e', Lang::T('User not found'));
        }
        break;

    default:
        $ui->display('admin/404.tpl');
        break;
}
