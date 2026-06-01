<?php

/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  Hotspot Users Management - Using tbl_customers (RADIUS-based)
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
        $order_pos = ['id' => 'asc', 'created_at' => 'desc', 'username' => 'asc', 'phonenumber' => 'asc'];

        if (!array_key_exists($order, $order_pos)) {
            $order = 'created_at';
        }

        // Query tbl_customers filtered for Hotspot service type
        $query = ORM::for_table('tbl_customers');
        $query->where('service_type', 'Hotspot');

        if ($search) {
            $query->where_raw(
                "(username LIKE ? OR phonenumber LIKE ? OR fullname LIKE ? OR email LIKE ?)",
                ["%$search%", "%$search%", "%$search%", "%$search%"]
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
        $user = ORM::for_table('tbl_customers')
            ->where('id', $user_id)
            ->where('service_type', 'Hotspot')
            ->find_one();

        if (!$user) {
            r2(getUrl('hotspot-users'), 'e', Lang::T('User not found'));
        }

        // Get active plans from tbl_user_recharges
        $recharges = ORM::for_table('tbl_user_recharges')
            ->where('customer_id', $user_id)
            ->order_by_desc('recharged_on')
            ->find_many();

        // Get MAC addresses that used trial (30-day tracking)
        $macUsage = ORM::for_table('tbl_hotspot_mac_trial')
            ->where('customer_id', $user_id)
            ->order_by_desc('used_date')
            ->find_many();

        $ui->assign('user', $user);
        $ui->assign('recharges', $recharges);
        $ui->assign('macUsage', $macUsage);
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

        // Get customer from tbl_customers
        $user = ORM::for_table('tbl_customers')
            ->where('id', $user_id)
            ->where('service_type', 'Hotspot')
            ->find_one();

        if ($user) {
            // Delete all related records
            ORM::for_table('tbl_user_recharges')
                ->where('customer_id', $user_id)
                ->delete_many();

            ORM::for_table('tbl_hotspot_mac_trial')
                ->where('customer_id', $user_id)
                ->delete_many();

            // Delete customer
            $user->delete();

            r2(getUrl('hotspot-users'), 's', Lang::T('User deleted successfully'));
        } else {
            r2(getUrl('hotspot-users'), 'e', Lang::T('User not found'));
        }
        break;

    case 'reset-trial':
        if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'])) {
            _alert(Lang::T('You do not have permission to access this page'), 'danger', "dashboard");
        }

        $user_id = $routes['2'];
        $csrf_token = _req('token');

        if (!Csrf::check($csrf_token)) {
            r2(getUrl('hotspot-users'), 'e', Lang::T('Invalid or Expired CSRF Token'));
        }

        // Get customer from tbl_customers
        $user = ORM::for_table('tbl_customers')
            ->where('id', $user_id)
            ->where('service_type', 'Hotspot')
            ->find_one();

        if ($user) {
            // Clear MAC trial records (allow them to use trial again)
            ORM::for_table('tbl_hotspot_mac_trial')
                ->where('customer_id', $user_id)
                ->delete_many();

            r2(getUrl('hotspot-users/view/' . $user_id), 's', Lang::T('Trial reset. User can use trial again.'));
        } else {
            r2(getUrl('hotspot-users'), 'e', Lang::T('User not found'));
        }
        break;

    default:
        $ui->display('admin/404.tpl');
        break;
}

