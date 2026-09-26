<?php
/**
 * 后台 - 导航管理（layuimini 子页面）
 *
 * 变量：$categories（每项带 links 子数组）
 *
 * 结构：
 *   - 顶部一张「分类」layui table（服务端本地数据），操作列给出该分类的
 *     添加链接 / 编辑 / 删除；分类新增走表格工具栏；
 *   - 每个分类下面一张「链接」layui table，表格 id / 工具栏 id 按分类 id 区分
 *     （navLinkTable<id> / navLinkToolbar<id>），行内给出编辑 / 删除；
 *   - 分类与链接的新增/编辑都打开迁移后的表单页
 *     （/admin/navigation/category-form、/admin/navigation/link-form）到 layer iframe。
 *
 * 表单保存成功后由表单页调用 AdminUi.reloadParentTable('...') 刷新本页表格，
 * 因此弹层里改完数据，这里的列表会跟着更新。
 */

$categories = is_array($categories ?? null) ? $categories : [];
$totalLinks = 0;
foreach ($categories as $c) {
    $totalLinks += count($c['links'] ?? []);
}

/** 传给 JS 的扁平数据：分类 + 每个分类的链接 */
$catData = [];
$linksByCategory = [];
foreach ($categories as $idx => $c) {
    $catId = (int)($c['id'] ?? 0);
    $links = is_array($c['links'] ?? null) ? $c['links'] : [];

    $catData[] = [
        'id'    => $catId,
        'name'  => (string)($c['name'] ?? ''),
        'icon'  => (string)($c['icon'] ?? ''),
        'rank'  => (int)($c['rank'] ?? 0),
        'links' => count($links),
    ];

    $rows = [];
    foreach ($links as $link) {
        $rows[] = [
            'id'          => (int)($link['id'] ?? 0),
            'category_id' => (int)($link['category_id'] ?? 0),
            'name'        => (string)($link['name'] ?? ''),
            'url'         => (string)($link['url'] ?? ''),
            'description' => (string)($link['description'] ?? ''),
            'icon'        => (string)($link['icon'] ?? ''),
            'clicks'      => (int)($link['clicks'] ?? 0),
            'rank'        => (int)($link['rank'] ?? 0),
        ];
    }
    $linksByCategory[$catId] = $rows;
}
?>

<blockquote class="layui-elem-quote layui-quote-nm">
  <span class="admin-num"><?= count($categories) ?></span> 个分类 ·
  <span class="admin-num"><?= $totalLinks ?></span> 条链接
</blockquote>

<script type="text/html" id="navCategoryToolbar">
  <div class="layui-btn-container">
    <button class="layui-btn layui-btn-sm" lay-event="addCategory">
      <i class="layui-icon layui-icon-add-1"></i> 添加分类
    </button>
  </div>
</script>

<script type="text/html" id="navCategoryRowBar">
  <a class="layui-btn layui-btn-xs layui-btn-normal" lay-event="addLink">添加链接</a>
  <a class="layui-btn layui-btn-xs" lay-event="edit">编辑</a>
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<script type="text/html" id="navLinkRowBar">
  <a class="layui-btn layui-btn-xs" lay-event="edit">编辑</a>
  <a class="layui-btn layui-btn-xs layui-btn-danger" lay-event="delete">删除</a>
</script>

<table class="layui-hide" id="navCategoryTable" lay-filter="navCategoryTable"></table>

<?php if (empty($categories)): ?>
  <blockquote class="layui-elem-quote layui-quote-nm">
    暂无导航分类，点击上方「添加分类」创建第一个导航分组。
  </blockquote>
<?php endif; ?>

