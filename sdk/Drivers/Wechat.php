<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;
require_once __DIR__ . '/AbstractDriver.php';

/**
 * 微信支付驱动。
 * 配置：网关地址:商户号:通知验签 Key
 */
class TypechoPaid_Driver_Wechat extends TypechoPaid_Driver_AbstractDriver
{
    public function createPayment(array $order, array $channel, $notifyUrl)
    {
        $gateway = $this->config($channel, 0);
        $merchantId = $this->config($channel, 1);
        if ($gateway === '') {
            throw new RuntimeException('微信支付缺少网关地址配置');
        }

        $payload = array(
            'mch_id' => $merchantId,
            'out_trade_no' => $order['trade_no'],
            'description' => $order['title'],
            'amount' => number_format((float)$order['price'], 2, '.', ''),
            'notify_url' => $notifyUrl,
            'channel' => 'wechat'
        );

        return array('pay_url' => $this->appendQuery($gateway, $payload), 'payload' => $payload, 'qr' => true);
    }

    public function verifyNotify(array $request, array $channel)
    {
        return $this->isPaid($request) && $this->verifyMd5($request, $this->config($channel, 2));
    }
}
