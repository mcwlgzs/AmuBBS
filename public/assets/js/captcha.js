/**
 * 验证码组件 - 支持 numeric/alpha/slider 三种模式
 * 用法: new CaptchaWidget(containerEl, { scene: 'login', onVerified: fn })
 */
class CaptchaWidget {
    constructor(el, opts = {}) {
        this.el = el;
        this.scene = opts.scene || '';
        this.onVerified = opts.onVerified || function(){};
        this.captchaId = '';
        this.captchaAnswer = '';
        this.verified = false;
        this.type = '';
        this.init();
    }

    async init() {
        this.el.innerHTML = '<div style="color:var(--text-muted);font-size:13px;">加载验证码...</div>';
        await this.load();
    }

    async load() {
        this.verified = false;
        this.captchaId = '';
        this.captchaAnswer = '';
        try {
            const resp = await fetch('/captcha/generate?scene=' + encodeURIComponent(this.scene));
            const data = await resp.json();
            if (!data.success) {
                var errDiv = document.createElement('div');
                errDiv.style.cssText = 'color:var(--danger);font-size:13px;';
                errDiv.textContent = data.message || '验证码加载失败';
                this.el.innerHTML = '';
                this.el.appendChild(errDiv);
                return;
            }
            this.type = data.data.type;
            this.captchaId = data.data.captcha_id;
            if (this.type === 'slider') {
                this.renderSlider(data.data);
            } else {
                this.renderImage(data.data);
            }
        } catch (e) {
            this.el.innerHTML = '<div style="color:var(--danger);font-size:13px;">验证码加载失败</div>';
        }
    }

    renderImage(data) {
        // 清理上一次 slider 的 document 级事件监听器
        if (this._cleanup) { this._cleanup(); this._cleanup = null; }
        this.el.innerHTML = '';
        const wrap = document.createElement('div');
        wrap.className = 'captcha-image-wrap';

        const img = document.createElement('img');
        img.src = data.image;
        img.alt = '验证码';
        img.title = '点击刷新';
        img.addEventListener('click', () => this.load());

        const input = document.createElement('input');
        input.type = 'text';
        input.placeholder = '输入验证码';
        input.maxLength = 6;
        input.autocomplete = 'off';
        input.addEventListener('input', () => {
            this.captchaAnswer = input.value.trim();
            this.verified = this.captchaAnswer.length >= 4;
            this.onVerified(this.verified, this.captchaId, this.captchaAnswer);
        });

        const refreshBtn = document.createElement('button');
        refreshBtn.type = 'button';
        refreshBtn.className = 'captcha-refresh-btn';
        refreshBtn.title = '刷新验证码';
        refreshBtn.innerHTML = '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M23 4v6h-6"/><path d="M1 20v-6h6"/><path d="M3.51 9a9 9 0 0114.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0020.49 15"/></svg>';
        refreshBtn.addEventListener('click', () => this.load());

        wrap.appendChild(img);
        wrap.appendChild(input);
        wrap.appendChild(refreshBtn);
        this.el.appendChild(wrap);
    }

