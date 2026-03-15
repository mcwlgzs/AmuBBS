/**
 * Emoji 选择器组件
 * 用法: new EmojiPicker(inputEl, { onSelect: function(emoji){} })
 */
(function(){
var EMOJI_DATA = {
    '常用': ['😀','😁','😂','🤣','😃','😄','😅','😆','😉','😊','😋','😎','🥰','😍','😘','😗','😙','😚','🙂','🤗','🤩','🤔','🤨','😐','😑','😶','🙄','😏','😣','😥','😮','🤐','😯','😪','😫','😴','😌','😛','😜','😝','🤤','😒','😓','😔','😕','🙃','🤑','😲','🙁','😖','😞','😟','😤','😢','😭','😦','😧','😨','😩','🤯','😬','😰','😱'],
    '手势': ['👋','🤚','🖐','✋','🖖','👌','🤌','🤏','✌','🤞','🤟','🤘','🤙','👈','👉','👆','🖕','👇','☝','👍','👎','✊','👊','🤛','🤜','👏','🙌','👐','🤲','🤝','🙏','✍','💪','🦾','🦿','🦵','🦶','👂','🦻','👃','👀','👁','🧠','🦷','🦴','👅','👄'],
    '物品': ['⌚','📱','💻','⌨','🖥','🖨','🖱','🖲','🕹','💽','💾','💿','📀','📷','📹','🎥','📞','☎','📟','📠','📺','📻','🎙','🎚','🎛','🧭','⏱','⏲','⏰','🕰','⌛','⏳','📡','🔋','🔌','💡','🔦','🕯','🧯','🛢','💸','💵','💴','💶','💷','💰','💳','💎','⚖','🧰','🔧','🔨','⚒','🛠','⛏','🔩','⚙','🧱','⛓','🧲','🔫','💣','🧨','🪓','🔪','🗡','⚔','🛡','🚬','⚰','⚱','🏺','🔮','📿','🧿','💈'],
    '自然': ['🌸','💐','🌷','🌹','🥀','🌺','🌻','🌼','🌱','🌲','🌳','🌴','🌵','🌾','🌿','☘','🍀','🍁','🍂','🍃','🍄','🌰','🦀','🦞','🦐','🦑','🐙','🐚','🐌','🦋','🐛','🐜','🐝','🐞','🦗','🕷','🦂','🐢','🐍','🦎','🐊','🐅','🐆','🦓','🦍','🦧','🐘','🦛','🦏','🐪','🐫','🦒','🦘','🐃','🐂','🐄','🐎','🐖','🐏','🐑','🦙','🐐','🐕','🐩','🦮','🐈','🐓','🦃','🦚','🦜','🦢','🦩','🕊','🐇','🦝','🦨','🦡','🦦','🦥','🐁','🐀','🐿','🦔'],
    '符号': ['❤','🧡','💛','💚','💙','💜','🖤','🤍','🤎','💔','❣','💕','💞','💓','💗','💖','💘','💝','⭐','🌟','✨','⚡','🔥','💥','☀','🌈','☁','🌧','⛈','🌩','❄','☃','⛄','💨','🌊','💧','💦','☔','🎵','🎶','🔔','🔕','📣','📢','💬','💭','🏁','🚩','🎌','🏴','🏳','✅','❌','❓','❗','‼','⁉','⭕','🔴','🟠','🟡','🟢','🔵','🟣','⚫','⚪','🟤','🔶','🔷','🔸','🔹','🔺','🔻','💠','🔘','🔳','🔲']
};

function EmojiPicker(input, opts) {
    opts = opts || {};
    this.input = input;
    this.onSelect = opts.onSelect || null;
    this.panel = null;
    this.currentTab = '常用';
    this._init();
}

EmojiPicker.prototype._init = function() {
    var self = this;
    var wrap = document.createElement('div');
    wrap.className = 'emoji-picker-wrap';

    // 预览按钮
    var preview = document.createElement('span');
    preview.className = 'emoji-picker-preview';
    preview.title = '选择图标';
    preview.textContent = this.input.value || '+';

    // 隐藏原始 input 或保留
    this.input.style.display = 'none';
    this.input.parentNode.insertBefore(wrap, this.input);
    wrap.appendChild(preview);
    wrap.appendChild(this.input);

    this.preview = preview;

    // 面板
    var panel = document.createElement('div');
    panel.className = 'emoji-picker-panel';
    wrap.appendChild(panel);
    this.panel = panel;

    this._buildPanel();

    // 点击预览打开/关闭
    preview.addEventListener('click', function(e) {
        e.stopPropagation();
        if (panel.classList.contains('show')) {
            panel.classList.remove('show');
        } else {
            self._closeAll();
            panel.classList.add('show');
        }
    });

    // 点击外部关闭
    document.addEventListener('click', function(e) {
        if (!wrap.contains(e.target)) {
            panel.classList.remove('show');
        }
    });

    // 监听 input 值变化
    var obs = new MutationObserver(function() {
        preview.textContent = self.input.value || '+';
    });
    this.input.addEventListener('change', function() {
        preview.textContent = self.input.value || '+';
    });
};

EmojiPicker.prototype._closeAll = function() {
    document.querySelectorAll('.emoji-picker-panel.show').forEach(function(p) {
        p.classList.remove('show');
    });
};

EmojiPicker.prototype._buildPanel = function() {
    var self = this;
    var panel = this.panel;
    panel.innerHTML = '';

    // tabs
    var tabs = document.createElement('div');
    tabs.className = 'emoji-picker-tabs';
    var categories = Object.keys(EMOJI_DATA);
    categories.forEach(function(cat) {
        var tab = document.createElement('span');
        tab.className = 'ep-tab' + (cat === self.currentTab ? ' active' : '');
        tab.textContent = cat;
        tab.addEventListener('click', function(e) {
            e.stopPropagation();
            self.currentTab = cat;
            self._buildPanel();
            self.panel.classList.add('show');
        });
        tabs.appendChild(tab);
    });
    panel.appendChild(tabs);

    // grid
    var grid = document.createElement('div');
    grid.className = 'emoji-picker-grid';
    var emojis = EMOJI_DATA[this.currentTab] || [];
    emojis.forEach(function(em) {
        var span = document.createElement('span');
        span.textContent = em;
        span.addEventListener('click', function(e) {
            e.stopPropagation();
            self.input.value = em;
            self.preview.textContent = em;
            panel.classList.remove('show');
            // 触发 change 事件
            self.input.dispatchEvent(new Event('change', {bubbles: true}));
            if (self.onSelect) self.onSelect(em);
        });
        grid.appendChild(span);
    });
    panel.appendChild(grid);

    // 清除按钮
    var clearWrap = document.createElement('div');
    clearWrap.className = 'emoji-picker-clear';
    var clearBtn = document.createElement('button');
    clearBtn.type = 'button';
    clearBtn.textContent = '清除图标';
    clearBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        self.input.value = '';
        self.preview.textContent = '+';
        panel.classList.remove('show');
        self.input.dispatchEvent(new Event('change', {bubbles: true}));
        if (self.onSelect) self.onSelect('');
    });
    clearWrap.appendChild(clearBtn);
    panel.appendChild(clearWrap);
};

window.EmojiPicker = EmojiPicker;
})();
