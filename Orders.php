<?php
class_exists('Typecho_Widget') or die('This file can not be loaded directly.');
include 'header.php';
include 'menu.php';

$db = Typecho_Db::get();
$options = Typecho_Widget::widget('Widget_Options');

$pageSize = 20;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$status = isset($_GET['status']) ? trim($_GET['status']) : '';
$email = isset($_GET['email']) ? trim($_GET['email']) : '';

$baseSelect = $db->select()->from('table.' . TypechoPaid_Plugin::tableName());
if ($status !== '' && in_array($status, array('pending', 'paid'))) {
    $baseSelect->where('status = ?', $status);
}
if ($email !== '') {
    $baseSelect->where('email LIKE ?', '%' . $email . '%');
}

// 用 COUNT(*) 计算总数，避免全量 fetch
$countSelect = clone $baseSelect;
$countSelect->select(array('COUNT(*)' => 'num'));
$total = intval($db->fetchObject($countSelect)->num);
$pageCount = max(1, intval(ceil($total / $pageSize)));
if ($currentPage > $pageCount) {
    $currentPage = $pageCount;
}

$listSelect = clone $baseSelect;
$listSelect->page($currentPage, $pageSize)->order('id', Typecho_Db::SORT_DESC);

$rows = $db->fetchAll($listSelect);

// ---- 订单金额统计 ----
$now = time();
$todayStart = strtotime('today');
$yesterdayStart = strtotime('yesterday');
$yesterdayEnd = $todayStart;
$thisWeekStart = strtotime('monday this week');
$thisMonthStart = strtotime('first day of this month');

function tpPaidSum($db, $table, $start, $end) {
    $sel = $db->select()->from('table.' . $table)
        ->where('status = ?', 'paid')
        ->where('created >= ?', $start);
    if ($end > 0) $sel->where('created < ?', $end);
    $sel->select(array('SUM(price)' => 'total'));
    $row = $db->fetchObject($sel);
    return $row && $row->total ? floatval($row->total) : 0;
}

$todaySum = tpPaidSum($db, TypechoPaid_Plugin::tableName(), $todayStart, 0);
$yesterdaySum = tpPaidSum($db, TypechoPaid_Plugin::tableName(), $yesterdayStart, $yesterdayEnd);
$thisWeekSum = tpPaidSum($db, TypechoPaid_Plugin::tableName(), $thisWeekStart, 0);
$thisMonthSum = tpPaidSum($db, TypechoPaid_Plugin::tableName(), $thisMonthStart, 0);
// ---- End 统计 ----

// 处理状态更改
$statusChanged = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['toggle_trade_no'])) {
    $toggleNo = trim($_POST['toggle_trade_no']);
    $newStatus = isset($_POST['new_status']) && $_POST['new_status'] === 'paid' ? 'paid' : 'pending';
    if ($toggleNo !== '') {
        $order = $db->fetchRow($db->select()->from('table.' . TypechoPaid_Plugin::tableName())->where('trade_no = ?', $toggleNo));
        if (!empty($order)) {
            $db->query($db->update('table.' . TypechoPaid_Plugin::tableName())
                ->rows(array('status' => $newStatus, 'paid_at' => $newStatus === 'paid' ? time() : 0))
                ->where('trade_no = ?', $toggleNo), Typecho_Db::WRITE);
            $statusChanged = true;
            // 重新加载列表
            $rows = $db->fetchAll($listSelect);
        }
    }
}

function tpPaidStatusLabel($status)
{
    if ($status === 'paid') {
        return '<span style="color:#0a8f43;font-weight:700;">已支付</span>';
    }
    return '<span style="color:#d97706;font-weight:700;">待支付</span>';
}

