/* assets/js/price-map.js — نقشه قیمت‌گذاری خدمات
 * گراف Hub-and-Spoke با مرکز ثابت (شعبه خود شما) + چیدمان تعیین‌شونده (deterministic).
 * بدون شبیه‌سازی نیرو و بدون وابستگی خارجی.
 */
(function () {
    'use strict';

    var DATA = window.PRICE_MAP || { nodes: [], links: [], services: [], meBranchId: 1 };
    var SVG_NS = 'http://www.w3.org/2000/svg';
    var TYPE_COLOR = { branch: '#6366F1', lab: '#0891B2', doctor: '#16A34A', designer: '#D97706' };
    var TYPE_ORDER = { branch: 0, lab: 1, doctor: 2, designer: 3 };
    var TYPE_NAME = { branch: 'شعبه', lab: 'لابراتوار', doctor: 'پزشک', designer: 'طراح' };
    var WORLD_W = 1200, WORLD_H = 760;
    var CX = 600, CY = 378;      // مرکز ثابت (هرگز حرکت نمی‌کند)
    var R1 = 250, R2 = 375;      // شعاع حلقه ۱ (همکاران) و حلقه ۲ (پزشک‌های زیرمجموعه)
    var STORAGE_KEY = 'pm_positions_v1';
    var COMPACT_ZOOM = 0.72;     // زیر این zoom برچسب قیمت خلاصه می‌شود
    var EDGE_COLOR = { sell: '#16A34A', buy: '#DC2626', other: '#64748B' };

    // ---------- helpers ----------
    function toFa(n) {
        return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[+d]; });
    }
    // نمایش فشرده: ۱.۹م / ۴۶۰ هزار / ۲۵۰ هزار
    function fmtPrice(p) {
        p = Number(p) || 0;
        if (p >= 1000000000) return toFa((p / 1000000000).toFixed(1).replace(/\.0$/, '')) + ' میلیارد';
        if (p >= 1000000) { var v = p / 1000000; return toFa(v >= 100 ? Math.round(v) : v.toFixed(1).replace(/\.0$/, '')) + 'م'; }
        if (p >= 1000) { var t = p / 1000; return toFa(t >= 100 ? Math.round(t) : t.toFixed(1).replace(/\.0$/, '')) + ' هزار'; }
        return toFa(p);
    }
    // نمایش دقیق: ۱٬۹۰۰٬۰۰۰ تومان
    function fmtFull(p) {
        return toFa(Number(p || 0).toLocaleString('en-US').replace(/,/g, '٬')) + ' تومان';
    }
    function el(name, cls, parent) {
        var e = document.createElementNS(SVG_NS, name);
        if (cls) e.setAttribute('class', cls);
        if (parent) parent.appendChild(e);
        return e;
    }
    var measCtx = document.createElement('canvas').getContext('2d');
    function textW(s, fs) {
        measCtx.font = (fs || 13) + 'px Vazirmatn, Tahoma, sans-serif';
        return measCtx.measureText(s).width;
    }
    function $(id) { return document.getElementById(id); }

    // ---------- state ----------
    var meKey = 'branch-' + (DATA.meBranchId || 1);
    var allNodes = DATA.nodes.slice();
    var nodeByKey = {};
    allNodes.forEach(function (n) { nodeByKey[n.key] = n; });
    if (!nodeByKey[meKey]) {
        var c = { key: meKey, label: 'شعبه مرکزی', type: 'branch', isMe: true, group: DATA.meBranchId || 1 };
        allNodes.push(c); nodeByKey[meKey] = c;
    } else {
        nodeByKey[meKey].isMe = true;
    }

    var nodes = [], edges = [];
    var transform = { tx: 0, ty: 0, k: 1 };
    var svg = $('pm-svg');
    var viewport = null;
    var down = null;             // pointer drag state (pan | node)
    var pointers = {};           // pinch: pointerId -> {x,y}
    var pinchStart = null;
    var tapMoved = 0;
    var focusKey = null;
    var labelTimer = null;

    var serviceSel = $('pm-service');
    var cbBranch = $('pm-type-branch');
    var cbLab = $('pm-type-lab');
    var cbDoctor = $('pm-type-doctor');
    var cbDesigner = $('pm-type-designer');
    var cbOnlyCenter = $('pm-only-center');
    var btnLayout = $('pm-relayout');
    var tooltip = $('pm-tooltip');
    var focusNote = $('pm-focus-note');
    var graphWrap = $('pm-graph-wrap');
    var genpriceEl = $('pm-genprice');

    // ---------- persistence (localStorage) ----------
    function loadSaved() {
        try { return JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}'); } catch (e) { return {}; }
    }
    function savePositions() {
        var map = loadSaved();
        nodes.forEach(function (n) {
            if (n.key === meKey) return;
            map[n.key] = { x: Math.round(n.x * 10) / 10, y: Math.round(n.y * 10) / 10 };
        });
        try { localStorage.setItem(STORAGE_KEY, JSON.stringify(map)); } catch (e) { /* ignore */ }
    }

    // ---------- rebuild: pick visible nodes/edges + deterministic layout ----------
    function typeFilter() {
        return { branch: cbBranch.checked, lab: cbLab.checked, doctor: cbDoctor.checked, designer: cbDesigner.checked };
    }

    // قیمت عمومی قابل اعمال یک خدمت: قیمت اختصاصی این شعبه (اگر باشد) وگرنه قیمت مرجع کاتالوگ
    function getServicePrice(sid) {
        var svc = null;
        DATA.services.forEach(function (s) { if (s.id === sid) svc = s; });
        if (!svc) return { ref: 0, branch: null, applicable: 0 };
        var branch = (typeof svc.branch_price === 'number' && svc.branch_price > 0) ? svc.branch_price : null;
        return { ref: svc.price || 0, branch: branch, applicable: branch !== null ? branch : (svc.price || 0) };
    }

    /**
     * از یک مجموعه لینک، گره‌ها و یال‌ها را می‌سازد.
     * قانون: پزشک فقط وقتی گره فردی می‌شود که برای آن خدمت «قیمت اختصاصی» داشته باشد؛
     * پزشک‌های فقط-عمومی به یک گره جمعی «پزشکان (عمومی)» ادغام می‌شوند.
     * شعب و لابراتوارها همیشه گره فردی می‌مانند.
     */
    function buildFromLinks(links) {
        var showType = typeFilter();
        var nodeList = [nodeByKey[meKey]];
        var edgeList = [];
        var generic = {};

        // پزشک‌هایی که حداقل یک قیمت اختصاصی دارند → فردی می‌مانند
        var specificDoctors = {};
        links.forEach(function (l) {
            if (l.price_type !== 'specific') return;
            var a = nodeByKey[l.from], b = nodeByKey[l.to];
            if (a && a.type === 'doctor' && a.key !== meKey) specificDoctors[a.key] = 1;
            if (b && b.type === 'doctor' && b.key !== meKey) specificDoctors[b.key] = 1;
        });

        function addNode(n) { if (nodeList.indexOf(n) === -1) nodeList.push(n); }

        var seenGeneric = {};
        links.forEach(function (l) {
            var a = nodeByKey[l.from], b = nodeByKey[l.to];
            if (!a || !b || !showType[a.type] || !showType[b.type]) return;
            var dir = l.from === meKey ? 'sell' : (l.to === meKey ? 'buy' : 'other');

            // ادغام پزشک‌های فقط-عمومی: یک گره جمعی «پزشکان (عمومی)» به ازای هر نرخ
            var docKey = null;
            if (a.type === 'doctor' && !specificDoctors[a.key]) docKey = a.key;
            else if (b.type === 'doctor' && !specificDoctors[b.key]) docKey = b.key;
            if (docKey) {
                var genKey = 'gen-doc-' + l.price;
                if (!generic[genKey]) {
                    generic[genKey] = { key: genKey, label: 'پزشکان (عمومی)', type: 'doctor', isMe: false, generic: true, price: l.price };
                    addNode(generic[genKey]);
                    nodeByKey[genKey] = generic[genKey]; // ثبت در lookup تا خط‌ها رسم شوند
                }
                var ef = a.key === docKey ? genKey : a.key;
                var et = b.key === docKey ? genKey : b.key;
                var deKey = ef + '>' + et;
                if (seenGeneric[deKey]) return;   // جلوگیری از یال تکراری (چند پزشک عمومی با یک نرخ)
                seenGeneric[deKey] = 1;
                edgeList.push({
                    id: 'g' + l.id, from: ef, to: et,
                    service: l.service, service_id: l.service_id,
                    price: l.price, price_type: l.price_type,
                    note: l.note, dir: dir, genericDoctor: true
                });
                return;
            }

            addNode(a); addNode(b);
            edgeList.push({
                id: l.id, from: l.from, to: l.to,
                service: l.service, service_id: l.service_id,
                price: l.price, price_type: l.price_type,
                note: l.note, dir: dir
            });
        });

        return { nodeList: nodeList, edgeList: edgeList };
    }

    function rebuild() {
        var serviceId = serviceSel.value ? parseInt(serviceSel.value, 10) : null;
        var onlyCenter = cbOnlyCenter ? cbOnlyCenter.checked : false;
        var nodeList, edgeList;

        if (!serviceId) {
            // ── Overview: فقط شعبه مرکزی + همسایه‌های مستقیم (بدون انتخاب خدمت) ──
            var centerLinks = DATA.links.filter(function (l) { return l.from === meKey || l.to === meKey; });
            var res = buildFromLinks(centerLinks);
            var nodesByKey = {};
            res.nodeList.forEach(function (n) { nodesByKey[n.key] = n; });
            var agg = {};
            res.edgeList.forEach(function (e) {
                var other = e.from === meKey ? e.to : e.from;
                if (other === meKey) return;
                if (!agg[other]) agg[other] = { count: 0, sell: 0, buy: 0 };
                agg[other].count++;
                if (e.from === meKey) agg[other].sell += e.price; else agg[other].buy += e.price;
            });
            nodeList = [nodeByKey[meKey]];
            edgeList = [];
            Object.keys(agg).forEach(function (k) {
                if (nodesByKey[k]) nodeList.push(nodesByKey[k]);
                var a = agg[k];
                var dir = a.sell >= a.buy ? 'sell' : 'buy';
                var gn = nodesByKey[k];
                edgeList.push({ id: 'ov-' + k, from: dir === 'sell' ? meKey : k, to: dir === 'sell' ? k : meKey, agg: true, count: a.count, dir: dir, price: (gn && gn.generic) ? gn.price : null });
            });
        } else {
            // ── حالت خدمت: فقط روابط همان خدمت ──
            var sLinks = DATA.links.filter(function (l) { return l.service_id === serviceId; });
            if (onlyCenter) sLinks = sLinks.filter(function (l) { return l.from === meKey || l.to === meKey; });
            var res2 = buildFromLinks(sLinks);
            nodeList = res2.nodeList;
            edgeList = res2.edgeList;

            // قیمت عمومی: اگر رابطه‌ی عمومی «ما از پزشک می‌گیریم» رسم نشده، برای هر خدمتِ دارای قیمت،
            // یک لبه‌ی مصنوعی از شعبه به گره «پزشکان (عمومی)» با قیمت عمومیِ قابل‌اعمال اضافه می‌شود.
            var anyGenFromMe = edgeList.some(function (e) { return e.from === meKey && e.price_type === 'general'; });
            var gp = getServicePrice(serviceId);
            if (!anyGenFromMe && gp.applicable > 0 && typeFilter().doctor) {
                var gKey = 'gen-doc-' + gp.applicable;
                var gNode = nodeByKey[gKey];
                if (!gNode) {
                    gNode = { key: gKey, label: 'پزشکان (عمومی)', type: 'doctor', isMe: false, generic: true, price: gp.applicable };
                    nodeByKey[gKey] = gNode;
                }
                if (nodeList.indexOf(gNode) === -1) nodeList.push(gNode);
                var svcObj = null;
                DATA.services.forEach(function (s) { if (s.id === serviceId) svcObj = s; });
                edgeList.push({
                    id: 'gen-' + serviceId, from: meKey, to: gKey,
                    service: svcObj ? svcObj.title : '', service_id: serviceId,
                    price: gp.applicable, price_type: 'general',
                    note: 'قیمت عمومی', dir: 'sell', genericDoctor: true
                });
            }
        }

        nodes = nodeList; edges = edgeList;
        layout(nodes, edges, serviceId);
        render();
        updateMargin();
        updateGeneralPrice(serviceId);
    }

    function updateGeneralPrice(serviceId) {
        if (!genpriceEl) return;
        if (!serviceId) { genpriceEl.textContent = ''; genpriceEl.style.display = 'none'; return; }
        var svc = null;
        DATA.services.forEach(function (s) { if (s.id === serviceId) svc = s; });
        var p = getServicePrice(serviceId);
        var label = svc ? svc.title : '';
        genpriceEl.textContent = (p.branch !== null && p.branch !== p.ref)
            ? 'قیمت عمومی «' + label + '»: ' + fmtFull(p.branch) + ' (این شعبه) — مرجع: ' + fmtFull(p.ref)
            : 'قیمت عمومی «' + label + '»: ' + fmtFull(p.applicable);
        genpriceEl.style.display = 'inline-block';
    }

    // ---------- deterministic layout (fixed center) ----------
    function layout(nodeList, edgeList, serviceId) {
        var center = nodeByKey[meKey];
        center.x = CX; center.y = CY;   // pin: مرکز هرگز حرکت نمی‌کند
        var saved = loadSaved();

        var placeSavedOr = function (n, autoX, autoY) {
            var p = saved[n.key];
            if (p) { n.x = p.x; n.y = p.y; return true; }
            n.x = autoX; n.y = autoY; return false;
        };

        if (!serviceId) {
            // حلقه ۱: همسایه‌های مستقیم — مرتب‌شده بر اساس نوع و نام
            var neigh = nodeList.filter(function (n) { return n.key !== meKey; });
            neigh.sort(function (a, b) {
                return (TYPE_ORDER[a.type] - TYPE_ORDER[b.type]) || (a.label < b.label ? -1 : 1);
            });
            var nn = neigh.length;
            neigh.forEach(function (n, i) {
                if (n.key === meKey) return;
                var th = (360 * i) / nn;
                placeSavedOr(n, CX + R1 * Math.cos(th * Math.PI / 180), CY + R1 * Math.sin(th * Math.PI / 180));
            });
            return;
        }

        // ── حالت خدمت: حلقه ۱ (مستقیم با مرکز) + حلقه ۲ (از طریق حلقه ۱) ──
        var t1 = [], t2 = [];
        nodeList.forEach(function (n) {
            if (n.key === meKey) return;
            var direct = edgeList.some(function (e) {
                return (e.from === meKey && e.to === n.key) || (e.to === meKey && e.from === n.key);
            });
            if (direct) t1.push(n.key); else t2.push(n.key);
        });

        // دسته‌بندی جهت: فروش (پایین)، خرید (بالا)، دوسویه (کناره‌ها)
        var bands = { sell: [], buy: [], both: [] };
        t1.forEach(function (k) {
            var sell = edgeList.some(function (e) { return e.from === meKey && e.to === k; });
            var buy = edgeList.some(function (e) { return e.to === meKey && e.from === k; });
            bands[sell && buy ? 'both' : (sell ? 'sell' : 'buy')].push(k);
        });
        ['sell', 'buy', 'both'].forEach(function (b) {
            bands[b].sort(function (a, c) { return (nodeByKey[a].label < nodeByKey[c].label) ? -1 : 1; });
        });

        var thetaOf = {};
        function placeNode(key, th) {
            var n = nodeByKey[key];
            var px = CX + R1 * Math.cos(th * Math.PI / 180);
            var py = CY + R1 * Math.sin(th * Math.PI / 180);
            placeSavedOr(n, px, py);
            thetaOf[key] = th;
        }
        function placeList(list, fromDeg, toDeg) {
            var n = list.length;
            if (!n) return;
            var to = toDeg < fromDeg ? toDeg + 360 : toDeg;
            var span = to - fromDeg;
            for (var i = 0; i < n; i++) {
                var th = (n === 1) ? (fromDeg + to) / 2 : fromDeg + span * i / (n - 1);
                placeNode(list[i], th % 360);
            }
        }
        placeList(bands.sell, 25, 155);            // پایین: ما می‌گیریم (فروش)
        placeList(bands.buy, 205, 335);            // بالا: ما می‌پردازیم (خرید)
        var both = bands.both, mid = Math.ceil(both.length / 2);
        placeList(both.slice(0, mid), 350, 10);    // راست: دوسویه
        placeList(both.slice(mid), 170, 190);      // چپ: دوسویه

        // حلقه ۲: گره‌های غیرمستقیم (مثل پزشک‌های فاطمه) نزدیک گره‌ی مبدأشان
        var siblingsOf = {};
        t2.forEach(function (k) {
            var anchor = null;
            edgeList.some(function (e) {
                if (e.from === k && thetaOf[e.to] !== undefined) { anchor = e.to; return true; }
                if (e.to === k && thetaOf[e.from] !== undefined) { anchor = e.from; return true; }
                return false;
            });
            if (!anchor) {
                // به مرکز وصل است ولی در حلقه ۱ نبود (نادر) → نزدیک مرکز
                anchor = meKey;
            }
            (siblingsOf[anchor] = siblingsOf[anchor] || []).push(k);
        });
        t2.forEach(function (k) {
            var n = nodeByKey[k];
            if (placeSavedOr(n, 0, 0)) return;
            var anchor = null;
            edgeList.some(function (e) {
                if (e.from === k && thetaOf[e.to] !== undefined) { anchor = e.to; return true; }
                if (e.to === k && thetaOf[e.from] !== undefined) { anchor = e.from; return true; }
                return false;
            });
            if (!anchor) anchor = meKey;
            var anchorNode = nodeByKey[anchor];
            // زاویه بر اساس موقعیت واقعی مبدأ (حتی اگر دستی جابه‌جا شده باشد)
            var base = (anchorNode && typeof anchorNode.x === 'number')
                ? Math.atan2(anchorNode.y - CY, anchorNode.x - CX) * 180 / Math.PI
                : 0;
            var sib = siblingsOf[anchor];
            var si = sib.indexOf(k);
            var spread = (sib.length - 1) * 24;
            var th = base + (si - (sib.length - 1) / 2) * 24;
            n.x = CX + R2 * Math.cos(th * Math.PI / 180);
            n.y = CY + R2 * Math.sin(th * Math.PI / 180);
        });
    }

    // ---------- render ----------
    function render() {
        if (!svg) return;
        svg.innerHTML = '';
        var defs = el('defs', null, svg);
        ['sell', 'buy', 'other'].forEach(function (d) {
            var mk = el('marker', null, defs);
            mk.setAttribute('id', 'pm-arrow-' + d);
            mk.setAttribute('viewBox', '0 0 10 10');
            mk.setAttribute('refX', '9'); mk.setAttribute('refY', '5');
            mk.setAttribute('markerWidth', '6.5'); mk.setAttribute('markerHeight', '6.5');
            mk.setAttribute('orient', 'auto-start-reverse');
            var p = el('path', null, mk);
            p.setAttribute('d', 'M 0 0 L 10 5 L 0 10 z');
            p.setAttribute('fill', EDGE_COLOR[d]);
        });
        viewport = el('g', 'pm-viewport', svg);

        edges.forEach(function (e) {
            var g = el('g', 'pm-edge pm-dir-' + e.dir, viewport);
            var path = el('path', null, g);
            e._g = g; e._path = path;
            var card = el('g', 'pm-card', g);
            var rect = el('rect', null, card);
            rect.setAttribute('rx', 9); rect.setAttribute('ry', 9);
            var t1 = el('text', 'pm-card-svc', card);
            t1.setAttribute('text-anchor', 'middle');
            var t2 = el('text', 'pm-card-price', card);
            t2.setAttribute('text-anchor', 'middle');
            e._card = card; e._rect = rect; e._t1 = t1; e._t2 = t2;
            g.addEventListener('pointerenter', function (ev) { if (!down) showEdgeTooltip(e, ev); });
            g.addEventListener('pointermove', function (ev) { if (!down) positionTooltip(ev); });
            g.addEventListener('pointerleave', hideTooltip);
        });

        nodes.forEach(function (n) {
            var fs = n.isMe ? 14 : 13;
            var w = Math.max(textW(n.label, fs) + 34, n.isMe ? 100 : 72);
            var h = n.isMe ? 46 : 40;
            n.w = w; n.h = h; n.fs = fs;
            var g = el('g', 'pm-node' + (n.isMe ? ' pm-me' : '') + (n.generic ? ' pm-node-generic' : ''), viewport);
            g.setAttribute('data-key', n.key);
            var rect = el('rect', null, g);
            rect.setAttribute('x', -w / 2); rect.setAttribute('y', -h / 2);
            rect.setAttribute('width', w); rect.setAttribute('height', h);
            rect.setAttribute('rx', 20); rect.setAttribute('ry', 20);
            rect.setAttribute('fill', TYPE_COLOR[n.type] || '#94A3B8');
            var text = el('text', null, g);
            text.setAttribute('text-anchor', 'middle');
            text.setAttribute('font-size', fs);
            text.textContent = n.label;
            text.setAttribute('y', fs * 0.35);
            n._g = g;
            if (n.isMe) {
                var sub = el('text', 'pm-center-price', g);
                sub.setAttribute('text-anchor', 'middle');
                sub.setAttribute('y', h / 2 + 18);
                n._sub = sub;
            }
            g.addEventListener('pointerenter', function (ev) { if (!down) showNodeTooltip(n, ev); });
            g.addEventListener('pointermove', function (ev) { if (!down) positionTooltip(ev); });
            g.addEventListener('pointerleave', hideTooltip);
        });

        applyTransform();
        updatePositions();
        updateLabels();
        updateCenterSub();
        updateEmpty();
    }

    function applyTransform() {
        if (viewport) viewport.setAttribute('transform', 'translate(' + transform.tx + ',' + transform.ty + ') scale(' + transform.k + ')');
    }

    function updatePositions() {
        if (!viewport) return;
        // fan برای یال‌های موازی (دو رابطه بین یک جفت گره)
        var groups = {};
        edges.forEach(function (e, i) {
            var k = e.from < e.to ? e.from + '|' + e.to : e.to + '|' + e.from;
            (groups[k] = groups[k] || []).push(i);
        });
        var fan = {};
        for (var k in groups) groups[k].forEach(function (idx, fi) { fan[edges[idx].id] = fi; });

        edges.forEach(function (e) {
            var a = nodeByKey[e.from], b = nodeByKey[e.to];
            if (!a || !b) return;
            var dx = b.x - a.x, dy = b.y - a.y, len = Math.hypot(dx, dy) || 1;
            var nx = -dy / len, ny = dx / len;
            var off = 24 + (fan[e.id] || 0) * 26;
            var cxm = (a.x + b.x) / 2 + nx * off, cym = (a.y + b.y) / 2 + ny * off;
            var rA = a.h / 2 + 4, rB = b.h / 2 + 5;
            var dax = cxm - a.x, day = cym - a.y, dla = Math.hypot(dax, day) || 1;
            var sx = a.x + dax / dla * rA, sy = a.y + day / dla * rA;
            var dbx = cxm - b.x, dby = cym - b.y, dlb = Math.hypot(dbx, dby) || 1;
            var ex = b.x + dbx / dlb * rB, ey = b.y + dby / dlb * rB;
            e._path.setAttribute('d', 'M ' + sx + ',' + sy + ' Q ' + cxm + ',' + cym + ' ' + ex + ',' + ey);
            e._path.setAttribute('marker-end', 'url(#pm-arrow-' + e.dir + ')');
            // کارت قیمت روی خم یال (t متغیر تا کارت‌های موازی روی هم نیفتند)
            var t = 0.42 + ((fan[e.id] || 0) % 3) * 0.11;
            var omx = (1 - t) * (1 - t) * a.x + 2 * (1 - t) * t * cxm + t * t * b.x;
            var omy = (1 - t) * (1 - t) * a.y + 2 * (1 - t) * t * cym + t * t * b.y;
            e._card.setAttribute('transform', 'translate(' + omx + ',' + omy + ')');
        });

        nodes.forEach(function (n) {
            if (n._g) n._g.setAttribute('transform', 'translate(' + n.x + ',' + n.y + ')');
        });
    }

    function layoutCard(e, compact) {
        var t1w = e._t1.textContent ? textW(e._t1.textContent, 11) : 0;
        var t2w = e._t2.textContent ? textW(e._t2.textContent, 14) : 0;
        var w = Math.max(t1w, t2w) + 18;
        var h = compact ? 22 : 36;
        e._rect.setAttribute('width', w);
        e._rect.setAttribute('height', h);
        e._rect.setAttribute('x', -w / 2);
        e._rect.setAttribute('y', -h / 2);
        if (e._t1.textContent) {
            e._t1.setAttribute('y', -h / 2 + 13);
            e._t2.setAttribute('y', h / 2 - 6);
        } else {
            e._t2.setAttribute('y', 5);
        }
    }

    function updateLabels() {
        if (!viewport) return;
        var compact = transform.k < COMPACT_ZOOM;
        edges.forEach(function (e) {
            if (e.agg) {
                e._t1.textContent = '';
                e._t2.textContent = e.price ? fmtPrice(e.price) : (toFa(e.count) + ' رابطه');
                e._card.setAttribute('class', 'pm-card pm-card-agg pm-card-' + e.dir);
                layoutCard(e, true);
                return;
            }
            var star = e.price_type === 'specific' ? ' ⭐' : '';
            e._t1.textContent = compact ? '' : e.service;
            e._t2.textContent = fmtPrice(e.price) + star;
            e._card.setAttribute('class', 'pm-card pm-card-' + e.dir + (e.price_type === 'specific' ? ' pm-card-specific' : ''));
            layoutCard(e, compact);
        });
    }

    function updateCenterSub() {
        var serviceId = serviceSel.value ? parseInt(serviceSel.value, 10) : null;
        var center = nodeByKey[meKey];
        if (!center || !center._sub) return;
        if (!serviceId) { center._sub.textContent = ''; return; }
        var p = getServicePrice(serviceId);
        var txt = 'قیمت عمومی: ' + fmtPrice(p.applicable);
        if (p.branch !== null && p.branch !== p.ref) txt += ' (این شعبه)';
        center._sub.textContent = txt;
    }

    function updateEmpty() {
        var emptyEl = $('pm-empty');
        if (!emptyEl) return;
        if (edges.length === 0) {
            emptyEl.style.display = 'flex';
            if (serviceSel.value) {
                var p = getServicePrice(parseInt(serviceSel.value, 10));
                emptyEl.innerHTML = 'برای این خدمت رابطه‌ای ثبت نشده است؛ قیمت عمومی آن ' + fmtFull(p.applicable) + ' است. از «افزودن رابطه قیمتی» شروع کنید.';
            } else {
                emptyEl.innerHTML = 'هنوز رابطه‌ای برای شعبه مرکزی ثبت نشده است — از دکمه «افزودن رابطه قیمتی» شروع کنید.';
            }
        } else {
            emptyEl.style.display = 'none';
        }
    }

    // ---------- focus (کلیک روی گره → فقط روابط مستقیم) ----------
    function toggleFocus(key) {
        if (focusKey === key) { clearFocus(); return; }
        focusKey = key;
        edges.forEach(function (e) { e._g.classList.toggle('pm-dim', e.from !== key && e.to !== key); });
        nodes.forEach(function (n) { if (n._g) n._g.classList.toggle('pm-dim', n.key !== key); });
        if (focusNote) {
            focusNote.textContent = 'روابط مستقیم «' + (nodeByKey[key] ? nodeByKey[key].label : key) + '» — کلیک دوباره، کلیک خالی یا ESC برای بازگشت';
            focusNote.style.display = 'block';
        }
    }
    function clearFocus() {
        focusKey = null;
        edges.forEach(function (e) { e._g.classList.remove('pm-dim'); });
        nodes.forEach(function (n) { if (n._g) n._g.classList.remove('pm-dim'); });
        if (focusNote) focusNote.style.display = 'none';
    }
    document.addEventListener('keydown', function (ev) { if (ev.key === 'Escape') clearFocus(); });

    // ---------- tooltip ----------
    function showNodeTooltip(n, ev) {
        if (n.generic) {
            showTooltip(
                '<div class="pm-tt-title">' + n.label + '</div>' +
                '<div>گروه عمومی پزشکان با نرخ یکسان</div>' +
                '<div class="pm-tt-price">' + fmtFull(n.price) + '</div>', ev);
            return;
        }
        var cnt = 0;
        DATA.links.forEach(function (l) { if (l.from === n.key || l.to === n.key) cnt++; });
        showTooltip(
            '<div class="pm-tt-title">' + n.label + '</div>' +
            '<div>نوع: ' + (TYPE_NAME[n.type] || n.type) + (n.isMe ? ' (شعبه خود شما)' : '') + '</div>' +
            '<div>تعداد رابطه: ' + toFa(cnt) + '</div>', ev);
    }
    function showEdgeTooltip(e, ev) {
        var a = nodeByKey[e.from], b = nodeByKey[e.to];
        if (e.agg) {
            showTooltip(
                '<div class="pm-tt-title">' + (a ? a.label : '') + ' ← ' + (b ? b.label : '') + '</div>' +
                '<div>' + toFa(e.count) + ' رابطه با شعبه مرکزی</div>', ev);
            return;
        }
        showTooltip(
            '<div class="pm-tt-title">' + (a ? a.label : '') + ' ← ' + (b ? b.label : '') + '</div>' +
            '<div>خدمت: ' + e.service + '</div>' +
            '<div class="pm-tt-price">' + fmtFull(e.price) + '</div>' +
            '<div>نوع قیمت: ' + (e.price_type === 'specific' ? 'اختصاصی ⭐' : 'عمومی') + '</div>' +
            (e.note ? '<div style="color:#525252;font-size:.82rem;max-width:220px;">' + e.note + '</div>' : '')
        , ev);
    }
    function showTooltip(html, ev) {
        if (!tooltip) return;
        tooltip.innerHTML = html;
        tooltip.style.display = 'block';
        positionTooltip(ev);
    }
    function positionTooltip(ev) {
        if (!tooltip || !graphWrap) return;
        var rect = graphWrap.getBoundingClientRect();
        var x = ev.clientX - rect.left, y = ev.clientY - rect.top;
        var tw = tooltip.offsetWidth || 170, th = tooltip.offsetHeight || 70;
        x += 14; y += 14;
        if (x + tw > graphWrap.clientWidth) x = Math.max(6, x - tw - 24);
        if (y + th > graphWrap.clientHeight) y = Math.max(6, y - th - 24);
        tooltip.style.left = x + 'px';
        tooltip.style.top = y + 'px';
    }
    function hideTooltip() { if (tooltip) tooltip.style.display = 'none'; }

    // ---------- pointer interactions: zoom / pan / drag / pinch / tap ----------
    function screenScale() { var r = svg.getBoundingClientRect(); return { x: WORLD_W / r.width, y: WORLD_H / r.height }; }

    function zoomAt(clientX, clientY, factor) {
        var rect = svg.getBoundingClientRect();
        var mx = (clientX - rect.left) / rect.width * WORLD_W;
        var my = (clientY - rect.top) / rect.height * WORLD_H;
        var k2 = Math.min(3, Math.max(0.25, transform.k * factor));
        var r = k2 / transform.k;
        transform.tx = mx - (mx - transform.tx) * r;
        transform.ty = my - (my - transform.ty) * r;
        transform.k = k2;
        applyTransform();
    }
    function scheduleLabelRefresh() {
        if (labelTimer) clearTimeout(labelTimer);
        labelTimer = setTimeout(function () { updateLabels(); }, 120);
    }

    svg.addEventListener('wheel', function (ev) {
        ev.preventDefault();
        zoomAt(ev.clientX, ev.clientY, ev.deltaY < 0 ? 1.12 : 0.89);
        scheduleLabelRefresh();
    }, { passive: false });

    svg.addEventListener('pointerdown', function (ev) {
        if (svg.setPointerCapture) { try { svg.setPointerCapture(ev.pointerId); } catch (e) {} }
        pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY };
        var ids = Object.keys(pointers);
        if (ids.length === 2) {
            var p0 = pointers[ids[0]], p1 = pointers[ids[1]];
            pinchStart = {
                dist: Math.hypot(p0.x - p1.x, p0.y - p1.y),
                k0: transform.k
            };
            down = null;
            svg.classList.add('pm-dragging');
            ev.preventDefault();
            return;
        }
        var nodeG = ev.target && ev.target.closest ? ev.target.closest('.pm-node') : null;
        tapMoved = 0;
        if (nodeG) {
            var n = nodeByKey[nodeG.getAttribute('data-key')];
            if (n) {
                down = { mode: 'node', node: n, sx: ev.clientX, sy: ev.clientY };
                svg.classList.add('pm-dragging');
            }
        } else {
            down = { mode: 'pan', sx: ev.clientX, sy: ev.clientY, tx0: transform.tx, ty0: transform.ty };
            svg.classList.add('pm-dragging');
        }
        ev.preventDefault();
    });

    svg.addEventListener('pointermove', function (ev) {
        if (pointers[ev.pointerId]) pointers[ev.pointerId] = { x: ev.clientX, y: ev.clientY };
        var ids = Object.keys(pointers);
        if (ids.length === 2 && pinchStart) {
            var p0 = pointers[ids[0]], p1 = pointers[ids[1]];
            var dist = Math.hypot(p0.x - p1.x, p0.y - p1.y) || 1;
            var midX = (p0.x + p1.x) / 2, midY = (p0.y + p1.y) / 2;
            var k2 = Math.min(3, Math.max(0.25, pinchStart.k0 * (dist / pinchStart.dist)));
            var rect = svg.getBoundingClientRect();
            var wx = (midX - rect.left) / rect.width * WORLD_W, wy = (midY - rect.top) / rect.height * WORLD_H;
            var r = k2 / transform.k;
            transform.tx = wx - (wx - transform.tx) * r;
            transform.ty = wy - (wy - transform.ty) * r;
            transform.k = k2;
            applyTransform();
            scheduleLabelRefresh();
            ev.preventDefault();
            return;
        }
        if (!down) return;
        var sc = screenScale();
        var dx = ev.clientX - down.sx, dy = ev.clientY - down.sy;
        tapMoved = Math.max(tapMoved, Math.abs(dx) + Math.abs(dy));
        if (down.mode === 'pan') {
            transform.tx = down.tx0 + dx * sc.x;
            transform.ty = down.ty0 + dy * sc.y;
            applyTransform();
        } else if (down.mode === 'node' && down.node.key !== meKey) {
            // مرکز ثابت است؛ فقط گره‌های دیگر جابه‌جا می‌شوند و فقط همین گره حرکت می‌کند
            var rect = svg.getBoundingClientRect();
            var wx = (ev.clientX - rect.left) * sc.x, wy = (ev.clientY - rect.top) * sc.y;
            down.node.x = (wx - transform.tx) / transform.k;
            down.node.y = (wy - transform.ty) / transform.k;
            updatePositions();
        }
        ev.preventDefault();
    });

    function endPointer(ev) {
        if (pointers[ev.pointerId]) delete pointers[ev.pointerId];
        if (Object.keys(pointers).length < 2) pinchStart = null;
        if (down) {
            var isTap = tapMoved < 8;
            if (down.mode === 'node') {
                if (isTap && down.node.key !== meKey) toggleFocus(down.node.key);
                else if (!isTap) savePositions();
            } else if (down.mode === 'pan' && isTap) {
                clearFocus();
            }
            down = null;
            svg.classList.remove('pm-dragging');
        }
    }
    svg.addEventListener('pointerup', endPointer);
    svg.addEventListener('pointercancel', endPointer);

    // ---------- margin panel (حاشیه سود) ----------
    function labelForKey(key) {
        var n = nodeByKey[key];
        return n ? n.label : key;
    }
    function updateMargin() {
        var panel = $('pm-margin');
        if (!panel) return;
        var selService = serviceSel.value ? parseInt(serviceSel.value, 10) : null;
        if (!selService) { panel.style.display = 'none'; return; }

        var svc = null;
        DATA.services.forEach(function (s) { if (s.id === selService) svc = s; });
        var out = [], inn = [];
        edges.forEach(function (e) {
            if (e.from === meKey) out.push({ name: labelForKey(e.to), price: e.price });
            if (e.to === meKey) inn.push({ name: labelForKey(e.from), price: e.price });
        });
        var sumOut = out.reduce(function (s, r) { return s + r.price; }, 0);
        var sumIn = inn.reduce(function (s, r) { return s + r.price; }, 0);
        var margin = sumOut - sumIn;

        var rows = function (arr, prefix) {
            if (!arr.length) return '<div class="pm-mrow"><span class="pm-name">—</span></div>';
            return arr.map(function (r) {
                return '<div class="pm-mrow"><span class="pm-name">' + prefix + ' ' + r.name + '</span><span class="pm-price">' + fmtFull(r.price) + '</span></div>';
            }).join('');
        };

        panel.innerHTML =
            '<div class="pm-margin-head">حاشیه سود «' + (svc ? svc.title : '') + '»</div>' +
            '<div class="pm-margin-cols">' +
                '<div class="pm-margin-col"><div class="pm-margin-col-title">ما می‌گیریم (فروش به)</div>' + rows(out, 'به') + '</div>' +
                '<div class="pm-margin-col"><div class="pm-margin-col-title">ما می‌پردازیم (خرید از)</div>' + rows(inn, 'از') + '</div>' +
            '</div>' +
            '<div class="pm-margin-net ' + (margin >= 0 ? 'pm-positive' : 'pm-negative') + '">' +
                'حاشیه: ' + fmtFull(margin) +
            '</div>';
        panel.style.display = 'block';
    }

    // ---------- modal CRUD ----------
    var modal = $('pm-modal'), form = $('pm-form');
    function syncEntity(typeId, entityId) {
        var t = $(typeId).value, sel = $(entityId);
        var firstVisible = null;
        Array.prototype.forEach.call(sel.options, function (o) {
            var show = o.getAttribute('data-type') === t;
            o.style.display = show ? '' : 'none';
            if (show && !firstVisible) firstVisible = o;
        });
        if (!sel.value || (sel.selectedOptions.length && sel.selectedOptions[0].style.display === 'none')) {
            if (firstVisible) sel.value = firstVisible.value;
        }
    }
    function applyKind() {
        var kindEl = $('pm-kind');
        if (!kindEl) return;
        var k = kindEl.value;
        var pType = $('pm-provider-type'), pId = $('pm-provider-id');
        var rType = $('pm-receiver-type'), rId = $('pm-receiver-id');
        var pWrap = $('pm-provider-wrap'), rWrap = $('pm-receiver-wrap');
        var hintEl = $('pm-kind-hint');
        if (!pType || !rType || !pWrap || !rWrap) return;
        // باز کردن هر دو سمت
        pType.disabled = false; pId.disabled = false;
        rType.disabled = false; rId.disabled = false;
        pWrap.style.display = 'block'; rWrap.style.display = 'block';
        var hint = '';
        if (k === 'design_fee') {
            // نرخ طراحی: ارائه‌دهنده «طراح» (قابل انتخاب) و دریافت‌کننده «شعبه مرکزی»
            pType.value = 'designer'; pType.disabled = true;
            if (pId) pId.disabled = false;   // انتخاب اینکه نرخ برای کدام طراح است
            rType.value = 'branch'; rType.disabled = true;
            rId.value = '1'; rId.disabled = true;
            hint = 'نرخ طراحی: ارائه‌دهنده = طراح (انتخاب کنید)، دریافت‌کننده = شعبه مرکزی (که هزینه‌ی طراحی را می‌پردازد). اگر خدمت را خالی بگذارید، نرخِ کلی همان طراح است.';
        } else if (k === 'general') {
            // قیمت عمومی: فقط خدمت + قیمت (بدون طرفین) — مرجع/پیش‌فرض
            pWrap.style.display = 'none'; rWrap.style.display = 'none';
            pType.disabled = true; pId.disabled = true; rType.disabled = true; rId.disabled = true;
            hint = 'قیمت عمومی/مرجع: فقط «خدمت» و «قیمت» را وارد کنید (بدون ارائه‌دهنده/دریافت‌کننده).';
        } else {
            // قیمت اختصاصی: هر دو طرف باز — جهت از روی طرفین مشخص می‌شود
            hint = 'قیمت اختصاصی: «ارائه‌دهنده» انجام‌دهنده‌ی کار و «دریافت‌کننده» پرداخت‌کننده است؛ جهت، محلِ استفاده از این نرخ را مشخص می‌کند.';
        }
        if (hintEl) hintEl.textContent = hint;
        syncEntity('pm-provider-type', 'pm-provider-id');
        syncEntity('pm-receiver-type', 'pm-receiver-id');
    }

    function openModal(d) {
        $('pm-modal-title').textContent = d ? 'ویرایش رابطه قیمتی' : 'افزودن رابطه قیمتی';
        $('pm-id').value = d ? d.id : '';
        $('pm-modal-service').value = d ? d.service : '';
        // نوع قدیمی «outsource» هم به «اختصاصی» نگاشت می‌شود (یکدست‌سازی جهت‌محور)
        var pk = d ? d.priceType : 'specific';
        if (pk === 'design_fee') pk = 'design_fee';
        else if (pk === 'general' || pk === 'branch_default') pk = 'general';
        else pk = 'specific';
        $('pm-kind').value = pk;
        // پیش‌فرض‌های هوشمند برای رابطه‌ی جدید (اختصاصی: ارائه‌دهنده = شعبه خودمان، گیرنده = پزشک)
        if (!d) {
            $('pm-provider-type').value = 'branch';
            $('pm-provider-id').value = String(DATA.meBranchId || 1);
            $('pm-receiver-type').value = 'doctor';
        } else {
            $('pm-provider-type').value = d.pType;
            $('pm-receiver-type').value = d.rType;
        }
        $('pm-price').value = d ? d.price : '';
        $('pm-note').value = d ? (d.note || '') : '';
        syncEntity('pm-provider-type', 'pm-provider-id');
        syncEntity('pm-receiver-type', 'pm-receiver-id');
        if (d) { $('pm-provider-id').value = d.pId; $('pm-receiver-id').value = d.rId; }
        applyKind();
        modal.style.display = 'flex';
    }
    function closeModal() { modal.style.display = 'none'; }

    if ($('pm-add-btn')) $('pm-add-btn').addEventListener('click', function () { openModal(null); });
    if ($('pm-cancel')) $('pm-cancel').addEventListener('click', closeModal);
    if (modal) modal.addEventListener('click', function (ev) { if (ev.target === modal) closeModal(); });
    if ($('pm-kind')) $('pm-kind').addEventListener('change', applyKind);
    if (form) form.addEventListener('submit', function (ev) {
        var k = $('pm-kind') ? $('pm-kind').value : 'specific';
        var pType = $('pm-provider-type'), pId = $('pm-provider-id');
        var rType = $('pm-receiver-type'), rId = $('pm-receiver-id');
        var svc = $('pm-modal-service'), price = $('pm-price');
        var msg = null;
        var cleanPrice = (price && price.value) ? Number(String(price.value).replace(/[,\u060C\u066B\u00A0 ]/g, '')) : NaN;
        if (!price || !price.value || !(cleanPrice > 0)) {
            msg = 'لطفاً قیمت معتبر وارد کنید.';
        } else if (k === 'general') {
            // ── قیمت عمومی: فقط خدمت + قیمت → ذخیره در مرجع/پیش‌فرض (بدون price_links) ──
            if (!svc || !svc.value) msg = 'لطفاً خدمت را انتخاب کنید.';
            if (!msg) {
                ev.preventDefault();
                var tokEl = form.querySelector('[name="_csrf_token"]');
                var csrf = tokEl ? tokEl.value : '';
                var fd = new FormData();
                fd.set('service_id', svc.value);
                fd.set('price', cleanPrice);
                fd.set('_csrf_token', csrf);
                var xhr = new XMLHttpRequest();
                xhr.open('POST', 'save_general_price.php', true);
                if (csrf) xhr.setRequestHeader('X-CSRF-Token', csrf);
                xhr.onload = function () {
                    var ok = false;
                    try { var r = JSON.parse(xhr.responseText); ok = r && r.success; } catch (e) {}
                    if (ok) { closeModal(); location.reload(); }
                    else { alert('ذخیره‌ی قیمت عمومی انجام نشد.'); }
                };
                xhr.onerror = function () { alert('خطا در ارتباط با سرور.'); };
                xhr.send(fd);
                return;
            }
        } else {
            // ── قیمت اختصاصی / نرخ طراحی: هر دو طرف مشخص هستند ──
            if (!svc || !svc.value) {
                if (k !== 'design_fee') msg = 'لطفاً خدمت را انتخاب کنید.';
            }
            if (!msg) {
                if (!pId || !pId.value) msg = 'لطفاً ارائه‌دهنده را انتخاب کنید.';
                else if (!rId || !rId.value) msg = 'لطفاً دریافت‌کننده را انتخاب کنید.';
                else if (pType && rType && pType.value === rType.value && pId.value === rId.value) msg = 'ارائه‌دهنده و دریافت‌کننده یکسان هستند.';
            }
            // ⚠️ selectهایی که با disabled قفل شده‌اند در POST فرستاده نمی‌شوند؛ به همین
            // دلیل ذخیره‌ی «نرخ طراحی» همیشه شکست می‌خورد (provider/receiver خالی می‌رفت).
            // قبل از ارسال، فقط برای همین لحظه بازشان می‌کنیم.
            if (!msg) {
                [pType, pId, rType, rId].forEach(function (el) { if (el) el.disabled = false; });
            }
        }
        if (msg) { ev.preventDefault(); alert(msg); }
    });

    ['pm-provider-type', 'pm-receiver-type'].forEach(function (id) {
        var tSel = $(id);
        if (tSel) tSel.addEventListener('change', function () { syncEntity(id, id.replace('-type', '-id')); });
    });

    document.querySelectorAll('.pm-edit').forEach(function (btn) {
        btn.addEventListener('click', function () {
            openModal({
                id: btn.dataset.id,
                service: btn.dataset.service,
                pType: btn.dataset.pType, pId: btn.dataset.pId,
                rType: btn.dataset.rType, rId: btn.dataset.rId,
                price: btn.dataset.price,
                priceType: btn.dataset.priceType,
                note: btn.dataset.note
            });
        });
    });

    // ---------- init ----------
    function onFilterChange() {
        clearFocus();
        rebuild();
    }

    function init() {
        if (!svg) return;
        DATA.services.forEach(function (s) {
            var o = document.createElement('option');
            o.value = s.id; o.textContent = s.title;
            serviceSel.appendChild(o);
        });
        serviceSel.addEventListener('change', function () {
            var ocw = $('pm-only-center-wrap');
            if (ocw) ocw.style.display = serviceSel.value ? '' : 'none';
            onFilterChange();
        });
        cbBranch.addEventListener('change', onFilterChange);
        cbLab.addEventListener('change', onFilterChange);
        cbDoctor.addEventListener('change', onFilterChange);
        if (cbDesigner) cbDesigner.addEventListener('change', onFilterChange);
        if (cbOnlyCenter) cbOnlyCenter.addEventListener('change', onFilterChange);
        btnLayout.addEventListener('click', function () {
            try { localStorage.removeItem(STORAGE_KEY); } catch (e) {}
            clearFocus();
            rebuild();
        });
        var ocw0 = $('pm-only-center-wrap');
        if (ocw0) ocw0.style.display = serviceSel.value ? '' : 'none';
        rebuild();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
