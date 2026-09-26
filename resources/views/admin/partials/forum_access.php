<?php
/**
 * 板块权限（layuimini 子页面，由 layer 的 iframe 弹层打开）
 *
 * 变量：$forum, $groups, $accessMap
 *
 * 注意：未勾选的 checkbox 不会随表单提交，后端会把它当成「默认允许」，
 * 所以每个权限都配一个 value="0" 的 hidden 占位；勾选时同名字段后者覆盖前者。
 * 这是 HTML 表单的标准做法，避免「取消勾选」被静默忽略。
 *
 * 提交时用 jQuery 的 serialize() 而不是 layui 的 data.field：
 * 这里有大量「同名 hidden + checkbox」，serialize() 会老老实实把两个都发出去，
 * 交给 PHP 解析成嵌套数组；layui 的字段收集在这种重复名字上不保证顺序。
 */

$forum     = is_array($forum ?? null) ? $forum : [];
$groups    = is_array($groups ?? null) ? $groups : [];
$accessMap = is_array($accessMap ?? null) ? $accessMap : [];

$perms = [
    'allow_read'   => '浏览',
    'allow_thread' => '发帖',
    'allow_post'   => '回复',
    'allow_attach' => '附件',
    'allow_down'   => '下载',
];

$forumId   = (int)($forum['id'] ?? 0);
$forumName = htmlspecialchars((string)($forum['name'] ?? ''), ENT_QUOTES, 'UTF-8');
?>

<form class="layui-form" id="forumAccessForm" action="">
  <input type="hidden" name="forum_id" value="<?= $forumId ?>">

  <blockquote class="layui-elem-quote layui-quote-nm" style="margin-bottom:12px">
    未勾选的权限将被禁止。<strong>默认全部允许</strong>，只有被限制过的用户组才会写入数据库。
  </blockquote>

  <table class="layui-table" lay-size="sm">
    <thead>
      <tr>
        <th>用户组</th>
        <?php foreach ($perms as $label): ?>
          <th style="width:70px;text-align:center"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></th>
        <?php endforeach; ?>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($groups as $g): ?>
      <?php
        $gid = (int)($g['id'] ?? 0);
        $row = $accessMap[$gid] ?? [];
      ?>
      <tr>
        <td><?= htmlspecialchars((string)($g['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        <?php foreach ($perms as $key => $label): ?>
          <?php $checked = !isset($row[$key]) || (int)$row[$key] === 1; ?>
          <td style="text-align:center">
            <input type="hidden" name="permissions[<?= $gid ?>][<?= $key ?>]" value="0">
            <input type="checkbox" name="permissions[<?= $gid ?>][<?= $key ?>]" value="1"
                   lay-skin="primary" title=" "
                   <?= $checked ? 'checked' : '' ?>>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div style="margin-top:16px">
    <button class="layui-btn" lay-submit lay-filter="forumAccessSubmit">保存权限</button>
    <button type="button" class="layui-btn layui-btn-primary" id="forumAccessCancel">取消</button>
  </div>
</form>

<script>
layui.use(['form'], function () {
  var form = layui.form;
  var $ = layui.jquery;

  form.on('submit(forumAccessSubmit)', function () {
    // serialize() 而不是 data.field：保留「同名 hidden + checkbox」两条记录
    $.post('/admin/forums/access-save', $('#forumAccessForm').serialize(), function (res) {
      if (res && res.code === 0) {
        AdminUi.ok(res.msg || '权限已保存');
        AdminUi.closeLayerAndReload('forumTable');
      } else {
        AdminUi.fail((res && (res.msg || res.message)) || '保存失败');
      }
    }, 'json').fail(function () {
      AdminUi.fail('保存失败');
    });

    return false;
  });

  $('#forumAccessCancel').on('click', function () {
    try {
      window.parent.layer.close(window.parent.layer.getFrameIndex(window.name));
    } catch (e) {
      history.back();
    }
  });
});
</script>
