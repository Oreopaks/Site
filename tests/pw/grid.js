// Playwright: сетка 1…12 на / (ADR M6) и «всё на лимите».
// Стенд: RDS_DB=<db> php -S 127.0.0.1:8080 -t public public/router.php
// Запуск: RDS_DB=<db> NODE_PATH=/home/oleg/traning/web/node_modules node tests/pw/grid.js
// Пишет списки прямо в БД стенда (только тестовая БД); в конце возвращает строки как были.
const { chromium } = require('playwright-core');
const { execFileSync } = require('child_process');

const BASE = process.env.BASE || 'http://127.0.0.1:8080/';
const DB = process.env.RDS_DB;
if (!DB) { console.error('нужен RDS_DB — та же БД, что у стенда'); process.exit(2); }
// пачка [sql, args] в одной транзакции; результат — строки каждого запроса
const sql = (...ops) => JSON.parse(execFileSync('php', ['-r',
  '$p=new PDO("sqlite:".$argv[1]); $p->exec("PRAGMA busy_timeout=5000"); $p->beginTransaction(); $o=[];'
  + ' foreach (json_decode($argv[2], true) as [$q, $a]) { $s=$p->prepare($q); $s->execute($a); $o[]=$s->fetchAll(PDO::FETCH_ASSOC); }'
  + ' $p->commit(); echo json_encode($o);', DB, JSON.stringify(ops)]).toString());
// первый запрос к стенду создаёт схему и сид в свежей БД
execFileSync('php', ['-r', 'file_get_contents($argv[1]);', BASE]);

const where = (kind) => kind ? [' WHERE kind = ?', [kind]] : ['', []];
const get = (tbl, kind) => { const [w, a] = where(kind); return sql(['SELECT * FROM ' + tbl + w + ' ORDER BY sort, id', a])[0]; };
const put = (tbl, kind, rows) => { const [w, a] = where(kind); sql(['DELETE FROM ' + tbl + w, a],
  ...rows.map((r) => ['INSERT INTO ' + tbl + ' (' + Object.keys(r).join(',') + ') VALUES (' + Object.keys(r).map(() => '?').join(',') + ')', Object.values(r)])); };
const copies = (row, n) => Array.from({ length: n }, (_, i) => { const { id, ...r } = row; return { ...r, sort: i + 1 }; });
const cols = (n, max) => n <= max ? n : Math.ceil(n / Math.ceil(n / max)); // как $cols в home.php

// список → [таблица, kind, max колонок, сетка на /]
const GRID = {
  services: ['services', null, 3, '#services [data-r=g3]'],
  fleet: ['fleet_cards', null, 3, '#fleet [data-r=g3]'],
  materials: ['materials', null, 4, '#materials [data-r=g4]'],
  why: ['items', 'why', 2, '#why [data-r=g2]'],
  step: ['items', 'step', 5, '[data-r=g5]'],
  stat_bar: ['items', 'stat_bar', 4, '[data-r=stats]'],
};
// лимиты полей = lists() в public/admin/index.php (ADR «Лимиты»)
const LIM = {
  services: ['services', null, { title: 40, text: 200 }],
  fleet_cards: ['fleet_cards', null, { title: 40, alt: 120, unit: 8 }],
  fleet_prices: ['fleet_prices', null, { name: 40, model: 30, unit: 12 }],
  materials: ['materials', null, { title: 30, text: 150, alt: 120, badge: 14, unit: 8 }],
  marquee: ['items', 'marquee', { title: 40 }],
  stat_hero: ['items', 'stat_hero', { title: 22, text: 8, suffix: 3 }],
  stat_bar: ['items', 'stat_bar', { title: 22, text: 7, suffix: 3 }],
  why: ['items', 'why', { title: 30, text: 160 }],
  step: ['items', 'step', { title: 24, text: 120 }],
};
const W = 'Асфальтобетонирование ';
const long = (n) => { const s = W.repeat(Math.ceil(n / W.length)).slice(0, n); return s.endsWith(' ') ? s.slice(0, -1) + 'е' : s; };
// меню на пределе правила суммы: 6 пунктов × 7 символов + 4·6 = 66
const NAV_N = 6, NAV_T = long(7);
// stat (поправка владельца 2026-10-26): title — реалистично; ровно 22 с пробелом и словом 21 невозможно (21+1+1=23),
// поэтому два прогона: слово «Асфальтобетонирование» (21) целиком и 22 с пробелом; text (п.6): hero 8, bar 7 — одним словом и с пробелом
const STAT = [{ title: 'Асфальтобетонирование', stat_hero: long(8), stat_bar: long(7) },
  { title: 'асфальтобетонный завод', stat_hero: 'Под ключ', stat_bar: '24 часа' }];

// снимок всех списков для возврата
const SNAP = Object.entries({ ...LIM, nav: ['items', 'nav'], ...GRID }).map(([, [t, k]]) => [t, k, get(t, k)]);

