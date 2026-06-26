/**
 * Cloudflare Worker — прокси для отправки заявок с сайта в Telegram.
 *
 * Зачем: токен бота нельзя класть в клиентский JS (виден в исходниках).
 * Worker хранит токен в секретах и пересылает заявку в чат.
 *
 * ── Деплой (бесплатный план Cloudflare) ──────────────────────────────
 * 1. Создать бота у @BotFather → получить BOT_TOKEN.
 * 2. Узнать CHAT_ID: написать боту, открыть
 *    https://api.telegram.org/bot<BOT_TOKEN>/getUpdates → поле chat.id
 *    (для группы добавить бота в группу и взять её id, обычно с минусом).
 * 3. dash.cloudflare.com → Workers → Create → вставить этот код.
 * 4. Settings → Variables and Secrets → добавить:
 *      BOT_TOKEN    = <токен>          (Secret)
 *      CHAT_ID      = <id чата>        (Secret)
 *      ALLOW_ORIGIN = https://oreopaks.github.io   (origin сайта, БЕЗ пути и без слэша в конце)
 * 5. (Опционально, против спама) Создать KV namespace и привязать его к
 *    воркеру под именем RATE — включит лимит 5 заявок/мин с одного IP.
 * 6. Скопировать URL воркера (вида https://xxx.workers.dev) и вставить
 *    в index.html → CFG.tgEndpoint.
 *
 * ВАЖНО: ALLOW_ORIGIN обязателен. Без него воркер отвечает 500 (fail-closed),
 * чтобы случайно не оставить эндпоинт открытым для всех.
 */

const LIMITS = { type: 60, material: 80, service: 80, name: 100, phone: 32, comment: 1000 };

export default {
  async fetch(request, env) {
    const allow = env.ALLOW_ORIGIN;
    const origin = request.headers.get('Origin') || '';

    // CORS fail-closed: ALLOW_ORIGIN должен быть задан.
    if (!allow) return new Response('Server misconfigured: ALLOW_ORIGIN not set', { status: 500 });

    const originOk = origin === allow;
    const cors = {
      'Access-Control-Allow-Origin': originOk ? origin : allow,
      'Access-Control-Allow-Methods': 'POST, OPTIONS',
      'Access-Control-Allow-Headers': 'Content-Type',
      'Vary': 'Origin',
    };

    if (request.method === 'OPTIONS') return new Response(null, { headers: cors });
    if (request.method !== 'POST') return new Response('Method Not Allowed', { status: 405, headers: cors });

    // Reject cross-site browser POSTs from other origins.
    if (origin && !originOk) return json({ ok: false, error: 'forbidden origin' }, 403, cors);

    // Limit body size (~16KB) before parsing.
    const len = parseInt(request.headers.get('Content-Length') || '0', 10);
    if (len > 16384) return json({ ok: false, error: 'payload too large' }, 413, cors);

    // Optional rate limit (requires a KV namespace bound as `RATE`): 5 req / min / IP.
    if (env.RATE) {
      const ip = request.headers.get('CF-Connecting-IP') || 'unknown';
      const key = 'rl:' + ip;
      const cnt = parseInt((await env.RATE.get(key)) || '0', 10);
      if (cnt >= 5) return json({ ok: false, error: 'rate limited' }, 429, cors);
      await env.RATE.put(key, String(cnt + 1), { expirationTtl: 60 });
    }

    let data;
    try { data = await request.json(); } catch { return json({ ok: false, error: 'bad json' }, 400, cors); }
    if (typeof data !== 'object' || data === null) return json({ ok: false, error: 'bad payload' }, 400, cors);

    // Honeypot: боты заполняют скрытое поле — молча игнорируем.
    if (data._hp) return json({ ok: true }, 200, cors);

    // Enforce per-field length limits.
    const clip = (v, max) => String(v ?? '').slice(0, max);
    const f = {
      type: clip(data.type, LIMITS.type),
      material: clip(data.material, LIMITS.material),
      service: clip(data.service, LIMITS.service),
      name: clip(data.name, LIMITS.name),
      phone: clip(data.phone, LIMITS.phone),
      comment: clip(data.comment, LIMITS.comment),
    };

    // Basic validation: name + a phone with at least 6 digits.
    const digits = (f.phone.match(/\d/g) || []).length;
    if (!f.name.trim() || digits < 6) return json({ ok: false, error: 'invalid input' }, 422, cors);

    const esc = (s) => String(s ?? '—').replace(/[<&>]/g, (c) => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' }[c]));
    const lines = [
      `<b>🚧 Заявка с сайта</b>`,
      `<b>Тип:</b> ${esc(f.type)}`,
      f.material ? `<b>Материал:</b> ${esc(f.material)}` : '',
      f.service ? `<b>Услуга:</b> ${esc(f.service)}` : '',
      `<b>Имя:</b> ${esc(f.name)}`,
      `<b>Телефон:</b> ${esc(f.phone)}`,
      f.comment ? `<b>Комментарий:</b> ${esc(f.comment)}` : '',
    ].filter(Boolean);

    let tgRes;
    try {
      tgRes = await fetch(`https://api.telegram.org/bot${env.BOT_TOKEN}/sendMessage`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ chat_id: env.CHAT_ID, text: lines.join('\n'), parse_mode: 'HTML' }),
      });
    } catch {
      return json({ ok: false, error: 'telegram unreachable' }, 502, cors);
    }

    if (!tgRes.ok) return json({ ok: false, error: 'telegram failed' }, 502, cors);
    return json({ ok: true }, 200, cors);
  },
};

function json(obj, status, cors) {
  return new Response(JSON.stringify(obj), {
    status,
    headers: { 'Content-Type': 'application/json', ...cors },
  });
}
