<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * 极简 SMTP 客户端，支持 SSL、STARTTLS 及 AUTH LOGIN。
 */
class TypechoPaid_Smtp
{
    private $host;
    private $port;
    private $encryption;
    private $username;
    private $password;
    private $socket;

    public function __construct($host, $port, $encryption, $username, $password)
    {
        $this->host = trim((string)$host);
        $this->port = $port > 0 ? intval($port) : 465;
        $this->encryption = in_array($encryption, array('ssl', 'tls', 'none')) ? $encryption : 'ssl';
        $this->username = (string)$username;
        $this->password = (string)$password;
    }

    public function send($from, $fromName, $to, $subject, $html)
    {
        if (!filter_var($from, FILTER_VALIDATE_EMAIL) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('SMTP 邮箱地址无效');
        }
        $remote = ($this->encryption === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $errno = 0;
        $error = '';
        $this->socket = stream_socket_client($remote, $errno, $error, 12, STREAM_CLIENT_CONNECT);
        if (!$this->socket) {
            throw new RuntimeException('SMTP 连接失败');
        }
        stream_set_timeout($this->socket, 12);
        try {
            $this->expect(array(220));
            $this->command('EHLO localhost', array(250));
            if ($this->encryption === 'tls') {
                $this->command('STARTTLS', array(220));
                if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('SMTP TLS 启动失败');
                }
                $this->command('EHLO localhost', array(250));
            }
            if ($this->username !== '') {
                $this->command('AUTH LOGIN', array(334));
                $this->command(base64_encode($this->username), array(334));
                $this->command(base64_encode($this->password), array(235));
            }
            $this->command('MAIL FROM:<' . $from . '>', array(250));
            $this->command('RCPT TO:<' . $to . '>', array(250, 251));
            $this->command('DATA', array(354));
            $headers = array(
                'From: ' . $this->mimeHeader($fromName) . ' <' . $from . '>',
                'To: <' . $to . '>',
                'Subject: ' . $this->mimeHeader($subject),
                'MIME-Version: 1.0',
                'Content-Type: text/html; charset=UTF-8',
                'Content-Transfer-Encoding: base64'
            );
            $body = chunk_split(base64_encode($html), 76, "\r\n");
            $data = implode("\r\n", $headers) . "\r\n\r\n" . $body;
            $data = preg_replace('/^\./m', '..', $data);
            $this->command($data . "\r\n.", array(250));
            $this->command('QUIT', array(221));
            fclose($this->socket);
            $this->socket = null;
            return true;
        } catch (Exception $e) {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
            throw $e;
        }
    }

    private function command($command, array $expected)
    {
        fwrite($this->socket, $command . "\r\n");
        $this->expect($expected);
    }

    private function expect(array $expected)
    {
        $response = '';
        do {
            $line = fgets($this->socket, 515);
            if ($line === false) {
                throw new RuntimeException('SMTP 服务器无响应');
            }
            $response .= $line;
        } while (isset($line[3]) && $line[3] === '-');
        $code = intval(substr($response, 0, 3));
        if (!in_array($code, $expected)) {
            throw new RuntimeException('SMTP 服务器拒绝请求（' . $code . '）');
        }
    }

    private function mimeHeader($value)
    {
        $value = trim((string)$value);
        return $value === '' ? '' : '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
