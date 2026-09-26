# htmx 开发指南

> 本文档描述 AMuBBS **前台**的前端方案。**后台不使用 htmx**：后台是 layuimini v2（Layui 2.6.3）
> 的 iframe 多标签外壳，走 `AdminUi.post / confirmPost / closeLayerAndReload` + jQuery，
> 见 [`12-后台.md`](./12-后台.md)。
> 历史背景：项目最初用 Alpine.js 做客户端状态，后台用 Layui；现在前台统一为
> **服务端渲染片段 + htmx 2**，Alpine 已从视图层退役（Layui 仍只服务后台）。

## 7.1 为什么是 htmx

| 约束 | htmx 的对应做法 |
|:-----|:----------------|
| 共享虚拟主机、无 root / 无 shell | 纯静态 JS 文件，上传即用，不需要构建步骤 |
| 不允许 Composer / npm / Node | 资产直接放在 `public/assets/vendor/`，不依赖 CDN |
| 页面以内容展示为主 | 交互 = 「提交表单 → 服务端回一小段 HTML → 换掉某个节点」 |
| 老机器、小内存 | 不把列表数据序列化到前端再渲染，浏览器只负责替换 DOM |

结论：**能由服务端渲染的，一律服务端渲染**；htmx 只负责发请求与替换片段，
剩下的几十种交互细节由 `app.js` 里一组 `data-*` 声明式增强补齐（见 7.4）。

Alpine / Vue 那类客户端框架要解决的问题（把 JSON 渲染成视图）在这个项目里并不存在，
反而会带来两套状态（服务端一份、客户端一份）不同步的隐患。

## 7.2 资产与引入方式

htmx **只在前后台中的「前台」加载**（`resources/views/layout/header.php`）；后台外壳
`resources/views/admin/layout.php` 与 iframe 子页面 `resources/views/admin/layout_child.php`
都不引 htmx，后台视图里也没有任何 `hx-*` 属性（`scripts/smoke.php` 第 6 节有断言守着：
后台子页面始终是完整文档，带 `HX-Request` 也照样返回整页）。

```php
// resources/views/layout/header.php
<script src=".../assets/js/captcha.js"></script>                      <!-- 同步：验证码组件 -->
<script defer src=".../assets/vendor/htmx/htmx.min.js"></script>      <!-- htmx 2.0.11 -->
<script defer src=".../assets/js/app.js"></script>                    <!-- 通用增强 -->
<script defer src=".../assets/js/utils.js"></script>
```

* 版本：htmx **2.0.11**，路径 `public/assets/vendor/htmx/htmx.min.js`
* CSRF Token 挂在 `<body>` 上，所有请求自动带上：

```php
<body hx-headers='{"X-CSRF-TOKEN":"<?= htmlspecialchars(\App\Middlewares\Csrf::generateToken(), ENT_QUOTES, 'UTF-8') ?>"}'>
```

  带了 `X-CSRF-TOKEN` 请求头，`Csrf` 中间件会把它当作 AJAX 请求处理，**不轮换 token**；
  普通表单 POST 会轮换，所以自动化脚本在非 htmx 请求后必须重新取 token。

* 安装向导是唯一不套用整站布局的页面，它自己引 htmx 并自带
  `hx-headers`（见 `resources/views/install.php`）。

## 7.3 服务端约定（`App\Controllers\Base`）

| 方法 | 用途 | htmx 响应 |
|:-----|:-----|:----------|
| `isHtmx()` | 判断是否 htmx 发起 | 读 `HTTP_HX_REQUEST` |
| `input()` | 统一取参数 | htmx 表单走 `$_POST`，旧 JSON 接口走 `php://input` |
| `frontFlash($msg, $type)` | 弹提示 | `HX-Trigger: {"frontFlash":{...}}` |
| `respondFragment($ok,$msg,$fn,$flash)` | 换掉某个片段 | 成功渲染 `$fn`；失败 `422 + HX-Reswap: none` |
| `respondSubmit($ok,$msg,$url,$extra)` | 表单提交后跳转 | 成功 `HX-Redirect`；失败 `422 + HX-Reswap: none` |
| `respondRefresh($ok,$msg)` | 改完整页重载 | 成功 `HX-Refresh: true`；失败 422 |

三条硬约定：

1. **`HX-Request` 存在时只回片段**，不带 `<html>`/`<head>` 外壳（列表页因此天然支持局部刷新）。
2. **失败用 422 + `HX-Reswap: none`**，页面停在原地、用户已填内容不丢，原因通过 toast 告知。
   唯一例外是安装向导：它的替换目标是「整块向导」，所以失败也回 200 并把错误渲染进页面
   （htmx 默认不替换 4xx 响应）。