    renderSlider(data) {
        this.el.innerHTML = '';
        // 清理上一次的事件
        if (this._cleanup) this._cleanup();

        const origW = data.width;
        const origH = data.height;
        const ps = data.pieceSize;
        const tY = data.targetY;

        // 背景图 + 拼图块
        const sliderWrap = document.createElement('div');
        sliderWrap.className = 'captcha-slider-wrap';

        const bgImg = document.createElement('img');
        bgImg.className = 'captcha-slider-bg';
        bgImg.src = data.image;
        bgImg.draggable = false;

        const piece = document.createElement('img');
        piece.className = 'captcha-slider-piece';
        piece.src = data.thumb;
        piece.draggable = false;

        sliderWrap.appendChild(bgImg);
        sliderWrap.appendChild(piece);

        // 滑块轨道
        const track = document.createElement('div');
        track.className = 'captcha-slider-track';

        const fill = document.createElement('div');
        fill.className = 'captcha-slider-track-fill';

        const thumb = document.createElement('div');
        thumb.className = 'captcha-slider-thumb';
        thumb.innerHTML = '→';

        const hint = document.createElement('div');
        hint.className = 'captcha-slider-hint';
        hint.textContent = '拖动滑块完成验证';

        track.appendChild(fill);
        track.appendChild(thumb);
        track.appendChild(hint);

        this.el.appendChild(sliderWrap);
        this.el.appendChild(track);

        // 获取实际渲染尺寸的辅助函数
        const getScale = () => {
            const renderedW = sliderWrap.offsetWidth;
            return renderedW / origW;
        };

        // 等背景图加载后定位拼图块 Y 坐标
        const positionPiece = () => {
            const scale = getScale();
            piece.style.width = (ps * scale) + 'px';
            piece.style.height = (ps * scale) + 'px';
            piece.style.top = (tY * scale) + 'px';
            piece.style.left = '0px';
            piece.style.transform = 'translateX(0px)';
        };
        bgImg.onload = positionPiece;
        // 也立即尝试一次（图片可能已缓存）
        if (bgImg.complete) positionPiece();

        // 滑动逻辑
        const thumbW = 36;
        let dragging = false, startX = 0, currentX = 0;

        const onStart = (e) => {
            if (this.verified) return;
            dragging = true;
            startX = (e.touches ? e.touches[0].clientX : e.clientX);
            hint.style.display = 'none';
            track.classList.remove('captcha-success', 'captcha-fail');
        };

        const onMove = (e) => {
            if (!dragging) return;
            e.preventDefault();
            const trackW = track.offsetWidth;
            const maxDx = trackW - thumbW;
            const clientX = e.touches ? e.touches[0].clientX : e.clientX;
            let dx = Math.max(0, Math.min(clientX - startX, maxDx));
            currentX = dx;
            thumb.style.transform = 'translateX(' + dx + 'px)';
            fill.style.width = (dx + thumbW / 2) + 'px';
            // 移动拼图块（映射到渲染宽度）
            const scale = getScale();
            const maxPieceX = origW - ps;
            const ratio = dx / maxDx;
            const pieceX = ratio * maxPieceX * scale;
            piece.style.transform = 'translateX(' + pieceX + 'px)';
        };

        const onEnd = () => {
            if (!dragging) return;
            dragging = false;
            const trackW = track.offsetWidth;
            const maxDx = trackW - thumbW;
            if (maxDx <= 0) return;
            // 映射到原始图片坐标
            const ratio = currentX / maxDx;
            const answerX = Math.round(ratio * (origW - ps));
            this.captchaAnswer = String(answerX);
            this.verifySlider(track, thumb, fill, piece, hint);
        };

        thumb.addEventListener('mousedown', onStart);
        thumb.addEventListener('touchstart', onStart, { passive: true });
        document.addEventListener('mousemove', onMove);
        document.addEventListener('touchmove', onMove, { passive: false });
        document.addEventListener('mouseup', onEnd);
        document.addEventListener('touchend', onEnd);

        this._cleanup = () => {
            document.removeEventListener('mousemove', onMove);
            document.removeEventListener('touchmove', onMove);
            document.removeEventListener('mouseup', onEnd);
            document.removeEventListener('touchend', onEnd);
        };
    }

    async verifySlider(track, thumb, fill, piece, hint) {
        try {
            const resp = await fetch('/captcha/verify', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content || ''
                },
                body: JSON.stringify({ captcha_id: this.captchaId, captcha_answer: this.captchaAnswer })
            });
            const data = await resp.json();
            if (data.success) {
                track.classList.add('captcha-success');
                this.verified = true;
                // 使用服务端返回的新 captcha_id 和 answer（原始的已被消费）
                this.captchaId = data.captcha_id;
                this.captchaAnswer = data.captcha_answer;
                this.onVerified(true, this.captchaId, this.captchaAnswer);
            } else {
                track.classList.add('captcha-fail');
                setTimeout(() => this.load(), 800);
            }
        } catch (e) {
            track.classList.add('captcha-fail');
            setTimeout(() => this.load(), 800);
        }
    }

    getValues() {
        return { captcha_id: this.captchaId, captcha_answer: this.captchaAnswer };
    }

    destroy() {
        if (this._cleanup) this._cleanup();
        this.el.innerHTML = '';
    }
}

/**
 * Alpine.js 组件工厂 - 在表单中使用
 * 用法: x-data="captchaWidget('login')"
 */
function captchaWidget(scene) {
    return {
        captchaId: '',
        captchaAnswer: '',
        captchaVerified: false,
        _widget: null,
        init() {
            const container = this.$refs.captchaContainer;
            if (!container) return;
            this._widget = new CaptchaWidget(container, {
                scene: scene,
                onVerified: (ok, id, answer) => {
                    this.captchaVerified = ok;
                    this.captchaId = id;
                    this.captchaAnswer = answer;
                }
            });
        },
        refreshCaptcha() {
            if (this._widget) this._widget.load();
        },
        getCaptchaData() {
            return { captcha_id: this.captchaId, captcha_answer: this.captchaAnswer };
        }
    };
}
