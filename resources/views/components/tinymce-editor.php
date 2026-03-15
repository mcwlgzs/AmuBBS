<?php
/**
 * TinyMCE 编辑器组件
 * 替代 markdown-editor.php
 *
 * 变量:
 *   $editorId          - textarea 的 id，默认 'tinymce-editor'
 *   $editorRows        - 行数（仅作为 fallback），默认 5
 *   $editorPlaceholder - 占位文字
 *   $editorModel       - Alpine.js x-model 绑定变量名，默认 'content'
 *   $editorMode        - 'full' 或 'simple'，默认 'full'
 *   $editorMinHeight   - 最小高度（px），默认 null（使用 JS 默认值）
 */
$_id = $editorId ?? 'tinymce-editor-' . mt_rand(1000, 9999);
$_rows = $editorRows ?? 5;
$_placeholder = $editorPlaceholder ?? '输入内容...';
$_model = $editorModel ?? 'content';
$_mode = $editorMode ?? 'full';
$_minHeight = $editorMinHeight ?? null;

// 防止重复加载 TinyMCE 脚本
if (empty($GLOBALS['_tinymce_loaded'])):
    $GLOBALS['_tinymce_loaded'] = true;

    // 检查 Emoji 功能是否启用
    $emojiEnabled = \App\Services\EmojiService::isEnabled();
?>
<script src="/assets/tinymce/tinymce.min.js"></script>
<?php if ($emojiEnabled): ?>
<script>window.__AMUBBS_EMOJI = <?= json_encode(\App\Services\EmojiService::getEmojiMap(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;</script>
<?php endif; ?>
<script src="/assets/tinymce/tinymce-init.js"></script>
<?php endif; ?>

<div class="form-group" style="margin-top:0;">
    <textarea id="<?= $_id ?>" rows="<?= $_rows ?>"
        placeholder="<?= htmlspecialchars($_placeholder) ?>"
        class="form-textarea"
        style="visibility:hidden;"></textarea>
</div>

<script>
(function() {
    // 等待 Alpine.js 初始化完成后再绑定
    function bindEditor() {
        var selector = '#' + <?= json_encode($_id) ?>;
        var modelName = <?= json_encode($_model) ?>;

        initTinyMCE(selector, {
            mode: <?= json_encode($_mode) ?>,
            <?php if ($_minHeight): ?>minHeight: <?= (int)$_minHeight ?>,<?php endif; ?>
            onChange: function(content) {
                // 同步内容到 Alpine.js 数据
                var el = document.querySelector(selector);
                if (el && el.closest('[x-data]')) {
                    var scope = el.closest('[x-data]');
                    if (scope._x_dataStack) {
                        var parts = modelName.split('.');
                        var data = scope._x_dataStack[0];
                        for (var i = 0; i < parts.length - 1; i++) {
                            if (data[parts[i]] !== undefined) data = data[parts[i]];
                        }
                        data[parts[parts.length - 1]] = content;
                    }
                }
            },
            onInit: function(editor) {
                // 从 Alpine.js 数据加载初始值
                var el = document.querySelector(selector);
                if (el && el.closest('[x-data]')) {
                    var scope = el.closest('[x-data]');
                    if (scope._x_dataStack) {
                        var parts = modelName.split('.');
                        var data = scope._x_dataStack[0];
                        for (var i = 0; i < parts.length; i++) {
                            if (data[parts[i]] !== undefined) data = data[parts[i]];
                            else { data = ''; break; }
                        }
                        if (typeof data === 'string' && data) {
                            editor.setContent(data);
                        }
                    }
                }
            }
        });
    }

    // 确保 DOM 和 Alpine 都就绪
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { setTimeout(bindEditor, 100); });
    } else {
        setTimeout(bindEditor, 100);
    }
})();
</script>