3. **提示文案放在响应头里**，前端不用各自处理。`frontFlash` 用 `json_encode` 默认的
   `\uXXXX` 转义——头字段是 ISO-8859-1 逐字节解码的，中文直接写进去会变乱码。

`HX-Refresh` 会紧接着整页重载，重载后 toast 还没来得及看就被冲掉了，所以 `app.js` 在
`htmx:beforeOnLoad` 里先看响应头，把提示存进 `sessionStorage`，重载后补弹一次。

一个接口服务两个目标时用 `HX-Target` 区分，例如：

```php
if (($_SERVER['HTTP_HX_TARGET'] ?? '') === 'notifPopup') { ... }
```

## 7.4 前端可复用机制（`data-*`）

`app.js` 暴露 `window.initHtmxForms(root)`，初次加载和每次 `htmx:afterSwap` 后都会执行，
对每个元素打一次性标记避免重复绑定。页面只要写属性，不用写 JS：

| 机制 | 页面侧声明 |
|:-----|:-----------|
| 验证码 | 容器 `data-captcha-scene="reply"`（自动回填同表单的 `captcha_id`/`captcha_answer`） |
| 草稿自动保存 | 表单 `data-draft-key="thread-reply-1"`，可选 `data-draft-hint`、`data-draft-restore="always"` |
| 标签快捷点选 | 可点元素 `data-tag-add="PHP"`，输入框 `data-tags-input` |
| 密码显隐 | 按钮 `data-pwd-toggle="#pwd"`（同步 `aria-pressed`） |
| 邮箱验证码倒计时 | 按钮 `data-code-countdown="60"` + 文案 `data-code-label` |
| 下拉菜单 | `data-dropdown` / `data-dropdown-toggle` / `data-dropdown-menu hidden` |
| 返回顶部 | 容器 `data-back-to-top` |
| 主题切换 | 按钮 `data-theme-toggle`（`.icon-moon` / `.icon-sun`） |
| 标签页 | 容器 `data-tabs="默认key"` / 按钮 `data-tab="key"` / 面板 `data-tab-panel="key"` |
| 滚动到底 | 容器 `data-scroll-bottom`（首次 + 追加片段后都贴底） |
| 公告关闭 | 容器 `data-ann-wrap` `data-ann-hide-mins` / 按钮 `data-ann-hide="ID"` / 条目 `data-ann-item` |
| 显隐切换 | 按钮 `data-toggle-target="#目标"`，可选 `data-toggle-alt="#另一块"`（二选一） |
| 引用回复 | 可点元素 `data-reply-to` `data-reply-name` `data-reply-floor` `data-reply-form="#表单"`，取消按钮 `data-reply-cancel` |
| 表情面板 | 按钮 `data-emoji-picker='#表单 [name="content"]'`（表情表取 `window.__AMUBBS_EMOJI`） |
| 上传缩略图删除 | 按钮 `data-remove-thumb` + 整块 `data-upload-thumb` |
| 字数统计 | 容器 `data-count-for="#输入框"` `data-count-max="1000"` |
| 遮罩关闭浮层 | 容器 `data-overlay-close`（点到自身才关，等价于原来的 `@click.self`） |
| 勾选式批量栏 | 工具栏 `data-check-toolbar="项目选择器"`，内含 `data-check-count` / `data-check-all` |
| 快捷填值 | 按钮 `data-set-value="#输入框"` `data-value="10"` |

按钮的加载态纯靠 CSS，不需要 JS：

```html
<button class="btn btn-primary">
  <span class="hx-idle">发表评论</span><span class="hx-busy">提交中...</span>
</button>
```

```css
.hx-busy { display: none; }
.htmx-request .hx-idle { display: none; }
.htmx-request .hx-busy { display: inline; }
```

## 7.5 实战示例

### 7.5.1 点赞按钮：`outerHTML` 换片段

```php
// resources/views/thread/_like_btn.php —— 服务端渲染按钮状态，前端不做任何判断
<button class="like-btn <?= $liked ? 'liked' : '' ?>"
        hx-post="/thread/like" hx-vals='{"thread_id":<?= $threadId ?>}'
        hx-swap="outerHTML"><?= $liked ? '已赞' : '赞' ?> <?= $likes ?></button>
```

```php
// App\Controllers\Thread::like()
$this->respondFragment(true, $liked ? '点赞成功' : '已取消', function () use ($liked, $likes) {
    $this->renderLikeButton($liked, $likes);   // 回一个同结构的按钮片段
});
```

