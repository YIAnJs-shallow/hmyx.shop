/* ===== 登录/注册页 Canvas 天空背景（南极雪原夜空） =====
 * 三层按帧序绘制：① 漫天繁星 + 银河雾 ② 极光帘幕 ③ 雪原反光
 * 纯手写 Canvas 2D，无第三方库；requestAnimationFrame 驱动：
 *   - devicePixelRatio 上限 1.5
 *   - visibilitychange 页面隐藏即暂停循环
 *   - resize 防抖 200ms 重建画布与星表
 *   - prefers-reduced-motion：帧率降至 8fps 且极光/反射振幅减半（放缓不隐藏）
 */
(function () {
    'use strict';

    var canvas = document.getElementById('sky-canvas');
    if (!canvas || !canvas.getContext) return;
    var ctx = canvas.getContext('2d');

    var MAX_DPR = 1.5;
    var REDUCED = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
    var FRAME_GAP = REDUCED ? 125 : 0;   // 8fps ≈ 125ms/帧
    var AMP = REDUCED ? 0.5 : 1;        // 振幅减半

    var W = 0, H = 0;                   // 画布 CSS 尺寸
    var stars = [];                     // 星表
    var bands = [];                     // 极光光带参数表
    var mwFog = null;                   // 银河雾离屏贴图
    var snowImg = null;                 // 雪原反光离屏贴图
    var simT = 0, lastTs = 0, rafId = 0, lastFrame = 0;

    /* 南极极光四色（沿横向插值）：主绿 / 青 / 深蓝 / 紫 */
    var AURORA_COLORS = [[0, 255, 135], [96, 239, 255], [0, 97, 255], [157, 80, 187]];
    /* 星色温：70% 冷白 / 20% 暖白 / 10% 淡蓝 */
    var STAR_COLORS = [[223, 233, 255], [255, 244, 224], [165, 200, 255]];

    function rand(a, b) { return a + Math.random() * (b - a); }

    /* 近似高斯（银河带散布用） */
    function gauss() { return (Math.random() + Math.random() + Math.random() + Math.random() - 2) / 2; }

    function rgb(c, a) { return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',' + a + ')'; }

    /* 极光调色板：u∈[0,1] 在四色间循环插值 */
    function auroraColor(u) {
        var p = ((u % 1) + 1) % 1 * 4, i = Math.floor(p), f = p - i;
        var c1 = AURORA_COLORS[i], c2 = AURORA_COLORS[(i + 1) % 4];
        return [Math.round(c1[0] + (c2[0] - c1[0]) * f),
                Math.round(c1[1] + (c2[1] - c1[1]) * f),
                Math.round(c1[2] + (c2[2] - c1[2]) * f)];
    }

    /* ---------- 初始化：画布 / 星表 / 光带 / 流星 / 离屏贴图 ---------- */
    function build() {
        W = window.innerWidth;
        H = window.innerHeight;
        var dpr = Math.min(window.devicePixelRatio || 1, MAX_DPR);
        canvas.width = Math.max(2, Math.round(W * dpr));
        canvas.height = Math.max(2, Math.round(H * dpr));
        canvas.style.width = W + 'px';
        canvas.style.height = H + 'px';
        ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

        /* 星表：260 颗，35% 撒入 -25deg 银河对角带（高斯散布） */
        stars = [];
        var i, u, x, y, size, r, col;
        /* 银河中心线：右上 (0.9W, -0.1H) → 左下 (0.1W, 1.1H) */
        var ldx = -0.8 * W, ldy = 1.2 * H, llen = Math.hypot(ldx, ldy);
        for (i = 0; i < 260; i++) {
            size = rand(0.5, 2.2);
            r = Math.random();
            col = STAR_COLORS[r < 0.7 ? 0 : (r < 0.9 ? 1 : 2)];
            if (Math.random() < 0.35) {
                u = Math.random();
                x = W * (0.9 - 0.8 * u);
                y = H * (-0.1 + 1.2 * u);
                var off = gauss() * H * 0.11;   // 带宽 ≈22% 高度
                x += (-ldy / llen) * off;
                y += (ldx / llen) * off;
            } else {
                x = Math.random() * W;
                y = Math.random() * H;
            }
            stars.push({
                x: x, y: y, size: size, col: col,
                base: rand(0.35, 0.75), amp: rand(0.15, 0.35),
                freq: rand(0.6, 2.2), phase: rand(0, Math.PI * 2)
            });
        }

        /* 极光光带：5 条铺满页宽 130%，各参数略异（k1≈0.008/w1≈0.6/k2≈0.02/w2≈1.4，A1≈60/A2≈25） */
        bands = [];
        var bandW = W * 1.3 / 5;
        for (i = 0; i < 5; i++) {
            var b = {
                x0: -W * 0.15 + i * bandW,
                w: bandW,
                cols: 80,
                base: H * (0.42 + 0.05 * Math.sin(i * 2.1)),
                k1: 0.008 * rand(0.9, 1.1), w1: 0.6 * rand(0.85, 1.15),
                k2: 0.02 * rand(0.9, 1.1), w2: 1.4 * rand(0.85, 1.15),
                A1: 60 * rand(0.9, 1.1) * AMP, A2: 25 * rand(0.9, 1.1) * AMP,
                tOff: i * 1.7,
                colorCache: []
            };
            var ci;
            for (ci = 0; ci <= b.cols; ci++) b.colorCache.push(auroraColor((b.x0 + ci * b.w / b.cols) / W));
            b.edgeColor = b.colorCache[Math.floor(b.cols / 2)];
            bands.push(b);
        }


        /* 银河雾贴图（离屏，-25deg 淡雾长带） */
        mwFog = document.createElement('canvas');
        mwFog.width = Math.max(2, Math.round(W));
        mwFog.height = Math.max(2, Math.round(H));
        var mctx = mwFog.getContext('2d');
        mctx.translate(W / 2, H / 2);
        mctx.rotate(-25 * Math.PI / 180);
        var bandH = H * 0.44;
        var mg = mctx.createLinearGradient(0, -bandH / 2, 0, bandH / 2);
        mg.addColorStop(0, 'rgba(200,215,255,0)');
        mg.addColorStop(0.45, 'rgba(200,215,255,0.06)');
        mg.addColorStop(0.55, 'rgba(200,215,255,0.07)');
        mg.addColorStop(1, 'rgba(200,215,255,0)');
        mctx.fillStyle = mg;
        mctx.fillRect(-W, -bandH / 2, W * 2, bandH);

        /* 雪原反光贴图（离屏，底部 12% 蓝白渐变） */
        snowImg = document.createElement('canvas');
        snowImg.width = Math.max(2, Math.round(W));
        snowImg.height = Math.max(2, Math.round(H * 0.12) + 2);
        var sctx = snowImg.getContext('2d');
        var sg = sctx.createLinearGradient(0, snowImg.height, 0, 0);
        sg.addColorStop(0, 'rgba(140,180,220,0.10)');
        sg.addColorStop(1, 'rgba(140,180,220,0)');
        sctx.fillStyle = sg;
        sctx.fillRect(0, 0, snowImg.width, snowImg.height);
    }

    /* ---------- ① 星空层：260 颗闪烁星 + 银河雾 ---------- */
    function drawStars(t) {
        if (mwFog) ctx.drawImage(mwFog, 0, 0);
        var i, s, a;
        for (i = 0; i < stars.length; i++) {
            s = stars[i];
            a = s.base + s.amp * Math.sin(t * s.freq + s.phase);
            if (a <= 0.05) continue;
            if (s.size > 1.6) {                      // 近景大星加 4px 同色微光晕
                ctx.shadowBlur = 4;
                ctx.shadowColor = rgb(s.col, Math.min(1, a));
            }
            ctx.fillStyle = rgb(s.col, a);
            ctx.fillRect(s.x, s.y, s.size, s.size);
            ctx.shadowBlur = 0;
        }
    }

    /* ---------- ② 极光帘幕：5 条光带 × ~80 根竖条，双正弦波 + lighter 叠加 ---------- */
    function drawAurora(t) {
        ctx.globalCompositeOperation = 'lighter';
        var bi, b, i, step, x, bottom, bottoms;
        for (bi = 0; bi < bands.length; bi++) {
            b = bands[bi];
            step = b.w / b.cols;
            bottoms = [];
            for (i = 0; i <= b.cols; i++) {
                x = b.x0 + i * step;
                bottom = b.base
                    + Math.sin(x * b.k1 + t * b.w1 + b.tOff) * b.A1
                    + Math.sin(x * b.k2 + t * b.w2 + b.tOff * 1.3) * b.A2;
                bottoms.push(bottom);
                if (bottom > 0) {
                    var g = ctx.createLinearGradient(x, 0, x, bottom);
                    g.addColorStop(0, rgb(b.colorCache[i], 0));
                    g.addColorStop(1, rgb(b.colorCache[i], 0.55));
                    ctx.fillStyle = g;
                    ctx.fillRect(x, 0, step + 1, bottom);
                }
            }
            /* 底缘亮边：同相位弧形亮线（alpha 0.8、2px、shadowBlur 8） */
            ctx.beginPath();
            for (i = 0; i <= b.cols; i++) {
                x = b.x0 + i * step;
                if (i === 0) ctx.moveTo(x, bottoms[i]);
                else ctx.lineTo(x, bottoms[i]);
            }
            ctx.strokeStyle = rgb(b.edgeColor, 0.8);
            ctx.lineWidth = 2;
            ctx.shadowBlur = 8;
            ctx.shadowColor = rgb(b.edgeColor, 0.8);
            ctx.stroke();
            ctx.shadowBlur = 0;
        }
        ctx.globalCompositeOperation = 'source-over';
    }


    /* ---------- ③ 雪原反光：离屏贴图 + 极光主色横向椭圆（宽度呼吸） ---------- */
    function drawSnow(t) {
        if (!snowImg) return;
        ctx.drawImage(snowImg, 0, H - snowImg.height);
        ctx.globalCompositeOperation = 'lighter';
        var c = auroraColor((Math.sin(t * 0.05) + 1) / 2);   // 跟随极光主色调缓慢变幻
        var rx = W * (0.35 + 0.05 * AMP * Math.sin(t * 0.3)); // 宽度呼吸
        ctx.save();
        ctx.translate(W / 2, H - snowImg.height * 0.35);
        ctx.scale(1, 0.18);                                  // 压扁成横向拉长椭圆
        var g = ctx.createRadialGradient(0, 0, 0, 0, 0, rx);
        g.addColorStop(0, rgb(c, 0.05));
        g.addColorStop(1, rgb(c, 0));
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.arc(0, 0, rx, 0, Math.PI * 2);
        ctx.fill();
        ctx.restore();
        ctx.globalCompositeOperation = 'source-over';
    }

    /* ---------- 渲染循环 ---------- */
    function render(t) {
        ctx.clearRect(0, 0, W, H);
        drawStars(t);
        drawAurora(t);
        drawSnow(t);
    }

    function frame(ts) {
        if (!lastTs) lastTs = ts;
        var dt = (ts - lastTs) / 1000;
        lastTs = ts;
        if (dt > 0) simT += Math.min(dt, 0.1);     // 页面隐藏恢复/掉帧时防时间跳变
        if (FRAME_GAP && ts - lastFrame < FRAME_GAP) {
            rafId = requestAnimationFrame(frame);
            return;
        }
        lastFrame = ts;
        render(simT);
        rafId = requestAnimationFrame(frame);
    }

    function start() {
        if (!rafId) rafId = requestAnimationFrame(frame);
    }
    function stop() {
        if (rafId) { cancelAnimationFrame(rafId); rafId = 0; }
        lastTs = 0;
    }

    /* 页面隐藏即暂停循环，恢复时续播 */
    document.addEventListener('visibilitychange', function () {
        if (document.hidden) stop(); else start();
    });

    /* resize 防抖 200ms：重建画布与星表/贴图 */
    var resizeTimer = 0;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(build, 200);
    });

    build();
    start();
})();
