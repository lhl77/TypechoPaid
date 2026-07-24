<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

require_once __DIR__ . '/PaymentInterface.php';

/** 支付驱动加载器。新增通道时只需在 sdk/Drivers/ 新建实现类并在此登记。 */
class TypechoPaid_PaymentFactory
{
    public static function create($driver)
    {
        $driver = strtolower(trim((string)$driver));
        $map = array(
            'alipay_face' => 'AlipayFace',
            'wechat' => 'Wechat',
            'epay' => 'Epay'
        );

        if (!isset($map[$driver])) {
            throw new InvalidArgumentException('不支持的支付驱动：' . $driver);
        }

        $class = 'TypechoPaid_Driver_' . $map[$driver];
        $file = __DIR__ . '/Drivers/' . $map[$driver] . '.php';
        if (!class_exists($class, false)) {
            require_once $file;
        }

        return new $class();
    }
}
