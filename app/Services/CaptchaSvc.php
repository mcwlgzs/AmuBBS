<?php
/**
 * 验证码服务 - 支持数字、字母、滑动拼图三种模式
 */

namespace App\Services;

class CaptchaSvc
{
    /** 验证码有效期（秒） */
    private const EXPIRE = 300;

    /** 滑动拼图容差（px） */
    private const SLIDER_TOLERANCE = 5;

    /**
     * 检查指定场景是否需要验证码
     */
    public static function isRequired(string $scene): bool
    {
        if (!SettingSvc::getBool('captcha_enabled', false)) {
            return false;
        }
        $scenes = array_map('trim', explode(',', SettingSvc::get('captcha_scenes', '')));
        return in_array($scene, $scenes, true);
    }

    /**
     * 获取当前配置的验证码类型
     */
    public static function getType(): string
    {
        $type = SettingSvc::get('captcha_type', 'numeric');
        if (!in_array($type, ['numeric', 'alpha', 'slider'], true)) {
            return 'numeric';
        }
        return $type;
    }

    /**
     * 生成验证码
     * @return array{captcha_id: string, type: string, image?: string, width?: int, thumb?: string}
     */
    public static function generate(string $type = ''): array
    {
        if ($type === '') {
            $type = self::getType();
        }

        // 确保 session 已启动（使用 Bootstrap 统一方法，保留 Redis/cookie 安全配置）
        \Core\Bootstrap::ensureSession();

        $captchaId = bin2hex(random_bytes(16));

        switch ($type) {
            case 'slider':
                return self::generateSlider($captchaId);
            case 'alpha':
                return self::generateImage($captchaId, 'alpha');
            case 'numeric':
            default:
                return self::generateImage($captchaId, 'numeric');
        }
    }

    /**
     * 校验验证码
     */
    public static function verify(string $captchaId, string $answer): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        $key = 'captcha_' . $captchaId;
        $data = $_SESSION[$key] ?? null;

        if (!$data) {
            return false;
        }

        // 立即销毁（一次性）
        unset($_SESSION[$key]);

        // 检查过期
        if (time() > ($data['expires'] ?? 0)) {
            return false;
        }

        $type = $data['type'] ?? 'numeric';

        if ($type === 'slider') {
            $target = (int)($data['answer'] ?? 0);
            $userAnswer = (int)$answer;
            return abs($target - $userAnswer) <= self::SLIDER_TOLERANCE;
        }

        if ($type === 'token') {
            // 滑动验证通过后生成的轻量 token，精确匹配
            return $answer === $data['answer'];
        }