<?php foreach ($categories as $cat): ?>
  <?php
    $catId     = (int)($cat['id'] ?? 0);
    $links     = is_array($cat['links'] ?? null) ? $cat['links'] : [];
    $tableId   = 'navLinkTable' . $catId;
    $toolbarId = 'navLinkToolbar' . $catId;
  ?>

  <div class="layui-card">
    <div class="layui-card-header" style="height:auto;line-height:2">
      <span class="layui-badge layui-bg-gray">分类</span>
      <strong style="margin-left:6px"><?= htmlspecialchars((string)($cat['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></strong>
      <?php if (!empty($cat['icon'])): ?>
        <span style="margin-left:6px"><?= htmlspecialchars((string)$cat['icon'], ENT_QUOTES, 'UTF-8') ?></span>
      <?php endif; ?>
      <span class="admin-muted" style="margin-left:8px">
        排序 <?= (int)($cat['rank'] ?? 0) ?> · <span class="admin-num"><?= count($links) ?></span> 条链接
      </span>
    </div>

    <div class="layui-card-body">

      <script type="text/html" id="<?= $toolbarId ?>">
        <div class="layui-btn-container">
          <button class="layui-btn layui-btn-sm layui-btn-normal" lay-event="addLink">
            <i class="layui-icon layui-icon-add-1"></i> 添加链接
          </button>
        </div>
      </script>

      <table class="layui-hide" id="<?= $tableId ?>" lay-filter="<?= $tableId ?>"></table>

    </div>
  </div>
<?php endforeach; ?>

<script>
layui.use(['table', 'form'], function () {
  var table = layui.table;
  var $ = layui.jquery;

  var catData        = <?= json_encode($catData, JSON_UNESCAPED_UNICODE) ?>;
  var linksByCategory = <?= json_encode($linksByCategory, JSON_UNESCAPED_UNICODE) ?>;

  /** 行内模板里要拼 HTML，先转义 & < > " ' */
  function esc(v) {
    return String(v === null || v === undefined ? '' : v)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  /** 打开分类表单（新增 id=0） */
  function openCategoryForm(id) {
    layui.layer.open({
      title: id ? '编辑分类' : '添加分类',
      type: 2,
      shade: 0.2,
      shadeClose: false,
      maxmin: true,
      area: ['640px', '560px'],
      content: '/admin/navigation/category-form?id=' + (id || 0)
    });
  }

  /** 打开链接表单（新增时用 category_id 预选分类） */
  function openLinkForm(id, categoryId) {
    var url = id
      ? '/admin/navigation/link-form?id=' + id
      : '/admin/navigation/link-form?category_id=' + (categoryId || 0);

    layui.layer.open({
      title: id ? '编辑链接' : '添加链接',
      type: 2,
      shade: 0.2,
      shadeClose: false,
      maxmin: true,
      area: ['700px', '620px'],
      content: url
    });
  }

  // ---------- 分类表 ----------
  var catTableId = 'navCategoryTable';

  table.render({
    elem: '#' + catTableId,
    id: catTableId,
    data: catData,
    toolbar: '#navCategoryToolbar',
    defaultToolbar: [],
    cols: [[
      { field: 'id', width: 80, title: 'ID', sort: true },
      { field: 'name', minWidth: 200, title: '分类名称', templet: function (d) {
          var icon = d.icon ? '<span style="margin-right:4px">' + esc(d.icon) + '</span>' : '';
          return icon + esc(d.name);
        } },
      { field: 'links', width: 110, title: '链接数', align: 'center', templet: function (d) {
          return '<span class="admin-num">' + d.links + '</span>';
        } },
      { field: 'rank', width: 90, title: '排序', sort: true, align: 'center' },
      { title: '操作', width: 230, align: 'center', toolbar: '#navCategoryRowBar' }
    ]],
    page: false,
    limit: 200,
    text: { none: '暂无导航分类，点上方「添加分类」创建' },
    skin: 'line'
  });

  table.on('toolbar(' + catTableId + ')', function (obj) {
    if (obj.event === 'addCategory') { openCategoryForm(0); }
  });

  table.on('tool(' + catTableId + ')', function (obj) {
    var d = obj.data;

    if (obj.event === 'addLink') {
      openLinkForm(0, d.id);
    } else if (obj.event === 'edit') {
      openCategoryForm(d.id);
    } else if (obj.event === 'delete') {
      AdminUi.confirmPost('删除分类「' + d.name + '」将同时删除该分类下的所有链接，确定继续吗？',
        '/admin/nav-categories/delete', { id: d.id });
    }
  });

  // ---------- 每个分类的链接表 ----------
  catData.forEach(function (cat) {
    var tableId = 'navLinkTable' + cat.id;
    var rows    = linksByCategory[String(cat.id)] || linksByCategory[cat.id] || [];

    table.render({
      elem: '#' + tableId,
      id: tableId,
      data: rows,
      toolbar: '#navLinkToolbar' + cat.id,
      defaultToolbar: [],
      cols: [[
        { field: 'name', minWidth: 160, title: '名称', templet: function (d) {
            var icon = d.icon
              ? '<img src="' + esc(d.icon) + '" alt="" style="width:16px;height:16px;vertical-align:middle;margin-right:4px" loading="lazy">'
              : '';
            return icon + esc(d.name);
          } },
        { field: 'url', minWidth: 240, title: 'URL', templet: function (d) {
            return '<a class="admin-ellipsis" href="' + esc(d.url) + '" target="_blank" rel="noopener noreferrer"'
                 + ' title="' + esc(d.url) + '">' + esc(d.url.substring(0, 40)) + '</a>';
          } },
        { field: 'description', minWidth: 160, title: '描述', templet: function (d) {
            return '<span class="admin-muted admin-ellipsis" title="' + esc(d.description) + '">'
                 + esc(d.description) + '</span>';
          } },
        { field: 'clicks', width: 100, title: '点击量', align: 'right', sort: true, templet: function (d) {
            return '<span class="admin-muted admin-num">' + d.clicks + '</span>';
          } },
        { field: 'rank', width: 90, title: '排序', align: 'right', sort: true, templet: function (d) {
            return '<span class="admin-muted admin-num">' + d.rank + '</span>';
          } },
        { title: '操作', width: 150, align: 'center', toolbar: '#navLinkRowBar' }
      ]],
      page: false,
      limit: 200,
      text: { none: '这个分类下还没有链接' },
      skin: 'line'
    });

    table.on('toolbar(' + tableId + ')', function (obj) {
      if (obj.event === 'addLink') { openLinkForm(0, cat.id); }
    });

    table.on('tool(' + tableId + ')', function (obj) {
      var d = obj.data;
      if (obj.event === 'edit') {
        openLinkForm(d.id);
      } else if (obj.event === 'delete') {
        AdminUi.confirmPost('确定删除链接「' + d.name + '」吗？', '/admin/nav-links/delete', { id: d.id });
      }
    });
  });
});
</script>
