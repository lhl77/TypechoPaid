<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
require_once __DIR__ . '/sdk/PaymentFactory.php';

class TypechoPaid_Action extends Widget_Abstract_Contents implements Widget_Interface_Do
{
    public function action()
    {
    }

    public function dispatch()
    {
        $do = isset($this->request->do) ? trim($this->request->do) : '';

        if ($do === 'create') {
            return $this->createOrder();
        }

        if ($do === 'unlock') {
            return $this->unlockContent();
        }

        if ($do === 'notify') {
            return $this->notifyPaid();
        }

        if ($do === 'status') {
            return $this->checkStatus();
        }

        if ($do === 'subscribe') {
            return $this->createSubscribe();
        }

        $this->response->throwJson(array('success' => 0, 'msg' => '无效请求'));
    }

    private function createOrder()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->response->throwJson(array('success' => 0, 'msg' => '请求方法不正确'));
        }

        $cid = intval($this->request->cid);
        $email = trim((string)$this->request->email);
        $visitPassword = trim((string)$this->request->visit_password);
        $channel = strtolower(trim((string)$this->request->channel));
        $planKey = trim((string)$this->request->plan_key);

        if ($planKey !== '') {
            return $this->createSubscribe();
        }

        if ($cid <= 0) {
            $this->response->throwJson(array('success' => 0, 'msg' => '文章参数错误'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->response->throwJson(array('success' => 0, 'msg' => '邮箱格式不正确'));
        }

        $channels = TypechoPaid_Plugin::getPaymentChannels();
        if (!isset($channels[$channel])) {
            $this->response->throwJson(array('success' => 0, 'msg' => '支付方式不存在或尚未配置'));
        }

        $content = $this->db->fetchRow($this->select()->where('table.contents.cid = ?', $cid));
        if (empty($content)) {
            $this->response->throwJson(array('success' => 0, 'msg' => '文章不存在'));
        }

        $fields = $this->fetchContentFields($cid);
        if (!isset($fields['paid_enable']) || intval($fields['paid_enable']) !== 1) {
            $this->response->throwJson(array('success' => 0, 'msg' => '该文章未开启付费阅读'));
        }

        $methods = TypechoPaid_Plugin::normalizeMethods(isset($fields['paid_methods']) ? $fields['paid_methods'] : '');
        if (empty($methods)) {
            $methods = array_keys($channels);
        }
        // 展开简写驱动名到复合 key（如 epay → epay:alipay, epay:wxpay），与 filterContent 保持一致
        $expandedMethods = array();
        foreach ($methods as $method) {
            if (isset($channels[$method])) {
                $expandedMethods[] = $method;
            } else {
                $prefix = $method . ':';
                foreach (array_keys($channels) as $key) {
                    if (strpos($key, $prefix) === 0) {
                        $expandedMethods[] = $key;
                    }
                }
            }
        }
        $methods = array_values(array_unique($expandedMethods));
        if (!empty($methods) && !in_array($channel, $methods, true)) {
            $this->response->throwJson(array('success' => 0, 'msg' => '该文章未启用此支付渠道'));
        }

        $ip = $this->clientIp();
        $turnstileEnabled = intval(TypechoPaid_Plugin::getOption('turnstile_enable', '0')) === 1;
        $turnstileSiteKey = trim((string)TypechoPaid_Plugin::getOption('turnstile_site_key', ''));
        if ($turnstileEnabled && $turnstileSiteKey !== '') {
            $threshold = intval(TypechoPaid_Plugin::getOption('turnstile_daily_threshold', '3'));
            if ($threshold <= 0) {
                $threshold = 3;
            }
            if (TypechoPaid_Plugin::dailyIpOrderCount($ip) >= $threshold) {
                $token = trim((string)$this->request->get('cf-turnstile-response'));
                if (!$this->verifyTurnstile($token, $ip)) {
                    $this->response->throwJson(array('success' => 0, 'msg' => '安全验证未通过，请完成验证后重试'));
                }
            }
        }

        $price = TypechoPaid_Plugin::normalizePrice(isset($fields['paid_price']) ? $fields['paid_price'] : 0);
        $tradeNo = $this->buildTradeNo($cid);
        $now = time();

        $row = array(
            'cid' => $cid,
            'title' => (string)$content['title'],
            'email' => $email,
            'price' => $price,
            'channel' => $channel,
            'trade_no' => $tradeNo,
            'visit_password' => $visitPassword === '' ? '' : password_hash($visitPassword, PASSWORD_DEFAULT),
            'status' => 'pending',
            'pay_url' => '',
            'notify_payload' => '',
            'ip' => $ip,
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'created' => $now,
            'paid_at' => 0
        );

        $qrCapable = false;
        try {
            $driver = TypechoPaid_PaymentFactory::create($channels[$channel]['driver']);
            $notifyUrl = Typecho_Common::url('typechopaid/notify?channel=' . rawurlencode($channel), $this->options->index);
            $payment = $driver->createPayment($row, $channels[$channel], $notifyUrl);
            $row['pay_url'] = isset($payment['pay_url']) ? (string)$payment['pay_url'] : '';
            $row['notify_payload'] = isset($payment['payload']) ? json_encode($payment['payload']) : '';
            $qrCapable = !empty($payment['qr']);
            $qrUrl = isset($payment['qr_url']) ? (string)$payment['qr_url'] : '';
        } catch (Exception $e) {
            $this->response->throwJson(array('success' => 0, 'msg' => '支付通道配置错误：' . $e->getMessage()));
        }

        $this->db->query($this->db->insert('table.' . TypechoPaid_Plugin::tableName())->rows($row), Typecho_Db::WRITE);

        $autoPaid = intval(TypechoPaid_Plugin::getOption('sandbox_auto_paid', '0')) === 1;
        if ($autoPaid) {
            $paidNow = $this->markOrderPaid($tradeNo, 'sandbox:auto-paid');
            TypechoPaid_Plugin::setUnlockCookie($cid);
            if ($paidNow) {
                $this->sendPurchaseNotification($tradeNo);
            }
        }

        $response = array(
            'success' => 1,
            'trade_no' => $tradeNo,
            'pay_url' => $row['pay_url'],
            'qr' => ($qrCapable && $row['pay_url'] !== '') ? 1 : 0,
            'auto_paid' => $autoPaid ? 1 : 0,
            'msg' => '下单成功'
        );
        if ($qrUrl !== '') {
            $response['qr_url'] = $qrUrl;
        }
        $this->response->throwJson($response);
    }

    private function createSubscribe()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->response->throwJson(array('success' => 0, 'msg' => '请求方法不正确'));
        }

        $cid = intval($this->request->cid);
        $email = trim((string)$this->request->email);
        $visitPassword = trim((string)$this->request->visit_password);
        $channel = strtolower(trim((string)$this->request->channel));
        $planKey = trim((string)$this->request->plan_key);

        $plan = TypechoPaid_Plugin::getPlanByKey($planKey);
        if ($plan === null) {
            $this->response->throwJson(array('success' => 0, 'msg' => '订阅计划不存在或已下线'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->response->throwJson(array('success' => 0, 'msg' => '邮箱格式不正确'));
        }

        $channels = TypechoPaid_Plugin::getPaymentChannels();
        if (!isset($channels[$channel])) {
            $this->response->throwJson(array('success' => 0, 'msg' => '支付方式不存在或尚未配置'));
        }

        $ip = $this->clientIp();
        $turnstileEnabled = intval(TypechoPaid_Plugin::getOption('turnstile_enable', '0')) === 1;
        $turnstileSiteKey = trim((string)TypechoPaid_Plugin::getOption('turnstile_site_key', ''));
        if ($turnstileEnabled && $turnstileSiteKey !== '') {
            $threshold = intval(TypechoPaid_Plugin::getOption('turnstile_daily_threshold', '3'));
            if ($threshold <= 0) {
                $threshold = 3;
            }
            if (TypechoPaid_Plugin::dailyIpOrderCount($ip) >= $threshold) {
                $token = trim((string)$this->request->get('cf-turnstile-response'));
                if (!$this->verifyTurnstile($token, $ip)) {
                    $this->response->throwJson(array('success' => 0, 'msg' => '安全验证未通过，请完成验证后重试'));
                }
            }
        }

        $price = TypechoPaid_Plugin::normalizePrice($plan['price']);
        $tradeNo = $this->buildTradeNo($cid > 0 ? $cid : mt_rand(1, 99999));
        $now = time();

        $title = '订阅：' . $plan['name'];
        if ($cid > 0) {
            $content = $this->db->fetchRow($this->select()->where('table.contents.cid = ?', $cid));
            if (!empty($content)) {
                $title .= ' - ' . $content['title'];
            }
        }

        $row = array(
            'cid' => $cid > 0 ? $cid : 0,
            'title' => $title,
            'email' => $email,
            'price' => $price,
            'channel' => $channel,
            'trade_no' => $tradeNo,
            'visit_password' => $visitPassword === '' ? '' : password_hash($visitPassword, PASSWORD_DEFAULT),
            'status' => 'pending',
            'pay_url' => '',
            'notify_payload' => '',
            'ip' => $ip,
            'ua' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : '',
            'plan_id' => $planKey,
            'expires_at' => $now + ($plan['duration_days'] * 86400),
            'created' => $now,
            'paid_at' => 0
        );

        $qrCapable = false;
        try {
            $driver = TypechoPaid_PaymentFactory::create($channels[$channel]['driver']);
            $notifyUrl = Typecho_Common::url('typechopaid/notify?channel=' . rawurlencode($channel), $this->options->index);
            $payment = $driver->createPayment($row, $channels[$channel], $notifyUrl);
            $row['pay_url'] = isset($payment['pay_url']) ? (string)$payment['pay_url'] : '';
            $row['notify_payload'] = isset($payment['payload']) ? json_encode($payment['payload']) : '';
            $qrCapable = !empty($payment['qr']);
            $qrUrl = isset($payment['qr_url']) ? (string)$payment['qr_url'] : '';
        } catch (Exception $e) {
            $this->response->throwJson(array('success' => 0, 'msg' => '支付通道配置错误：' . $e->getMessage()));
        }

        $this->db->query($this->db->insert('table.' . TypechoPaid_Plugin::tableName())->rows($row), Typecho_Db::WRITE);

        $autoPaid = intval(TypechoPaid_Plugin::getOption('sandbox_auto_paid', '0')) === 1;
        if ($autoPaid) {
            $paidNow = $this->markOrderPaid($tradeNo, 'sandbox:auto-paid');
            TypechoPaid_Plugin::setSubscriptionCookieUntil($planKey, $row['expires_at']);
            if ($paidNow) {
                $this->sendPurchaseNotification($tradeNo);
            }
        }

        $response = array(
            'success' => 1,
            'trade_no' => $tradeNo,
            'pay_url' => $row['pay_url'],
            'qr' => ($qrCapable && $row['pay_url'] !== '') ? 1 : 0,
            'auto_paid' => $autoPaid ? 1 : 0,
            'msg' => '下单成功'
        );
        if ($qrUrl !== '') {
            $response['qr_url'] = $qrUrl;
        }
        $this->response->throwJson($response);
    }

    private function checkStatus()
    {
        $tradeNo = trim((string)$this->request->get('trade_no'));
        if ($tradeNo === '') {
            $this->response->throwJson(array('success' => 0, 'msg' => '缺少订单号'));
        }

        $order = $this->db->fetchRow(
            $this->db->select()->from('table.' . TypechoPaid_Plugin::tableName())->where('trade_no = ?', $tradeNo)
        );
        if (empty($order)) {
            $this->response->throwJson(array('success' => 0, 'msg' => '订单不存在'));
        }

        if ($order['status'] === 'paid') {
            if (!empty($order['plan_id']) && !empty($order['expires_at'])) {
                TypechoPaid_Plugin::setSubscriptionCookieUntil($order['plan_id'], intval($order['expires_at']));
            } else {
                TypechoPaid_Plugin::setUnlockCookie(intval($order['cid']));
            }
            $this->response->throwJson(array('success' => 1, 'status' => 'paid'));
        }

        $this->response->throwJson(array('success' => 1, 'status' => 'pending'));
    }

    private function verifyTurnstile($token, $ip)
    {
        $secret = trim((string)TypechoPaid_Plugin::getOption('turnstile_secret_key', ''));
        if ($secret === '' || $token === '') {
            return false;
        }

        $payload = http_build_query(array(
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $ip
        ));

        $ch = curl_init('https://challenges.cloudflare.com/turnstile/v0/siteverify');
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true
        ));
        $response = curl_exec($ch);
        $error = curl_errno($ch);
        curl_close($ch);

        if ($error || $response === false) {
            return false;
        }

        $data = json_decode($response, true);
        return is_array($data) && !empty($data['success']);
    }

    private function unlockContent()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->response->throwJson(array('success' => 0, 'msg' => '请求方法不正确'));
        }

        $cid = intval($this->request->cid);
        $email = trim((string)$this->request->email);
        $credential = trim((string)$this->request->credential);
        if ($credential === '') {
            $credential = trim((string)$this->request->trade_no);
        }
        if ($credential === '') {
            $credential = trim((string)$this->request->visit_password);
        }

        if ($cid <= 0) {
            $this->response->throwJson(array('success' => 0, 'msg' => '文章参数错误'));
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->response->throwJson(array('success' => 0, 'msg' => '邮箱格式不正确'));
        }

        if ($credential === '' && !TypechoPaid_Plugin::isUnlockedByCookie($cid)) {
            $this->response->throwJson(array('success' => 0, 'msg' => '未设置访问密码时，请填写商户订单号'));
        }

        $select = $this->db->select()->from('table.' . TypechoPaid_Plugin::tableName())
            ->where('cid = ?', $cid)
            ->where('email = ?', $email)
            ->where('status = ?', 'paid')
            ->order('id', Typecho_Db::SORT_DESC);

        $orders = $this->db->fetchAll($select);
        if (empty($orders)) {
            $this->response->throwJson(array('success' => 0, 'msg' => '没有找到已支付订单'));
        }

        if ($credential === '' && TypechoPaid_Plugin::isUnlockedByCookie($cid)) {
            $this->response->throwJson(array('success' => 1, 'msg' => '验证成功'));
        }

        $passed = false;
        foreach ($orders as $order) {
            if ($credential !== '' && hash_equals((string)$order['trade_no'], $credential)) {
                $passed = true;
                break;
            }

            if ($credential !== '' && !empty($order['visit_password']) && password_verify($credential, $order['visit_password'])) {
                $passed = true;
                break;
            }
        }

        if (!$passed) {
            $this->response->throwJson(array('success' => 0, 'msg' => '订单校验失败，请检查订单号或访问密码'));
        }

        TypechoPaid_Plugin::setUnlockCookie($cid);
        $this->response->throwJson(array('success' => 1, 'msg' => '验证成功'));
    }

    private function notifyPaid()
    {
        $channel = strtolower(trim((string)$this->request->channel));
        $tradeNo = trim((string)$this->request->out_trade_no);
        if ($tradeNo === '') {
            $tradeNo = trim((string)$this->request->trade_no);
        }
        $channels = TypechoPaid_Plugin::getPaymentChannels();

        // 某些网关不会保留 notify_url 的 query 参数，回调里可能没有 channel。
        if ($channel === '' && $tradeNo !== '') {
            $orderByTradeNo = $this->db->fetchRow(
                $this->db->select()->from('table.' . TypechoPaid_Plugin::tableName())->where('trade_no = ?', $tradeNo)
            );
            if (!empty($orderByTradeNo['channel'])) {
                $channel = strtolower((string)$orderByTradeNo['channel']);
            }
        }

        if (!isset($channels[$channel])) {
            $this->response->throwJson(array('success' => 0, 'msg' => 'channel error'));
        }

        if ($tradeNo === '') {
            $this->response->throwJson(array('success' => 0, 'msg' => 'trade_no required'));
        }

        try {
            $driver = TypechoPaid_PaymentFactory::create($channels[$channel]['driver']);
            // 仅使用 POST 数据验签，避免 Cookie 污染 $_REQUEST
            if (!$driver->verifyNotify($_POST, $channels[$channel])) {
                $this->response->throwJson(array('success' => 0, 'msg' => 'invalid payment notification'));
            }
        } catch (Exception $e) {
            $this->response->throwJson(array('success' => 0, 'msg' => 'payment driver error'));
        }

        // 校验支付金额（防篡改）
        $order = $this->db->fetchRow($this->db->select()->from('table.' . TypechoPaid_Plugin::tableName())->where('trade_no = ?', $tradeNo));
        if (empty($order)) {
            $this->response->throwJson(array('success' => 0, 'msg' => 'order not found'));
        }
        $orderPrice = floatval($order['price']);
        $notifyAmount = $this->extractNotifyAmount($_POST);
        if ($notifyAmount !== null && abs($notifyAmount - $orderPrice) > 0.005) {
            $this->response->throwJson(array('success' => 0, 'msg' => 'amount mismatch: expected ' . $orderPrice . ', got ' . $notifyAmount));
        }

        // 仅存储 POST 数据，避免 Cookie 等敏感信息入库
        $paidNow = $this->markOrderPaid($tradeNo, json_encode($_POST));
        if ($paidNow === false) {
            $this->response->throwJson(array('success' => 0, 'msg' => 'order not found'));
        }

        // 仅真正的首次支付成功才设置 Cookie 和发送通知（防止重放刷新 Cookie）
        if ($paidNow === true) {
            if (!empty($order['plan_id']) && !empty($order['expires_at'])) {
                TypechoPaid_Plugin::setSubscriptionCookieUntil($order['plan_id'], intval($order['expires_at']));
            } elseif (!empty($order['cid'])) {
                TypechoPaid_Plugin::setUnlockCookie(intval($order['cid']));
            }
            $this->sendPurchaseNotification($tradeNo);
        }

        $this->response->throwJson(array('success' => 1, 'msg' => 'ok'));
    }

    /**
     * 从通知参数中提取支付金额（兼容各网关字段名）
     * @return float|null null 表示无法提取金额（不强制拦截）
     */
    private function extractNotifyAmount(array $post)
    {
        $map = array(
            'money'        => 1.0,   // 易支付：元
            'total_amount' => 1.0,   // 支付宝：元
            'amount'       => 1.0,   // 通用：元
            'total_fee'    => 0.01,  // 微信：分 → 元
        );
        foreach ($map as $key => $factor) {
            if (isset($post[$key]) && is_numeric($post[$key])) {
                return round(floatval($post[$key]) * $factor, 2);
            }
        }
        return null;
    }

    private function markOrderPaid($tradeNo, $payload)
    {
        $order = $this->db->fetchRow($this->db->select()->from('table.' . TypechoPaid_Plugin::tableName())->where('trade_no = ?', $tradeNo));
        if (empty($order)) {
            return false;
        }

        if ($order['status'] === 'paid') {
            return null;
        }

        $this->db->query(
            $this->db->update('table.' . TypechoPaid_Plugin::tableName())
                ->rows(array(
                    'status' => 'paid',
                    'paid_at' => time(),
                    'notify_payload' => is_scalar($payload) ? (string)$payload : json_encode($payload)
                ))
                ->where('trade_no = ?', $tradeNo),
            Typecho_Db::WRITE
        );

        return true;
    }

    private function sendPurchaseNotification($tradeNo)
    {
        $order = $this->db->fetchRow(
            $this->db->select()->from('table.' . TypechoPaid_Plugin::tableName())->where('trade_no = ?', $tradeNo)
        );
        if (!empty($order) && $order['status'] === 'paid') {
            TypechoPaid_Plugin::sendPurchaseNotification($order);
        }
    }

    private function buildTradeNo($cid)
    {
        return 'TP' . date('YmdHis') . $cid . mt_rand(1000, 9999);
    }

    private function clientIp()
    {
        return TypechoPaid_Plugin::clientIp();
    }

    private function fetchContentFields($cid)
    {
        $rows = $this->db->fetchAll(
            $this->db->select()->from('table.fields')->where('cid = ?', intval($cid))
        );

        $fields = array();
        foreach ($rows as $row) {
            $type = isset($row['type']) ? $row['type'] : 'str';
            if ($type === 'int') {
                $value = isset($row['int_value']) ? $row['int_value'] : 0;
            } elseif ($type === 'float') {
                $value = isset($row['float_value']) ? $row['float_value'] : 0;
            } else {
                $value = isset($row['str_value']) ? $row['str_value'] : '';
            }

            $fields[$row['name']] = $value;
        }

        return $fields;
    }
}
