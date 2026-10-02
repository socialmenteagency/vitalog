/* Vitalog — visualización de historia clínica.
   Sin dependencias: SVG generado a mano. Especificaciones de marca:
   línea 2px, dots >=8px con anillo de superficie 2px, área ~10%,
   grillas hairline sólidas, números tabulares en columnas.

   Vitales + matriz integrados (28-ago-2026): los gráficos de signos vitales
   viven como filas de la misma tabla, con el eje X anclado al centro de cada
   columna de examen (tiempo lineal por tramos entre exámenes). Hover en una
   celda de laboratorio = crosshair sincronizado en los tres vitales + tooltip
   combinado + resaltado de fila y columna. */

(function () {
  'use strict';

  const STATE = { lang: 'es', detail: null, filter: '' };
  const S = window.SALUD;
  const D = S.data;

  // ---------- utilidades ----------

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[c]);
  }

  function t(key) {
    return (S.i18n[STATE.lang] && S.i18n[STATE.lang][key]) || key;
  }

  function locale() { return STATE.lang === 'pt' ? 'pt-BR' : 'es-UY'; }

  function fmtNum(v, dec) {
    if (v == null || isNaN(v)) return '—';
    return new Intl.NumberFormat(locale(), {
      minimumFractionDigits: dec, maximumFractionDigits: dec
    }).format(v);
  }

  function fmtNumLoose(v) {
    if (v == null) return '';
    const dec = (String(v).split('.')[1] || '').length;
    return fmtNum(v, Math.min(dec, 2));
  }

  function monthShort(ym) {
    const [y, m] = ym.split('-').map(Number);
    return new Intl.DateTimeFormat(locale(), { month: 'short' }).format(new Date(y, m - 1, 1)).replace('.', '');
  }

  function fmtMonthYear(ym) {
    return monthShort(ym) + ' ' + ym.slice(0, 4);
  }

  function fmtDateLong(iso) {
    const d = new Date(iso + 'T00:00:00');
    return new Intl.DateTimeFormat(locale(), { day: 'numeric', month: 'long', year: 'numeric' }).format(d);
  }

  function refText(a) {
    if (a.refOverride !== undefined) return a.refOverride;
    const f = v => fmtNumLoose(v);
    if (a.low != null && a.high != null) return f(a.low) + '–' + f(a.high) + ' ' + a.unit;
    if (a.low != null) return '≥ ' + f(a.low) + ' ' + a.unit;
    if (a.high === 0) return 'negativo · ' + a.unit;
    if (a.high != null) return '≤ ' + f(a.high) + ' ' + a.unit;
    return a.unit;
  }

  function aname(a) { return STATE.lang === 'pt' ? a.name_pt : a.name_es; }
  function mname(m) { return STATE.lang === 'pt' ? m.name_pt : m.name_es; }
  function munit(m) { return STATE.lang === 'pt' ? m.unit_pt : m.unit_es; }

  function ymToTime(ym) {
    const [y, m] = ym.split('-').map(Number);
    return new Date(y, m - 1, 15).getTime();   // medio del mes
  }

  // ---------- tooltip global ----------

  let tipEl = null;
  function showTip(clientX, clientY, html) {
    if (!tipEl) {
      tipEl = document.createElement('div');
      tipEl.className = 'chart-tip';
      document.body.appendChild(tipEl);
    }
    tipEl.innerHTML = html;
    tipEl.style.left = clientX + 'px';
    tipEl.style.top = clientY + 'px';
    tipEl.style.display = 'block';
  }
  function hideTip() { if (tipEl) tipEl.style.display = 'none'; }

  // valores de los 3 vitales para el mes de un examen (o null)
  function vitalsAtMonth(ym) {
    return D.health.map(m => {
      const p = m.points.find(p => p.month === ym && p.value != null);
      return { metric: m, value: p ? p.value : null };
    });
  }

  function vitalsTipLine(ym) {
    const parts = vitalsAtMonth(ym)
      .filter(v => v.value != null)
      .map(v => `${esc(mname(v.metric))} ${fmtNum(v.value, v.metric.dec)} ${esc(munit(v.metric))}`);
    return parts.length ? `<span class="tip-sub">${parts.join(' · ')}</span>` : '';
  }

  // ---------- gráficos de vitales integrados a la matriz ----------

  // registro por métrica para el crosshair sincronizado
  const VCHARTS = {};

  function renderInlineVital(el, metric, anchors) {
    // anchors: [{time, x}] = centro de cada columna de examen, en px del contenedor
    el.innerHTML = '';
    VCHARTS[metric.metric] = null;
    const pts = metric.points.filter(p => p.value != null)
      .map(p => ({ month: p.month, time: ymToTime(p.month), value: p.value }));
    if (pts.length < 2 || anchors.length < 2) return;

    const W = Math.max(el.clientWidth || 300, 160);
    const H = 58;
    const TOP = 8, BOT = 6;

    // tiempo → x: lineal por tramos entre anclas; extrapola en los bordes
    function timeX(tm) {
      let i = 0;
      while (i < anchors.length - 1 && tm > anchors[i + 1].time) i++;
      if (tm < anchors[0].time) i = 0;
      // pasado el último ancla se extrapola con el último tramo (sino todo se apilaría en ella)
      if (i >= anchors.length - 1) i = anchors.length - 2;
      const a = anchors[i], b = anchors[i + 1];
      const rate = b.time === a.time ? 0 : (b.x - a.x) / (b.time - a.time);
      return a.x + (tm - a.time) * rate;
    }

    const vis = pts.map(p => ({ ...p, x: timeX(p.time) })).filter(p => p.x >= 2 && p.x <= W - 2);
    if (vis.length < 2) return;

    const vals = vis.map(p => p.value);
    let lo = Math.min(...vals), hi = Math.max(...vals);
    const pad = (hi - lo) * 0.18 || 1;
    lo -= pad; hi += pad;
    const y = v => TOP + (H - TOP - BOT) - ((v - lo) / (hi - lo)) * (H - TOP - BOT);

    let ticks = '';
    anchors.forEach(a => {
      if (a.x >= 0 && a.x <= W) {
        ticks += `<line class="chart-grid" x1="${a.x.toFixed(1)}" y1="${TOP - 4}" x2="${a.x.toFixed(1)}" y2="${H - 2}"></line>`;
      }
    });

    const lineD = vis.map((p, i) => (i ? 'L' : 'M') + p.x.toFixed(1) + ' ' + y(p.value).toFixed(1)).join('');
    const areaD = lineD + `L${vis[vis.length - 1].x.toFixed(1)} ${H - 2}L${vis[0].x.toFixed(1)} ${H - 2}Z`;
    const last = vis[vis.length - 1];
    // etiqueta final: a la derecha del dot, o a la izquierda si no entra
    const fitsRight = last.x + 42 <= W;
    const endLabel = fitsRight
      ? `<text class="chart-endlabel" x="${(last.x + 8).toFixed(1)}" y="${(y(last.value) + 3.5).toFixed(1)}">${fmtNum(last.value, metric.dec)}</text>`
      : `<text class="chart-endlabel" x="${(last.x - 8).toFixed(1)}" y="${(y(last.value) - 6).toFixed(1)}" text-anchor="end">${fmtNum(last.value, metric.dec)}</text>`;

    el.innerHTML =
      `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" aria-label="${esc(mname(metric))}" role="img">` +
      ticks +
      `<path class="chart-area" d="${areaD}"></path>` +
      `<path class="chart-line" d="${lineD}"></path>` +
      `<g class="sync-marks"></g>` +
      `<circle class="chart-dot" cx="${last.x.toFixed(1)}" cy="${y(last.value).toFixed(1)}" r="3.5"></circle>` +
      endLabel +
      `<rect class="hover-zone" x="0" y="0" width="${W}" height="${H}" fill="transparent"></rect>` +
      `</svg>`;

    const svg = el.firstElementChild;
    const chart = { metric, svg, W, H, TOP, BOT, y, timeX, vis, marks: svg.querySelector('.sync-marks') };
    VCHARTS[metric.metric] = chart;

    // hover directo sobre el gráfico: mes más cercano
    const zone = svg.querySelector('.hover-zone');
    function onMove(ev) {
      const p = ev.touches ? ev.touches[0] : ev;
      const rect = svg.getBoundingClientRect();
      const relX = (p.clientX - rect.left) * (W / rect.width);
      let best = null, bd = Infinity;
      vis.forEach(pt => { const d = Math.abs(pt.x - relX); if (d < bd) { bd = d; best = pt; } });
      if (!best) return;
      chart.marks.innerHTML =
        `<line class="chart-crosshair" x1="${best.x.toFixed(1)}" y1="${TOP - 4}" x2="${best.x.toFixed(1)}" y2="${H - 2}"></line>` +
        `<circle class="chart-dot" cx="${best.x.toFixed(1)}" cy="${y(best.value).toFixed(1)}" r="4"></circle>`;
      showTip(p.clientX, p.clientY,
        `<span class="tip-label">${esc(fmtMonthYear(best.month))}</span>${fmtNum(best.value, metric.dec)} ${esc(munit(metric))}`);
    }
    function onOut() { chart.marks.innerHTML = ''; hideTip(); }
    zone.addEventListener('mousemove', onMove);
    zone.addEventListener('mouseleave', onOut);
    zone.addEventListener('touchstart', onMove, { passive: true });
    zone.addEventListener('touchmove', onMove, { passive: true });
    zone.addEventListener('touchend', onOut);
  }

  // crosshair sincronizado desde una celda de examen
  function syncVitals(examIdx) {
    const exam = D.exams[examIdx];
    const ym = exam ? exam.date.slice(0, 7) : null;
    Object.values(VCHARTS).forEach(ch => {
      if (!ch) return;
      ch.marks.innerHTML = '';
      if (!ym) return;
      const tm = ymToTime(ym);
      const x = ch.timeX(tm);
      if (x < 0 || x > ch.W) return;
      let mark = `<line class="chart-crosshair" x1="${x.toFixed(1)}" y1="${ch.TOP - 4}" x2="${x.toFixed(1)}" y2="${ch.H - 2}"></line>`;
      const pt = ch.vis.find(p => p.month === ym);
      if (pt) {
        const lx = Math.min(pt.x + 7, ch.W - 30);
        mark += `<circle class="chart-dot" cx="${pt.x.toFixed(1)}" cy="${ch.y(pt.value).toFixed(1)}" r="4"></circle>` +
                `<text class="chart-endlabel sync-label" x="${lx.toFixed(1)}" y="${(ch.y(pt.value) - 7).toFixed(1)}">${fmtNum(pt.value, ch.metric.dec)}</text>`;
      }
      ch.marks.innerHTML = mark;
    });
  }

  // mini-evolución de un vital (serie completa) para la columna de sparklines
  function vitalSparkSvg(m) {
    const pts = m.points.filter(p => p.value != null);
    if (pts.length < 2) return '';
    const W = 68, H = 24, P = 4;
    const vals = pts.map(p => p.value);
    let lo = Math.min(...vals), hi = Math.max(...vals);
    if (hi === lo) { hi += 1; lo -= 1; }
    const x = i => P + (i / (pts.length - 1)) * (W - 2 * P);
    const y = v => (H - P) - ((v - lo) / (hi - lo)) * (H - 2 * P);
    const d = pts.map((p, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p.value).toFixed(1)).join('');
    return `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" aria-hidden="true">` +
           `<path class="chart-line" d="${d}" style="stroke-width:1.5"></path>` +
           `<circle class="chart-dot" cx="${x(pts.length - 1)}" cy="${y(pts[pts.length - 1].value)}" r="3"></circle></svg>`;
  }

  // ---------- sparkline de fila ----------

  function sparkSvg(a) {
    const idx = [];
    a.values.forEach((v, i) => { if (v != null) idx.push(i); });
    if (idx.length < 2) return '';
    const W = 68, H = 24, P = 4;
    const vals = idx.map(i => a.values[i]);
    let lo = Math.min(...vals), hi = Math.max(...vals);
    if (hi === lo) { hi += 1; lo -= 1; }
    const n = a.values.length;
    const x = i => P + (i / (n - 1)) * (W - 2 * P);
    const y = v => (H - P) - ((v - lo) / (hi - lo)) * (H - 2 * P);
    const d = idx.map((i, k) => (k ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(a.values[i]).toFixed(1)).join('');
    let dots = '';
    idx.forEach(i => {
      if (a.flags[i]) dots += `<circle class="chart-dot-bad" cx="${x(i)}" cy="${y(a.values[i])}" r="3"></circle>`;
    });
    const last = idx[idx.length - 1];
    if (!a.flags[last]) dots += `<circle class="chart-dot" cx="${x(last)}" cy="${y(a.values[last])}" r="3"></circle>`;
    return `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" aria-hidden="true">` +
           `<path class="chart-line" d="${d}" style="stroke-width:1.5"></path>${dots}</svg>`;
  }

  // ---------- info educativa y rangos por escalón (assets/analyte-info.json) ----------
  // Se carga aparte: si falla, el modal sigue funcionando con el rango estándar.

  let INFO = null;
  let VINFO = null;   // signos vitales: escalones, rangos y textos (assets/vitals-info.json)
  let rendered = false;
  Promise.all([
    fetch('assets/analyte-info.json', { cache: 'no-cache' })
      .then(r => (r.ok ? r.json() : null))
      .then(j => { INFO = j; })
      .catch(() => { /* sin info: solo rango estándar */ }),
    fetch('assets/vitals-info.json', { cache: 'no-cache' })
      .then(r => (r.ok ? r.json() : null))
      .then(j => { VINFO = j; })
      .catch(() => { /* sin info de vitales: el gráfico igual abre */ })
  ]).then(() => {
    // los rangos recomendados de los vitales y el texto de "Reciente" salen de estos archivos
    if (rendered && !STATE.detail) render();
  });

  function uiInfo(key) {
    const lang = STATE.lang;
    return (INFO && INFO.ui && INFO.ui[lang] && INFO.ui[lang][key]) ||
           (VINFO && VINFO.ui && VINFO.ui[lang] && VINFO.ui[lang][key]) || '';
  }
  function infoFor(a) {
    const i = (INFO && INFO.info && INFO.info[a.code]) || (VINFO && VINFO.info && VINFO.info[a.code]);
    return i ? i[STATE.lang] : null;
  }
  function tiersFor(a) {
    if (a.tiers) return a.tiers;
    const tiers = (INFO && INFO.tiers && INFO.tiers[a.code]) || (VINFO && VINFO.tiers && VINFO.tiers[a.code]) || null;
    // HDL: el escalón protector empieza en 50 mg/dL en mujeres (40 en hombres)
    if (tiers && a.code === 'hdl' && D.profile && D.profile.sex === 'female') {
      return tiers.map(z => Object.assign({}, z, {
        from: z.from === 40 ? 50 : z.from,
        to: z.to === 40 ? 50 : z.to,
        r: z.r === '<40' ? '<50' : (z.r === '40–59' ? '50–59' : z.r)
      }));
    }
    return tiers;
  }

  // ---------- consejos personalizados con los datos de la persona ----------
  // Los consejos genéricos de peso, caminata y ejercicio se reemplazan por una versión
  // con su IMC, sus pasos y sus minutos de ejercicio (Apple Health) cuando hay datos.

  function metricPts(metric) {
    const m = D.health.find(h => h.metric === metric);
    return m ? m.points.filter(p => p.value != null) : [];
  }
  function recentAvg(metric, n) {
    const pts = metricPts(metric).slice(-n);
    return pts.length ? pts.reduce((s, p) => s + p.value, 0) / pts.length : null;
  }
  function personalTip(esTip) {
    const pt = STATE.lang === 'pt';
    const n1 = v => fmtNum(v, 1);
    const n0 = v => fmtNum(v, 0);
    if (/^(Bajar peso si hay sobrepeso|Bajar el exceso de grasa abdominal|Bajar peso gradualmente)$/.test(esTip)) {
      const w = metricPts('weight');
      const hm = D.profile && D.profile.height_cm ? D.profile.height_cm / 100 : null;
      if (!w.length || !hm) return null;
      const now = w[w.length - 1].value;
      const bmi = now / (hm * hm);
      if (bmi < 25) {
        return pt ? 'Seu IMC é ' + n1(bmi) + ' (peso saudável): manter o peso já ajuda este valor.'
                  : 'Tu IMC es ' + n1(bmi) + ' (peso saludable): mantenerlo ya ayuda a este valor.';
      }
      const cat = bmi < 30 ? (pt ? 'sobrepeso' : 'sobrepeso') : (pt ? 'obesidade' : 'obesidad');
      const ref = w.length > 3 ? w[w.length - 4].value : w[0].value;
      const d = now - ref;
      let trend = '';
      if (Math.abs(d) >= 0.5) {
        trend = d < 0
          ? (pt ? ' Você baixou ' + n1(-d) + ' kg nos últimos meses: continue assim.' : ' Has bajado ' + n1(-d) + ' kg en los últimos meses: sigue así.')
          : (pt ? ' Você subiu ' + n1(d) + ' kg nos últimos meses.' : ' Has subido ' + n1(d) + ' kg en los últimos meses.');
      }
      const goal = n1(now * 0.05);
      return pt ? 'Seu IMC é ' + n1(bmi) + ' (' + cat + ').' + trend + ' Perder 5 % do peso (cerca de ' + goal + ' kg) já melhora este valor.'
                : 'Tu IMC es ' + n1(bmi) + ' (' + cat + ').' + trend + ' Bajar el 5 % del peso (unos ' + goal + ' kg) ya mejora este valor.';
    }
    if (esTip === 'Caminar al menos 30 minutos al día') {
      const st = recentAvg('steps', 3);
      if (st == null) return null;
      if (st >= 7000) {
        return pt ? 'Você já caminha cerca de ' + n0(st) + ' passos por dia: mantenha o hábito.'
                  : 'Ya caminas unos ' + n0(st) + ' pasos al día: mantén el hábito.';
      }
      const gap = 7000 - st;
      if (gap <= 1000) {
        return pt ? 'Hoje você caminha cerca de ' + n0(st) + ' passos por dia; faltam cerca de ' + n0(gap) + ' para chegar a 7.000.'
                  : 'Hoy caminas unos ' + n0(st) + ' pasos al día; te faltan unos ' + n0(gap) + ' para llegar a 7.000.';
      }
      return pt ? 'Hoje você caminha cerca de ' + n0(st) + ' passos por dia; a meta é chegar a 7.000. Comece somando 1.000 (cerca de ' + n0(st + 1000) + ') e vá subindo aos poucos.'
                : 'Hoy caminas unos ' + n0(st) + ' pasos al día; la meta es llegar a 7.000. Empieza sumando 1.000 (unos ' + n0(st + 1000) + ') y ve subiendo poco a poco.';
    }
    if (/^(Actividad física regular|Ejercicio aeróbico regular|Actividad física, incluida fuerza)$/.test(esTip)) {
      const ex = recentAvg('exercise', 3);
      if (ex == null) return null;
      if (ex >= 21) {
        return pt ? 'Você já faz cerca de ' + n0(ex) + ' min de exercício por dia: mantenha e some força 2 vezes por semana.'
                  : 'Ya haces unos ' + n0(ex) + ' min de ejercicio al día: mantenlo y suma fuerza 2 veces por semana.';
      }
      return pt ? 'Hoje você faz cerca de ' + n0(ex) + ' min de exercício por dia; a meta é 21 (150 por semana). Caminhar rápido conta.'
                : 'Hoy haces unos ' + n0(ex) + ' min de ejercicio al día; la meta es 21 (150 por semana). Caminar rápido cuenta.';
    }
    return null;
  }
  function tipsFor(a) {
    const i = (INFO && INFO.info && INFO.info[a.code]) || (VINFO && VINFO.info && VINFO.info[a.code]);
    if (!i) return [];
    const es = (i.es && i.es.tips) || [];
    return ((i[STATE.lang] || {}).tips || []).map((tip, idx) => personalTip(es[idx] || '') || tip);
  }

  // Zonas del gráfico, de abajo hacia arriba. from/to null = abierto. Cada zona
  // cubre [from, to). Con escalones publicados (colesterol, glucemia…) son esos;
  // si no, el rango estándar y, a cada lado, "fuera de rango".
  function zonesFor(a) {
    const tiers = tiersFor(a);
    if (tiers) {
      return tiers.map(z => ({
        from: z.from, to: z.to, level: z.level, r: z.r || '',
        label: STATE.lang === 'pt' ? z.pt : z.es
      }));
    }
    if (a.low == null && a.high == null) return [];
    const bad = t('out_of_range');
    const z = [];
    if (a.low != null) z.push({ from: null, to: a.low, level: 'bad', label: bad, r: '' });
    z.push({ from: a.low, to: a.high, level: 'ok', label: t('in_range'), r: refText(a) });
    if (a.high != null) z.push({ from: a.high, to: null, level: 'bad', label: bad, r: '' });
    return z;
  }

  function zoneOf(zones, v) {
    for (const z of zones) {
      if ((z.from == null || v >= z.from) && (z.to == null || v < z.to)) return z;
    }
    return null;
  }

  // Escala vertical lógica: se arma desde los rangos (con aire por encima y por
  // debajo), no desde los datos; así un valor apenas fuera de rango se ve apenas
  // fuera. Solo se amplía si un dato cae más allá, y entonces sí se ve lejos.
  function chartDomain(zones, vals) {
    const dMin = Math.min(...vals), dMax = Math.max(...vals);
    const edges = [];
    zones.forEach(z => {
      if (z.from != null) edges.push(z.from);
      if (z.to != null) edges.push(z.to);
    });
    if (!edges.length) {
      const p = (dMax - dMin) * 0.2 || Math.abs(dMax) * 0.1 || 1;
      return [Math.max(dMin - p, 0), dMax + p];
    }
    const sorted = Array.from(new Set(edges)).sort((p, q) => p - q);
    const eMin = sorted[0], eMax = sorted[sorted.length - 1];
    const span = eMax - eMin;
    // arriba: un escalón más allá del último límite
    const step = sorted.length > 1 ? eMax - sorted[sorted.length - 2] : eMax * 0.5;
    let hi = eMax + Math.max(step * 0.8, eMax * 0.15);
    // abajo: si "menos es mejor" parte de 0; si no, aire bajo el primer límite
    let lo = zones[0].from == null && zones[0].level === 'ok'
      ? 0
      : eMin - Math.max(span * 0.8, eMin * 0.4);
    if (hi <= lo) hi = lo + 1;
    // los datos que se salen de la escala la amplían
    const pad = (hi - lo) * 0.06;
    lo = Math.min(lo, dMin - pad);
    hi = Math.max(hi, dMax + pad);
    if (lo < 0) lo = 0;
    if (lo > 0 && lo < (hi - lo) * 0.12) lo = 0;
    return [lo, hi];
  }

  // ---------- gráfico de detalle ----------

  // Serie de un analito o de un signo vital para el modal:
  //   all  = posiciones del eje X (fecha de cada examen / cada mes) con su etiqueta
  //   pts  = puntos con valor {t, v, flag}
  //   lastText = fecha/origen del último valor, labelAll = rotular cada punto
  function seriesOf(a) {
    if (a.series) return a.series;
    const idx = [];
    a.values.forEach((v, i) => { if (v != null) idx.push(i); });
    const times = D.exams.map(e => Date.parse(e.date));
    const li = idx[idx.length - 1];
    return {
      all: D.exams.map((e, i) => ({ t: times[i], label: fmtMonthYear(e.date.slice(0, 7)) })),
      pts: idx.map(i => ({ t: times[i], v: a.values[i], flag: a.flags[i], label: fmtDateLong(D.exams[i].date) })),
      lastText: fmtDateLong(D.exams[li].date) + (D.exams[li].lab ? ' · ' + D.exams[li].lab : ''),
      labelAll: true, dotR: 4.5
    };
  }

  function renderDetailChart(el, a) {
    const S = seriesOf(a);
    const W = Math.max(el.clientWidth || 560, 300);
    const times = S.all.map(p => p.t);
    const t0 = Math.min(...times), t1 = Math.max(...times);
    const zones = zonesFor(a);
    const tiered = !!tiersFor(a);
    const [lo, hi] = chartDomain(zones, S.pts.map(p => p.v));

    const M = { top: 22, right: 24, bottom: 30, left: 46 };
    const ih = 218;
    const iw = W - M.left - M.right;
    const xt = t => M.left + (t1 === t0 ? iw / 2 : ((t - t0) / (t1 - t0)) * iw);

    // etiquetas del eje X: horizontales si entran todas; si no, inclinadas 60° y más juntas
    const pickLabels = gap => {
      const out = [];
      let lastX = -Infinity;
      S.all.forEach(p => { if (xt(p.t) - lastX >= gap) { out.push(p); lastX = xt(p.t); } });
      return out;
    };
    const labW = Math.max.apply(null, S.all.map(p => p.label.length)) * 6.2;
    let xLabels = pickLabels(labW + 10);
    const slant = xLabels.length < S.all.length;
    if (slant) { xLabels = pickLabels(20); M.bottom = 66; }
    const H = M.top + ih + M.bottom;
    const y0 = M.top + ih;

    const y = v => y0 - ((v - lo) / (hi - lo)) * ih;
    const DOT = { ok: 'chart-dot', warn: 'chart-dot-warn', bad: 'chart-dot-bad' };
    const levelOf = p => {
      if (tiered) { const z = zoneOf(zones, p.v); return z ? z.level : 'ok'; }
      return p.flag ? 'bad' : 'ok';
    };

    // zonas de color con su nombre
    let zoneSvg = '', zoneLabels = '';
    zones.forEach(z => {
      const top = z.to == null ? M.top : y(Math.min(z.to, hi));
      const bot = z.from == null ? y0 : y(Math.max(z.from, lo));
      const h = bot - top;
      if (h <= 0.5) return;
      zoneSvg += `<rect class="chart-zone chart-zone-${z.level}" x="${M.left}" y="${top.toFixed(1)}" width="${iw}" height="${h.toFixed(1)}"></rect>`;
      if (h >= 15) {
        zoneLabels += `<text class="chart-zone-label chart-zone-label-${z.level}" x="${M.left + 8}" y="${(top + h / 2 + 4).toFixed(1)}">${esc(z.label + (z.r ? ' ' + z.r : ''))}</text>`;
      }
    });

    // límites entre zonas: línea, marca y número en el eje Y (sin pisarse)
    const edgeVals = [];
    zones.forEach(z => [z.from, z.to].forEach(v => {
      if (v != null && v > lo && v < hi && edgeVals.indexOf(v) < 0) edgeVals.push(v);
    }));
    edgeVals.sort((p, q) => q - p);
    let grid = '', lastY = -Infinity;
    edgeVals.forEach(v => {
      grid += `<line class="chart-band-edge" x1="${M.left}" y1="${y(v).toFixed(1)}" x2="${M.left + iw}" y2="${y(v).toFixed(1)}"></line>` +
              `<line class="chart-tick chart-tick-major" x1="${M.left - 5}" y1="${y(v).toFixed(1)}" x2="${M.left}" y2="${y(v).toFixed(1)}"></line>`;
      if (y(v) - lastY >= 13) {
        grid += `<text class="chart-axis-label" x="${M.left - 8}" y="${(y(v) + 3).toFixed(1)}" text-anchor="end">${fmtNumLoose(v)}</text>`;
        lastY = y(v);
      }
    });
    if (lo === 0) {
      grid += `<line class="chart-tick chart-tick-major" x1="${M.left - 5}" y1="${y(0).toFixed(1)}" x2="${M.left}" y2="${y(0).toFixed(1)}"></line>` +
              `<text class="chart-axis-label" x="${M.left - 8}" y="${(y(0) + 3).toFixed(1)}" text-anchor="end">0</text>`;
    }

    // ejes y marcas del eje X: una marca corta por dato y una larga por etiqueta
    let axes = `<line class="chart-axis-line" x1="${M.left}" y1="${M.top}" x2="${M.left}" y2="${y0}"></line>` +
               `<line class="chart-axis-line" x1="${M.left}" y1="${y0}" x2="${M.left + iw}" y2="${y0}"></line>`;
    S.all.forEach(p => {
      const x = xt(p.t).toFixed(1);
      axes += `<line class="chart-tick" x1="${x}" y1="${y0}" x2="${x}" y2="${y0 + 3}"></line>`;
    });
    xLabels.forEach(p => {
      const x = xt(p.t).toFixed(1);
      axes += `<line class="chart-tick chart-tick-major" x1="${x}" y1="${y0}" x2="${x}" y2="${y0 + 6}"></line>`;
      axes += slant
        ? `<text class="chart-axis-label" transform="rotate(-60 ${x} ${y0 + 12})" x="${x}" y="${y0 + 12}" text-anchor="end">${esc(p.label)}</text>`
        : `<text class="chart-axis-label" x="${x}" y="${y0 + 20}" text-anchor="middle">${esc(p.label)}</text>`;
    });

    const lineD = S.pts.map((p, k) => (k ? 'L' : 'M') + xt(p.t).toFixed(1) + ' ' + y(p.v).toFixed(1)).join('');

    // con muchos datos (serie mensual larga) los puntos se achican para que no "enrosquen" la línea
    const dense = S.pts.length > 40;
    const dotR = dense ? 1.8 : S.dotR;
    const dotStyle = dense ? ' style="stroke-width:0.6"' : '';
    let dots = '', labels = '';
    S.pts.forEach((p, k) => {
      dots += `<circle class="${DOT[levelOf(p)] || 'chart-dot'}" cx="${xt(p.t)}" cy="${y(p.v)}" r="${dotR}"${dotStyle}></circle>`;
      if (S.labelAll || k === S.pts.length - 1) {
        labels += `<text class="chart-endlabel chart-halo" x="${xt(p.t)}" y="${y(p.v) - 11}" text-anchor="middle">${fmtNum(p.v, a.dec)}</text>`;
      }
    });

    el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-label="${esc(aname(a))}">` +
      zoneSvg + grid + zoneLabels + axes + `<path class="chart-line" d="${lineD}"></path>` + dots + labels +
      `<g class="hover-marks"></g>` +
      `<rect class="hover-zone" x="${M.left}" y="${M.top}" width="${iw}" height="${ih}" fill="transparent"></rect></svg>`;

    // al mover el mouse en horizontal: crosshair + valor del dato más cercano
    const svg = el.firstElementChild;
    const marks = svg.querySelector('.hover-marks');
    const zoneEl = svg.querySelector('.hover-zone');
    function onMove(ev) {
      const pt = ev.touches ? ev.touches[0] : ev;
      const rect = svg.getBoundingClientRect();
      const rx = (pt.clientX - rect.left) * (W / rect.width);
      let best = null, bd = Infinity;
      S.pts.forEach(p => { const d = Math.abs(xt(p.t) - rx); if (d < bd) { bd = d; best = p; } });
      if (!best) return;
      const bx = xt(best.t), by = y(best.v);
      marks.innerHTML =
        `<line class="chart-crosshair" x1="${bx.toFixed(1)}" y1="${M.top}" x2="${bx.toFixed(1)}" y2="${y0}"></line>` +
        `<circle class="${DOT[levelOf(best)] || 'chart-dot'}" cx="${bx.toFixed(1)}" cy="${by.toFixed(1)}" r="5"></circle>`;
      const z = zones.length ? zoneOf(zones, best.v) : null;
      const lab = best.label || (S.all.find(q => q.t === best.t) || {}).label || '';
      showTip(pt.clientX, rect.top + by * (rect.height / H),
        `<span class="tip-label">${esc(lab)}</span><strong>${fmtNum(best.v, a.dec)}</strong> ${esc(a.unit)}` + (z ? ' · ' + esc(z.label) : ''));
    }
    function onOut() { marks.innerHTML = ''; hideTip(); }
    zoneEl.addEventListener('mousemove', onMove);
    zoneEl.addEventListener('mouseleave', onOut);
    zoneEl.addEventListener('touchstart', onMove, { passive: true });
    zoneEl.addEventListener('touchmove', onMove, { passive: true });
    zoneEl.addEventListener('touchend', onOut);
  }

  // ---------- vitales sueltos (fallback cuando no hay exámenes) ----------

  const UP_GOOD = { weight: false, steps: true, exercise: true, resting_hr: false };

  function vitalDelta(m) {
    const pts = m.points.filter(p => p.value != null);
    if (!pts.length) return null;
    const last = pts[pts.length - 1], first = pts[0];
    const delta = last.value - first.value;
    const good = delta === 0 ? null : (delta > 0) === UP_GOOD[m.metric];
    return { last, first, delta, good };
  }

  function standaloneVitalsHtml() {
    let cards = '';
    D.health.forEach((m, mi) => {
      const d = vitalDelta(m);
      if (!d) return;
      const unit = munit(m);
      const cls = d.good == null ? '' : (d.good ? 'is-good' : 'is-bad');
      const arrow = d.delta === 0 ? '=' : (d.delta > 0 ? '▲' : '▼');
      cards +=
        `<article class="vital-card">
          <div class="vital-stat">
            <div class="vital-label">${esc(mname(m))}</div>
            <div class="vital-value">${fmtNum(d.last.value, m.dec)}<span class="vital-unit">${esc(unit)}</span></div>
            <div class="vital-delta ${cls}">${arrow} ${fmtNum(Math.abs(d.delta), m.dec)} ${esc(unit)} <span class="vs">${esc(t('vs_start'))} ${esc(fmtMonthYear(d.first.month))}</span></div>
          </div>
          <div class="vital-chart" data-vital="${mi}"></div>
        </article>`;
    });
    if (!cards) return '';
    return `<section class="section" aria-labelledby="vitals-title">
      <div class="section-hd">
        <h2 class="section-title" id="vitals-title">${esc(t('vitals'))}</h2>
        <span class="section-note">${esc(t('vitals_note'))}</span>
      </div>
      <div class="vitals">${cards}</div>
    </section>`;
  }

  function renderStandaloneVital(el, metric) {
    // versión simple a ancho completo (solo se usa sin exámenes)
    const pts = metric.points.filter(p => p.value != null);
    if (!pts.length) { el.innerHTML = ''; return; }
    const W = Math.max(el.clientWidth || 480, 280), H = 96;
    const M = { top: 14, right: 52, bottom: 18, left: 34 };
    const iw = W - M.left - M.right, ih = H - M.top - M.bottom;
    const vals = pts.map(p => p.value);
    let lo = Math.min(...vals), hi = Math.max(...vals);
    const pad = (hi - lo) * 0.15 || 1;
    lo -= pad; hi += pad;
    const x = i => M.left + (pts.length === 1 ? iw / 2 : (i / (pts.length - 1)) * iw);
    const y = v => M.top + ih - ((v - lo) / (hi - lo)) * ih;
    const lineD = pts.map((p, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(p.value).toFixed(1)).join('');
    const areaD = lineD + `L${x(pts.length - 1).toFixed(1)} ${(M.top + ih).toFixed(1)}L${x(0).toFixed(1)} ${(M.top + ih).toFixed(1)}Z`;
    const li = pts.length - 1;
    el.innerHTML =
      `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" role="img" aria-label="${esc(mname(metric))}">` +
      `<path class="chart-area" d="${areaD}"></path><path class="chart-line" d="${lineD}"></path>` +
      `<circle class="chart-dot" cx="${x(li)}" cy="${y(pts[li].value)}" r="4"></circle>` +
      `<text class="chart-endlabel" x="${x(li) + 9}" y="${y(pts[li].value) + 4}">${fmtNum(pts[li].value, metric.dec)}</text></svg>`;
  }

  // ---------- matriz integrada ----------

  // Mes más reciente con datos de signos vitales (YYYY-MM)
  function latestVitalMonth() {
    let best = '';
    D.health.forEach(m => m.points.forEach(p => { if (p.value != null && p.month > best) best = p.month; }));
    return best;
  }

  // Columna extra a la derecha del último examen cuando hay vitales más recientes
  // (p. ej. peso de sept/oct): los gráficos de vitales continúan hasta ella.
  function trailingCols() {
    const n = D.exams.length;
    const latest = latestVitalMonth();
    if (!n || !latest) return 0;
    return latest > D.exams[n - 1].date.slice(0, 7) ? 1 : 0;
  }

  // Rango recomendado de un signo vital (texto bajo su nombre, como en los exámenes)
  function vitalRef(m) {
    if (m.metric === 'weight') {
      const hm = D.profile && D.profile.height_cm ? D.profile.height_cm / 100 : null;
      if (!hm) return '';
      return fmtNum(18.5 * hm * hm, 1) + '–' + fmtNum(25 * hm * hm, 1) + ' kg';
    }
    const R = VINFO && VINFO.ref && VINFO.ref[m.metric];
    return R ? R[STATE.lang] : '';
  }

  // Cambio de un valor respecto del examen anterior de ese mismo analito (≥ 5 %).
  // tone: better = se acerca al rango, worse = se aleja, flat = sin rango o ya dentro.
  function changeInfo(a, i) {
    const v = a.values[i];
    if (v == null) return null;
    let p = null;
    for (let j = i - 1; j >= 0; j--) if (a.values[j] != null) { p = a.values[j]; break; }
    if (p == null) return null;
    const rel = (v - p) / (Math.abs(p) || 1);
    if (Math.abs(rel) < 0.05) return null;
    const dist = x => (a.low != null && x < a.low) ? a.low - x : ((a.high != null && x > a.high) ? x - a.high : 0);
    const d0 = dist(p), d1 = dist(v);
    return { up: v > p, tone: d1 < d0 ? 'better' : (d1 > d0 ? 'worse' : 'flat'), pct: Math.abs(rel) * 100 };
  }
  function changeTitle(c) {
    return t(c.up ? 'increased' : 'decreased') + ' ' + fmtNum(c.pct, 0) + ' % · ' + t('vs_prev');
  }

  // ---------- controles pendientes ----------
  // Por grupo de exámenes: se sugiere repetir cada 12 meses, o cada 6 si algún valor
  // del grupo estaba fuera de rango en su última medición. Orientativo: el médico decide.

  function monthsTxt(n) {
    return n + ' ' + t(n === 1 ? 'due_month_one' : 'due_month_many');
  }
  function dueItems() {
    const now = new Date();
    const items = [];
    D.categories.forEach(cat => {
      let last = -1, flagged = false;
      cat.analytes.forEach(a => {
        let li = -1;
        a.values.forEach((v, i) => { if (v != null) li = i; });
        if (li > last) last = li;
        if (li >= 0 && a.flags[li]) flagged = true;
      });
      if (last < 0) return;
      const interval = flagged ? 6 : 12;
      const lastDate = new Date(D.exams[last].date + 'T00:00:00');
      const due = new Date(lastDate);
      due.setMonth(due.getMonth() + interval);
      const left = (due - now) / (30.44 * 86400000);
      if (left < 1) items.push({ name: STATE.lang === 'pt' ? cat.name_pt : cat.name_es, ym: D.exams[last].date.slice(0, 7), interval, flagged, left });
    });
    items.sort((p, q) => p.left - q.left);
    return items;
  }
  function dueHtml() {
    if (!D.exams.length) return '';
    const items = dueItems();
    const latest = latestVitalMonth();
    const nowYm = new Date().toISOString().slice(0, 7);
    let stale = '';
    if (latest) {
      const [ly, lm] = latest.split('-').map(Number), [ny, nm] = nowYm.split('-').map(Number);
      if ((ny - ly) * 12 + (nm - lm) >= 2) stale = t('due_apple').replace('{m}', fmtMonthYear(latest));
    }
    if (!items.length && !stale) return '';
    const lis = items.map(it => {
      const late = it.left <= 0;
      const status = late
        ? t('due_overdue').replace('{n}', monthsTxt(Math.max(1, Math.round(-it.left))))
        : t('due_soon');
      return `<li class="${late ? 'is-late' : 'is-soon'}"><strong>${esc(it.name)}</strong>` +
        `<span>${esc(t('last_value'))}: ${esc(fmtMonthYear(it.ym))} · ${esc(t('due_every').replace('{n}', it.interval))}` +
        `${it.flagged ? ' (' + esc(t('due_flagged')) + ')' : ''} · <em>${esc(status)}</em></span></li>`;
    }).join('') + (stale ? `<li class="is-soon"><strong>Apple Health</strong><span>${esc(stale)}</span></li>` : '');
    return `<section class="section due">
      <h2 class="section-title">${esc(t('due_title'))}</h2>
      <ul class="due-list">${lis}</ul>
      <p class="due-note">${esc(t('due_note'))}</p>
    </section>`;
  }

  function matrixHtml() {
    if (!D.exams.length) return '';
    const n = D.exams.length;
    const extra = trailingCols();
    const cols = n + extra;
    const hasHealth = D.health.some(m => m.points.some(p => p.value != null));

    let thead = `<tr><th class="col-name" scope="col"></th><th class="cell-spark" scope="col"></th>`;
    D.exams.forEach((e, i) => {
      const last = i === n - 1 ? ' col-last' : '';
      const ym = e.date.slice(0, 7);
      thead += `<th scope="col" class="col-date${last}" data-col="${i}">${esc(monthShort(ym))}<span class="th-year">${ym.slice(0, 4)}</span></th>`;
    });
    for (let k = 0; k < extra; k++) {
      const ym = latestVitalMonth();
      thead += k === 0
        ? `<th scope="col" class="col-recent" title="${esc(uiInfo('recent_note') || (STATE.lang === 'pt' ? 'Sem exame laboratorial: apenas sinais vitais' : 'Sin examen de laboratorio: solo signos vitales'))}">${esc(uiInfo('recent') || (STATE.lang === 'pt' ? 'Recente' : 'Reciente'))}<span class="th-year">${esc(fmtMonthYear(ym))}</span></th>`
        : '<th scope="col" class="col-recent"></th>';
    }
    thead += '</tr>';
    const emptyRecent = '<td class="cell-val col-recent"></td>'.repeat(extra);

    let vitalRows = '';
    if (hasHealth) {
      vitalRows += `<tr class="cat-row" data-catmatch="${esc(S.i18n.es.vitals + ' ' + S.i18n.pt.vitals)}"><td class="col-name">${esc(t('vitals'))}</td><td colspan="${cols + 1}" class="cat-note">${esc(t('vitals_note'))}</td></tr>`;
      D.health.forEach((m, mi) => {
        if (!vitalDelta(m)) return;
        vitalRows +=
          `<tr class="vital-row" tabindex="0" role="button" data-metric="${esc(m.metric)}" aria-label="${esc(mname(m))} — ${esc(t('evolution'))}">
            <td class="col-name">
              <div class="analyte-name">${esc(mname(m))}</div>
              ${vitalRef(m) ? `<div class="analyte-ref">${esc(vitalRef(m))}</div>` : ''}
            </td>
            <td class="cell-spark">${vitalSparkSvg(m)}</td>
            <td class="vital-chart-cell" colspan="${cols}"><div class="vital-inline" data-vital="${mi}"></div></td>
          </tr>`;
      });
    }

    let rows = '';
    D.categories.forEach(cat => {
      rows += `<tr class="cat-row" data-catmatch="${esc(cat.name_es + ' ' + cat.name_pt)}"><td class="col-name">${esc(STATE.lang === 'pt' ? cat.name_pt : cat.name_es)}</td><td colspan="${cols + 1}"></td></tr>`;
      cat.analytes.forEach(a => {
        let cells = '';
        a.values.forEach((v, i) => {
          const isLast = i === n - 1;
          if (v == null) {
            cells += `<td class="cell-val is-empty${isLast ? ' is-last' : ''}" data-col="${i}">—</td>`;
          } else if (a.flags[i]) {
            const sr = a.flags[i] === 'high' ? t('high_abbr') : t('low_abbr');
            const c = changeInfo(a, i);
            const arrow = c ? `<span class="chip-arrow" title="${esc(changeTitle(c))}" aria-hidden="true">${c.up ? '▲' : '▼'}</span>` : '';
            const csr = c ? `<span class="visually-hidden"> ${esc(changeTitle(c))}</span>` : '';
            cells += `<td class="cell-val${isLast ? ' is-last' : ''}" data-col="${i}"><span class="val-chip" title="${esc(t('out_of_range'))} (${esc(sr)})">${arrow}${fmtNum(v, a.dec)}<span class="visually-hidden"> ${esc(t('out_of_range'))} (${esc(sr)})</span>${csr}</span></td>`;
          } else {
            const c = changeInfo(a, i);
            const arrow = c ? `<span class="chg chg-${c.tone}" title="${esc(changeTitle(c))}" aria-hidden="true">${c.up ? '▲' : '▼'}</span>` : '';
            const csr = c ? `<span class="visually-hidden"> ${esc(changeTitle(c))}</span>` : '';
            cells += `<td class="cell-val${isLast ? ' is-last' : ''}" data-col="${i}">${arrow}${fmtNum(v, a.dec)}${csr}</td>`;
          }
        });
        rows += `<tr class="analyte-row" tabindex="0" role="button" data-analyte="${esc(a.code)}"
                     aria-label="${esc(aname(a))} — ${esc(t('evolution'))}">
          <td class="col-name">
            <div class="analyte-name">${esc(aname(a))}</div>
            <div class="analyte-ref">${esc(refText(a))}</div>
          </td>
          <td class="cell-spark">${sparkSvg(a)}</td>${cells}${emptyRecent}</tr>`;
      });
    });

    return `<section class="section" aria-labelledby="labs-title">
      <div class="section-hd">
        <h2 class="section-title" id="labs-title">${esc(t('labs'))}</h2>
        <span class="section-note">${esc(t('labs_note'))}</span>
      </div>
      <div class="matrix-toolbar">
        <div class="filter-box">
          <input id="matrix-filter" type="search" placeholder="${esc(t('filter_ph'))}"
                 value="${esc(STATE.filter)}" autocomplete="off" aria-label="${esc(t('filter_ph'))}">
        </div>
        <div class="matrix-legend">
          <span><span class="legend-swatch" aria-hidden="true"></span>${esc(t('out_of_range'))}</span>
          <span class="legend-arrows" title="${esc(t('legend_change'))}"><span class="chg chg-better" aria-hidden="true">▲</span> ${esc(t('increased'))} · <span class="chg chg-worse" aria-hidden="true">▼</span> ${esc(t('decreased'))} <span class="legend-sub">${esc(t('legend_change'))}</span></span>
        </div>
      </div>
      <div class="matrix-wrap"><table class="matrix">
        <thead>${thead}</thead><tbody>${vitalRows}${rows}
        <tr class="no-results-row" hidden><td class="col-name"></td><td colspan="${cols + 1}"></td></tr>
        </tbody>
      </table></div>
    </section>`;
  }

  // mide el centro de cada columna de examen y dibuja los vitales alineados
  function renderIntegratedVitals() {
    const table = document.querySelector('table.matrix');
    if (!table) return;
    const containers = table.querySelectorAll('.vital-inline');
    if (!containers.length) return;

    const ths = table.querySelectorAll('thead th.col-date');
    const firstContainer = containers[0];
    const contRect = firstContainer.getBoundingClientRect();
    // los valores y la fecha van alineados a la derecha de la columna: anclar al
    // centro del texto (el año es la línea más ancha), no al centro de la celda
    // (se miden los nodos de texto: el año es un bloque y su caja ocupa toda la celda)
    function centerOf(th) {
      let left = Infinity, right = -Infinity;
      const walker = document.createTreeWalker(th, NodeFilter.SHOW_TEXT);
      const rng = document.createRange();
      for (let n = walker.nextNode(); n; n = walker.nextNode()) {
        rng.selectNodeContents(n);
        const tr = rng.getBoundingClientRect();
        if (tr.width > 0) { left = Math.min(left, tr.left); right = Math.max(right, tr.right); }
      }
      const r = th.getBoundingClientRect();
      return (right > left ? (left + right) / 2 : r.left + r.width / 2) - contRect.left;
    }
    const anchors = [];
    ths.forEach((th, i) => anchors.push({ time: Date.parse(D.exams[i].date), x: centerOf(th) }));
    // columna "Reciente": ancla en el mes más reciente de vitales
    const rec = table.querySelector('thead th.col-recent');
    if (rec && ths.length) anchors.push({ time: ymToTime(latestVitalMonth()), x: centerOf(rec) });

    containers.forEach(el => {
      renderInlineVital(el, D.health[Number(el.dataset.vital)], anchors);
    });
  }

  // ---------- filtro de analitos ----------

  function normTxt(s) {
    return String(s).toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '');
  }

  function applyFilter() {
    const table = document.querySelector('table.matrix');
    if (!table) return;
    const q = normTxt(STATE.filter.trim());
    const rows = Array.from(table.tBodies[0].rows);
    let curCat = null, curCatMatched = false, curCatAny = false, total = 0;
    const flushCat = () => { if (curCat) curCat.hidden = !!q && !curCatMatched && !curCatAny; };

    rows.forEach(row => {
      if (row.classList.contains('no-results-row')) return;
      if (row.classList.contains('cat-row')) {
        flushCat();
        curCat = row;
        curCatMatched = !!q && normTxt(row.dataset.catmatch || '').includes(q);
        curCatAny = false;
        return;
      }
      let name = '';
      if (row.classList.contains('vital-row')) {
        const m = D.health.find(h => h.metric === row.dataset.metric);
        name = m ? m.name_es + ' ' + m.name_pt : '';
      } else if (row.classList.contains('analyte-row')) {
        const a = findAnalyte(row.dataset.analyte);
        name = a ? a.name_es + ' ' + a.name_pt : '';
      }
      const show = !q || curCatMatched || normTxt(name).includes(q);
      row.hidden = !show;
      if (show) { curCatAny = true; total++; }
    });
    flushCat();

    const nr = table.querySelector('.no-results-row');
    if (nr) {
      nr.hidden = !q || total > 0;
      nr.lastElementChild.textContent = t('no_results') + ' “' + STATE.filter.trim() + '”';
    }
  }

  function bindFilter() {
    const input = document.getElementById('matrix-filter');
    if (!input) return;
    input.addEventListener('input', () => { STATE.filter = input.value; applyFilter(); });
    input.addEventListener('keydown', ev => {
      if (ev.key === 'Escape' && input.value) {
        ev.stopPropagation();
        input.value = ''; STATE.filter = ''; applyFilter();
      }
    });
  }

  // "/" enfoca el filtro desde cualquier lado
  document.addEventListener('keydown', ev => {
    if (ev.key !== '/' || STATE.detail) return;
    const tag = (document.activeElement && document.activeElement.tagName) || '';
    if (tag === 'INPUT' || tag === 'TEXTAREA') return;
    const input = document.getElementById('matrix-filter');
    if (input) { ev.preventDefault(); input.focus(); input.select(); }
  });

  // hover en celdas de laboratorio: columna + fila + sync de vitales + tooltip
  function bindMatrixHover() {
    const table = document.querySelector('table.matrix');
    if (!table) return;
    let curCol = null;

    function setCol(col) {
      if (col === curCol) return;
      table.querySelectorAll('.col-hot').forEach(el => el.classList.remove('col-hot'));
      curCol = col;
      if (col == null) { syncVitals(null); return; }
      table.querySelectorAll(`[data-col="${col}"]`).forEach(el => el.classList.add('col-hot'));
      syncVitals(col);
    }

    table.addEventListener('mousemove', ev => {
      const td = ev.target.closest && ev.target.closest('td.cell-val');
      if (!td) return;
      const col = Number(td.dataset.col);
      setCol(col);
      const row = td.parentElement;
      const a = findAnalyte(row.dataset.analyte);
      const exam = D.exams[col];
      if (!a || !exam) return;
      const ym = exam.date.slice(0, 7);
      const v = a.values[col];
      const valTxt = v == null ? '—' : fmtNum(v, a.dec) + ' ' + a.unit;
      showTip(ev.clientX, ev.clientY,
        `<span class="tip-label">${esc(fmtMonthYear(ym))}</span><strong>${esc(aname(a))}</strong> ${esc(valTxt)}` +
        vitalsTipLine(ym));
    });
    table.addEventListener('mouseleave', () => { setCol(null); hideTip(); });
    table.addEventListener('mouseover', ev => {
      // al pasar a una celda que no es de valor (nombre, spark), limpiar tooltip
      if (ev.target.closest && !ev.target.closest('td.cell-val') && !ev.target.closest('.vital-inline')) {
        setCol(null); hideTip();
      }
    });
  }

  // ---------- resumen y recomendaciones (Gemini, publicados desde el backend) ----------

  function aiText() {
    return D.ai && D.ai.text && (D.ai.text[STATE.lang] || D.ai.text.es) || null;
  }
  function aiSummaryHtml() {
    const x = aiText();
    if (!x || !x.summary) return '';
    return `<section class="section ai-summary">
      <h2 class="section-title">${esc(t('ai_summary'))}</h2>
      <p class="ai-text">${esc(x.summary)}</p>
    </section>`;
  }
  function aiRecsHtml() {
    const x = aiText();
    if (!x || !x.recommendations || !x.recommendations.length) return '';
    const qs = (x.doctor_questions || []).length
      ? `<div class="ai-doctor"><h3>${esc(t('ai_doctor'))}</h3><ul>${x.doctor_questions.map(q => `<li>${esc(q)}</li>`).join('')}</ul></div>` : '';
    return `<section class="section ai-recs">
      <h2 class="section-title">${esc(t('ai_recs'))}</h2>
      <ol class="ai-list">${x.recommendations.map(r =>
        `<li><strong>${esc(r.title)}</strong><span>${esc(r.body)}</span></li>`).join('')}</ol>
      ${qs}
      <p class="ai-disc">${esc(t('ai_disclaimer'))}</p>
    </section>`;
  }

  function scrollMatrixToEnd() {
    const wrap = document.querySelector('.matrix-wrap');
    if (wrap && wrap.scrollWidth > wrap.clientWidth) wrap.scrollLeft = wrap.scrollWidth;
  }

  // ---------- render principal ----------

  function render() {
    document.documentElement.lang = STATE.lang;
    document.title = t('title') + ' — ' + D.patient.name;
    const app = document.getElementById('app');
    const genDate = fmtDateLong(D.generated_at.slice(0, 10));
    const hasExams = D.exams.length > 0;
    const hasHealth = D.health.some(m => m.points.some(p => p.value != null));
    const hasData = hasExams || hasHealth;

    app.innerHTML =
      `<header class="hdr">
        <div>
          <p class="hdr-eyebrow">${esc(t('title'))}</p>
          <h1 class="hdr-name">${esc(D.patient.name)}</h1>
          <p class="hdr-meta">${esc(t('subtitle'))}</p>
        </div>
        <div class="hdr-controls">
          <div class="lang-toggle" role="group" aria-label="Idioma">
            <button type="button" data-lang="es" aria-pressed="${STATE.lang === 'es'}">ES</button>
            <button type="button" data-lang="pt" aria-pressed="${STATE.lang === 'pt'}">PT</button>
          </div>
          <button type="button" class="btn-ghost" id="btn-print">${esc(t('print'))}</button>
          ${S.links.backend ? `<a class="btn-ghost" href="${esc(S.links.backend)}">${esc(t('admin'))}</a>` : ''}
          ${S.links.logout ? `<a class="btn-ghost" href="${esc(S.links.logout)}">${esc(t('logout'))}</a>` : ''}
        </div>
      </header>` +
      (D.is_dummy ? `<div class="dummy-banner">${esc(t('dummy_notice'))}</div>` : '') +
      aiSummaryHtml() +
      dueHtml() +
      (hasData
        ? (hasExams ? matrixHtml() : standaloneVitalsHtml())
        : `<section class="section"><p class="hdr-meta">${esc(t('no_data'))}</p></section>`) +
      aiRecsHtml() +
      `<footer class="foot"><span>${esc(t('updated'))}: ${esc(genDate)}</span><a href="https://github.com/socialmenteagency/vitalog" target="_blank" rel="noopener">Vitalog · AGPL-3.0</a></footer>`;

    if (hasExams) {
      renderIntegratedVitals();
      // las fuentes web cambian el ancho de las columnas al cargar: realinear los gráficos
      if (document.fonts && document.fonts.ready) document.fonts.ready.then(() => { renderIntegratedVitals(); scrollMatrixToEnd(); });
      bindMatrixHover();
      bindFilter();
      applyFilter();
      // arrancar mostrando los exámenes más recientes
      scrollMatrixToEnd();
    } else {
      app.querySelectorAll('.vital-chart').forEach(el => {
        renderStandaloneVital(el, D.health[Number(el.dataset.vital)]);
      });
    }

    app.querySelectorAll('.lang-toggle button').forEach(btn => {
      btn.addEventListener('click', () => setLang(btn.dataset.lang));
    });
    const printBtn = document.getElementById('btn-print');
    if (printBtn) printBtn.addEventListener('click', () => window.print());

    app.querySelectorAll('.analyte-row, .vital-row').forEach(row => {
      const open = () => (row.dataset.analyte ? openDetail(row.dataset.analyte) : openVitalDetail(row.dataset.metric));
      row.addEventListener('click', open);
      row.addEventListener('keydown', ev => {
        if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); open(); }
      });
    });
  }

  // ---------- detalle ----------

  function findAnalyte(code) {
    for (const cat of D.categories) {
      for (const a of cat.analytes) if (a.code === code) return a;
    }
    return null;
  }

  function openDetail(code) {
    const a = findAnalyte(code);
    if (a) showDetail(a);
  }

  // Un signo vital se muestra con el mismo modal que un analito: se lo adapta a
  // la misma forma (serie mensual, rango/escalones, texto educativo).
  function vitalAsAnalyte(m) {
    const pts = m.points.filter(p => p.value != null);
    if (!pts.length) return null;
    const last = pts[pts.length - 1];
    const a = {
      code: m.metric, name_es: m.name_es, name_pt: m.name_pt, unit: munit(m), dec: m.dec,
      low: null, high: null, flags: [], isVital: true,
      series: {
        all: pts.map(p => ({ t: ymToTime(p.month), label: fmtMonthYear(p.month) })),
        pts: pts.map(p => ({ t: ymToTime(p.month), v: p.value })),
        lastText: fmtMonthYear(last.month) + ' · Apple Health',
        labelAll: false, dotR: 3.2
      }
    };
    const R = VINFO && VINFO.ref && VINFO.ref[m.metric];
    if (m.metric === 'weight') {
      // rango saludable según IMC 18,5–24,9 y la estatura del perfil
      const hm = D.profile && D.profile.height_cm ? D.profile.height_cm / 100 : null;
      if (hm) {
        const k = hm * hm, kg = v => fmtNum(v * k, 1);
        a.tiers = [
          { from: null, to: 18.5 * k, level: 'warn', r: '<' + kg(18.5) + ' kg', es: 'Bajo peso', pt: 'Abaixo do peso' },
          { from: 18.5 * k, to: 25 * k, level: 'ok', r: kg(18.5) + '–' + kg(25) + ' kg', es: 'Peso saludable', pt: 'Peso saudável' },
          { from: 25 * k, to: 30 * k, level: 'warn', r: kg(25) + '–' + kg(30) + ' kg', es: 'Sobrepeso', pt: 'Sobrepeso' },
          { from: 30 * k, to: null, level: 'bad', r: '≥' + kg(30) + ' kg', es: 'Obesidad', pt: 'Obesidade' }
        ];
        a.refOverride = kg(18.5) + '–' + kg(25) + ' kg (' + uiInfo('bmi_note') + ')';
      } else {
        a.refOverride = uiInfo('height_needed');
        a.noRange = true;
      }
    } else if (R) {
      a.refOverride = R[STATE.lang];
    }
    return a;
  }

  function openVitalDetail(metric) {
    const m = D.health.find(h => h.metric === metric);
    const a = m && vitalAsAnalyte(m);
    if (a) showDetail(a);
  }

  function showDetail(a) {
    closeDetail();
    hideTip();
    const S = seriesOf(a);
    const last = S.pts[S.pts.length - 1];
    const flag = last.flag;
    const zone = tiersFor(a) ? zoneOf(zonesFor(a), last.v) : null;
    let flagHtml;
    if (zone) {
      flagHtml = `<span class="detail-flag ${zone.level}">${esc(zone.label)}</span>`;
    } else if (a.noRange) {
      flagHtml = '';
    } else if (flag) {
      flagHtml = `<span class="detail-flag">${esc(t('out_of_range'))} (${esc(flag === 'high' ? t('high_abbr') : t('low_abbr'))})</span>`;
    } else {
      flagHtml = `<span class="detail-flag ok">${esc(t('in_range'))}</span>`;
    }

    // valor de laboratorio fuera de rango: sugerir consultarlo (y, si está en zona "bad", pronto)
    let doctorNote = '';
    if (!a.noRange && a.flags && a.flags.length && (flag || (zone && zone.level !== 'ok'))) {
      const urgent = zone ? zone.level === 'bad' : false;
      doctorNote = `<p class="doctor-note${urgent ? ' urgent' : ''}">${esc(t(urgent ? 'see_doctor_bad' : 'see_doctor'))}</p>`;
    }

    const info = infoFor(a);
    let infoHtml = '';
    if (info) {
      const tips = tipsFor(a).map(x => `<li>${esc(x)}</li>`).join('');
      infoHtml =
        `<p class="detail-about">${esc(info.about)} <button type="button" class="link-btn" aria-expanded="false">${esc(uiInfo('learn_more'))}</button></p>
        <div class="detail-more" hidden>
          <h4>${esc(uiInfo('what'))}</h4>
          <p>${esc(info.more)}</p>
          ${tips ? `<h4>${esc(uiInfo('improve'))}</h4><ul>${tips}</ul>` : ''}
          <p class="detail-disc">${esc(uiInfo('disclaimer'))}</p>
        </div>`;
    }

    const wrap = document.createElement('div');
    wrap.className = 'detail-backdrop';
    wrap.innerHTML =
      `<div class="detail-card" role="dialog" aria-modal="true" aria-label="${esc(aname(a))}">
        <div class="detail-hd">
          <div>
            <h3 class="detail-title">${esc(aname(a))}</h3>
            <p class="detail-sub">${esc(t('reference'))}: ${esc(refText(a))}</p>
          </div>
          <button type="button" class="detail-close">${esc(t('close'))}</button>
        </div>
        ${infoHtml}
        ${doctorNote}
        <div class="detail-stat">
          <span class="detail-value">${fmtNum(last.v, a.dec)}<span class="vital-unit"> ${esc(a.unit)}</span></span>
          ${flagHtml}
          <span class="detail-sub">${esc(S.lastText)}</span>
        </div>
        <div class="detail-chart"></div>
      </div>`;
    const nav = detailNeighbours(a);
    wrap.insertAdjacentHTML('beforeend', navBtnHtml('prev', nav.prev) + navBtnHtml('next', nav.next));
    document.body.appendChild(wrap);
    STATE.detail = wrap;
    STATE.detailNav = nav;

    renderDetailChart(wrap.querySelector('.detail-chart'), a);
    wrap.querySelector('.detail-close').addEventListener('click', closeDetail);
    wrap.querySelectorAll('.detail-nav').forEach(b => b.addEventListener('click', () => stepDetail(b.classList.contains('prev') ? -1 : 1)));
    wrap.addEventListener('click', ev => { if (ev.target === wrap) closeDetail(); });
    const more = wrap.querySelector('.detail-more');
    const moreBtn = wrap.querySelector('.link-btn');
    if (more && moreBtn) {
      moreBtn.addEventListener('click', () => {
        const open = more.hidden;
        more.hidden = !open;
        moreBtn.setAttribute('aria-expanded', String(open));
        moreBtn.textContent = uiInfo(open ? 'show_less' : 'learn_more');
      });
    }
    wrap.querySelector('.detail-close').focus();
  }

  // Anterior/siguiente fila visible de la tabla (signos vitales y analitos, en el orden en pantalla).
  function rowKey(r) { return r.dataset.analyte ? 'a:' + r.dataset.analyte : 'v:' + r.dataset.metric; }
  function rowName(r) {
    if (r.dataset.analyte) { const a = findAnalyte(r.dataset.analyte); return a ? aname(a) : ''; }
    const m = D.health.find(h => h.metric === r.dataset.metric);
    return m ? mname(m) : '';
  }
  function detailNeighbours(a) {
    const table = document.querySelector('table.matrix');
    const none = { prev: null, next: null };
    if (!table) return none;
    const rows = Array.from(table.tBodies[0].querySelectorAll('tr.vital-row, tr.analyte-row')).filter(r => !r.hidden);
    const key = (a.isVital ? 'v:' : 'a:') + a.code;
    const i = rows.findIndex(r => rowKey(r) === key);
    if (i < 0) return none;
    const pick = r => (r ? { key: rowKey(r), name: rowName(r) } : null);
    return { prev: pick(rows[i - 1]), next: pick(rows[i + 1]) };
  }
  function navBtnHtml(dir, target) {
    const pt = STATE.lang === 'pt';
    const word = dir === 'prev' ? 'Anterior' : (pt ? 'Próximo' : 'Siguiente');
    const label = target ? word + ': ' + target.name : word;
    const d = dir === 'prev' ? 'M15 5l-7 7 7 7' : 'M9 5l7 7-7 7';
    return '<button type="button" class="detail-nav ' + dir + '"' + (target ? '' : ' disabled') +
      ' aria-label="' + esc(label) + '" title="' + esc(label) + '">' +
      '<svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true"><path d="' + d + '" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg></button>';
  }
  function stepDetail(dir) {
    const target = STATE.detailNav && (dir < 0 ? STATE.detailNav.prev : STATE.detailNav.next);
    if (!target) return;
    if (target.key.charAt(0) === 'a') openDetail(target.key.slice(2)); else openVitalDetail(target.key.slice(2));
  }

  function closeDetail() {
    if (STATE.detail) { STATE.detail.remove(); STATE.detail = null; STATE.detailNav = null; }
    hideTip();
  }

  document.addEventListener('keydown', ev => {
    if (ev.key === 'Escape') closeDetail();
    else if ((ev.key === 'ArrowLeft' || ev.key === 'ArrowRight') && STATE.detail && !ev.altKey && !ev.ctrlKey && !ev.metaKey && !ev.shiftKey) {
      ev.preventDefault();
      stepDetail(ev.key === 'ArrowLeft' ? -1 : 1);
    }
  });

  // ---------- idioma ----------

  function setLang(lang) {
    STATE.lang = lang === 'pt' ? 'pt' : 'es';
    try { localStorage.setItem('salud_lang', STATE.lang); } catch (e) { /* privado */ }
    closeDetail();
    hideTip();
    render();
  }

  let stored = null;
  try { stored = localStorage.getItem('salud_lang'); } catch (e) { /* privado */ }
  STATE.lang = S.lang || stored || 'es';
  if (STATE.lang !== 'pt') STATE.lang = 'es';

  render();
  rendered = true;

  // re-render al cambiar el ancho (los anclajes de columna cambian)
  let resizeT = null;
  window.addEventListener('resize', () => {
    clearTimeout(resizeT);
    resizeT = setTimeout(() => { closeDetail(); hideTip(); render(); }, 180);
  });
})();
