<?php
class_exists('Typecho_Widget') or die('This file can not be loaded directly.');
include 'header.php';
include 'menu.php';

$db = Typecho_Db::get();
$options = Typecho_Widget::widget('Widget_Options');
$pluginOpts = $options->plugin('TypechoPaid');

$pageSize = 20;
$currentPage = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$filterPlan = isset($_GET['plan']) ? trim($_GET['plan']) : '';
$filterPaid = isset($_GET['paid']) ? trim($_GET['paid']) : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$plans = TypechoPaid_Plugin::getSubscriptionPlans();
$planNames = array();
$planActiveCounts = array();
$now = time();
if (!empty($plans)) {
    foreach (array_keys($plans) as $pk) {
        $planNames[$pk] = $plans[$pk]['name'];
        // 统计该计划当前有效的订阅数
        $countSel = $db->select(array('COUNT(*)' => 'num'))
            ->from('table.' . TypechoPaid_Plugin::tableName())
            ->where('plan_id = ?', $pk)
            ->where('status = ?', 'paid')
            ->where('paid_at > ?', 0)
            ->where('expires_at > ?', $now);
        $planActiveCounts[$pk] = intval($db->fetchObject($countSel)->num);
    }
}

// 处理表单提交
$saved = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['save_articles'])) {
    $updates = isset($_POST['art']) && is_array($_POST['art']) ? $_POST['art'] : array();
    foreach ($updates as $cid => $data) {
        $cid = intval($cid);
        if ($cid <= 0) continue;

        $enable = isset($data['paid_enable']) ? intval($data['paid_enable']) : 0;
        $price = isset($data['paid_price']) ? floatval($data['paid_price']) : 0;
        $plan = isset($data['paid_plan']) ? trim($data['paid_plan']) : '';
        $methods = isset($data['paid_methods']) ? trim($data['paid_methods']) : '';

        $existing = $db->fetchAll($db->select()->from('table.fields')->where('cid = ?', $cid));
        $fieldMap = array();
        foreach ($existing as $f) {
            $fieldMap[$f['name']] = $f;
        }

        $toUpdate = array(
            'paid_enable' => array('type' => 'int', 'value' => $enable),
            'paid_price' => array('type' => 'float', 'value' => $price),
            'paid_plan' => array('type' => 'str', 'value' => $plan),
            'paid_methods' => array('type' => 'str', 'value' => $methods),
        );

        foreach ($toUpdate as $name => $info) {
            $type = $info['type'];
            $value = $info['value'];
            if (isset($fieldMap[$name])) {
                // update
                $row = array();
                if ($type === 'int') {
                    $row['int_value'] = intval($value);
                    $row['str_value'] = (string)intval($value);
                } elseif ($type === 'float') {
                    $row['float_value'] = floatval($value);
                    $row['str_value'] = (string)floatval($value);
                } else {
                    $row['str_value'] = (string)$value;
                }
                $db->query($db->update('table.fields')->rows($row)->where('cid = ?', $cid)->where('name = ?', $name), Typecho_Db::WRITE);
            } else {
                // insert
                $row = array(
                    'cid' => $cid,
                    'name' => $name,
                    'type' => $type,
                    'str_value' => '',
                    'int_value' => 0,
                    'float_value' => 0,
                );
                if ($type === 'int') {
                    $row['int_value'] = intval($value);
                    $row['str_value'] = (string)intval($value);
                } elseif ($type === 'float') {
                    $row['float_value'] = floatval($value);
                    $row['str_value'] = (string)floatval($value);
                } else {
                    $row['str_value'] = (string)$value;
                }
                $db->query($db->insert('table.fields')->rows($row), Typecho_Db::WRITE);
            }
        }
    }
    $saved = true;
}

// 查询文章列表
$baseSelect = $db->select()
    ->from('table.contents')
    ->where('type = ?', 'post')
    ->order('created', Typecho_Db::SORT_DESC);

