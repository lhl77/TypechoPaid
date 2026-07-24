<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

abstract class TypechoPaid_Driver_AbstractDriver implements TypechoPaid_PaymentInterface
{
    protected function config(array $channel, $index, $default = '')
    {
        return isset($channel['configs'][$index]) ? trim((string)$channel['configs'][$index]) : $default;
    }

    protected function appendQuery($url, array $params)
    {
        $join = strpos($url, '?') === false ? '?' : '&';
        return $url . $join . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    protected function isPaid(array $request)
    {
        $status = strtolower(trim((string)(isset($request['status']) ? $request['status'] : '')));
        $tradeStatus = strtoupper(trim((string)(isset($request['trade_status']) ? $request['trade_status'] : '')));
        return in_array($status, array('paid', 'success', 'trade_success'))
            || in_array($tradeStatus, array('TRADE_SUCCESS', 'TRADE_FINISHED'));
    }

    protected function verifyMd5(array $request, $key)
    {
        if ($key === '') {
            return false;
        }

        $sign = isset($request['sign']) ? (string)$request['sign'] : '';
        if ($sign === '') {
            return false;
        }

        // channel/do 是 TypechoPaid 路由参数，不是第三方网关参与签名的业务参数。
        unset($request['sign'], $request['sign_type'], $request['channel'], $request['do']);
        ksort($request);
        $pairs = array();
        foreach ($request as $name => $value) {
            if ($value !== '' && !is_array($value)) {
                $pairs[] = $name . '=' . $value;
            }
        }

        return hash_equals(md5(implode('&', $pairs) . $key), $sign);
    }
}
