<?php
/**
 * 邮件发送服务
 * 使用 SMTP 协议通过 fsockopen 发送邮件（无需第三方库）
 */

namespace Core;

class Mailer
{
    private string $host;
    private int $port;
    private string $user;
    private string $pass;
    private string $from;
    private string $fromName;
    private string $encryption; // tls, ssl, none

    public function __construct(?array $config = null)
    {
        if ($config === null) {
            $config = self::loadConfig();
        }
        $this->host = $config['smtp_host'] ?? '';
        $this->port = (int)($config['smtp_port'] ?? 465);
        $this->user = $config['smtp_user'] ?? '';
        $this->pass = $config['smtp_pass'] ?? '';
        $this->from = $config['smtp_from'] ?? $this->user;
        $this->fromName = $config['smtp_from_name'] ?? 'AMuBBS';
        $this->encryption = $config['smtp_encryption'] ?? 'ssl';
    }

    /**
     * 从数据库加载 SMTP 配置
     */
    public static function loadConfig(): array
    {
        try {
            $rows = Database::fetchAll(
                "SELECT `key`, `value` FROM settings WHERE `key` LIKE 'smtp_%'"
            );
            $config = [];
            foreach ($rows as $r) {
                $config[$r['key']] = $r['value'];
            }
            return $config;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * 是否已配置
     */
    public function isConfigured(): bool
    {
        return !empty($this->host) && !empty($this->user) && !empty($this->pass);
    }

    /**
     * 发送邮件
     */
    public function send(string $to, string $subject, string $body, bool $isHtml = true): bool
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('邮件服务未配置');
        }

        // 防止邮件头注入
        if (preg_match('/[\r\n]/', $to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('无效的收件人地址');
        }
        if (preg_match('/[\r\n]/', $subject)) {
            throw new \RuntimeException('邮件主题包含非法字符');
        }

        $prefix = $this->encryption === 'ssl' ? 'ssl://' : '';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'allow_self_signed' => false,
            ],
        ]);
        $socket = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno, $errstr, 10,
            STREAM_CLIENT_CONNECT, $context
        );

        if (!$socket) {
            throw new \RuntimeException("SMTP 连接失败: {$errstr} ({$errno})");
        }

        stream_set_timeout($socket, 10);

        try {
            $this->readResponse($socket, 220);

            // 使用发件人域名作为 EHLO 标识，避免泄露内部主机名
            $ehloDomain = 'localhost';
            if (str_contains($this->from, '@')) {
                $ehloDomain = substr($this->from, strpos($this->from, '@') + 1);
            }

            $this->sendCommand($socket, "EHLO " . $ehloDomain, 250);

            // STARTTLS
            if ($this->encryption === 'tls') {
                $this->sendCommand($socket, "STARTTLS", 220);
                stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT);
                $this->sendCommand($socket, "EHLO " . $ehloDomain, 250);
            }

            // AUTH LOGIN
            $this->sendCommand($socket, "AUTH LOGIN", 334);
            $this->sendCommand($socket, base64_encode($this->user), 334);
            $this->sendCommand($socket, base64_encode($this->pass), 235);

            // MAIL FROM
            $this->sendCommand($socket, "MAIL FROM:<{$this->from}>", 250);

            // RCPT TO
            $this->sendCommand($socket, "RCPT TO:<{$to}>", 250);

            // DATA
            $this->sendCommand($socket, "DATA", 354);

            $contentType = $isHtml ? 'text/html' : 'text/plain';
            $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
            $encodedFrom = '=?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->from . '>';

            $message = "From: {$encodedFrom}\r\n";
            $message .= "To: <{$to}>\r\n";
            $message .= "Subject: {$encodedSubject}\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: {$contentType}; charset=UTF-8\r\n";
            $message .= "Content-Transfer-Encoding: base64\r\n";
            $message .= "Date: " . date('r') . "\r\n";
            $message .= "Message-ID: <" . bin2hex(random_bytes(8)) . "@" . $ehloDomain . ">\r\n";
            $message .= "\r\n";
            $message .= chunk_split(base64_encode($body));

            $this->sendCommand($socket, $message . "\r\n.", 250);
            $this->sendCommand($socket, "QUIT", 221);

            return true;
        } finally {
            fclose($socket);
        }
    }

    /**
     * 发送验证码邮件
     */
    public function sendVerifyCode(string $to, string $code, string $purpose = '密码重置'): bool
    {
        $siteName = 'AMuBBS';
        try {
            $row = Database::fetchOne("SELECT `value` FROM settings WHERE `key` = 'site_name'");
            if ($row) $siteName = $row['value'];
        } catch (\Throwable $e) {
            error_log('[Mailer] fetch site_name failed: ' . $e->getMessage());
        }

        $subject = "[{$siteName}] {$purpose}验证码";
        $body = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#f5f6f7;padding:40px 20px;">
<div style="max-width:480px;margin:0 auto;background:#fff;border-radius:8px;padding:32px;box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <h2 style="margin:0 0 16px;font-size:20px;color:#1a1a1a;">{$purpose}验证码</h2>
    <p style="color:#555;font-size:14px;line-height:1.6;">你正在进行{$purpose}操作，验证码为：</p>
    <div style="margin:20px 0;padding:16px;background:#f0f5ff;border-radius:8px;text-align:center;">
        <span style="font-size:32px;font-weight:700;letter-spacing:6px;color:#0066ff;">{$code}</span>
    </div>
    <p style="color:#999;font-size:13px;line-height:1.6;">验证码 15 分钟内有效，请勿泄露给他人。</p>
    <p style="color:#999;font-size:13px;">如果这不是你的操作，请忽略此邮件。</p>
    <hr style="border:none;border-top:1px solid #eee;margin:20px 0;">
    <p style="color:#bbb;font-size:12px;text-align:center;">{$siteName}</p>
</div>
</body></html>
HTML;

        return $this->send($to, $subject, $body);
    }

    private function sendCommand($socket, string $command, int $expectedCode): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->readResponse($socket, $expectedCode);
    }

    private function readResponse($socket, int $expectedCode): string
    {
        $response = '';
        $maxLines = 100;
        $i = 0;
        while ($line = fgets($socket, 512)) {
            $response .= $line;
            // 第4个字符是空格表示最后一行
            if (isset($line[3]) && $line[3] === ' ') break;
            if (++$i >= $maxLines) {
                throw new \RuntimeException('SMTP 响应超过最大行数限制');
            }
        }
        $code = (int)substr($response, 0, 3);
        if ($code !== $expectedCode) {
            throw new \RuntimeException("SMTP 错误 [{$code}]: " . trim($response));
        }
        return $response;
    }
}