$countSelect = $db->select('COUNT(*)')->from('table.contents')->where('type = ?', 'post');
$total = $db->fetchObject($countSelect)->{'COUNT(*)'};
$pageCount = max(1, intval(ceil($total / $pageSize)));
if ($currentPage > $pageCount) $currentPage = $pageCount;

$listSelect = $db->select()
    ->from('table.contents')
    ->where('type = ?', 'post')
    ->page($currentPage, $pageSize)
    ->order('created', Typecho_Db::SORT_DESC);

$posts = $db->fetchAll($listSelect);

// 批量获取所有文章的 fields
$cids = array();
foreach ($posts as $p) {
    $cids[] = intval($p['cid']);
}
$allFields = array();
if (!empty($cids)) {
    $in = implode(',', $cids);
    $fieldRows = $db->fetchAll($db->query("SELECT * FROM {$db->getPrefix()}fields WHERE cid IN ($in)"));
    foreach ($fieldRows as $fr) {
        $cid = intval($fr['cid']);
        if (!isset($allFields[$cid])) $allFields[$cid] = array();
        $type = $fr['type'];
        if ($type === 'int') {
            $allFields[$cid][$fr['name']] = isset($fr['int_value']) ? $fr['int_value'] : 0;
        } elseif ($type === 'float') {
            $allFields[$cid][$fr['name']] = isset($fr['float_value']) ? $fr['float_value'] : 0;
        } else {
            $allFields[$cid][$fr['name']] = isset($fr['str_value']) ? $fr['str_value'] : '';
        }
    }
}

