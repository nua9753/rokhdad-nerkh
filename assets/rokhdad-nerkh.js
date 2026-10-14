/* Rokhdad Nerkh front end: live numbers, chart, card-to-image sharing, gold calculator, currency converter. No dependencies. */
(function () {
	'use strict';

	var cfg = window.NRY || {};
	var FA = '۰۱۲۳۴۵۶۷۸۹';
	var AR = '٠١٢٣٤٥٦٧٨٩';

	function fa(s) {
		return String(s).replace(/\d/g, function (d) { return FA.charAt(d); });
	}

	function num(n, dec) {
		if (!isFinite(n)) { return '—'; }
		var a = Math.abs(n);
		if (dec == null && a > 0 && a < 1) {
			var s = a.toFixed(Math.min(10, 3 - Math.floor(Math.log10(a)))).replace(/0+$/, '');
			while (s.length < s.indexOf('.') + 3) { s += '0'; }
			return fa((n < 0 ? '−' : '') + s.replace('.', '٫'));
		}
		if (dec == null) { dec = (a < 100000 && Math.floor(n) !== n) ? 2 : 0; }
		var parts = Math.abs(n).toFixed(dec).split('.');
		parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '٬');
		return fa((n < 0 ? '−' : '') + parts[0] + (parts[1] ? '٫' + parts[1] : ''));
	}

	function pct(p) {
		var sign = p > 0 ? '+' : (p < 0 ? '−' : '');
		return sign + fa(Math.abs(p).toFixed(2).replace('.', '٫')) + '٪';
	}

	function parse(s) {
		s = String(s == null ? '' : s)
			.replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); })
			.replace(/[٠-٩]/g, function (d) { return AR.indexOf(d); })
			.replace(/[٬,\s]/g, '')
			.replace('٫', '.');
		return s === '' ? NaN : Number(s);
	}

	function all(root, sel) {
		return Array.prototype.slice.call(root.querySelectorAll(sel));
	}

	function getJSON(path, done) {
		if (!cfg.rest || !window.fetch) { return; }
		fetch(cfg.rest + path, { credentials: 'omit' })
			.then(function (r) { return r.ok ? r.json() : null; })
			.then(function (data) { if (data) { done(data); } })
			.catch(function () {});
	}

	/* ---------- live numbers ---------- */

	function setText(el, field, text) {
		var target = el.getAttribute('data-f') === field ? el : el.querySelector('[data-f="' + field + '"]');
		if (target) { target.textContent = text; }
	}

	function refreshPrices() {
		var nodes = all(document, '[data-nry]');
		if (!nodes.length) { return; }
		var symbols = {};
		nodes.forEach(function (n) { symbols[n.getAttribute('data-nry')] = 1; });
		getJSON('prices?s=' + encodeURIComponent(Object.keys(symbols).join(',')), function (data) {
			nodes.forEach(function (n) {
				var it = data.items && data.items[n.getAttribute('data-nry')];
				if (!it) { return; }
				setText(n, 'price', num(it.price));
				setText(n, 'pct', pct(it.pct));
				setText(n, 'change', num(Math.abs(it.change)));
				n.classList.remove('nry-up', 'nry-down', 'nry-flat');
				n.classList.add(it.pct > 0 ? 'nry-up' : (it.pct < 0 ? 'nry-down' : 'nry-flat'));
			});
		});
	}

	/* ---------- chart ---------- */

	function div(cls) {
		var d = document.createElement('div');
		d.className = cls;
		return d;
	}

	function drawChart(box, data) {
		var plot = box.querySelector('.nry-chart__plot');
		var pts = (data && data.points) || [];
		var set = function (k, v) { var el = box.querySelector('[data-a="' + k + '"]'); if (el) { el.textContent = v; } };
		plot.innerHTML = '';
		plot.classList.remove('is-on');
		box._nry = null;
		if (pts.length < 2) {
			plot.textContent = (data && data.note) || 'برای این بازه داده‌ای در دسترس نیست.';
			set('from', ''); set('range', ''); set('to', '');
			return;
		}
		var W = 1000, H = 400, min = Infinity, max = -Infinity;
		pts.forEach(function (p) { if (p.v < min) { min = p.v; } if (p.v > max) { max = p.v; } });
		var span = (max - min) || Math.abs(max) * 0.01 || 1;
		var lo = min - span * 0.14, hi = max + span * 0.14;
		var fx = function (i) { return i / (pts.length - 1); };
		var fy = function (v) { return 1 - (v - lo) / (hi - lo); };
		var line = pts.map(function (p, i) {
			return (i ? 'L' : 'M') + (fx(i) * W).toFixed(1) + ' ' + (fy(p.v) * H).toFixed(1);
		}).join('');
		var ticks = [0.14, 0.38, 0.62, 0.86];
		var grid = ticks.map(function (f) { return 'M0 ' + (f * H).toFixed(1) + 'H' + W; }).join('');
		plot.innerHTML =
			'<svg viewBox="0 0 ' + W + ' ' + H + '" preserveAspectRatio="none" aria-hidden="true">' +
			'<path d="' + grid + '" stroke="#d7dce6" stroke-width="1" fill="none" vector-effect="non-scaling-stroke"/>' +
			'<path d="' + line + 'L' + W + ' ' + H + 'L0 ' + H + 'Z" fill="#fbf1d3"/>' +
			'<path d="' + line + '" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linejoin="round" vector-effect="non-scaling-stroke"/>' +
			'</svg>';
		// price axis
		ticks.forEach(function (f) {
			var t = div('nry-chart__tick');
			t.style.top = (f * 100) + '%';
			t.textContent = num(hi - f * (hi - lo));
			plot.appendChild(t);
		});
		// hover layer
		var cursor = div('nry-chart__cursor'), dot = div('nry-chart__dot'), tip = div('nry-chart__tip');
		var value = document.createElement('b'), when = document.createElement('span');
		tip.setAttribute('aria-live', 'polite');
		tip.appendChild(value); tip.appendChild(when);
		plot.appendChild(cursor); plot.appendChild(dot); plot.appendChild(tip);
		box._nry = { pts: pts, fx: fx, fy: fy, unit: data.unit || '', cursor: cursor, dot: dot, tip: tip, value: value, when: when, i: -1 };

		set('from', pts[0].t);
		set('to', pts[pts.length - 1].t);
		set('range', 'کمترین ' + num(min) + ' — بیشترین ' + num(max) + (data.unit ? ' ' + data.unit : ''));
	}

	function showPoint(box, i) {
		var d = box._nry;
		if (!d) { return; }
		i = Math.max(0, Math.min(d.pts.length - 1, i));
		d.i = i;
		var p = d.pts[i], x = d.fx(i) * 100, y = d.fy(p.v) * 100;
		d.cursor.style.left = x + '%';
		d.dot.style.left = x + '%';
		d.dot.style.top = y + '%';
		d.tip.style.left = x + '%';
		d.tip.classList.toggle('nry-chart__tip--flip', x > 55);
		d.value.textContent = num(p.v) + (d.unit ? ' ' + d.unit : '');
		d.when.textContent = p.t;
		box.querySelector('.nry-chart__plot').classList.add('is-on');
	}

	function hidePoint(box) {
		if (box._nry) { box._nry.i = -1; }
		box.querySelector('.nry-chart__plot').classList.remove('is-on');
	}

	function initChart(box) {
		var symbol = box.getAttribute('data-nry-chart');
		var plot = box.querySelector('.nry-chart__plot');
		function load(range) {
			all(box, '[data-range]').forEach(function (b) {
				b.setAttribute('aria-pressed', b.getAttribute('data-range') === range ? 'true' : 'false');
			});
			getJSON('history?symbol=' + encodeURIComponent(symbol) + '&range=' + range, function (data) { drawChart(box, data); });
		}
		all(box, '[data-range]').forEach(function (b) {
			b.addEventListener('click', function () { load(b.getAttribute('data-range')); });
		});
		function fromPointer(e) {
			if (!box._nry) { return; }
			var r = plot.getBoundingClientRect();
			showPoint(box, Math.round(((e.clientX - r.left) / r.width) * (box._nry.pts.length - 1)));
		}
		plot.addEventListener('pointermove', fromPointer);
		plot.addEventListener('pointerdown', fromPointer);
		plot.addEventListener('pointerleave', function () { hidePoint(box); });
		plot.addEventListener('blur', function () { hidePoint(box); });
		plot.addEventListener('keydown', function (e) {
			var d = box._nry;
			if (!d) { return; }
			var last = d.pts.length - 1, i = d.i < 0 ? last : d.i;
			if (e.key === 'ArrowRight') { i += 1; }
			else if (e.key === 'ArrowLeft') { i -= 1; }
			else if (e.key === 'Home') { i = 0; }
			else if (e.key === 'End') { i = last; }
			else if (e.key === 'Escape') { hidePoint(box); return; }
			else { return; }
			e.preventDefault();
			showPoint(box, i);
		});
		load('d');
	}

	/* ---------- share the price card as an image ---------- */

	function cardImage(card) {
		var W = 1200, H = 675;
		var c = document.createElement('canvas');
		c.width = W; c.height = H;
		var x = c.getContext('2d');
		var cs = getComputedStyle(card);
		var navy = cs.getPropertyValue('--nry-navy').trim() || '#0a2463';
		var gold = cs.getPropertyValue('--nry-gold').trim() || '#e3ae2b';
		var red = cs.getPropertyValue('--nry-red').trim() || '#d8232a';
		var deep = '#8f6200', pale = '#dbe6ff';
		var family = cs.fontFamily || 'sans-serif';
		var read = function (f) { var el = card.querySelector('[data-f="' + f + '"]'); return el ? el.textContent.trim() : ''; };
		var draw = function (text, size, weight, color, px, py, align, dir) {
			x.font = weight + ' ' + size + 'px ' + family;
			x.fillStyle = color;
			x.textAlign = align || 'right';
			x.direction = dir || 'rtl';
			x.fillText(text, px, py);
			return x.measureText(text).width;
		};

		x.fillStyle = navy; x.fillRect(0, 0, W, H);
		x.fillStyle = red; x.fillRect(0, 0, W, 8);
		var tw = draw(card.getAttribute('data-title') || '', 54, 800, '#ffffff', 1140, 108);
		x.fillStyle = red; x.fillRect(1140 - tw - 36, 72, 18, 18);
		draw(cfg.site || '', 28, 700, pale, 60, 104, 'left');

		x.fillStyle = gold; x.fillRect(40, 150, 1120, 450);
		x.strokeStyle = deep; x.lineWidth = 2; x.strokeRect(54, 164, 1092, 422);

		draw(card.getAttribute('data-label') || '', 38, 800, navy, 1096, 244);

		var s1 = card.getAttribute('data-stamp1'), s2 = card.getAttribute('data-stamp2');
		if (s1) {
			x.strokeStyle = navy; x.lineWidth = 4;
			x.beginPath(); x.arc(160, 232, 58, 0, Math.PI * 2); x.stroke();
			draw(s1, 28, 800, navy, 160, s2 ? 238 : 242, 'center');
			if (s2) { draw(s2, 15, 800, navy, 160, 264, 'center'); }
		}

		var price = read('price'), size = 128;
		x.font = '800 ' + size + 'px ' + family;
		while (size > 60 && x.measureText(price).width > 760) { size -= 6; x.font = '800 ' + size + 'px ' + family; }
		var pw = draw(price, size, 800, navy, 1096, 424, 'right', 'ltr');
		draw(card.getAttribute('data-unit') || '', 38, 700, navy, 1096 - pw - 28, 424);

		x.strokeStyle = deep; x.lineWidth = 2;
		x.beginPath(); x.moveTo(96, 470); x.lineTo(1104, 470); x.stroke();

		var lw = draw('تغییر امروز', 30, 700, navy, 1096, 544);
		draw(read('pct'), 38, 800, navy, 1096 - lw - 30, 546, 'right', 'ltr');
		draw(read('time'), 24, 500, navy, 96, 544, 'left');
		return c;
	}

	function shareCard(card) {
		var canvas = cardImage(card);
		var name = (card.getAttribute('data-nry') || 'price') + '.png';
		canvas.toBlob(function (blob) {
			if (!blob) { return; }
			var file = window.File ? new File([blob], name, { type: 'image/png' }) : null;
			if (file && navigator.canShare && navigator.canShare({ files: [file] })) {
				navigator.share({ files: [file], title: card.getAttribute('data-title') || '' }).catch(function () {});
				return;
			}
			var a = document.createElement('a');
			a.href = URL.createObjectURL(blob);
			a.download = name;
			document.body.appendChild(a);
			a.click();
			a.remove();
			setTimeout(function () { URL.revokeObjectURL(a.href); }, 2000);
		}, 'image/png');
	}

	function initShare(btn) {
		btn.addEventListener('click', function () {
			var card = btn.closest('.nry-card');
			var go = function () { shareCard(card); };
			// wait for the page font, so the image is not drawn with a fallback face
			if (document.fonts && document.fonts.ready) { document.fonts.ready.then(go); } else { go(); }
		});
	}

	/* ---------- calculators ---------- */

	function press(group, attr, value) {
		group.forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute(attr) === value ? 'true' : 'false'); });
	}

	function out(box, key, text) {
		var el = box.querySelector('[data-o="' + key + '"]');
		if (el) { el.textContent = text; }
	}

	function initGold(box) {
		var st = { k: 18, u: 'g' };
		var inp = {};
		all(box, '[data-i]').forEach(function (i) { inp[i.getAttribute('data-i')] = i; });
		var ks = all(box, '[data-k]'), us = all(box, '[data-u]');

		function calc() {
			var w = parse(inp.w.value), p = parse(inp.p.value);
			var a = parse(inp.a.value) || 0, s = parse(inp.s.value) || 0, t = parse(inp.t.value) || 0;
			if (!(w > 0 && p > 0)) {
				['base', 'wage', 'profit', 'tax', 'total'].forEach(function (k) { out(box, k, '—'); });
				return;
			}
			var grams = st.u === 'm' ? w * 4.6083 : w;
			var base = grams * p * st.k / 18;
			var wage = base * a / 100;
			var profit = (base + wage) * s / 100;
			var tax = (wage + profit) * t / 100;
			out(box, 'base', num(base, 0));
			out(box, 'wage', num(wage, 0));
			out(box, 'profit', num(profit, 0));
			out(box, 'tax', num(tax, 0));
			out(box, 'total', num(base + wage + profit + tax, 0));
		}

		ks.forEach(function (b) { b.addEventListener('click', function () { st.k = Number(b.getAttribute('data-k')); press(ks, 'data-k', String(st.k)); calc(); }); });
		us.forEach(function (b) { b.addEventListener('click', function () { st.u = b.getAttribute('data-u'); press(us, 'data-u', st.u); calc(); }); });
		Object.keys(inp).forEach(function (k) { inp[k].addEventListener('input', calc); });
		inp.p.addEventListener('blur', function () { var n = parse(inp.p.value); inp.p.value = isNaN(n) ? '' : num(Math.round(n), 0); });
		box.querySelector('[data-reset]').addEventListener('click', function () {
			st = { k: 18, u: 'g' };
			press(ks, 'data-k', '18'); press(us, 'data-u', 'g');
			Object.keys(inp).forEach(function (k) { inp[k].value = inp[k].getAttribute('data-default') || ''; });
			calc();
		});
		calc();
	}

	function initFx(box) {
		var curs = all(box, '[data-cur]'), dirs = all(box, '[data-dir]');
		var amt = box.querySelector('[data-i="amt"]'), rate = box.querySelector('[data-i="rate"]');
		var st = { cur: curs[0], dir: 'f' };

		function label(k, v) { var el = box.querySelector('[data-l="' + k + '"]'); if (el) { el.textContent = v; } }

		function calc() {
			var name = st.cur.getAttribute('data-name');
			label('in', st.dir === 'f' ? name : 'تومان');
			label('out', st.dir === 'f' ? 'تومان' : name);
			var a = parse(amt.value), r = parse(rate.value);
			if (!(a > 0 && r > 0)) { out(box, 'total', '—'); out(box, 'rial', '—'); return; }
			if (st.dir === 'f') {
				out(box, 'total', num(a * r, 0));
				out(box, 'rial', num(a * r * 10, 0) + ' ریال');
			} else {
				out(box, 'total', num(a / r, 2));
				out(box, 'rial', num(a * 10, 0) + ' ریال');
			}
		}

		function pick(b) {
			st.cur = b;
			press(curs, 'data-cur', b.getAttribute('data-cur'));
			rate.value = num(Number(b.getAttribute('data-rate')), 0);
			calc();
		}

		curs.forEach(function (b) { b.addEventListener('click', function () { pick(b); }); });
		dirs.forEach(function (b) { b.addEventListener('click', function () { st.dir = b.getAttribute('data-dir'); press(dirs, 'data-dir', st.dir); calc(); }); });
		amt.addEventListener('input', calc);
		rate.addEventListener('input', calc);
		rate.addEventListener('blur', function () { var n = parse(rate.value); rate.value = isNaN(n) ? '' : num(Math.round(n), 0); });
		box.querySelector('[data-reset]').addEventListener('click', function () {
			st.dir = 'f'; press(dirs, 'data-dir', 'f'); amt.value = ''; pick(curs[0]);
		});
		pick(curs[0]);
	}

	function start() {
		all(document, '[data-nry-chart]').forEach(initChart);
		all(document, '[data-nry-share]').forEach(initShare);
		all(document, '[data-nry-calc="gold"]').forEach(initGold);
		all(document, '[data-nry-calc="fx"]').forEach(initFx);
		if (document.querySelector('[data-nry]')) {
			setInterval(refreshPrices, Math.max(30, Number(cfg.every) || 60) * 1000);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
