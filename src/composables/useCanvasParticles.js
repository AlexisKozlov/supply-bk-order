/**
 * useCanvasParticles — анимация фона с «тлеющими углями» (orbs + sparks).
 *
 * Используется в HomeView и TelegramLinkView.
 *
 * @param {import('vue').Ref<HTMLCanvasElement|null>} canvasRef — ref на <canvas>
 * @param {Object} [opts]
 * @param {Array}  [opts.orbs]        — массив orb-объектов (по умолчанию 3 стандартных)
 * @param {number} [opts.sparkCount]  — кол-во искр (по умолчанию 30)
 * @param {boolean}[opts.hotCenter]   — рисовать горячий центр у orbs (по умолчанию false)
 * @param {boolean}[opts.grain]       — рисовать зерно-шум (по умолчанию false)
 * @param {'window'|'parent'} [opts.sizeSource] — откуда брать размеры (по умолчанию 'window')
 *
 * @returns {{ start: () => void, stop: () => void }}
 */
export function useCanvasParticles(canvasRef, opts = {}) {
  const {
    orbs: orbsDef = [
      { x: 0.3, y: 0.7, r: 300, rgb: [180, 30, 0], sp: 0.4, ph: 0 },
      { x: 0.7, y: 0.65, r: 250, rgb: [200, 60, 0], sp: 0.3, ph: 2 },
      { x: 0.5, y: 0.8, r: 350, rgb: [160, 40, 0], sp: 0.5, ph: 4 },
    ],
    sparkCount = 30,
    hotCenter = false,
    grain = false,
    sizeSource = 'window',
  } = opts;

  let rafId = null;
  let resizeFn = null;

  function start() {
    stop(); // убрать предыдущую анимацию, если была

    const c = canvasRef.value;
    if (!c) return;
    const ctx = c.getContext('2d');
    let w, h;

    const resize = () => {
      if (sizeSource === 'parent') {
        const p = c.parentElement;
        if (!p) return;
        w = c.width = p.clientWidth;
        h = c.height = p.clientHeight;
      } else {
        w = c.width = window.innerWidth;
        h = c.height = window.innerHeight;
      }
    };
    resize();
    resizeFn = resize;
    window.addEventListener('resize', resizeFn);

    const orbs = orbsDef.map(o => ({ ...o }));
    const sparks = Array.from({ length: sparkCount }, () => ({
      x: Math.random(), y: Math.random(),
      vy: -(0.0002 + Math.random() * 0.0008),
      vx: (Math.random() - 0.5) * 0.0003,
      r: 0.5 + Math.random() * 1.5,
      ph: Math.random() * Math.PI * 2,
      sp: 0.01 + Math.random() * 0.02,
      bright: Math.random(),
    }));

    let t = 0;
    const loop = () => {
      t += 0.016;
      if (w <= 0 || h <= 0) { rafId = requestAnimationFrame(loop); return; }

      // Фоновый градиент
      const bg = ctx.createLinearGradient(0, 0, 0, h);
      bg.addColorStop(0, '#110a05');
      bg.addColorStop(0.4, '#1a0e07');
      bg.addColorStop(0.7, '#221309');
      bg.addColorStop(1, '#2a180c');
      ctx.fillStyle = bg;
      ctx.fillRect(0, 0, w, h);

      // Дышащие orbs
      for (const o of orbs) {
        const breath = 0.6 + 0.4 * Math.sin(t * o.sp + o.ph);
        const r = o.r * (0.9 + breath * 0.2);
        const px = o.x * w + Math.sin(t * 0.2 + o.ph) * 20;
        const py = o.y * h + Math.cos(t * 0.15 + o.ph) * 15;
        const g = ctx.createRadialGradient(px, py, 0, px, py, r);
        const a = 0.08 + breath * 0.06;
        g.addColorStop(0, `rgba(${o.rgb},${a * 1.5})`);
        g.addColorStop(0.3, `rgba(${o.rgb},${a * 0.8})`);
        g.addColorStop(0.7, `rgba(${o.rgb},${a * 0.2})`);
        g.addColorStop(1, `rgba(${o.rgb},0)`);
        ctx.fillStyle = g;
        ctx.fillRect(0, 0, w, h);

        if (hotCenter) {
          const g2 = ctx.createRadialGradient(px, py, 0, px, py, r * 0.15);
          g2.addColorStop(0, `rgba(255,200,120,${breath * 0.06})`);
          g2.addColorStop(1, 'rgba(255,100,0,0)');
          ctx.fillStyle = g2;
          ctx.fillRect(px - r * 0.2, py - r * 0.2, r * 0.4, r * 0.4);
        }
      }

      // Искры
      for (const s of sparks) {
        s.ph += s.sp;
        s.x += s.vx + Math.sin(s.ph) * 0.0002;
        s.y += s.vy;
        if (s.y < -0.05) { s.y = 1.05; s.x = Math.random(); }
        const px = s.x * w, py = s.y * h;
        const glow = 0.3 + 0.7 * (0.5 + 0.5 * Math.sin(s.ph * 2));
        ctx.globalAlpha = glow * (s.bright > 0.7 ? 0.8 : 0.4);
        ctx.shadowBlur = s.r * 6;
        ctx.shadowColor = s.bright > 0.7 ? '#FFB060' : '#D65000';
        ctx.beginPath();
        ctx.arc(px, py, s.r * glow, 0, Math.PI * 2);
        ctx.fillStyle = s.bright > 0.7 ? '#FFD090' : '#FF8040';
        ctx.fill();
      }
      ctx.globalAlpha = 1;
      ctx.shadowBlur = 0;

      // Зерно-шум
      if (grain) {
        ctx.globalAlpha = 0.012;
        for (let i = 0; i < 200; i++) {
          ctx.fillStyle = Math.random() > 0.5 ? '#F5E6D0' : '#000';
          ctx.fillRect(Math.random() * w, Math.random() * h, 1, 1);
        }
        ctx.globalAlpha = 1;
      }

      rafId = requestAnimationFrame(loop);
    };
    loop();
  }

  function stop() {
    if (rafId) { cancelAnimationFrame(rafId); rafId = null; }
    if (resizeFn) { window.removeEventListener('resize', resizeFn); resizeFn = null; }
  }

  return { start, stop };
}
