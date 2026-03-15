<?php
/**
 * 附件/图片上传服务
 */

namespace App\Services;

use Core\Database;

class AttachmentSvc
{
    private static array $allowedImageTypes = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
    ];

    /**
     * 允许的附件扩展名及其合法 MIME 类型
     */
    private static array $allowedExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'zip', 'rar', '7z', 'gz', 'tar',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
        'txt', 'csv', 'md',
    ];

    /**
     * 扩展名到合法 MIME 类型的映射（用于交叉验证）
     */
    private static array $extMimeMap = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'rar'  => ['application/x-rar-compressed', 'application/vnd.rar'],
        '7z'   => ['application/x-7z-compressed'],
        'gz'   => ['application/gzip', 'application/x-gzip'],
        'tar'  => ['application/x-tar'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'txt'  => ['text/plain'],
        'csv'  => ['text/csv', 'text/plain'],
        'md'   => ['text/plain', 'text/markdown'],
    ];

    /**
     * 最大文件大小 20MB
     */
    private const MAX_FILE_SIZE = 20 * 1024 * 1024;

    /**
     * 上传图片
     * @return string 图片 URL
     */
    public static function uploadImage(int $userId, array $file): string
    {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('文件上传失败');
        }

        // 第一层验证：MIME 类型
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (!isset(self::$allowedImageTypes[$mimeType])) {
            throw new \RuntimeException('仅支持 JPG/PNG/GIF/WebP 格式');
        }

        // 第二层验证：使用 getimagesize 验证文件确实是图片（检查 magic bytes）
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            throw new \RuntimeException('文件不是有效的图片');
        }
        
        // 第三层验证：确保 getimagesize 返回的类型与 MIME 类型一致
        $imageMimeType = $imageInfo['mime'] ?? '';
        if ($imageMimeType !== $mimeType) {
            throw new \RuntimeException('图片类型验证失败');
        }

        // 验证大小（最大 5MB）
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new \RuntimeException('图片不能超过 5MB');
        }

        $ext = self::$allowedImageTypes[$mimeType];
        $dateDir = date('Y/m');
        $uploadDir = APP_PATH . 'public/uploads/images/' . $dateDir . '/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            throw new \RuntimeException('无法创建上传目录');
        }

        $filename = 'img_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new \RuntimeException('文件保存失败');
        }

        // 图片自动缩略：超过配置宽度时等比缩放
        self::autoResizeImage($destPath, $mimeType);

        // 图片水印
        self::applyWatermark($destPath, $mimeType);

        // 生成缩略图
        self::generateThumbnail($destPath, $mimeType, $uploadDir, $filename);

        $url = '/uploads/images/' . $dateDir . '/' . $filename;

        // 记录到附件表
        try {
            Database::execute(
                "INSERT INTO attachments (user_id, filename, filepath, filesize, mimetype, created_at) VALUES (?, ?, ?, ?, ?, ?)",
                [$userId, $file['name'] ?? $filename, $url, $file['size'], $mimeType, time()]
            );
        } catch (\Throwable $e) {
            error_log('[AttachmentSvc] uploadImage DB record failed: ' . $e->getMessage());
        }

        return $url;
    }

    /**
     * 上传通用文件（附件）
     * @return array ['id' => int, 'url' => string, 'filename' => string, 'filesize' => int]
     */
    public static function uploadFile(int $userId, array $file): array
    {
        if (empty($file['tmp_name']) || $file['error'] !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('文件上传失败');
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            throw new \RuntimeException('文件不能超过 20MB');
        }

        // 验证扩展名
        $originalName = $file['name'] ?? 'unknown';
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, self::$allowedExtensions, true)) {
            throw new \RuntimeException('不支持的文件类型: ' . $ext);
        }

        // 验证 MIME 与扩展名交叉匹配
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($file['tmp_name']);

        if (isset(self::$extMimeMap[$ext])) {
            if (!in_array($mimeType, self::$extMimeMap[$ext], true)) {
                throw new \RuntimeException('文件内容与扩展名不匹配');
            }
        }

        $dateDir = date('Y/m');
        $uploadDir = APP_PATH . 'public/uploads/attachments/' . $dateDir . '/';

        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            throw new \RuntimeException('无法创建上传目录');
        }

        $storedName = 'att_' . bin2hex(random_bytes(12)) . '.' . $ext;
        $destPath = $uploadDir . $storedName;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new \RuntimeException('文件保存失败');
        }

        $filepath = '/uploads/attachments/' . $dateDir . '/' . $storedName;

        Database::execute(
            "INSERT INTO attachments (user_id, filename, filepath, filesize, mimetype, created_at) VALUES (?, ?, ?, ?, ?, ?)",
            [$userId, $originalName, $filepath, $file['size'], $mimeType, time()]
        );

        $attachId = Database::lastInsertId();

        return [
            'id' => $attachId,
            'url' => $filepath,
            'filename' => $originalName,
            'filesize' => $file['size'],
            'is_image' => isset(self::$allowedImageTypes[$mimeType]),
        ];
    }

    /**
     * 下载附件（含权限检查）
     */
    public static function download(int $attachId, ?int $userId = null): void
    {
        $attach = Database::fetchOne("SELECT * FROM attachments WHERE id = ?", [$attachId]);
        if (!$attach) {
            throw new \RuntimeException('附件不存在');
        }

        $filePath = APP_PATH . 'public' . $attach['filepath'];

        // 防止路径穿越：确保文件在 public 目录下
        $realPath = realpath($filePath);
        $publicDir = realpath(APP_PATH . 'public');
        if ($realPath === false || $publicDir === false || strpos($realPath, $publicDir) !== 0) {
            throw new \RuntimeException('文件路径非法');
        }

        if (!file_exists($realPath)) {
            throw new \RuntimeException('文件不存在');
        }

        $mimeType = $attach['mimetype'] ?: 'application/octet-stream';
        $isImage = isset(self::$allowedImageTypes[$mimeType]);

        // 非图片附件需要检查下载权限
        if (!$isImage) {
            if ($userId === null || $userId <= 0) {
                throw new \RuntimeException('请先登录后下载');
            }
            // 检查用户组下载权限
            if (!PermissionSvc::can($userId, 'down')) {
                throw new \RuntimeException('您所在的用户组无下载权限');
            }
            // 检查板块级下载权限
            $threadId = (int)($attach['thread_id'] ?? 0);
            if ($threadId > 0) {
                $thread = Database::fetchOne("SELECT forum_id FROM threads WHERE id = ?", [$threadId]);
                if ($thread) {
                    $forumId = (int)$thread['forum_id'];
                    if (!PermissionSvc::can($userId, 'down', $forumId)) {
                        throw new \RuntimeException('您无权下载此板块的附件');
                    }
                }
            }
        }

        // 更新下载次数
        Database::execute("UPDATE attachments SET downloads = downloads + 1 WHERE id = ?", [$attachId]);

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . filesize($realPath));

        if (!$isImage) {
            $asciiName = preg_replace('/[^\x20-\x7E]/', '_', $attach['filename']);
            $utf8Name = rawurlencode($attach['filename']);
            header("Content-Disposition: attachment; filename=\"{$asciiName}\"; filename*=UTF-8''{$utf8Name}");
        }

        readfile($realPath);
        exit;
    }

    /**
     * 获取帖子的附件列表
     */
    public static function getByThread(int $threadId): array
    {
        return Database::fetchAll(
            "SELECT * FROM attachments WHERE thread_id = ? ORDER BY created_at ASC",
            [$threadId]
        );
    }

    /**
     * 关联附件到帖子
     */
    public static function associateToThread(array $attachIds, int $threadId, int $userId, int $postId = 0): void
    {
        if (empty($attachIds)) return;
        $placeholders = implode(',', array_fill(0, count($attachIds), '?'));
        // 只关联属于当前用户且未关联的附件，防止劫持他人附件
        $params = array_merge([$threadId, $postId, $userId], $attachIds);
        Database::execute(
            "UPDATE attachments SET thread_id = ?, post_id = ? WHERE user_id = ? AND thread_id = 0 AND id IN ({$placeholders})",
            $params
        );
    }

    /**
     * 清理未关联的临时附件（超过24小时）
     */
    public static function cleanOrphanAttachments(): int
    {
        $threshold = time() - 86400;
        $orphans = Database::fetchAll(
            "SELECT id, filepath FROM attachments WHERE thread_id = 0 AND post_id = 0 AND created_at < ?",
            [$threshold]
        );

        if (empty($orphans)) {
            return 0;
        }

        // 先删除数据库记录，再删物理文件，避免中途崩溃导致记录残留
        $ids = array_column($orphans, 'id');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        Database::execute("DELETE FROM attachments WHERE id IN ({$placeholders})", $ids);

        foreach ($orphans as $orphan) {
            $filePath = APP_PATH . 'public' . $orphan['filepath'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        return count($orphans);
    }

    /**
     * 格式化文件大小
     */
    public static function formatSize(int $bytes): string
    {
        if ($bytes >= 1048576) return round($bytes / 1048576, 1) . ' MB';
        if ($bytes >= 1024) return round($bytes / 1024, 1) . ' KB';
        return $bytes . ' B';
    }

    /**
     * 图片自动缩略：超过配置最大宽度时等比缩放
     * 使用 PHP GD 库 imagecopyresampled
     */
    private static function autoResizeImage(string $filePath, string $mimeType): void
    {
        $maxWidth = SettingSvc::getInt('image_max_width', 1920);
        if ($maxWidth <= 0) {
            return; // 0 表示不限制
        }

        // GIF 不缩放（可能是动图）
        if ($mimeType === 'image/gif') {
            return;
        }

        if (!function_exists('imagecreatefromjpeg')) {
            return; // GD 库不可用
        }

        $info = @getimagesize($filePath);
        if (!$info || $info[0] <= $maxWidth) {
            return; // 无需缩放
        }

        $srcW = $info[0];
        $srcH = $info[1];
        $ratio = $maxWidth / $srcW;
        $newW = $maxWidth;
        $newH = (int)round($srcH * $ratio);

        $src = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($filePath),
            'image/png' => @imagecreatefrompng($filePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
            default => null,
        };

        if (!$src) {
            return;
        }

        $dst = imagecreatetruecolor($newW, $newH);

        // PNG/WebP 保持透明
        if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        match ($mimeType) {
            'image/jpeg' => imagejpeg($dst, $filePath, 85),
            'image/png' => imagepng($dst, $filePath, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, $filePath, 85) : null,
            default => null,
        };

        imagedestroy($src);
        imagedestroy($dst);
    }

    /**
     * 添加文字水印
     */
    private static function applyWatermark(string $filePath, string $mimeType): void
    {
        if (!SettingSvc::getBool('watermark_enabled')) {
            return;
        }

        // GIF 不加水印
        if ($mimeType === 'image/gif') {
            return;
        }

        if (!function_exists('imagecreatefromjpeg')) {
            return;
        }

        $text = SettingSvc::get('watermark_text', 'AMuBBS');
        if ($text === '') return;

        $position = SettingSvc::get('watermark_position', 'bottom-right');
        $opacity = SettingSvc::getInt('watermark_opacity', 30);

        $info = @getimagesize($filePath);
        if (!$info || $info[0] < 200 || $info[1] < 200) {
            return; // 图片太小不加水印
        }

        $src = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($filePath),
            'image/png' => @imagecreatefrompng($filePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
            default => null,
        };

        if (!$src) return;

        $imgW = $info[0];
        $imgH = $info[1];

        // 字体大小根据图片宽度自适应
        $fontSize = max(12, (int)($imgW * 0.025));

        // 使用内置字体（无需外部字体文件）
        $alpha = (int)(127 - ($opacity / 100 * 127));
        $white = imagecolorallocatealpha($src, 255, 255, 255, $alpha);
        $shadow = imagecolorallocatealpha($src, 0, 0, 0, $alpha + 20 > 127 ? 127 : $alpha + 20);

        // 计算文字尺寸（使用内置字体）
        // 注意：imagestring() 不支持多字节字符，中文水印会乱码。完整修复需 imagettftext() + TTF 字体。
        $fontIndex = min(5, max(1, (int)($fontSize / 5)));
        $textW = imagefontwidth($fontIndex) * mb_strlen($text, 'UTF-8');
        $textH = imagefontheight($fontIndex);
        $padding = 10;

        // 计算位置
        [$x, $y] = match ($position) {
            'top-left' => [$padding, $padding],
            'top-right' => [$imgW - $textW - $padding, $padding],
            'bottom-left' => [$padding, $imgH - $textH - $padding],
            'center' => [($imgW - $textW) / 2, ($imgH - $textH) / 2],
            default => [$imgW - $textW - $padding, $imgH - $textH - $padding], // bottom-right
        };

        // 阴影
        imagestring($src, $fontIndex, (int)$x + 1, (int)$y + 1, $text, $shadow);
        // 文字
        imagestring($src, $fontIndex, (int)$x, (int)$y, $text, $white);

        // PNG 保持透明
        if ($mimeType === 'image/png') {
            imagesavealpha($src, true);
        }

        match ($mimeType) {
            'image/jpeg' => imagejpeg($src, $filePath, 90),
            'image/png' => imagepng($src, $filePath, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($src, $filePath, 90) : null,
            default => null,
        };

        imagedestroy($src);
    }

    /**
     * 生成缩略图（_thumb 后缀）
     */
    private static function generateThumbnail(string $filePath, string $mimeType, string $dir, string $filename): void
    {
        $thumbWidth = SettingSvc::getInt('image_thumb_width', 400);
        if ($thumbWidth <= 0) {
            return;
        }

        if ($mimeType === 'image/gif') {
            return;
        }

        if (!function_exists('imagecreatefromjpeg')) {
            return;
        }

        $info = @getimagesize($filePath);
        if (!$info || $info[0] <= $thumbWidth) {
            return; // 原图已经够小
        }

        $srcW = $info[0];
        $srcH = $info[1];
        $ratio = $thumbWidth / $srcW;
        $newW = $thumbWidth;
        $newH = (int)round($srcH * $ratio);

        $src = match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($filePath),
            'image/png' => @imagecreatefrompng($filePath),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($filePath) : null,
            default => null,
        };

        if (!$src) return;

        $dst = imagecreatetruecolor($newW, $newH);

        if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
            imagefilledrectangle($dst, 0, 0, $newW, $newH, $transparent);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);

        // 缩略图文件名：原名_thumb.ext
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $thumbPath = $dir . $base . '_thumb.' . $ext;

        match ($mimeType) {
            'image/jpeg' => imagejpeg($dst, $thumbPath, 80),
            'image/png' => imagepng($dst, $thumbPath, 7),
            'image/webp' => function_exists('imagewebp') ? imagewebp($dst, $thumbPath, 80) : null,
            default => null,
        };

        imagedestroy($src);
        imagedestroy($dst);
    }
}