(async () => {
  let fail = 0;
  const check = (name, ok, why = '') => { console.log(name + (ok ? ' OK' : ' FAIL ' + why)); if (!ok) fail++; };
  const browser = await chromium.launch({ executablePath: process.env.PW_CHROME || '/home/oleg/.cache/ms-playwright/chromium-1228/chrome-linux64/chrome' });
  const pages = {};
  const page = async (w) => pages[w] ??= await browser.newPage({ viewport: { width: w, height: 900 }, reducedMotion: 'reduce' });
  const load = async (w) => { const p = await page(w); await p.goto(BASE); await p.evaluate(() => document.fonts.ready); return p; };
  const widths = (p) => p.evaluate(() => [document.documentElement.scrollWidth, document.documentElement.clientWidth]);
  // видимые элементы правее окна (кроме бегущей строки)
  const outside = (p, root = 'body') => p.evaluate((root) => {
    const cw = document.documentElement.clientWidth, bad = [];
    for (const el of document.querySelectorAll(root + ' *')) {
      if (el.closest('[data-marquee],[data-marquee-fast]') || !el.checkVisibility()) continue;
      const r = el.getBoundingClientRect();
      if (r.right > cw + 0.5) bad.push(`${el.tagName.toLowerCase()}${el.id ? '#' + el.id : ''} «${el.textContent.trim().slice(0, 30)}» right=${r.right.toFixed(1)} (+${(r.right - cw).toFixed(1)}px)`);
    }
    return bad;
  }, root);

  try {
    // 1. сетка: n ∈ {1,4,5,7,12} на 390 и 1440
    for (const [name, [tbl, kind, max, sel]] of Object.entries(GRID)) {
      const seed = get(tbl, kind);
      for (let n = 1; n <= 12; n++) {
        put(tbl, kind, copies(seed[0], n));
        for (const w of [390, 1440]) {
          const p = await load(w);
          const [sw, cw] = await widths(p);
          const c = await p.$eval(sel, (el) => [el.children.length, getComputedStyle(el).gridTemplateColumns.split(' ').length]);
          const want = w === 1440 ? cols(n, max) : null;
          check(`${name} n=${n} @${w}: карточек=${c[0]} колонок=${c[1]}${want ? ' (ждём ' + want + ')' : ' (≤2)'} scrollWidth=${sw} clientWidth=${cw}`,
            c[0] === n && sw <= cw && (want ? c[1] === want : c[1] <= 2));
        }
      }
      put(tbl, kind, seed);
    }

    // 2. меню: 6 пунктов на пределе правила суммы на 1280 и 1440
    const nav = get('items', 'nav');
    put('items', 'nav', copies(nav[0], NAV_N).map((r, i) => ({ ...r, title: NAV_T, href: nav[i % nav.length].href })));
    for (const w of [1280, 1440]) {
      const p = await load(w);
      const [sw, cw] = await widths(p);
      const m = await p.$eval('nav[data-desknav]', (nv) => {
        const a = [...nv.querySelectorAll('a')].map((x) => x.getBoundingClientRect());
        return [a.length, new Set(a.map((r) => Math.round(r.top))).size, a[a.length - 1].right, nv.nextElementSibling.getBoundingClientRect().left];
      });
      const bad = await outside(p, 'header');
      check(`nav ${NAV_N}×«${NAV_T}» (сумма ${NAV_N * NAV_T.length}+4·${NAV_N}=${NAV_N * NAV_T.length + 4 * NAV_N}) @${w}: пунктов=${m[0]} строк=${m[1]} правый край меню=${m[2].toFixed(1)} телефон слева=${m[3].toFixed(1)} scrollWidth=${sw} clientWidth=${cw}`,
        m[0] === NAV_N && m[1] === 1 && m[2] <= m[3] && sw <= cw && !bad.length, bad.join('; '));
    }

    // 3. всё на лимите: каждое текстовое поле ровно на лимит длинными словами (stat title/text — из STAT), цены 999999, числа 9999, меню как в п.2
    for (const st of STAT) {
      for (const [t, [tbl, kind, lim]] of Object.entries(LIM)) {
        put(tbl, kind, get(tbl, kind).map((r) => {
          const o = { ...r };
          for (const [f, n] of Object.entries(lim)) o[f] = long(n);
          if (t.startsWith('stat_')) Object.assign(o, { title: st.title, text: st[t] });
          if ('price' in o && o.price !== null) o.price = 999999;
          if ('num' in o && o.num !== null) o.num = 9999;
          return o;
        }));
      }
      for (const w of [390, 1280, 1440]) {
        const p = await load(w);
        const [sw, cw] = await widths(p);
        const bad = await outside(p);
        check(`всё на лимите (stat «${st.title}» / «${st.stat_hero}» / «${st.stat_bar}») @${w}: scrollWidth=${sw} clientWidth=${cw} за окном=${bad.length}`, sw <= cw && !bad.length, bad.slice(0, 10).join('; '));
      }
    }
  } finally {
    for (const [t, k, rows] of SNAP) put(t, k, rows);
    await browser.close();
  }
  process.exit(fail ? 1 : 0);
})().catch((e) => { console.error(e); process.exit(1); });