要点：片段根节点必须和 `hx-swap="outerHTML"` 的语义匹配（这里就是按钮本身），
这样第二次点击时新按钮上的 `hx-post` 依旧有效。

### 7.5.2 楼层编辑：二选一，不发请求

编辑表单和正文都预先渲染好，只是其中一个带 `hidden`：

```html
<div class="post-content" id="post-9-view">...</div>
<form id="post-9-edit" hidden hx-post="/post/edit" hx-swap="none">
  <input type="hidden" name="post_id" value="9">
  <textarea name="content" required minlength="2">...</textarea>
  <button type="submit" class="btn btn-primary btn-sm">
    <span class="hx-idle">保存</span><span class="hx-busy">保存中...</span>
  </button>
  <button type="button" class="btn btn-ghost btn-sm"
          data-toggle-target="#post-9-edit" data-toggle-alt="#post-9-view">取消</button>
</form>
<button class="btn-quote" data-toggle-target="#post-9-edit" data-toggle-alt="#post-9-view">编辑</button>
```

编辑与取消共用同一组属性，切换完全由 `initToggles` 完成——原来这是 Alpine 组件、
还要用 `$refs` 取 textarea，现在一行 JS 都不用写。

### 7.5.3 整块向导：失败也回 200

安装向导只有一个替换目标，每一步都由服务端渲染：

```html
<div class="install-container">
  <div class="install-header">...</div>
  <div id="installWizard" hx-target="#installWizard" hx-swap="innerHTML"
       hx-headers='{"X-CSRF-TOKEN":"..."}'><?php include ... ?></div>
</div>
```

```php
private function fail(string $message): void
{
    if ($this->isHtmx()) { $this->renderWizard($message); return; }  // 200 + 错误条
    $this->error($message);                                          // 旧 JSON 通道
}
```

按钮必须是 `type="button"`：它们只负责发动 htmx 请求，否则浏览器还会原生提交一次表单。
表单加 `novalidate`，校验全部交给服务端，避免「点『上一步』被必填项拦住」这类坑。

## 7.6 常见坑

* **htmx 默认不替换 4xx/5xx 响应。** 想让错误内容出现在页面里，要么回 200（向导做法），
  要么在 `htmx:beforeSwap` 里把 `shouldSwap` 置为 true。
* **`hx-post` 挂在 `<button>` 上时会带上最近一层 `<form>` 的全部字段**，所以字段和按钮
  必须包在同一个表单里（向导把步骤条、正文、底部按钮都放在一个 `<form>` 内）。
* **`htmx:afterSwap` 后新片段里的 `data-*` 需要重新初始化**，所以 `app.js` 在
  `htmx:afterSwap` 里再次调用 `initHtmxForms(root)`。
* **响应头里的中文必须转义**（见 7.3 第 3 条）。
* **列表页追加片段**用 `hx-swap="beforeend"`；要同时更新计数徽标就用 `hx-swap-oob`。
* **非 htmx 调用方要保留**：所有改造过的接口都保留原 JSON 分支，`input()` 负责同时读
  `$_POST` 与 `php://input`。

## 7.7 测试与迁移度度量

* `scripts/smoke.php` —— 端到端冒烟：游客/登录两态、页面缓存 CSRF 隔离、后台 27 个页面、
  16 个后台接口、**前台**片段响应，以及「已迁移视图无 Alpine 指令」。
  其中两条边界断言值得记住：后台子页面**始终**是完整文档（第 6 节，即使带 `HX-Request`
  也不回片段），前台页面必须加载 htmx 并把动作写成 `hx-post`（第 7 节）。
* `scripts/selftest_cache.php` / `selftest_hooks.php` / `selftest_install.php` ——
  缓存驱动、插件钩子，以及全新安装全流程（在临时副本 + 临时库上跑，不碰当前站点）。
* 迁移进度用一个正则度量（`ALPINE_RE`）：`x-data|x-model|x-show|x-text|x-for|x-ref|x-init`
  `|@click|@submit|@change|@keydown|@input|:class="|:disabled="|:placeholder="|:type="`
  `|x-cloak[ >]|$refs|$dispatch`。
  冒号指令要求带 `="`、`x-cloak` 要求后面是空格或 `>`，所以 CSS 里的 `.btn:disabled`
  和 `[x-cloak]{...}` 不会被算成指令。
  当前 `resources/views` 下该正则命中数为 **0**，Alpine 已从视图层退役。
