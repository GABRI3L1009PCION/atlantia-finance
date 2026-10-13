/**
 * Atlantia Finance: Cloudflare Worker for the after-hours page.
 *
 * - Outside service hours (default 6:00 AM to 11:00 PM, Guatemala time) it answers with the closing page WITHOUT
 *   contacting the origin, so the server can be switched off.
 * - Inside service hours it forwards every request to the origin; if the origin does not answer (Cloudflare 52x
 *   errors) it shows the same page with a "temporarily unavailable" message instead of Cloudflare's error screen.
 * - Health checks, payment webhooks and the public API are always forwarded, never replaced by the page.
 *
 * Optional variables (Worker > Settings > Variables):
 *   FORCE_CLOSED  "true" closes the platform right now, at any hour.
 *   BYPASS_TOKEN  secret; open https://your-domain/?bypass=<token> once to keep working out of hours (cookie, 12 h).
 *   OPENS_AT / CLOSES_AT  "HH:MM" in Guatemala time (defaults 06:00 and 23:00).
 */

const GUATEMALA_OFFSET_HOURS = -6; // Guatemala does not observe daylight saving time.
const PASS_THROUGH = [/^\/up$/, /^\/webhooks\//, /^\/api\//, /^\/calendar\//];
const ORIGIN_DOWN = new Set([521, 522, 523, 524, 525, 526, 527, 530]);

function toMinutes(value, fallback) {
    const [hours, minutes] = String(value || fallback).split(':').map(Number);

    return hours * 60 + (minutes || 0);
}

function schedule(env, now = new Date()) {
    const opens = toMinutes(env.OPENS_AT, '06:00');
    const closes = toMinutes(env.CLOSES_AT, '23:00');
    const local = new Date(now.getTime() + GUATEMALA_OFFSET_HOURS * 3600 * 1000);
    const minuteOfDay = local.getUTCHours() * 60 + local.getUTCMinutes();
    const open = minuteOfDay >= opens && minuteOfDay < closes;

    // Next opening: today if it has not opened yet, otherwise tomorrow (as a UTC instant).
    const day = Date.UTC(local.getUTCFullYear(), local.getUTCMonth(), local.getUTCDate());
    const todayOpening = day + opens * 60000 - GUATEMALA_OFFSET_HOURS * 3600 * 1000;
    const nextOpening = new Date(minuteOfDay < opens && env.FORCE_CLOSED !== 'true' ? todayOpening : todayOpening + 86400000);

    return { open, opens, closes, nextOpening, tomorrow: nextOpening.getTime() - now.getTime() > 0 && new Date(nextOpening.getTime() + GUATEMALA_OFFSET_HOURS * 3600 * 1000).getUTCDate() !== local.getUTCDate() };
}

function label(minutes) {
    const hours24 = Math.floor(minutes / 60);
    const hours = hours24 % 12 === 0 ? 12 : hours24 % 12;

    return `${hours}:${String(minutes % 60).padStart(2, '0')} ${hours24 < 12 ? 'AM' : 'PM'}`;
}

function bypassed(request, env) {
    return Boolean(env.BYPASS_TOKEN) && (request.headers.get('Cookie') || '').includes(`atlantia_bypass=${env.BYPASS_TOKEN}`);
}

function page(info, mode) {
    const title = mode === 'down' ? 'Estamos mejorando <span class="hot">nuestro servicio</span>' : 'Nuestro horario de servicio <span class="hot">ha finalizado</span>';
    const text = mode === 'down'
        ? 'La plataforma no está disponible en este momento. Nuestro equipo ya está trabajando para restablecerla. Vuelve a intentarlo en unos minutos.'
        : 'La plataforma se encuentra temporalmente fuera de su horario habitual de funcionamiento. Estaremos nuevamente disponibles en nuestro próximo horario de servicio para que puedas continuar utilizando tus herramientas financieras con normalidad.';
    const next = mode === 'down' ? '' : `
        <div class="row"><span>🗓 Próxima apertura (${info.tomorrow ? 'mañana' : 'hoy'}):</span><b>${label(info.opens)}</b></div>`;

    return `<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>${mode === 'down' ? 'Servicio no disponible' : 'Fuera de horario de servicio'} - Atlantia Finance</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;color:#fff;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:radial-gradient(ellipse at 80% 30%,rgba(190,24,56,.35),transparent 55%),radial-gradient(ellipse at 10% 90%,rgba(120,10,30,.45),transparent 50%),linear-gradient(135deg,#1a0910,#0c0508)}
.wrap{max-width:1180px;margin:auto;min-height:100vh;padding:32px 28px;display:flex;flex-direction:column}
header{display:flex;justify-content:space-between;align-items:flex-start;gap:16px}
.brand{display:flex;gap:14px;align-items:center}.brand svg{width:48px;height:48px}.brand h2{margin:0;font-size:34px;line-height:1;letter-spacing:-.02em}.brand h2 span{font-weight:300}
.brand p{margin:8px 0 0;font-size:11px;letter-spacing:.28em;text-transform:uppercase;color:rgba(255,255,255,.7)}
.rule{width:48px;height:1px;background:#e2354f;margin-top:16px}.top{font-size:11px;letter-spacing:.3em;text-transform:uppercase;color:rgba(255,255,255,.6);padding-top:8px}
main{flex:1;display:grid;grid-template-columns:1.05fr 1fr;gap:40px;align-items:center;padding:36px 0}
h1{margin:0;font-size:clamp(34px,5vw,60px);line-height:1.05;letter-spacing:-.02em;font-weight:800}.hot{color:#ff3d5a}
.lead{max-width:560px;margin:24px 0 0;font-size:18px;line-height:1.7;color:rgba(255,255,255,.85)}
.card{max-width:560px;margin-top:30px;border:1px solid rgba(226,53,79,.3);border-radius:18px;background:rgba(255,255,255,.04);padding:18px 22px;box-shadow:0 20px 60px -30px rgba(226,53,79,.6)}
.card h3{margin:0;padding-bottom:12px;border-bottom:1px solid rgba(255,255,255,.1);font-size:17px}
.row{display:flex;justify-content:space-between;gap:16px;padding:12px 0;border-bottom:1px solid rgba(255,255,255,.1);color:rgba(255,255,255,.88)}.row b{color:#fff;white-space:nowrap}.zone{padding-top:12px;color:rgba(255,255,255,.88)}
button{margin-top:26px;width:100%;max-width:560px;height:56px;border:0;border-radius:999px;color:#fff;font-size:18px;font-weight:700;cursor:pointer;background:linear-gradient(90deg,#d61f43,#a5122f);box-shadow:0 14px 40px -12px rgba(226,53,79,.9)}button:hover{filter:brightness(1.1)}
.note{max-width:560px;margin-top:16px;text-align:center;font-size:14px;color:rgba(255,255,255,.7)}.note b{color:#fff}
.art{position:relative;min-height:440px}.art svg.chart{position:absolute;inset:0;width:100%;height:100%}
.clock{position:absolute;top:50%;left:50%;transform:translate(-50%,-50%);width:78%;padding:30px;border-radius:32px;text-align:center;border:1px solid rgba(255,106,128,.4);background:linear-gradient(#3a0b17,#13050a);box-shadow:0 30px 80px -20px rgba(226,53,79,.7)}
.clock .t{font:300 clamp(56px,7vw,92px)/1 ui-monospace,Consolas,monospace;letter-spacing:.08em;text-shadow:0 0 18px rgba(255,255,255,.55)}.clock .t small{font-size:20px;color:rgba(255,255,255,.8);letter-spacing:0;margin-left:10px}
.clock p{margin:12px 0 0;font-size:12px;letter-spacing:.3em;text-transform:uppercase;color:rgba(255,255,255,.5)}
footer{border-top:1px solid rgba(255,255,255,.1);padding-top:18px;text-align:center;font-size:12px;color:rgba(255,255,255,.6)}
@media(max-width:900px){main{grid-template-columns:1fr}.art,.top{display:none}.brand h2{font-size:28px}}
</style></head><body><div class="wrap">
<header><div><div class="brand">
<svg viewBox="0 0 64 64" aria-hidden="true"><rect x="4" y="44" width="8" height="16" rx="1" fill="#ff3d5a"/><rect x="16" y="34" width="8" height="26" rx="1" fill="#ff3d5a"/><rect x="28" y="24" width="8" height="36" rx="1" fill="#ff6a80"/><path d="M40 60 L52 6 L60 6 L60 60 L52 60 L52 24 L48 60Z" fill="#ff8a9a"/></svg>
<div><h2>Atlantia <span>Finance</span></h2><p>Finanzas hoy, más posibilidades mañana</p></div></div><div class="rule"></div></div>
<p class="top">Seguridad · Tecnología · Tu futuro</p></header>
<main><div>
<h1>${title}</h1><p class="lead">${text}</p>
<div class="card"><h3>🕘 Horario de servicio</h3>
<div class="row"><span>☀ Apertura:</span><b>${label(info.opens)}</b></div>
<div class="row"><span>🌙 Cierre:</span><b>${label(info.closes)}</b></div>${next}
<div class="zone">🌐 Hora de Guatemala (UTC-6)</div></div>
<button type="button" onclick="location.reload()">↻ Volver a intentar</button>
<p class="note" id="note">Gracias por tu comprensión y confianza.</p></div>
<div class="art" aria-hidden="true"><svg class="chart" viewBox="0 0 640 460"><defs><linearGradient id="l" x1="0" x2="1"><stop offset="0" stop-color="#ff3d5a" stop-opacity=".2"/><stop offset="1" stop-color="#ff3d5a"/></linearGradient><linearGradient id="a" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#ff3d5a" stop-opacity=".35"/><stop offset="1" stop-color="#ff3d5a" stop-opacity="0"/></linearGradient></defs>
<path d="M20 380 L110 330 L190 350 L280 270 L360 300 L450 190 L540 220 L620 90 L620 460 L20 460Z" fill="url(#a)"/><path d="M20 380 L110 330 L190 350 L280 270 L360 300 L450 190 L540 220 L620 90" fill="none" stroke="url(#l)" stroke-width="4" stroke-linejoin="round" stroke-linecap="round"/><circle cx="620" cy="90" r="7" fill="#ff3d5a"/></svg>
<div class="clock"><div class="t">${label(info.closes).split(' ')[0]}<small>${label(info.closes).split(' ')[1]}</small></div><p>Hora de cierre</p></div></div></main>
<footer>© ${new Date().getUTCFullYear()} Atlantia Finance - Todos los derechos reservados</footer></div>
${mode === 'down' ? '' : `<script>(function(){var t=${info.nextOpening.getTime()};var n=document.getElementById('note');function f(){var m=Math.max(0,Math.round((t-Date.now())/60000));n.innerHTML='Abrimos de nuevo en <b>'+(m>=60?Math.floor(m/60)+' h '+String(m%60).padStart(2,'0')+' min':m+' min')+'</b>. Gracias por tu comprensión y confianza.';if(t-Date.now()<=0)location.reload()}f();setInterval(f,30000)})()</script>`}
</body></html>`;
}

function closed(info, mode) {
    const retry = Math.max(60, Math.round((info.nextOpening.getTime() - Date.now()) / 1000));

    return new Response(page(info, mode), {
        status: 503,
        headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store', 'Retry-After': String(mode === 'down' ? 120 : retry) },
    });
}

export default {
    async fetch(request, env) {
        const url = new URL(request.url);
        const info = schedule(env);

        if (PASS_THROUGH.some((pattern) => pattern.test(url.pathname))) {
            return fetch(request);
        }

        if (env.BYPASS_TOKEN && url.searchParams.get('bypass') === env.BYPASS_TOKEN) {
            url.searchParams.delete('bypass');

            return new Response(null, { status: 302, headers: { Location: url.toString(), 'Set-Cookie': `atlantia_bypass=${env.BYPASS_TOKEN}; Path=/; Max-Age=43200; Secure; HttpOnly; SameSite=Lax` } });
        }

        if ((env.FORCE_CLOSED === 'true' || !info.open) && !bypassed(request, env)) {
            return closed(info, 'hours');
        }

        try {
            const response = await fetch(request);

            return ORIGIN_DOWN.has(response.status) ? closed(info, 'down') : response;
        } catch {
            return closed(info, 'down');
        }
    },
};