function tpGet($fields, $key, $default = '') {
    return isset($fields[$key]) ? $fields[$key] : $default;
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
            .tp-manage-title { word-break:break-word; }
            .tp-manage-hint { color:#888; font-size:0.88rem; }
            .tp-manage-cell input.text-s,
            .tp-manage-cell select.text-s { max-width:100%; box-sizing:border-box; }
            .tp-manage-cell-plan input.text-s { min-width:120px; width:auto; max-width:180px; }
            .tp-manage-cell-methods input.text-s { min-width:90px; width:auto; max-width:140px; }
            /* 移动端适配 */
            @media (max-width:768px) {
                .tp-manage-operate { flex-direction:column; align-items:stretch; }
            }
            @media (max-width:480px) {
                .typecho-list-operate-form .search{flex-direction:column;gap:6px;}
                .typecho-list-operate-form .text-s,.typecho-list-operate-form select{max-width:100%;}
                .typecho-pager{font-size:11px;justify-content:center;}
                .typecho-pager li{margin:0 1px;}
                .typecho-pager a{padding:0 6px;}
            }
        </style>
        <div class="row typecho-page-main" role="main">
            <div class="col-mb-12 typecho-list">

                <?php if ($saved): ?>
                    <div class="message notice"><p><?php _e('设置已保存，自定义字段已同步更新。'); ?></p></div>
                <?php endif; ?>

                <form method="post" action="" id="tp-manage-form">
                <div class="typecho-list-operate clearfix">
                    <div class="operate" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
                        <button type="submit" name="save_articles" value="1" class="btn primary">保存所有修改</button>
                        <span class="tp-manage-hint">修改后务必点击保存，才会同步更新文章自定义字段。</span>
                    </div>
                </div>

                <div class="typecho-table-wrap" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                    <table class="typecho-list-table" style="min-width:700px;">
                        <colgroup>
                            <col style="min-width:68px;">
                            <col>
                            <col style="min-width:80px;">
                            <col style="min-width:75px;">
                            <col style="min-width:160px;">
                            <col style="min-width:110px;">
                        </colgroup>
                        <thead>
                        <tr>
                            <th>CID</th>
                            <th>标题</th>
                            <th>付费开关</th>
                            <th>价格</th>
                            <th>订阅计划</th>
                            <th>支付渠道</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($posts)): ?>
                            <?php foreach ($posts as $p):
                                $cid = intval($p['cid']);
                                $fields = isset($allFields[$cid]) ? $allFields[$cid] : array();
                                $paidEnable = tpGet($fields, 'paid_enable', '0');
                                $paidPrice = tpGet($fields, 'paid_price', '0');
                                $paidPlan = tpGet($fields, 'paid_plan', '');
                                $paidMethods = tpGet($fields, 'paid_methods', '');
                                ?>
                                <tr>
                                    <td><?php echo $cid; ?></td>
                                    <td class="tp-manage-title">
                                        <a href="<?php echo $options->adminUrl('write-post.php?cid=' . $cid); ?>" target="_blank" title="编辑文章">
                                            <?php echo htmlspecialchars($p['title']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <select name="art[<?php echo $cid; ?>][paid_enable]" class="text-s">
                                            <option value="0"<?php echo $paidEnable == '0' ? ' selected' : ''; ?>>关闭</option>
                                            <option value="1"<?php echo $paidEnable == '1' ? ' selected' : ''; ?>>开启</option>
                                        </select>
                                    </td>
                                    <td class="tp-manage-cell">
                                        <input type="number" step="0.01" min="0" name="art[<?php echo $cid; ?>][paid_price]"
                                               value="<?php echo htmlspecialchars($paidPrice); ?>" class="text-s" style="width:80px;">
                                    </td>
                                    <td class="tp-manage-cell tp-manage-cell-plan">
                                        <input type="text" name="art[<?php echo $cid; ?>][paid_plan]"
                                               value="<?php echo htmlspecialchars($paidPlan); ?>" class="text-s"
                                               placeholder="逗号分隔多个">
                                    </td>
                                    <td class="tp-manage-cell tp-manage-cell-methods">
                                        <input type="text" name="art[<?php echo $cid; ?>][paid_methods]"
                                               value="<?php echo htmlspecialchars($paidMethods); ?>" class="text-s"
                                               placeholder="默认留空">
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <tr><td colspan="6">暂无文章</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                </form>

<?php echo TypechoPaid_Plugin::renderPager($currentPage, $pageCount, $options->adminUrl('extending.php?panel=TypechoPaid%2FManage.php', true)); ?>

                <hr>

                <h4>订阅计划预览</h4>
                <?php if (empty($plans)): ?>
                    <p class="tp-manage-hint">尚未配置订阅计划，请在 <a href="<?php $options->adminUrl('options-plugin.php?config=TypechoPaid'); ?>">插件设置</a> 中添加。</p>
                <?php else: ?>
                    <div class="typecho-table-wrap" style="overflow-x:auto;-webkit-overflow-scrolling:touch;">
                    <table class="typecho-list-table" style="max-width:700px;min-width:480px;">
                        <thead>
                        <tr><th>标识</th><th>名称</th><th>价格</th><th>时长（天）</th><th>生效中</th><th>操作</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($plans as $pk => $pv): ?>
                            <tr>
                                <td><code><?php echo htmlspecialchars($pk); ?></code></td>
                                <td><?php echo htmlspecialchars($pv['name']); ?></td>
                                <td>￥<?php echo number_format($pv['price'], 2); ?></td>
                                <td><?php echo intval($pv['duration_days']); ?></td>
                                <td><strong><?php echo isset($planActiveCounts[$pk]) ? $planActiveCounts[$pk] : 0; ?></strong> 人</td>
                                <td><a href="<?php $options->adminUrl('extending.php?panel=TypechoPaid%2FSubscriptions.php&plan=' . urlencode($pk)); ?>" class="btn btn-xs">查看订阅</a></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                    <p class="tp-manage-hint">计划配置在 <a href="<?php $options->adminUrl('options-plugin.php?config=TypechoPaid'); ?>">插件设置 → 订阅计划</a> 中编辑。</p>
                <?php endif; ?>

            </div>
        </div>
    </div>
</div>
<?php
include 'copyright.php';
include 'common-js.php';
include 'table-js.php';
include 'footer.php';
?>
