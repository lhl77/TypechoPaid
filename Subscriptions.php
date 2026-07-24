<?php
class_exists('Typecho_Widget') or die('This file can not be loaded directly.');
include 'header.php';
include 'menu.php';

$db = Typecho_Db::get();
$options = Typecho_Widget::widget('Widget_Options');
$pageSize = 20;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$planKey = isset($_GET['plan']) ? trim($_GET['plan']) : '';
$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$state = isset($_GET['state']) ? trim($_GET['state']) : '';
$plans = TypechoPaid_Plugin::getSubscriptionPlans();
$now = time();

$baseSelect = $db->select()->from('table.' . TypechoPaid_Plugin::tableName())
    ->where('plan_id <> ?', '')
    ->where('paid_at > ?', 0);  // 排除未实际支付的脏数据

// 状态筛选：全部时不限状态，active/expired 只查已支付，invalid 只查已失效
if ($state === 'invalid') {
    $baseSelect->where('status = ?', 'invalid');
} elseif ($state !== '') {
    $baseSelect->where('status = ?', 'paid');
}

if ($planKey !== '') {
    $baseSelect->where('plan_id = ?', $planKey);
}
if ($email !== '') {
    $baseSelect->where('email LIKE ?', '%' . $email . '%');
}
if ($state === 'active') {
    $baseSelect->where('expires_at > ?', $now);
} elseif ($state === 'expired') {
    $baseSelect->where('expires_at <= ?', $now);
}
// 用 COUNT(*) 计算总数，避免全量 fetch
$countSelect = clone $baseSelect;
$countSelect->select(array('COUNT(*)' => 'num'));
$total = intval($db->fetchObject($countSelect)->num);
$pageCount = max(1, intval(ceil($total / $pageSize)));
$currentPage = min($currentPage, $pageCount);

$listSelect = clone $baseSelect;
$listSelect->page($currentPage, $pageSize)->order('expires_at', Typecho_Db::SORT_DESC);
$rows = $db->fetchAll($listSelect);

// 处理设为失效
$invalidated = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['invalidate_id'])) {
    $invId = intval($_POST['invalidate_id']);
    if ($invId > 0) {
        $db->query($db->update('table.' . TypechoPaid_Plugin::tableName())
            ->rows(array('status' => 'invalid'))
            ->where('id = ?', $invId)
            ->where('status = ?', 'paid'), Typecho_Db::WRITE);
        $invalidated = true;
    }
}

// 查询失效订阅数
$invalidSel = $db->select(array('COUNT(*)' => 'num'))->from('table.' . TypechoPaid_Plugin::tableName())
    ->where('plan_id <> ?', '')->where('status = ?', 'invalid')->where('paid_at > ?', 0);
