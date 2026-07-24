<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 所有支付驱动必须实现的统一接口。
 */
interface TypechoPaid_PaymentInterface
{
    /**
     * 创建支付请求。
     *
     * @param array $order 当前订单
     * @param array $channel 已解析的通道配置
     * @param string $notifyUrl 异步通知地址
     * @return array{pay_url:string,payload:array}
     */
    public function createPayment(array $order, array $channel, $notifyUrl);

    /**
     * 验证第三方通知并返回是否可将订单标记为已支付。
     *
     * @param array $request 通知参数
     * @param array $channel 已解析的通道配置
     * @return bool
     */
    public function verifyNotify(array $request, array $channel);
}
