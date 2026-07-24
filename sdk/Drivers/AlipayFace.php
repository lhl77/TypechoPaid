<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
require_once __DIR__ . '/AbstractDriver.php';

/**
 * 支付宝当面付驱动。
 * 配置：网关地址:AppId:通知验签 Key
 */
class TypechoPaid_Driver_AlipayFace extends TypechoPaid_Driver_AbstractDriver
{
    public function createPayment(array $order, array $channel, $notifyUrl)
    {
        $gateway = $this->config($channel, 0);
        $appId = $this->config($channel, 1);
        if ($gateway === '') {
            throw new RuntimeException('支付宝当面付缺少网关地址配置');
        }

        $payload = array(
            'app_id' => $appId,
            'out_trade_no' => $order['trade_no'],
            'subject' => $order['title'],
            'total_amount' => number_format((float)$order['price'], 2, '.', ''),
            'notify_url' => $notifyUrl,
            'channel' => 'alipay_face'
        );

        return array('pay_url' => $this->appendQuery($gateway, $payload), 'payload' => $payload, 'qr' => true);
    }

    public function verifyNotify(array $request, array $channel)
    {
        return $this->isPaid($request) && $this->verifyMd5($request, $this->config($channel, 2));
    }
}