$invalidCount = intval($db->fetchObject($invalidSel)->num);

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
            .tp-subscription-state{display:inline-block;min-width:52px;text-align:center;font-weight:700;}
            .tp-subscription-state.is-active{color:#078347;}
            .tp-subscription-state.is-expired{color:#8b5e00;}
            .tp-subscription-plan{font-weight:600;word-break:break-word;}
            .tp-subscription-hint{color:#777;font-size:.9em;}
            .tp-subscription-state.is-invalid{color:#dc2626;}
            /* 移动端适配 */
            @media (max-width:768px) {
                .tp-subscription-filter .search{display:flex;flex-wrap:wrap;gap:8px;}
                .tp-subscription-filter .text-s,.tp-subscription-filter select{max-width:100%;}
            }
            @media (max-width:480px) {
                .typecho-list-operate-form .search{flex-direction:column;gap:6px;}
                .typecho-list-operate-form .text-s,.typecho-list-operate-form select{max-width:100%;}
                .typecho-pager{font-size:11px;}
                .typecho-pager li{margin:0 1px;}
                .typecho-pager a{padding:0 6px;}
            }
        </style>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">
                <?php if ($invalidated): ?>
                    <div class="message notice"><p><?php _e('该订阅已设为失效，无法恢复。'); ?></p></div>
                <?php endif; ?>
                <div class="typecho-list-operate clearfix tp-subscription-filter">
                    <form method="get" action="<?php $options->adminUrl('extending.php'); ?>" class="typecho-list-operate-form" onsubmit="return (function(f){var q=new FormData(f);var p=[];q.forEach(function(v,k){if(v!=='')p.push(encodeURIComponent(k)+'='+encodeURIComponent(v));});location.search='?'+p.join('&');return false;})(this)">
                        <input type="hidden" name="panel" value="TypechoPaid/Subscriptions.php">
                        <div class="search" role="search">
                            <input class="text-s" type="text" name="email" placeholder="按邮箱筛选" value="<?php echo htmlspecialchars($email); ?>">
                            <select name="plan">
                                <option value="">全部计划</option>
                                <?php foreach ($plans as $key => $plan): ?>
                                    <option value="<?php echo htmlspecialchars($key); ?>"<?php echo $planKey === $key ? ' selected' : ''; ?>><?php echo htmlspecialchars($plan['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <select name="state">
                                <option value="">全部有效期</option>
                                <option value="active"<?php echo $state === 'active' ? ' selected' : ''; ?>>有效中</option>
                                <option value="expired"<?php echo $state === 'expired' ? ' selected' : ''; ?>>已到期</option>
                                <option value="invalid"<?php echo $state === 'invalid' ? ' selected' : ''; ?>>已失效</option>
                            </select>
                            <button type="submit" class="btn btn-s">筛选</button>
                        </div>
                    </form>
                </div>
                <p class="tp-subscription-hint"><?php if ($state === 'invalid'): ?>以下为已设为失效的订阅记录，不再参与文章解锁。<?php else: ?>只显示已支付的订阅订单；过期后对应计划的文章将自动恢复锁定。<?php if($invalidCount > 0): ?>另有 <a href="?panel=TypechoPaid%2FSubscriptions.php&state=invalid">已失效订阅 <?php echo $invalidCount; ?> 条</a>。<?php endif; ?><?php endif; ?></p>
                <div class="typecho-table-wrap" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                    <table class="typecho-list-table" style="min-width:850px;">
                        <thead><tr><th>ID</th><th>订阅计划</th><th>邮箱</th><th>金额</th><th>购买时间</th><th>到期时间</th><th>剩余时间</th><th>商户订单号</th><th>状态</th><th>操作</th></tr></thead>
                        <tbody>
                        <?php if (!empty($rows)): ?>
                            <?php foreach ($rows as $row):
                                $expires = intval($row['expires_at']);
                                $active = $expires > $now;
                                $remaining = $active ? max(1, intval(ceil(($expires - $now) / 86400))) . ' 天' : '—';
                                $planName = isset($plans[$row['plan_id']]) ? $plans[$row['plan_id']]['name'] : $row['plan_id'];
                            ?>
                            <tr>
                                <td><?php echo intval($row['id']); ?></td>
                                <td class="tp-subscription-plan"><?php echo htmlspecialchars($planName); ?></td>
                                <td><?php echo htmlspecialchars($row['email']); ?></td>
                                <td>￥<?php echo number_format(floatval($row['price']), 2); ?></td>
                                <td><?php echo date('Y-m-d H:i:s', intval($row['paid_at'])); ?></td>
                                <td><?php echo $expires > 0 ? date('Y-m-d H:i:s', $expires) : '—'; ?></td>
                                <td><?php echo $remaining; ?></td>
                                <td><?php echo htmlspecialchars($row['trade_no']); ?></td>
                                <td><span class="tp-subscription-state <?php echo $row['status'] === 'invalid' ? 'is-invalid' : ($active ? 'is-active' : 'is-expired'); ?>"><?php echo $row['status'] === 'invalid' ? '已失效' : ($active ? '有效中' : '已到期'); ?></span></td>
                                <td style="white-space:nowrap">
                                    <?php if ($row['status'] !== 'invalid' && $active): ?>
                                    <form method="post" style="display:inline" onsubmit="if(!confirm('确认将此订阅设为失效？失效后无法恢复。'))return false;var s=this.closest('tr').querySelector('.tp-subscription-state');if(s){s.textContent='已失效';s.className='tp-subscription-state is-invalid';}"> 
                                        <input type="hidden" name="invalidate_id" value="<?php echo intval($row['id']); ?>">
                                        <button type="submit" class="btn btn-xs" style="color:#dc2626">设为失效</button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="10"><h6 class="typecho-list-table-title">暂无符合条件的订阅订单</h6></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
<?php
$pagerParams = array('panel=TypechoPaid%2FSubscriptions.php');
if ($planKey !== '') $pagerParams[] = 'plan=' . urlencode($planKey);
if ($state !== '') $pagerParams[] = 'state=' . urlencode($state);
if ($email !== '') $pagerParams[] = 'email=' . urlencode($email);
echo TypechoPaid_Plugin::renderPager($currentPage, $pageCount, $options->adminUrl('extending.php?' . implode('&', $pagerParams), true));
?>
            </div>
        </div>
    </div>
</div>
<?php
include 'copyright.php';
include 'common-js.php';
include 'table-js.php';
include 'footer.php';
