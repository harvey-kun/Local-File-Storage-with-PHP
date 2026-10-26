/*
    GradientWaves (React Bits) ported to plain JavaScript, no React needed.
    Usage:  const gw = new GradientWaves(containerElement, { ...options });
            gw.update({ horizonColor: "#123456" });   // change options at any time
            gw.destroy();
    Option names are the same as the React props (see the table in the React Bits docs).
    "colors" may be a function returning { horizonColor, waveColor, crestColor, lightMode }; it is called every few frames,
    so the waves follow the page theme (including the colours that change while a song plays).

    This file also holds GridMotion (React Bits), a drifting wall of tiles used on the login page.
    Usage:  const gm = new GridMotion(containerElement, { items: ["a.jpg", "b.jpg", "text tile"], rows: 4 });
            or  { source: "loginwall.php?list=1" }  to load the tiles from a URL that returns { "items": [ ... ] }.
            gm.update({ speed: 10 });  gm.setItems([...]);  gm.destroy();
    Tiles use the page's own colours (var(--card), var(--text), var(--line)) so they follow light / dark mode.
*/
(function () {
    const MAX_SWELLS = 4, SWELL_SECONDS = 3.2, SWELL_GAIN = 2.8, SWELL_WIDTH = 8, INTRO_SECONDS = 2.6, CAMERA_HEIGHT = 30;
    const QUALITY = { low: 56, medium: 84, high: 120 };

    const VERTEX = `#version 300 es
in vec2 aPosition;
void main() {
  gl_Position = vec4(aPosition, 0.0, 1.0);
}`;

    const FRAGMENT = `#version 300 es
precision highp float;
uniform vec2 uResolution;
uniform float uTime;
uniform float uClock;
uniform float uAmplitude;
uniform float uWaveScale;
uniform float uWaveRatio;
uniform float uSwell;
uniform float uTurbulence;
uniform float uHeight;
uniform float uTilt;
uniform float uZoom;
uniform vec2 uLook;
uniform float uFogDepth;
uniform float uBrightness;
uniform float uOpacity;
uniform float uGrain;
uniform vec3 uHorizon;
uniform vec3 uWave;
uniform vec3 uCrest;
uniform vec3 uBackground;
uniform float uOpaque;
uniform float uLight;
uniform vec3 uSun;
uniform float uRelief;
uniform float uSheen;
uniform float uSteps;
uniform float uStride;
uniform vec3 uIntro;
uniform vec3 uSwells[${MAX_SWELLS}];
uniform float uSwellWidth;
out vec4 outColor;

const float FAR = 4000.0;

float hash(vec2 p) {
  vec3 p3 = fract(vec3(p.xyx) * 0.1031);
  p3 += dot(p3, p3.yzx + 33.33);
  return fract((p3.x + p3.y) * p3.z);
}

vec3 surface(vec2 xy) {
  vec2 freq = vec2(uWaveScale / 7.0, (uWaveScale * uWaveRatio) / 3.0);
  vec4 tc = vec4(uTime / 0.130, uTime / 0.810, uTime / 0.200, uTime / 0.710);
  float a = xy.x + tc.x;
  float swellPhase = (xy.y + a) / 20.0 + tc.y;
  float bend = uSwell * cos(swellPhase) / 20.0;
  float mx = a + uSwell * sin(swellPhase);
  float crossPhase = xy.x / 23.0 + tc.w;
  float my = xy.y - tc.z + uTurbulence * cos(crossPhase);
  float twist = -uTurbulence * sin(crossPhase) / 23.0;
  float cx = cos(mx * freq.x);
  float cy = cos(my * freq.y);
  float rise = 1.0;
  if (uIntro.z > 0.0) rise = mix(1.0, smoothstep(uIntro.y * 0.45, uIntro.y * 1.6, xy.x), uIntro.z);
  float amp = uAmplitude * rise;
  float h = (sin(mx * freq.x) + sin(my * freq.y)) * amp + uHeight;
  float dx = amp * (freq.x * cx * (1.0 + bend) + freq.y * cy * twist);
  float dy = amp * (freq.x * cx * bend + freq.y * cy);
  for (int k = 0; k < ${MAX_SWELLS}; k++) {
    vec3 sw = uSwells[k];
    if (sw.z <= 0.0) continue;
    float u = (xy.x - sw.x) / uSwellWidth;
    float v = (xy.y - sw.y) / (uSwellWidth * 3.5);
    float bump = sw.z * exp(-u * u - v * v);
    h += bump;
    dx += bump * (-2.0 * u / uSwellWidth);
    dy += bump * (-2.0 * v / (uSwellWidth * 3.5));
  }
  return vec3(h, dx, dy);
}

float march(vec3 origin, vec3 dir) {
  float dist = 0.0;
  for (int i = 0; i < 128; i++) {
    if (float(i) >= uSteps) break;
    float gap = origin.z + dist * dir.z - surface(origin.xy + dist * dir.xy).x;
    if (abs(gap) < 0.002 * dist + 0.01) break;
    dist += uStride * gap;
    if (!(abs(dist) < FAR)) return FAR;
  }
  return dist;
}

void main() {
  float c;
  float s;
  float vfov = (3.14159 / 2.3) / max(uZoom, 0.05);
  vec3 cam = vec3(0.0, 0.0, ${CAMERA_HEIGHT.toFixed(1)});
  vec2 uv = gl_FragCoord.xy / uResolution - 0.5;
  uv.x *= uResolution.x / uResolution.y;
  uv.y *= -1.0;

  vec3 dir = vec3(0.0, 0.0, -1.0);
  float ulen = length(uv);
  float xrot = vfov * ulen;
  c = cos(xrot);
  s = sin(xrot);
  dir = mat3(1.0, 0.0, 0.0, 0.0, c, -s, 0.0, s, c) * dir;
  vec2 nuv = ulen > 1e-5 ? uv / ulen : vec2(1.0, 0.0);
  dir = mat3(nuv.x, -nuv.y, 0.0, nuv.y, nuv.x, 0.0, 0.0, 0.0, 1.0) * dir;
  c = cos(uTilt + uLook.y);
  s = sin(uTilt + uLook.y);
  dir = mat3(c, 0.0, s, 0.0, 1.0, 0.0, -s, 0.0, c) * dir;
  c = cos(uLook.x);
  s = sin(uLook.x);
  dir = mat3(c, s, 0.0, -s, c, 0.0, 0.0, 0.0, 1.0) * dir;

  float dist = march(cam, dir);
  vec3 pos = cam + dist * dir;
  vec3 field = surface(pos.xy);
  vec3 view = -dir;

  float crest = clamp((field.x - uHeight) / max(2.0 * uAmplitude, 0.001) * 0.5 + 0.5, 0.0, 1.0);
  vec3 body = mix(uWave, uCrest, smoothstep(0.45, 1.0, crest) * 0.5);
  vec3 normal = normalize(vec3(-field.yz * (1.0 + 1.5 * uRelief), 1.0));
  float diffuse = clamp(dot(normal, uSun) * 0.5 + 0.5, 0.0, 1.0);
  float lightness = mix(1.0, diffuse * diffuse * (3.0 - 2.0 * diffuse), uRelief);
  float sheen = pow(clamp(dot(normal, normalize(uSun + view)), 0.0, 1.0), 14.0) * uSheen * (0.4 + 0.6 * uRelief);
  float rim = pow(1.0 - clamp(dot(normal, view), 0.0, 1.0), 3.0);

  vec3 col;
  float alpha;
  float haze = 1.0 - exp(-dist / (uFogDepth * 5.0));
  float fade = 1.0 - smoothstep(0.35, 1.0, haze);
  if (uLight < 0.5) {
    vec3 lit = mix(uHorizon * 0.18, body, lightness) + uCrest * sheen;
    lit = mix(lit, uHorizon, rim * 0.15 * uRelief);
    col = mix(lit, uHorizon, smoothstep(0.0, 1.0, haze)) * 0.85 * uBrightness;
    alpha = fade;
  } else {
    vec3 hue = mix(uHorizon, body, 0.35 + 0.65 * lightness);
    float density = mix(0.16, 0.9, 1.0 - lightness);
    density *= 1.0 - clamp(sheen, 0.0, 1.0) * 0.85;
    density *= 1.0 - 0.5 * smoothstep(0.0, 1.0, haze);
    col = hue;
    alpha = fade * clamp(density * uBrightness, 0.0, 1.0);
  }

  float reveal = uIntro.x;
  col = clamp(col, 0.0, 1.0);
  float grain = (hash(gl_FragCoord.xy + fract(uClock) * 64.0) - 0.5) * uGrain;
  alpha = clamp(alpha * (1.0 + grain), 0.0, 1.0) * reveal * uOpacity;
  if (uOpaque > 0.5) {
    outColor = vec4(mix(uBackground, col, alpha), 1.0);
  } else {
    outColor = vec4(col * alpha, alpha);
  }
}`;

    const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
    const easeInOut = t => (t < 0.5 ? 2 * t * t : 1 - Math.pow(-2 * t + 2, 2) / 2);

    let probe = null;
    function parseColor(value, fallback) {
        if (!value || value === "transparent") return null;
        if (!probe) probe = document.createElement("canvas").getContext("2d");
        if (!probe) return fallback;
        probe.fillStyle = "#000000";
        probe.fillStyle = value;
        const text = probe.fillStyle;
        if (text.charAt(0) === "#") {
            const hex = parseInt(text.slice(1), 16);
            return [((hex >> 16) & 255) / 255, ((hex >> 8) & 255) / 255, (hex & 255) / 255];
        }
        const m = /rgba?\(([\d.]+),\s*([\d.]+),\s*([\d.]+)(?:,\s*([\d.]+))?/.exec(text);
        if (!m) return fallback;
        return [Number(m[1]) / 255, Number(m[2]) / 255, Number(m[3]) / 255];
    }

    function compile(gl, type, source) {
        const sh = gl.createShader(type);
        if (!sh) return null;
        gl.shaderSource(sh, source);
        gl.compileShader(sh);
        if (!gl.getShaderParameter(sh, gl.COMPILE_STATUS)) { console.error(gl.getShaderInfoLog(sh)); gl.deleteShader(sh); return null; }
        return sh;
    }

    const DEFAULTS = {
        horizonColor: "#5227FF", waveColor: "#FF9FFC", crestColor: "#FFFFFF", backgroundColor: "transparent", lightMode: false,
        speed: 0.4, amplitude: 2.5, waveScale: 0.6, waveRatio: 0.9, swell: 35, turbulence: 20, tilt: 64, zoom: 1, height: 5.5,
        fogDepth: 15, relief: 1, lightAngle: 120, sheen: 0, brightness: 1, opacity: 1, grain: 0.2, quality: "medium", intro: true,
        mouseInteraction: true, parallaxStrength: 0.5, mouseStrength: 0.6, swellOnClick: true, paused: false, dpr: 2, colors: null
    };

    const prefersReduced = () => !!(window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches);

    function GradientWaves(container, options) {
        this.container = container;
        this.opts = Object.assign({}, DEFAULTS, options || {});
        this.ok = this._init();
    }

    GradientWaves.prototype.update = function (o) {
        Object.assign(this.opts, o || {});
        this._settings();
        if (this._wake) this._wake();
    };

    GradientWaves.prototype._settings = function () {
        const o = this.opts;
        if (typeof o.colors === "function") {
            const c = o.colors() || {};
            ["horizonColor", "waveColor", "crestColor", "lightMode"].forEach(k => { if (c[k] !== undefined) o[k] = c[k]; });
        }
        this.s = {
            horizon: parseColor(o.horizonColor, [0.32, 0.15, 1]) || [0.32, 0.15, 1],
            wave: parseColor(o.waveColor, [1, 0.62, 0.99]) || [1, 0.62, 0.99],
            crest: parseColor(o.crestColor, [1, 1, 1]) || [1, 1, 1],
            paper: parseColor(o.backgroundColor, null),
            lightMode: !!o.lightMode, speed: o.speed,
            amplitude: Math.max(0, o.amplitude), waveScale: Math.max(0.01, o.waveScale), waveRatio: Math.max(0.01, o.waveRatio),
            swell: o.swell, turbulence: o.turbulence, tilt: (clamp(o.tilt, 10, 85) * Math.PI) / 180, zoom: Math.max(0.05, o.zoom),
            height: o.height, fogDepth: Math.max(0.5, o.fogDepth), relief: clamp(o.relief, 0, 1), lightAngle: (o.lightAngle * Math.PI) / 180,
            sheen: Math.max(0, o.sheen), brightness: Math.max(0, o.brightness), opacity: clamp(o.opacity, 0, 1), grain: Math.max(0, o.grain),
            steps: QUALITY[o.quality] || QUALITY.medium, intro: o.intro, mouseInteraction: o.mouseInteraction,
            parallaxStrength: Math.max(0, o.parallaxStrength), mouseStrength: clamp(o.mouseStrength, 0, 1), swellOnClick: o.swellOnClick,
            paused: o.paused, dpr: Math.max(0.5, o.dpr)
        };
    };

    GradientWaves.prototype._init = function () {
        const self = this, container = this.container;
        this._settings();

        const canvas = document.createElement("canvas");
        canvas.className = "gradient-waves-canvas";
        container.appendChild(canvas);
        const gl = canvas.getContext("webgl2", { alpha: true, antialias: false, depth: false, stencil: false, premultipliedAlpha: true, powerPreference: "high-performance" });
        if (!gl) { canvas.remove(); return false; }
        const vs = compile(gl, gl.VERTEX_SHADER, VERTEX), fs = compile(gl, gl.FRAGMENT_SHADER, FRAGMENT), program = gl.createProgram();
        if (!vs || !fs || !program) { canvas.remove(); return false; }
        gl.attachShader(program, vs); gl.attachShader(program, fs);
        gl.bindAttribLocation(program, 0, "aPosition");
        gl.linkProgram(program);
        gl.deleteShader(vs); gl.deleteShader(fs);
        if (!gl.getProgramParameter(program, gl.LINK_STATUS)) { gl.deleteProgram(program); canvas.remove(); return false; }

        const names = ["uResolution", "uTime", "uClock", "uAmplitude", "uWaveScale", "uWaveRatio", "uSwell", "uTurbulence", "uHeight", "uTilt", "uZoom", "uLook",
            "uFogDepth", "uBrightness", "uOpacity", "uGrain", "uHorizon", "uWave", "uCrest", "uBackground", "uOpaque", "uLight", "uSun", "uRelief", "uSheen",
            "uSteps", "uStride", "uIntro", "uSwells", "uSwellWidth"];
        const u = {};
        names.forEach(n => { u[n] = gl.getUniformLocation(program, n); });
        const vao = gl.createVertexArray();
        gl.bindVertexArray(vao);
        const buffer = gl.createBuffer();
        gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
        gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
        gl.enableVertexAttribArray(0);
        gl.vertexAttribPointer(0, 2, gl.FLOAT, false, 0, 0);
        gl.useProgram(program);

        const swellData = new Float32Array(MAX_SWELLS * 3);
        const st = { width: 1, height: 1, cssW: 0, cssH: 0, time: 0, clock: 0, last: 0, raf: 0, visible: false, introAt: -1,
                     pointer: [0.5, 0.5], inside: false, look: [0, 0], steer: [0, 0], swells: [], frame: 0 };

        const fit = () => {
            const ratio = Math.min(window.devicePixelRatio || 1, self.s.dpr);
            const w = Math.max(1, Math.round(st.cssW * ratio)), h = Math.max(1, Math.round(st.cssH * ratio));
            if (w === st.width && h === st.height) return;
            st.width = w; st.height = h; canvas.width = w; canvas.height = h;
        };
        const measure = () => { st.cssW = container.clientWidth; st.cssH = container.clientHeight; fit(); wake(); };

        const introProgress = (s, reduced) => (!s.intro || reduced || st.introAt < 0) ? 1 : clamp((st.clock - st.introAt) / INTRO_SECONDS, 0, 1);

        const rayAt = (pt, aspect, s, look) => {
            const uvx = (pt[0] - 0.5) * aspect, uvy = pt[1] - 0.5;
            const vfov = Math.PI / 2.3 / Math.max(s.zoom, 0.05);
            const len = Math.hypot(uvx, uvy), turn = vfov * len;
            let d = [0, -Math.sin(turn), -Math.cos(turn)];
            const nx = len > 1e-5 ? uvx / len : 1, ny = len > 1e-5 ? uvy / len : 0;
            d = [nx * d[0] + ny * d[1], -ny * d[0] + nx * d[1], d[2]];
            const pitch = s.tilt + look[1], cp = Math.cos(pitch), sp = Math.sin(pitch);
            d = [cp * d[0] - sp * d[2], d[1], sp * d[0] + cp * d[2]];
            const cy = Math.cos(look[0]), sy = Math.sin(look[0]);
            return [cy * d[0] - sy * d[1], sy * d[0] + cy * d[1], d[2]];
        };
        const groundAt = (pt, aspect, s, look) => {
            const d = rayAt(pt, aspect, s, look);
            if (d[2] > -0.02) return null;
            const t = (CAMERA_HEIGHT - s.height) / -d[2];
            return [d[0] * t, d[1] * t];
        };

        const render = reduced => {
            const s = self.s;
            gl.viewport(0, 0, st.width, st.height);
            gl.uniform2f(u.uResolution, st.width, st.height);
            gl.uniform1f(u.uTime, st.time);
            gl.uniform1f(u.uClock, st.clock);
            gl.uniform1f(u.uAmplitude, s.amplitude);
            gl.uniform1f(u.uWaveScale, s.waveScale);
            gl.uniform1f(u.uWaveRatio, s.waveRatio);
            gl.uniform1f(u.uSwell, s.swell);
            gl.uniform1f(u.uTurbulence, s.turbulence);
            gl.uniform1f(u.uHeight, s.height);
            gl.uniform1f(u.uTilt, s.tilt);
            gl.uniform1f(u.uZoom, s.zoom);
            gl.uniform2f(u.uLook, st.look[0], st.look[1]);
            gl.uniform1f(u.uFogDepth, s.fogDepth);
            gl.uniform1f(u.uBrightness, s.brightness);
            gl.uniform1f(u.uOpacity, s.opacity);
            gl.uniform1f(u.uGrain, s.grain);
            gl.uniform3fv(u.uHorizon, s.horizon);
            gl.uniform3fv(u.uWave, s.wave);
            gl.uniform3fv(u.uCrest, s.crest);
            const paper = s.paper || [0, 0, 0];
            gl.uniform3f(u.uBackground, paper[0], paper[1], paper[2]);
            gl.uniform1f(u.uOpaque, s.paper ? 1 : 0);
            gl.uniform1f(u.uLight, s.lightMode ? 1 : 0);
            const angle = s.lightAngle + st.steer[0] * s.mouseStrength * 2;
            const alt = 0.52 - st.steer[1] * s.mouseStrength * 0.7;
            gl.uniform3f(u.uSun, Math.cos(alt) * Math.cos(angle), Math.cos(alt) * Math.sin(angle), Math.sin(alt));
            gl.uniform1f(u.uRelief, s.relief);
            gl.uniform1f(u.uSheen, s.sheen);
            gl.uniform1f(u.uSteps, s.steps);
            const slope = s.amplitude * ((s.waveScale / 7) * (1 + Math.abs(s.swell) / 20) + ((s.waveScale * s.waveRatio) / 3) * (1 + Math.abs(s.turbulence) / 23));
            const progress = introProgress(s, reduced);
            const shown = Math.min(1, progress / 0.22);
            const crest = 160 * Math.pow(3 / 160, easeInOut(Math.max(0, (progress - 0.1) / 0.9)));
            const settle = clamp((progress - 0.75) / 0.25, 0, 1);
            gl.uniform3f(u.uIntro, shown * shown * (3 - 2 * shown), crest, 1 - settle * settle * (3 - 2 * settle));
            swellData.fill(0);
            let peak = 0;
            st.swells.forEach((sw, i) => {
                const age = st.clock - sw.at, life = age / SWELL_SECONDS;
                const env = (1 - Math.exp(-age / 0.25)) * Math.pow(1 - life, 1.6);
                const lift = s.amplitude * SWELL_GAIN * env;
                swellData.set([sw.x - age * 12, sw.y, lift], i * 3);
                peak = Math.max(peak, lift);
            });
            gl.uniform3fv(u.uSwells, swellData);
            gl.uniform1f(u.uSwellWidth, SWELL_WIDTH);
            gl.uniform1f(u.uStride, 0.9 / Math.max(1, (slope + (peak * 0.86) / SWELL_WIDTH) / 1.5));
            gl.clearColor(0, 0, 0, 0);
            gl.clear(gl.COLOR_BUFFER_BIT);
            gl.drawArrays(gl.TRIANGLES, 0, 3);
        };

        const tick = now => {
            st.raf = 0;
            if (!st.visible || document.hidden) return;
            if (self.opts.colors && (st.frame++ % 6 === 0)) self._settings();   // follow the page theme
            const s = self.s;
            const dt = st.last ? Math.min((now - st.last) / 1000, 0.1) : 0;
            st.last = now;
            const reduced = prefersReduced();
            st.clock += dt;
            if (st.introAt < 0) st.introAt = st.clock;
            if (!s.paused && !reduced) st.time += dt * s.speed;

            const steering = s.mouseInteraction && st.inside && !reduced;
            const target = steering ? st.pointer : [0.5, 0.5];
            const ease = 1 - Math.exp(-dt / 0.45);
            st.look[0] += ((target[0] - 0.5) * s.parallaxStrength * 0.35 - st.look[0]) * ease;
            st.look[1] += ((target[1] - 0.5) * s.parallaxStrength * 0.18 - st.look[1]) * ease;
            const se = 1 - Math.exp(-dt / 0.6);
            st.steer[0] += ((steering ? st.pointer[0] - 0.5 : 0) - st.steer[0]) * se;
            st.steer[1] += ((steering ? 0.5 - st.pointer[1] : 0) - st.steer[1]) * se;
            st.swells = st.swells.filter(sw => st.clock - sw.at < SWELL_SECONDS);

            st.raf = requestAnimationFrame(tick);
            fit();
            render(reduced);
        };
        const wake = () => {
            if (st.raf || !st.visible || document.hidden) return;
            st.last = 0;
            st.raf = requestAnimationFrame(tick);
        };
        this._wake = wake;

        const local = e => {
            const r = container.getBoundingClientRect();
            return { x: (e.clientX - r.left) / Math.max(r.width, 1), y: (e.clientY - r.top) / Math.max(r.height, 1),
                     inside: e.clientX >= r.left && e.clientX <= r.right && e.clientY >= r.top && e.clientY <= r.bottom };
        };
        const onMove = e => { const p = local(e); st.inside = p.inside; if (p.inside) st.pointer = [p.x, p.y]; };
        const onLeave = () => { st.inside = false; };
        const onDown = e => {
            const s = self.s;
            if (!s.swellOnClick || e.button !== 0 || prefersReduced()) return;
            if (e.target instanceof Element && e.target.closest('a, button, input, textarea, select, label, [role="button"]')) return;
            const p = local(e);
            if (!p.inside) return;
            const g = groundAt([p.x, p.y], st.width / Math.max(st.height, 1), s, st.look);
            if (!g) return;
            st.swells.push({ x: g[0], y: g[1], at: st.clock });
            if (st.swells.length > MAX_SWELLS) st.swells.shift();
        };

        const vis = new IntersectionObserver(en => { st.visible = en.some(x => x.isIntersecting); wake(); });
        vis.observe(container);
        const sizes = new ResizeObserver(measure);
        sizes.observe(container);
        measure();
        document.addEventListener("visibilitychange", wake);
        window.addEventListener("pointermove", onMove, { passive: true });
        window.addEventListener("pointerdown", onDown);
        document.documentElement.addEventListener("pointerleave", onLeave);

        this.destroy = function () {
            cancelAnimationFrame(st.raf);
            this._wake = null;
            document.removeEventListener("visibilitychange", wake);
            vis.disconnect(); sizes.disconnect();
            window.removeEventListener("pointermove", onMove);
            window.removeEventListener("pointerdown", onDown);
            document.documentElement.removeEventListener("pointerleave", onLeave);
            gl.deleteProgram(program); gl.deleteBuffer(buffer); gl.deleteVertexArray(vao);
            const ext = gl.getExtension("WEBGL_lose_context");
            if (ext) ext.loseContext();
            canvas.remove();
        };
        return true;
    };

    window.GradientWaves = GradientWaves;
})();


/* ============================================================
   GridMotion (React Bits) in plain JavaScript
   ============================================================ */
(function () {
    const DIRECTIONS = ["alternate", "left", "right"];
    const IMAGE = /^(https?:\/\/|\/\/|\/|\.\.?\/|data:image\/|blob:)|\.(avif|gif|jpe?g|png|svg|webp|php)(\?.*)?$/i;
    const INERTIA = [0.42, 0.32, 0.26, 0.22];
    const PACE = [1, 0.86, 1.12, 0.94];
    const DEFAULTS = {
        items: [], source: null, shuffle: true, maxTiles: 48,
        rows: 4, aspectRatio: 1.33, gap: 16, radius: 14, angle: -12, tilt: 0, speed: 24, direction: "alternate",
        parallax: 0.5, spotlight: 0.6, dim: 0.35, grayscale: false, fade: 0.5, tileColor: null, textColor: null
    };

    const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
    const wrap = (v, t) => ((v % t) + t) % t;
    const smoothstep = (a, b, v) => { const t = clamp((v - a) / (b - a), 0, 1); return t * t * (3 - 2 * t); };

    function feather(side, zone) {
        const ease = t => t * t * t * (t * (t * 6 - 15) + 10);
        const stops = [], mirrored = [];
        for (let i = 0; i <= 12; i++) {
            const t = i / 12;
            stops.push("rgba(0,0,0," + ease(t).toFixed(4) + ") " + (t * zone).toFixed(2) + "%");
            const m = (12 - i) / 12;
            mirrored.push("rgba(0,0,0," + ease(m).toFixed(4) + ") " + (100 - m * zone).toFixed(2) + "%");
        }
        return "linear-gradient(" + side + "," + stops.join(",") + "," + mirrored.join(",") + ")";
    }

    function injectStyle() {
        if (document.getElementById("grid-motion-style")) return;
        const st = document.createElement("style");
        st.id = "grid-motion-style";
        st.textContent =
            ".grid-motion{isolation:isolate;overflow:hidden}" +
            ".grid-motion__plane{position:absolute;top:50%;left:50%;opacity:0}" +
            ".grid-motion__row{position:absolute;left:0;display:flex;gap:var(--gm-gap);will-change:transform}" +
            ".grid-motion__tile{position:relative;display:flex;flex:none;align-items:center;justify-content:center;width:var(--gm-tile-w);height:var(--gm-tile-h);overflow:hidden;border-radius:var(--gm-radius);background:var(--gm-tile);color:var(--gm-text);font-size:var(--gm-font);line-height:1.2;text-align:center;opacity:calc(1 - var(--gm-dim) * (1 - var(--spot, 0)))}" +
            ".grid-motion__tile::after{content:'';position:absolute;inset:0;border-radius:inherit;box-shadow:inset 0 0 0 1px var(--gm-border);pointer-events:none}" +
            ".grid-motion--gray .grid-motion__tile{filter:grayscale(calc(1 - var(--spot, 0)))}" +
            ".grid-motion__tile img{display:block;width:100%;height:100%;object-fit:cover;pointer-events:none;user-select:none}" +
            ".grid-motion__text{padding:0 1em}";
        document.head.appendChild(st);
    }

    /* an item (string / {src, alt} / DOM element) -> a descriptor */
    function toTile(item) {
        if (item === null || item === undefined || item === false) return { kind: "empty" };
        if (typeof Node !== "undefined" && item instanceof Node) return { kind: "node", node: item };
        if (typeof item === "object" && typeof item.src === "string") return { kind: "image", src: item.src, alt: item.alt || "" };
        const text = String(item).trim();
        return IMAGE.test(text) ? { kind: "image", src: text, alt: "" } : { kind: "text", text: text };
    }

    function makeTile(tile) {
        const el = document.createElement("div");
        el.className = "grid-motion__tile";
        if (tile.kind === "image") {
            const img = document.createElement("img");
            img.alt = tile.alt; img.draggable = false; img.decoding = "async";
            img.addEventListener("error", function () { img.remove(); });   // a missing picture just leaves an empty tile
            img.src = tile.src;
            el.appendChild(img);
        } else if (tile.kind === "text") {
            const sp = document.createElement("span");
            sp.className = "grid-motion__text"; sp.textContent = tile.text;
            el.appendChild(sp);
        } else if (tile.kind === "node") {
            el.appendChild(tile.node.cloneNode(true));
        }
        return el;
    }

    function GridMotion(container, options) {
        if (!(this instanceof GridMotion)) return new GridMotion(container, options);
        injectStyle();
        const self = this;
        const opts = Object.assign({}, DEFAULTS, options || {});
        const root = container;
        root.classList.add("grid-motion");
        // the wall is positioned against its container; keep it fixed/absolute if the page already set that
        if (getComputedStyle(root).position === "static") root.style.position = "relative";
        root.setAttribute("aria-hidden", "true");

        const motion = { offsets: [], shifts: [], intro: 0, pointer: { x: 0, y: 0, active: false } };
        let tiles = [], layout = null, plane = null, rowEls = [], tileEls = [], spots = [], written = [];
        let raf = 0, last = performance.now(), visible = true, alive = true, size = { width: 0, height: 0 }, loadToken = 0;
        const reduce = !!(window.matchMedia && matchMedia("(prefers-reduced-motion: reduce)").matches);

        const cfg = function () {
            return {
                rows: clamp(Math.round(opts.rows), 1, 12), aspect: clamp(opts.aspectRatio, 0.3, 4), gap: Math.max(0, opts.gap),
                angle: opts.angle, tilt: clamp(opts.tilt, 0, 75), speed: opts.speed,
                direction: DIRECTIONS.indexOf(opts.direction) >= 0 ? opts.direction : "alternate",
                parallax: clamp(opts.parallax, 0, 1), spotlight: clamp(opts.spotlight, 0, 1),
                dim: clamp(opts.dim, 0, 1), fade: clamp(opts.fade, 0, 1)
            };
        };

        function computeLayout(s) {
            const w = size.width, h = size.height;
            if (!w || !h) return null;
            const turn = s.angle * Math.PI / 180, lean = s.tilt * Math.PI / 180;
            const cos = Math.abs(Math.cos(turn)), sin = Math.abs(Math.sin(turn));
            const planeHeight = ((w * sin + h * cos) / Math.max(0.3, Math.cos(lean))) * 1.12;
            const planeWidth = (w * cos + h * sin) * (1 + Math.sin(lean) * 0.9) * 1.12;
            const tileHeight = Math.max(16, (planeHeight - s.gap * (s.rows - 1)) / s.rows);
            const tileWidth = tileHeight * s.aspect;
            const step = tileWidth + s.gap;
            const perRow = Math.ceil(planeWidth / step) + 1;
            const period = perRow * step;
            return {
                planeWidth: planeWidth, planeHeight: planeHeight, tileWidth: tileWidth, tileHeight: tileHeight, step: step,
                perRow: perRow, period: period, copies: Math.ceil(planeWidth / period) + 2,
                top: (planeHeight - s.rows * tileHeight - (s.rows - 1) * s.gap) / 2,
                perspective: Math.max(w, h) * 1.5
            };
        }

        /* (re)build the wall of tiles */
        function build() {
            const s = cfg();
            if (plane) { plane.remove(); plane = null; }
            layout = computeLayout(s);
            rowEls = []; tileEls = []; spots = []; written = [];
            if (!layout) return;

            root.style.setProperty("--gm-tile", opts.tileColor || "var(--card, #1b1922)");
            root.style.setProperty("--gm-text", opts.textColor || "var(--text, rgba(255,255,255,.78))");
            root.style.setProperty("--gm-border", "var(--line, rgba(255,255,255,.07))");
            root.style.setProperty("--gm-radius", Math.max(0, opts.radius) + "px");
            root.style.setProperty("--gm-dim", String(s.dim));
            root.classList.toggle("grid-motion--gray", !!opts.grayscale);
            root.style.perspective = s.tilt > 0 ? layout.perspective + "px" : "";
            const mask = s.fade > 0 ? feather("to right", s.fade * 34) + "," + feather("to bottom", s.fade * 44) : "";
            root.style.webkitMaskImage = root.style.maskImage = mask;
            root.style.webkitMaskComposite = mask ? "source-in" : "";
            root.style.maskComposite = mask ? "intersect" : "";

            plane = document.createElement("div");
            plane.className = "grid-motion__plane";
            plane.style.width = layout.planeWidth + "px";
            plane.style.height = layout.planeHeight + "px";
            plane.style.transform = "translate(-50%,-50%) rotateX(" + s.tilt + "deg) rotateZ(" + s.angle + "deg)";
            plane.style.setProperty("--gm-gap", s.gap + "px");
            plane.style.setProperty("--gm-tile-w", layout.tileWidth + "px");
            plane.style.setProperty("--gm-tile-h", layout.tileHeight + "px");
            plane.style.setProperty("--gm-font", clamp(layout.tileHeight * 0.12, 12, 32) + "px");
            if (motion.intro >= 1) plane.style.opacity = "1";

            for (let r = 0; r < s.rows; r++) {
                const row = document.createElement("div");
                row.className = "grid-motion__row";
                row.style.top = (layout.top + r * (layout.tileHeight + s.gap)) + "px";
                const list = [];
                for (let k = 0; k < layout.copies * layout.perRow; k++) {
                    const j = k % layout.perRow;
                    const tile = tiles.length ? tiles[(r * layout.perRow + j) % tiles.length] : { kind: "empty" };
                    const el = makeTile(tile);
                    row.appendChild(el); list.push(el);
                }
                plane.appendChild(row);
                rowEls.push(row); tileEls.push(list);
                spots.push(new Float32Array(list.length)); written.push(new Float32Array(list.length));
            }
            root.appendChild(plane);
            kick();
        }

        function frame(now) {
            raf = 0;
            if (!alive || !plane || !layout) return;
            const s = cfg();
            const dt = Math.min(0.05, Math.max(0.001, (now - last) / 1000));
            last = now;

            if (motion.intro < 1) {
                motion.intro = Math.min(1, motion.intro + dt / 0.9);
                plane.style.opacity = String(1 - Math.pow(1 - motion.intro, 3));
            }

            const rect = root.getBoundingClientRect();
            const scale = rect.width ? root.clientWidth / rect.width : 1;
            const halfW = root.clientWidth / 2 || 1, halfH = root.clientHeight / 2 || 1;
            const px = (motion.pointer.x - rect.left) * scale - halfW;
            const py = (motion.pointer.y - rect.top) * scale - halfH;
            const inside = motion.pointer.active && Math.abs(px) <= halfW && Math.abs(py) <= halfH;
            const lean = s.tilt * Math.PI / 180, turn = s.angle * Math.PI / 180, depth = layout.perspective;
            let flatY = py, flatX = px;
            if (lean > 0.0001) {
                flatY = (py * depth) / (depth * Math.cos(lean) + py * Math.sin(lean));
                flatX = (px * (depth - flatY * Math.sin(lean))) / depth;
            }
            const planeX = flatX * Math.cos(turn) + flatY * Math.sin(turn);
            const planeY = -flatX * Math.sin(turn) + flatY * Math.cos(turn);
            const nudge = motion.pointer.active ? clamp(px / halfW, -1, 1) : 0;
            const reach = (0.6 + 2.2 * s.spotlight) * Math.max(layout.tileWidth, layout.tileHeight);
            const settle = 1 - Math.exp(-dt / 0.22);

            for (let r = 0; r < rowEls.length; r++) {
                const row = rowEls[r];
                const drift = s.direction === "left" ? -1 : s.direction === "right" ? 1 : (r % 2 ? 1 : -1);
                if (motion.offsets[r] === undefined) { motion.offsets[r] = r * layout.step * 0.37; motion.shifts[r] = 0; }
                if (!reduce) motion.offsets[r] += s.speed * drift * PACE[r % PACE.length] * dt;
                const target = reduce ? 0 : s.parallax * layout.tileWidth * 1.2 * nudge * (r % 2 ? -1 : 1);
                motion.shifts[r] += (target - motion.shifts[r]) * (1 - Math.exp(-dt / INERTIA[r % INERTIA.length]));
                const x = -layout.period + wrap(motion.offsets[r] + motion.shifts[r], layout.period);
                row.style.transform = "translate3d(" + x.toFixed(2) + "px,0,0)";

                const rowY = layout.top + r * (layout.tileHeight + s.gap) + layout.tileHeight / 2 - layout.planeHeight / 2;
                const left = x - layout.planeWidth / 2 + layout.tileWidth / 2;
                const list = tileEls[r], values = spots[r], marks = written[r];
                for (let k = 0; k < list.length; k++) {
                    let wanted = 0;
                    if (inside && s.spotlight > 0) {
                        const d = Math.hypot(left + k * layout.step - planeX, rowY - planeY) / reach;
                        wanted = 1 - smoothstep(0.25, 1, d);
                    }
                    values[k] += (wanted - values[k]) * settle;
                    if (Math.abs(values[k] - marks[k]) > 0.004 || (wanted === 0 && values[k] < 0.004 && marks[k] !== 0)) {
                        marks[k] = values[k] < 0.004 ? 0 : values[k];
                        list[k].style.setProperty("--spot", marks[k].toFixed(3));
                    }
                }
            }
            if (visible && !document.hidden) raf = requestAnimationFrame(frame);
        }

        function kick() {
            if (!raf && alive && visible && !document.hidden) { last = performance.now(); raf = requestAnimationFrame(frame); }
        }

        function setItems(list) {
            let arr = (list || []).slice();
            if (opts.shuffle) for (let i = arr.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); const t = arr[i]; arr[i] = arr[j]; arr[j] = t; }
            tiles = arr.slice(0, opts.maxTiles).map(toTile);
            build();
        }

        /* items from a URL: { "items": [ "img1.jpg", ... ] }. If it fails or is empty the wall just stays empty. */
        function load(url) {
            const token = ++loadToken;
            fetch(url, { credentials: "same-origin", cache: "no-store" })
                .then(function (r) { return r.ok ? r.json() : { items: [] }; })
                .then(function (d) {
                    if (!alive || token !== loadToken) return;
                    const items = d && Array.isArray(d.items) ? d.items : [];
                    if (items.length) { setItems(items); self.loaded = true; }
                })
                .catch(function () {});
        }

        const sizes = new ResizeObserver(function () {
            const w = Math.round(root.clientWidth), h = Math.round(root.clientHeight);
            if (w === size.width && h === size.height) return;
            size = { width: w, height: h };
            build();
        });
        sizes.observe(root);

        const vis = new IntersectionObserver(function (e) { visible = e.some(function (x) { return x.isIntersecting; }); kick(); });
        vis.observe(root);
        const wake = function () { kick(); };
        document.addEventListener("visibilitychange", wake);

        const onMove = function (e) { motion.pointer.x = e.clientX; motion.pointer.y = e.clientY; motion.pointer.active = true; };
        const onUp = function (e) { if (e.pointerType === "touch") motion.pointer.active = false; };
        const onLeave = function () { motion.pointer.active = false; };
        window.addEventListener("pointermove", onMove, { passive: true });
        window.addEventListener("pointerdown", onMove, { passive: true });
        window.addEventListener("pointerup", onUp);
        window.addEventListener("blur", onLeave);
        document.documentElement.addEventListener("pointerleave", onLeave);

        this.loaded = false;
        this.setItems = setItems;
        this.update = function (next) {
            Object.assign(opts, next || {});
            if (next && next.source) load(opts.source);
            else if (next && next.items) setItems(opts.items);
            else build();
        };
        this.destroy = function () {
            alive = false; cancelAnimationFrame(raf);
            sizes.disconnect(); vis.disconnect();
            document.removeEventListener("visibilitychange", wake);
            window.removeEventListener("pointermove", onMove);
            window.removeEventListener("pointerdown", onMove);
            window.removeEventListener("pointerup", onUp);
            window.removeEventListener("blur", onLeave);
            document.documentElement.removeEventListener("pointerleave", onLeave);
            if (plane) plane.remove();
            root.classList.remove("grid-motion", "grid-motion--gray");
        };

        if (opts.items && opts.items.length) setItems(opts.items);
        if (opts.source) load(opts.source);
    }

    window.GridMotion = GridMotion;
})();
