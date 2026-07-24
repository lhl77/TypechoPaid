<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
require_once __DIR__ . '/AbstractDriver.php';

/**
 * 易支付驱动。
 * 配置：网关地址:商户 PID:商户 Key:支付类型（可选，默认 alipay）
 */
class TypechoPaid_Driver_Epay extends TypechoPaid_Driver_AbstractDriver
{
    public function createPayment(array $order, array $channel, $notifyUrl)
    {
        $gateway = $this->normalizeGateway($this->config($channel, 0));
        $pid = $this->config($channel, 1);
        $key = $this->config($channel, 2);
        $type = $this->config($channel, 3, 'alipay');
        if ($gateway === '' || $pid === '' || $key === '') {
            throw new RuntimeException('易支付缺少网关地址、PID 或 Key 配置');
        }

        // 获取用户 IP（部分网关要求 clientip 不能为空）
        $clientIp = isset($order['ip']) ? (string)$order['ip'] : '';
        if ($clientIp === '') {
            // 尝试从常见的代理头获取 IP
            if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
                $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $clientIp = trim($ips[0]);
            } elseif (!empty($_SERVER['HTTP_CLIENT_IP'])) {
                $clientIp = $_SERVER['HTTP_CLIENT_IP'];
            } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
                $clientIp = $_SERVER['REMOTE_ADDR'];
            }
        }
        // 最终兜底：确保 IP 不为空
        if ($clientIp === '') {
            $clientIp = '127.0.0.1';
        }

        $payload = array(
            'pid' => $pid,
            'type' => $type,
            'out_trade_no' => $order['trade_no'],
            'name' => $order['title'],
            'money' => number_format((float)$order['price'], 2, '.', ''),
            'clientip' => $clientIp,
            'notify_url' => $notifyUrl,
            'return_url' => $notifyUrl
        );
        $payload['sign'] = $this->sign($payload, $key);
        $payload['sign_type'] = 'MD5';

        // 第一步：POST 到 mapi.php 在网关创建订单
        // 参照 WHMCS epay 插件逻辑：先调用网关创建订单，获取 payurl/qrcode/urlscheme
        $apiUrl = $this->buildApiUrl($gateway);
        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            )
        ));
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_errno($ch);
        $curlErrMsg = curl_error($ch);
        curl_close($ch);

        // 第二步：检查请求是否成功
        if ($curlErr || $raw === false) {
            throw new RuntimeException('易支付网关请求失败: ' . ($curlErrMsg ?: '网络错误'));
        }

        if ($httpCode < 200 || $httpCode >= 400) {
            throw new RuntimeException('易支付网关返回 HTTP ' . $httpCode);
        }

        // 第三步：解析网关响应
        $result = $this->parseApiResponse($raw);
        if (!is_array($result)) {
            throw new RuntimeException('易支付网关返回格式异常，无法解析');
        }

        // 第四步：检查业务状态码
        $code = isset($result['code']) ? (string)$result['code'] : '';
        $msg = isset($result['msg']) ? (string)$result['msg'] : '';
        
        // code=1 表示成功；部分网关 code=0 也表示成功
        if ($code !== '1' && $code !== '0') {
            throw new RuntimeException('易支付网关下单失败: ' . ($msg !== '' ? $msg : 'code=' . $code));
        }

        // 第五步：提取支付链接（订单已在网关创建）
        $payUrl = isset($result['payurl']) ? trim((string)$result['payurl']) : '';
        $qrUrl = isset($result['qrcode']) ? trim((string)$result['qrcode']) : '';
        $urlScheme = isset($result['urlscheme']) ? trim((string)$result['urlscheme']) : '';

        // 必须至少有一个支付链接
        if ($payUrl === '' && $qrUrl === '' && $urlScheme === '') {
            throw new RuntimeException('易支付网关未返回支付链接' . ($msg !== '' ? ': ' . $msg : ''));
        }

        // 验证：确保返回的不是 submit.php URL（这种 URL 会在访问时才创建订单）
        // 正确的流程应该是：mapi.php 已创建订单，返回的是预创建的支付链接
        if ($payUrl !== '' && strpos($payUrl, 'submit.php') !== false) {
            throw new RuntimeException('易支付网关返回了 submit.php URL，说明订单未在网关预创建');
        }

        // 构建返回数据
        $response = array(
            'payload' => $payload,
            'qr' => true
        );

        // pay_url：优先 payurl，其次 qrcode，最后 urlscheme
        if ($payUrl !== '') {
            $response['pay_url'] = $payUrl;
        } elseif ($qrUrl !== '') {
            $response['pay_url'] = $qrUrl;
        } else {
            $response['pay_url'] = $urlScheme;
        }

        // qr_url：网关生成的二维码图片地址，前端直接 <img src> 展示
        if ($qrUrl !== '') {
            $response['qr_url'] = $qrUrl;
        }

        return $response;
    }

    public function verifyNotify(array $request, array $channel)
    {
        // 易支付 v1 仅使用 MD5 签名，拒绝其他签名类型
        $signType = isset($request['sign_type']) ? strtoupper(trim((string)$request['sign_type'])) : '';
        if ($signType !== '' && $signType !== 'MD5') {
            return false;
        }
        return $this->isPaid($request) && $this->verifyMd5($request, $this->config($channel, 2));
    }

    private function sign(array $payload, $key)
    {
        ksort($payload);
        $pairs = array();
        foreach ($payload as $name => $value) {
            if ($value !== '' && !is_array($value)) {
                $pairs[] = $name . '=' . $value;
            }
        }
        return md5(implode('&', $pairs) . $key);
    }

    private function normalizeGateway($domain)
    {
        $domain = trim((string)$domain);
        if ($domain === '') {
            return '';
        }
        // 去掉用户可能在末尾误填的 /submit.php、/mapi.php、/api.php 等路径
        $domain = preg_replace('#/(?:submit|mapi|api)\.php$#i', '', rtrim($domain, '/'));
        return $domain;
    }

    private function buildApiUrl($gateway)
    {
        // 构建 mapi.php 接口地址，用于 POST 创建订单并获取支付链接和二维码
        return rtrim($gateway, '/') . '/mapi.php';
    }

    private function parseApiResponse($raw)
    {
        // 尝试 JSON 解析
        $result = json_decode($raw, true);
        if (is_array($result)) {
            return $result;
        }

        // 尝试 form-encoded 格式（部分网关返回 key=value&key2=value2）
        if (strpos($raw, '=') !== false && strpos($raw, '&') !== false) {
            parse_str($raw, $parsed);
            if (is_array($parsed) && !empty($parsed)) {
                return $parsed;
            }
        }

        return null;
    }
}