        // numeric / alpha: 不区分大小写
        return strtolower(trim($answer)) === strtolower($data['answer']);
    }

    /**
     * 在控制器中校验验证码（统一入口）
     * 如果场景不需要验证码则直接通过
     * @throws \RuntimeException
     */
    public static function check(string $scene): void
    {
        if (!self::isRequired($scene)) {
            return;
        }

        $captchaId = trim($_POST['captcha_id'] ?? '');
        $captchaAnswer = trim($_POST['captcha_answer'] ?? '');

        if ($captchaId === '' || $captchaAnswer === '') {
            throw new \RuntimeException('请完成验证码验证');
        }

        if (!self::verify($captchaId, $captchaAnswer)) {
            throw new \RuntimeException('验证码错误，请重试');
        }
    }

    // ==================== 图片验证码（numeric / alpha） ====================

    private static function generateImage(string $captchaId, string $type): array
    {
        $length = 4;
        if ($type === 'alpha') {
            // 排除容易混淆的字符: 0O1lI
            $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= $chars[random_int(0, strlen($chars) - 1)];
            }
        } else {
            $code = '';
            for ($i = 0; $i < $length; $i++) {
                $code .= random_int(0, 9);
            }
        }

        // 存入 session
        $_SESSION['captcha_' . $captchaId] = [
            'type' => $type,
            'answer' => $code,
            'expires' => time() + self::EXPIRE,
        ];

        // 生成图片
        $width = 120;
        $height = 40;
        $image = self::renderCaptchaImage($code, $width, $height);

        return [
            'captcha_id' => $captchaId,
            'type' => $type,
            'image' => 'data:image/png;base64,' . base64_encode($image),
        ];
    }

    /**
     * 用 GD 库渲染验证码图片
     */
    private static function renderCaptchaImage(string $code, int $width, int $height): string
    {
        $img = imagecreatetruecolor($width, $height);

        // 背景色
        $bgColor = imagecolorallocate($img, random_int(230, 250), random_int(230, 250), random_int(230, 250));
        imagefilledrectangle($img, 0, 0, $width, $height, $bgColor);

        // 干扰线
        for ($i = 0; $i < 4; $i++) {
            $lineColor = imagecolorallocate($img, random_int(150, 200), random_int(150, 200), random_int(150, 200));
            imageline($img, random_int(0, $width), random_int(0, $height), random_int(0, $width), random_int(0, $height), $lineColor);
        }

        // 干扰点
        for ($i = 0; $i < 50; $i++) {
            $dotColor = imagecolorallocate($img, random_int(150, 220), random_int(150, 220), random_int(150, 220));
            imagesetpixel($img, random_int(0, $width), random_int(0, $height), $dotColor);
        }

        // 绘制文字
        $fontSize = 5; // GD 内置字体大小 1-5
        $charWidth = imagefontwidth($fontSize);
        $charHeight = imagefontheight($fontSize);
        $totalWidth = $charWidth * strlen($code);
        $startX = (int)(($width - $totalWidth) / 2);
        $startY = (int)(($height - $charHeight) / 2);

        for ($i = 0; $i < strlen($code); $i++) {
            $textColor = imagecolorallocate($img, random_int(20, 100), random_int(20, 100), random_int(20, 100));
            $x = $startX + $i * $charWidth + random_int(-2, 2);
            $y = $startY + random_int(-3, 3);
            imagechar($img, $fontSize, $x, $y, $code[$i], $textColor);
        }

        ob_start();
        imagepng($img);
        $data = ob_get_clean();
        imagedestroy($img);

        return $data;
    }

    // ==================== 滑动拼图验证码 ====================

    private static function generateSlider(string $captchaId): array
    {
        $width = 260;
        $height = 116;
        $pieceSize = 36;

        // 随机目标位置（留出边距）
        $targetX = random_int($pieceSize + 20, $width - $pieceSize - 20);
        $targetY = random_int(10, $height - $pieceSize - 10);

        // 存入 session
        $_SESSION['captcha_' . $captchaId] = [
            'type' => 'slider',
            'answer' => (string)$targetX,
            'expires' => time() + self::EXPIRE,
        ];

        // 先绘制完整背景（含装饰）
        $bgImg = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y++) {
            $r = (int)(100 + ($y / $height) * 60);
            $g = (int)(140 + ($y / $height) * 40);
            $b = (int)(180 + ($y / $height) * 30);
            $color = imagecolorallocate($bgImg, $r, $g, $b);
            imageline($bgImg, 0, $y, $width, $y, $color);
        }
        for ($i = 0; $i < 6; $i++) {
            $deco = imagecolorallocate($bgImg, random_int(80, 180), random_int(120, 200), random_int(160, 230));
            imagefilledellipse($bgImg, random_int(0, $width), random_int(0, $height), random_int(20, 60), random_int(20, 60), $deco);
        }

        // 从完整背景中截取拼图块（pieceSize x pieceSize）
        $pieceImg = imagecreatetruecolor($pieceSize, $pieceSize);
        imagecopy($pieceImg, $bgImg, 0, 0, $targetX, $targetY, $pieceSize, $pieceSize);
        // 拼图块白色边框
        $pBorder = imagecolorallocate($pieceImg, 255, 255, 255);
        imagerectangle($pieceImg, 0, 0, $pieceSize - 1, $pieceSize - 1, $pBorder);
        // 内阴影效果
        $shadow = imagecolorallocatealpha($pieceImg, 0, 0, 0, 100);
        imagerectangle($pieceImg, 1, 1, $pieceSize - 2, $pieceSize - 2, $shadow);

        // 在背景上画缺口
        $slotColor = imagecolorallocatealpha($bgImg, 0, 0, 0, 80);
        imagefilledrectangle($bgImg, $targetX, $targetY, $targetX + $pieceSize - 1, $targetY + $pieceSize - 1, $slotColor);
        $borderColor = imagecolorallocate($bgImg, 255, 255, 255);
        imagerectangle($bgImg, $targetX, $targetY, $targetX + $pieceSize - 1, $targetY + $pieceSize - 1, $borderColor);

        ob_start();
        imagepng($bgImg);
        $bgData = ob_get_clean();
        imagedestroy($bgImg);

        ob_start();
        imagepng($pieceImg);
        $pieceData = ob_get_clean();
        imagedestroy($pieceImg);

        return [
            'captcha_id' => $captchaId,
            'type' => 'slider',
            'image' => 'data:image/png;base64,' . base64_encode($bgData),
            'thumb' => 'data:image/png;base64,' . base64_encode($pieceData),
            'width' => $width,
            'height' => $height,
            'pieceSize' => $pieceSize,
            'targetY' => $targetY,
        ];
    }
}