?>
<div class="main">
    <div class="body container">
        <?php include 'page-title.php'; ?>
        <?php echo TypechoPaid_Plugin::renderCardsWrapperOpen(); ?>
        <?php echo TypechoPaid_Plugin::renderInfoCard(); ?>
        <?php echo TypechoPaid_Plugin::renderDocPromo(); ?>
        <?php echo TypechoPaid_Plugin::renderABPromo(); ?>
        <?php echo TypechoPaid_Plugin::renderCardsWrapperClose(); ?>
        <style>
            /* 统计卡片 */
            .tp-orders-stats{display:flex;gap:12px;margin-bottom:16px;flex-wrap:wrap;}
            .tp-orders-stat{flex:1;min-width:120px;padding:14px 16px;border-radius:12px;background:#fff;border:1px solid rgba(0,0,0,.06);box-shadow:0 1px 3px rgba(0,0,0,.04);}
            .tp-orders-stat-label{font-size:12px;color:#6b6b78;margin-bottom:4px;}
            .tp-orders-stat-value{font-size:22px;font-weight:700;color:#1d1b20;}
            html[data-theme="dark"] .tp-orders-stat{background:#1e1e2a;border-color:rgba(255,255,255,.06);}
            html[data-theme="dark"] .tp-orders-stat-label{color:#9d9caa;}
            html[data-theme="dark"] .tp-orders-stat-value{color:#e4e2ed;}
            @media (max-width:768px){.tp-orders-stat{min-width:calc(50% - 6px);}}
        </style>
        <div class="tp-orders-stats">
            <div class="tp-orders-stat"><div class="tp-orders-stat-label">今日订单金额</div><div class="tp-orders-stat-value"><?php echo number_format($todaySum, 2); ?></div></div>
            <div class="tp-orders-stat"><div class="tp-orders-stat-label">昨日订单金额</div><div class="tp-orders-stat-value"><?php echo number_format($yesterdaySum, 2); ?></div></div>
            <div class="tp-orders-stat"><div class="tp-orders-stat-label">本周订单金额</div><div class="tp-orders-stat-value"><?php echo number_format($thisWeekSum, 2); ?></div></div>
            <div class="tp-orders-stat"><div class="tp-orders-stat-label">本月订单金额</div><div class="tp-orders-stat-value"><?php echo number_format($thisMonthSum, 2); ?></div></div>
        </div>
        <style>
            .tp-orders-actions { text-align:center; white-space:nowrap; }
            .tp-inline-form { display:inline; }
            .tp-orders-actions .btn-xs { padding:2px 8px; }
            /* 移动端适配 */
            @media (max-width:768px) {
                .tp-orders-filter { flex-direction:column; align-items:stretch; }
                .tp-orders-filter .search { flex-wrap:wrap; }
                .tp-orders-stat{min-width:calc(50% - 6px);}
            }
            @media (max-width:480px) {
                .tp-orders-stat{min-width:100%;}
                .typecho-list-operate-form .search{flex-direction:column;gap:6px;}
                .typecho-list-operate-form .text-s,.typecho-list-operate-form select{max-width:100%;}
                .typecho-pager{font-size:11px;}
                .typecho-pager li{margin:0 1px;}
                .typecho-pager a{padding:0 6px;}
            }
        </style>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">
                <?php if ($statusChanged): ?>
                    <div class="message notice"><p><?php _e('订单状态已更新。'); ?></p></div>
                <?php endif; ?>
                <div class="typecho-list-operate clearfix">
                    <form method="get" action="<?php $options->adminUrl('extending.php'); ?>" class="typecho-list-operate-form" onsubmit="return (function(f){var q=new FormData(f);var p=[];q.forEach(function(v,k){if(v!=='')p.push(encodeURIComponent(k)+'='+encodeURIComponent(v));});location.search='?'+p.join('&');return false;})(this)">
                        <input type="hidden" name="panel" value="TypechoPaid/Orders.php">
                        <div class="search" role="search">
                            <input class="text-s" type="text" name="email" placeholder="按邮箱筛选" value="<?php echo htmlspecialchars($email); ?>">
                            <select name="status">
                                <option value=""<?php echo $status === '' ? ' selected' : ''; ?>>全部状态</option>
                                <option value="pending"<?php echo $status === 'pending' ? ' selected' : ''; ?>>待支付</option>
                                <option value="paid"<?php echo $status === 'paid' ? ' selected' : ''; ?>>已支付</option>
                            </select>
                            <button type="submit" class="btn btn-s">筛选</button>
                        </div>
                    </form>
                </div>

                <div class="typecho-table-wrap" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                    <table class="typecho-list-table" style="min-width:900px;">
                        <colgroup>
                            <col style="min-width:50px;">
                            <col style="min-width:55px;">
                            <col>
                            <col style="min-width:160px;">
                            <col style="min-width:75px;">
                            <col style="min-width:80px;">
                            <col style="min-width:160px;">
                            <col style="min-width:65px;">
                            <col style="min-width:140px;">
                            <col style="min-width:70px;">
                        </colgroup>
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>CID</th>
                            <th>文章标题</th>
                            <th>邮箱</th>
                            <th>金额</th>
                            <th>渠道</th>
                            <th>商户订单号</th>
                            <th>状态</th>
                            <th>创建时间</th>
                            <th class="tp-orders-actions">操作</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row): ?>
                                <tr>
                                    <td><?php echo intval($row['id']); ?></td>
                                    <td><?php echo intval($row['cid']); ?></td>
                                    <td><?php echo htmlspecialchars($row['title']); ?></td>
                                    <td><?php echo htmlspecialchars($row['email']); ?></td>
                                    <td>￥<?php echo number_format(floatval($row['price']), 2); ?></td>
                                    <td><?php echo htmlspecialchars(TypechoPaid_Plugin::methodLabel($row['channel'])); ?></td>
                                    <td><?php echo htmlspecialchars($row['trade_no']); ?></td>
                                    <td><?php echo tpPaidStatusLabel($row['status']); ?></td>
                                    <td><?php echo date('Y-m-d H:i:s', intval($row['created'])); ?></td>
                                    <td class="tp-orders-actions">
                                        <form method="post" class="tp-inline-form" onsubmit="return confirm('确认要更改该订单状态吗？');">
                                            <input type="hidden" name="toggle_trade_no" value="<?php echo htmlspecialchars($row['trade_no']); ?>">
                                            <input type="hidden" name="new_status" value="<?php echo $row['status'] === 'paid' ? 'pending' : 'paid'; ?>">
                                            <button type="submit" class="btn btn-xs"><?php echo $row['status'] === 'paid' ? '标为待支付' : '标为已支付'; ?></button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="10"><h6 class="typecho-list-table-title">暂无订单</h6></td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

<?php
$pagerParams = array('panel=TypechoPaid%2FOrders.php');
if ($status !== '') $pagerParams[] = 'status=' . urlencode($status);
if ($email !== '') $pagerParams[] = 'email=' . urlencode($email);
echo TypechoPaid_Plugin::renderPager($currentPage, $pageCount, $options->adminUrl('extending.php?' . implode('&', $pagerParams), true));
?>
</div>

<?php
include 'copyright.php';
include 'common-js.php';
include 'table-js.php';
include 'footer.php';
