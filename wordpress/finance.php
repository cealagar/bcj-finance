<?php
/* BCJ Coach module: BCJ Finance PWA v1.0.0 (2026-11-17, Jeff).
 * Separate installable app at https://bodybycoachjeff.com/finance/ with its OWN logins (not WordPress accounts):
 * payment approvals (manual QR/bank, n8n i7FLUp5YumOIftqH) + revenue reports + web push on every new payment.
 * Users: option bcjfin_users (password_hash). Sessions: option bcjfin_sess (sha256(token) => user, exp 30 days).
 * Roles: owner (approve, reports, manage logins), partner (approve + reports).
 * First owner: created on /finance/ while logged in to WordPress as admin (one time). Partners join by an invite link.
 * n8n → POST /wp-json/bcj-fin/v1/notify (header x-bcj-fin) → push to all Finance devices. No Gemini / GCP: P0.
 * Partner money scope (v1.3.0): partner sees only reservations + ebooks revenue, ad spend and card charges; coaching/renewal/onboarding hidden from data, approvals, push; decide blocked server-side.
 * Card charges (v1.2.1): Meta 'Account billed' events (activities API) with PH time, transaction ID, card, CSV.
 * BCJ branding (v1.2.0): BCJ Coach look (black header + official dark logo, Anton titles, Inter, #921D2D/#C8283F), official square logo app icons.
 * Install popup (v1.1.2): beforeinstallprompt on Android/Chrome, Share › Add to Home Screen steps on iPhone; hidden when installed.
 * Partner scope (v1.1.1): partner sees Approvals + Meta Ads only; /data strips ledger to date+amount for non-owners.
 * Ads tab (v1.1.0): Meta ad spend + billing from n8n IpzzhQ5ybwML5566 via bcj-pay-list ads.
 * Rollback: mod_put {name:'finance.php', op:'del'}. */
if (!defined('ABSPATH')) exit;
define('BCJFIN_NOTIFY_KEY', '__BCJFIN_NOTIFY_KEY__');
define('BCJFIN_PAY_KEY', '__BCJ_PAY_KEY__');
define('BCJFIN_N8N', 'https://n8n.srv1170024.hstgr.cloud/webhook/');

function bcjfin_users() { $u = get_option('bcjfin_users'); return is_array($u) ? $u : []; }
function bcjfin_save_users($u) { update_option('bcjfin_users', $u, false); }
function bcjfin_sess() { $s = get_option('bcjfin_sess'); return is_array($s) ? $s : []; }
function bcjfin_auth(WP_REST_Request $r) {
    $t = (string) $r->get_header('x-fin-token'); if (strlen($t) < 32) return null;
    $S = bcjfin_sess(); $h = hash('sha256', $t);
    if (empty($S[$h]) || $S[$h]['exp'] < time()) return null;
    $U = bcjfin_users(); $u = $S[$h]['u'];
    return isset($U[$u]) ? ['u' => $u] + $U[$u] : null;
}
function bcjfin_out($d, $code = 200) { $x = new WP_REST_Response($d, $code); $x->header('Cache-Control', 'no-store'); return $x; }
function bcjfin_n8n($path, $body = null) {
    $args = ['timeout' => 60, 'headers' => ['x-bcj-pay' => BCJFIN_PAY_KEY, 'Content-Type' => 'application/json']];
    $res = $body === null ? wp_remote_get(BCJFIN_N8N . $path, $args) : wp_remote_post(BCJFIN_N8N . $path, $args + ['body' => wp_json_encode($body)]);
    if (is_wp_error($res)) return ['ok' => false, 'error' => $res->get_error_message()];
    $j = json_decode(wp_remote_retrieve_body($res), true); return is_array($j) ? $j : ['ok' => false, 'error' => 'bad response'];
}
// Partner scope: coaching / renewal / onboarding money is owner-only (Jeff, Nov 17 2026).
function bcjfin_is_coach($x) {
    if (strpos((string) ($x['id'] ?? ''), 'OB:') === 0) return true;
    return (bool) preg_match('/coach|renew|season|onboard/i', ($x['kind'] ?? '') . ' ' . ($x['product'] ?? '') . ' ' . ($x['src'] ?? '') . ' ' . ($x['label'] ?? ''));
}
function bcjfin_push_all($owner_only = false) {
    if (!function_exists('bcjhq_rem_cfg') || !function_exists('bcjhq_b64u') || !function_exists('bcjhq_der2raw')) return 0;
    $c = bcjhq_rem_cfg(); if (empty($c['priv']) || empty($c['pub'])) return 0;
    $subs = get_option('bcjfin_subs'); $subs = is_array($subs) ? $subs : []; $keep = []; $sent = 0;
    $U = bcjfin_users();
    foreach ($subs as $s) {
        if ($owner_only && (($U[$s['u'] ?? '']['role'] ?? '') !== 'owner')) { $keep[] = $s; continue; }
        $ep = $s['endpoint'] ?? ''; $u = parse_url($ep); if (empty($u['host'])) continue;
        $h = bcjhq_b64u('{"typ":"JWT","alg":"ES256"}');
        $p = bcjhq_b64u(wp_json_encode(['aud' => $u['scheme'] . '://' . $u['host'], 'exp' => time() + 12 * 3600, 'sub' => 'https://bodybycoachjeff.com']));
        if (!openssl_sign("$h.$p", $der, $c['priv'], OPENSSL_ALGO_SHA256)) { $keep[] = $s; continue; }
        $jwt = "$h.$p." . bcjhq_b64u(bcjhq_der2raw($der));
        $res = wp_remote_post($ep, ['timeout' => 15, 'headers' => ['TTL' => '86400', 'Urgency' => 'high', 'Authorization' => "vapid t=$jwt, k=" . $c['pub'], 'Content-Length' => '0'], 'body' => '']);
        $code = is_wp_error($res) ? 0 : (int) wp_remote_retrieve_response_code($res);
        if ($code === 404 || $code === 410) continue;
        if ($code >= 200 && $code < 300) $sent++;
        $keep[] = $s;
    }
    update_option('bcjfin_subs', $keep, false); return $sent;
}

add_action('rest_api_init', function () {
    $ns = 'bcj-fin/v1';
    register_rest_route($ns, '/state', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
        $me = bcjfin_auth($r);
        return bcjfin_out(['ok' => true, 'setup' => !bcjfin_users(), 'me' => $me ? ['u' => $me['u'], 'name' => $me['name'], 'role' => $me['role']] : null,
            'vapid' => function_exists('bcjhq_rem_cfg') ? (bcjhq_rem_cfg()['pub'] ?? '') : '']);
    }]);
    // one-time owner setup: only while no Finance users exist AND the browser is logged in to WordPress as admin
    register_rest_route($ns, '/setup', ['methods' => 'POST', 'permission_callback' => function () { return current_user_can('manage_options'); }, 'callback' => function (WP_REST_Request $r) {
        if (bcjfin_users()) return bcjfin_out(['ok' => false, 'error' => 'Already set up'], 400);
        $u = strtolower(preg_replace('/[^a-z0-9._-]/i', '', (string) $r->get_param('u'))); $p = (string) $r->get_param('p');
        if (strlen($u) < 3 || strlen($p) < 10) return bcjfin_out(['ok' => false, 'error' => 'Username 3+ characters, password 10+ characters'], 400);
        bcjfin_save_users([$u => ['name' => sanitize_text_field((string) $r->get_param('name') ?: $u), 'role' => 'owner', 'hash' => password_hash($p, PASSWORD_DEFAULT), 'created' => time()]]);
        return bcjfin_out(['ok' => true]);
    }]);
    register_rest_route($ns, '/login', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
        $ip = md5((string) ($_SERVER['REMOTE_ADDR'] ?? '')); $k = 'bcjfin_try_' . $ip; $n = (int) get_transient($k);
        if ($n >= 8) return bcjfin_out(['ok' => false, 'error' => 'Too many attempts. Try again in 15 minutes.'], 429);
        $u = strtolower(trim((string) $r->get_param('u'))); $p = (string) $r->get_param('p'); $U = bcjfin_users();
        if (empty($U[$u]) || empty($U[$u]['hash']) || !password_verify($p, $U[$u]['hash'])) { set_transient($k, $n + 1, 15 * MINUTE_IN_SECONDS); return bcjfin_out(['ok' => false, 'error' => 'Wrong username or password'], 401); }
        delete_transient($k);
        $t = bin2hex(random_bytes(24)); $S = bcjfin_sess(); $now = time();
        foreach ($S as $h => $s) if ($s['exp'] < $now) unset($S[$h]);
        $S[hash('sha256', $t)] = ['u' => $u, 'exp' => $now + 30 * DAY_IN_SECONDS, 'at' => $now]; update_option('bcjfin_sess', $S, false);
        return bcjfin_out(['ok' => true, 'token' => $t, 'me' => ['u' => $u, 'name' => $U[$u]['name'], 'role' => $U[$u]['role']]]);
    }]);
    register_rest_route($ns, '/logout', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
        $S = bcjfin_sess(); unset($S[hash('sha256', (string) $r->get_header('x-fin-token'))]); update_option('bcjfin_sess', $S, false); return bcjfin_out(['ok' => true]);
    }]);
    register_rest_route($ns, '/password', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
        $me = bcjfin_auth($r); if (!$me) return bcjfin_out(['ok' => false, 'error' => 'login'], 401);
        $U = bcjfin_users(); $old = (string) $r->get_param('old'); $new = (string) $r->get_param('new');
        if (!password_verify($old, $U[$me['u']]['hash'])) return bcjfin_out(['ok' => false, 'error' => 'Current password is wrong'], 400);
        if (strlen($new) < 10) return bcjfin_out(['ok' => false, 'error' => 'New password: 10+ characters'], 400);
        $U[$me['u']]['hash'] = password_hash($new, PASSWORD_DEFAULT); bcjfin_save_users($U); return bcjfin_out(['ok' => true]);
    }]);
    // owner: invite / list / remove users
    register_rest_route($ns, '/users', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
        $me = bcjfin_auth($r); if (!$me || $me['role'] !== 'owner') return bcjfin_out(['ok' => false, 'error' => 'Owner only'], 403);
        $U = bcjfin_users(); $op = (string) $r->get_param('op');
        if ($op === 'invite') {
            $u = strtolower(preg_replace('/[^a-z0-9._-]/i', '', (string) $r->get_param('u')));
            if (strlen($u) < 3 || isset($U[$u])) return bcjfin_out(['ok' => false, 'error' => 'Pick a new username (3+ characters)'], 400);
            $code = bin2hex(random_bytes(16));
            $U[$u] = ['name' => sanitize_text_field((string) $r->get_param('name') ?: $u), 'role' => 'partner', 'hash' => '', 'invite' => hash('sha256', $code), 'invite_exp' => time() + 3 * DAY_IN_SECONDS, 'created' => time()];
            bcjfin_save_users($U); return bcjfin_out(['ok' => true, 'link' => home_url('/finance/?invite=' . $code . '&u=' . rawurlencode($u))]);
        }
        if ($op === 'remove') { $u = (string) $r->get_param('u'); if ($u !== $me['u'] && isset($U[$u])) { unset($U[$u]); bcjfin_save_users($U);
            $S = bcjfin_sess(); foreach ($S as $h => $s) if ($s['u'] === $u) unset($S[$h]); update_option('bcjfin_sess', $S, false); } }
        $L = []; foreach (bcjfin_users() as $k => $v) $L[] = ['u' => $k, 'name' => $v['name'], 'role' => $v['role'], 'pending' => empty($v['hash'])];
        return bcjfin_out(['ok' => true, 'users' => $L]);
    }]);
    register_rest_route($ns, '/accept', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
        $U = bcjfin_users(); $u = strtolower((string) $r->get_param('u')); $code = (string) $r->get_param('code'); $p = (string) $r->get_param('p');
        if (empty($U[$u]['invite']) || !hash_equals($U[$u]['invite'], hash('sha256', $code)) || ($U[$u]['invite_exp'] ?? 0) < time()) return bcjfin_out(['ok' => false, 'error' => 'This invite link expired or was already used. Ask for a new one.'], 400);
        if (strlen($p) < 10) return bcjfin_out(['ok' => false, 'error' => 'Password: 10+ characters'], 400);
        $U[$u]['hash'] = password_hash($p, PASSWORD_DEFAULT); unset($U[$u]['invite'], $U[$u]['invite_exp']); bcjfin_save_users($U);
        return bcjfin_out(['ok' => true]);
    }]);
    register_rest_route($ns, '/data', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
        $me = bcjfin_auth($r); if (!$me) return bcjfin_out(['ok' => false, 'error' => 'login'], 401);
        $d = bcjfin_n8n('bcj-pay-list?all=1');
        if ($me['role'] !== 'owner' && is_array($d)) { // partner: approvals + Meta ads + revenue totals only
            $keep = function ($x) { return !bcjfin_is_coach($x); };
            $d['ledger'] = array_values(array_map(function ($x) { return ['date' => $x['date'] ?? '', 'amount' => $x['amount'] ?? 0, 'kind' => $x['kind'] ?? '', 'label' => $x['label'] ?? '']; }, array_filter((array) ($d['ledger'] ?? []), $keep)));
            foreach (['pending', 'recent'] as $k) $d[$k] = array_values(array_filter((array) ($d[$k] ?? []), $keep));
            unset($d['rejected']); $d['scope'] = 'partner';
        }
        return bcjfin_out($d);
    }]);
    register_rest_route($ns, '/decide', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
        $me = bcjfin_auth($r); if (!$me) return bcjfin_out(['ok' => false, 'error' => 'login'], 401);
        $id = preg_replace('/[^A-Za-z0-9:\-]/', '', (string) $r->get_param('id')); $a = $r->get_param('action') === 'ok' ? 'ok' : 'no';
        if ($me['role'] !== 'owner') {
            $lst = bcjfin_n8n('bcj-pay-list?all=1'); $hit = null;
            foreach ((array) ($lst['pending'] ?? []) as $x) if (($x['id'] ?? '') === $id) $hit = $x;
            if (!$hit || bcjfin_is_coach($hit)) return bcjfin_out(['ok' => false, 'error' => 'Coaching payments are approved by Jeff.'], 403);
        }
        $res = bcjfin_n8n('bcj-pay-decide', ['id' => $id, 'action' => $a, 'by' => $me['name']]);
        $log = get_option('bcjfin_log'); $log = is_array($log) ? $log : []; array_unshift($log, ['t' => time(), 'by' => $me['name'], 'id' => $id, 'a' => $a]); update_option('bcjfin_log', array_slice($log, 0, 300), false);
        return bcjfin_out($res);
    }]);
    register_rest_route($ns, '/sub', ['methods' => 'POST', 'permission_callback' => '__return_true', 'callback' => function (WP_REST_Request $r) {
        $me = bcjfin_auth($r); if (!$me) return bcjfin_out(['ok' => false, 'error' => 'login'], 401);
        $s = (array) $r->get_param('sub'); if (empty($s['endpoint'])) return bcjfin_out(['ok' => false], 400);
        $L = get_option('bcjfin_subs'); $L = is_array($L) ? $L : []; $L = array_values(array_filter($L, function ($x) use ($s) { return ($x['endpoint'] ?? '') !== $s['endpoint']; }));
        $L[] = ['endpoint' => esc_url_raw($s['endpoint']), 'u' => $me['u'], 'at' => time()]; update_option('bcjfin_subs', $L, false);
        return bcjfin_out(['ok' => true, 'devices' => count($L)]);
    }]);
    // n8n → new payment
    register_rest_route($ns, '/notify', ['methods' => 'POST', 'permission_callback' => function (WP_REST_Request $r) { return hash_equals(BCJFIN_NOTIFY_KEY, (string) $r->get_header('x-bcj-fin')); }, 'callback' => function (WP_REST_Request $r) {
        $ti = sanitize_text_field((string) $r->get_param('title')); $tx = sanitize_text_field((string) $r->get_param('text'));
        $priv = (bool) preg_match('/coach|renew|season|onboard/i', $ti . ' ' . $tx);
        update_option('bcjfin_last', ['t' => time(), 'title' => $ti, 'text' => $tx, 'priv' => $priv], false);
        return bcjfin_out(['ok' => true, 'pushed' => bcjfin_push_all($priv)]);
    }]);
    register_rest_route($ns, '/peek', ['methods' => 'GET', 'permission_callback' => '__return_true', 'callback' => function () {
        $l = get_option('bcjfin_last'); $l = is_array($l) ? $l : [];
        if (!empty($l['priv'])) return bcjfin_out(['title' => '💳 New coaching payment', 'text' => 'Open BCJ Finance to check and approve.', 'fresh' => ($l['t'] ?? 0) > time() - 900]);
        return bcjfin_out(['title' => $l['title'] ?? 'BCJ Finance', 'text' => $l['text'] ?? 'New payment to check', 'fresh' => ($l['t'] ?? 0) > time() - 900]);
    }]);
});

// serve the app shell, manifest, icon and service worker under /finance/
add_action('init', function () {
    $path = trim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
    if (strpos($path, 'finance') !== 0) return;
    $sub = substr($path, 7);
    if ($sub === '/sw.js') { header('Content-Type: application/javascript'); header('Service-Worker-Allowed: /finance/'); header('Cache-Control: no-cache'); echo bcjfin_sw(); exit; }
    if ($sub === '/manifest.json') { header('Content-Type: application/manifest+json'); echo wp_json_encode(['name' => 'BCJ Finance', 'short_name' => 'BCJ Finance', 'start_url' => '/finance/', 'scope' => '/finance/', 'display' => 'standalone', 'background_color' => '#000000', 'theme_color' => '#000000',
        'icons' => [['src' => 'https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-300x300.png', 'sizes' => '300x300', 'type' => 'image/png', 'purpose' => 'any'], ['src' => 'https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-1024x1024.png', 'sizes' => '1024x1024', 'type' => 'image/png', 'purpose' => 'any'], ['src' => 'https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-1024x1024.png', 'sizes' => '1024x1024', 'type' => 'image/png', 'purpose' => 'maskable']]]); exit; }
    if ($sub === '/icon.svg') { header('Content-Type: image/svg+xml'); header('Cache-Control: public, max-age=86400'); echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512"><rect width="512" height="512" rx="96" fill="#141414"/><circle cx="256" cy="256" r="150" fill="#C8283F"/><text x="256" y="318" font-family="Arial Black,Arial" font-weight="900" font-size="190" text-anchor="middle" fill="#fff">&#8369;</text></svg>'; exit; }
    if ($sub === '' || $sub === '/') { nocache_headers(); header('Content-Type: text/html; charset=utf-8'); echo bcjfin_shell(); exit; }
}, 1);
function bcjfin_sw() { return <<<'JS'
self.addEventListener('install',e=>self.skipWaiting());
self.addEventListener('activate',e=>e.waitUntil(self.clients.claim()));
self.addEventListener('push',e=>{e.waitUntil(fetch('/wp-json/bcj-fin/v1/peek',{cache:'no-store'}).then(r=>r.json()).catch(()=>({title:'BCJ Finance',text:'New payment to check'})).then(d=>self.registration.showNotification(d.title||'BCJ Finance',{body:d.text||'New payment to check',icon:'https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-300x300.png',badge:'https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-300x300.png',tag:'bcjfin',renotify:true,requireInteraction:true,data:{url:'/finance/'}})));});
self.addEventListener('notificationclick',e=>{e.notification.close();e.waitUntil(self.clients.matchAll({type:'window'}).then(L=>{for(const c of L){if(c.url.indexOf('/finance/')>=0){c.focus();c.postMessage('refresh');return;}}return self.clients.openWindow('/finance/');}));});
JS; }
function bcjfin_shell() {
    $nonce = current_user_can('manage_options') ? wp_create_nonce('wp_rest') : '';
    $html = bcjfin_html();
    return str_replace(['{{NONCE}}', '{{REST}}'], [esc_attr($nonce), esc_url_raw(rest_url('bcj-fin/v1/'))], $html ?: 'BCJ Finance: app file missing');
}
function bcjfin_html() { return <<<'BCJFINAPP'
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>BCJ Finance</title><meta name="theme-color" content="#000000"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Anton&family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet"><link rel="manifest" href="/finance/manifest.json"><link rel="icon" href="https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-300x300.png"><link rel="apple-touch-icon" href="https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-300x300.png"><meta name="apple-mobile-web-app-capable" content="yes"><meta name="apple-mobile-web-app-status-bar-style" content="black"><meta name="apple-mobile-web-app-title" content="BCJ Finance">
<meta name="robots" content="noindex,nofollow">
<style>
:root{--k:#141414;--r:#921D2D;--rd:#C8283F;--rl:#E04A5F;--g:#E5B84B;--bg:#F6F5F4;--card:#fff;--line:#E7E4E2;--mut:#6B6B6B;--ok:#2F6B3F;--shadow:0 1px 2px rgba(0,0,0,.05),0 4px 14px rgba(0,0,0,.04)}
*{box-sizing:border-box}body{margin:0;font-family:Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:var(--bg);color:var(--k);font-size:15px;-webkit-tap-highlight-color:transparent}
header{position:sticky;top:0;z-index:5;background:#000;color:#fff;border-bottom:1px solid #1d1d1d;padding:calc(10px + env(safe-area-inset-top)) 16px 10px;display:flex;align-items:center;gap:10px}header .hl{height:26px;width:auto;flex:none}header .gr{flex:1;min-width:0}header .ht{font:400 19px/1.1 Anton,Inter,sans-serif;letter-spacing:.6px;text-transform:uppercase;margin:0}header .ht span{color:#C8283F}
header small{color:#bbb;font-size:12px}header #upd{color:#888;font-size:11px;text-align:right}
main{max-width:720px;margin:0 auto;padding:14px 14px calc(90px + env(safe-area-inset-bottom))}
nav{position:fixed;bottom:0;left:0;right:0;background:#000;border-top:1px solid #1d1d1d;display:flex;padding-bottom:env(safe-area-inset-bottom);z-index:5}
nav button{flex:1;border:0;background:none;padding:10px 4px 8px;font:inherit;font-size:12px;color:#9a9a9a;cursor:pointer;position:relative}nav button i{display:block;font-style:normal;font-size:20px}
nav button.on{color:#fff;font-weight:700;box-shadow:inset 0 2px 0 var(--rd)}nav button.on i{filter:none}nav .bd{position:absolute;top:4px;left:55%;background:var(--rd);color:#fff;border-radius:9px;font-size:11px;padding:0 6px;font-weight:800}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:14px;margin:0 0 12px;box-shadow:var(--shadow)}
.pend{border:2px solid var(--r)}
.row{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}
.amt{font-weight:800;font-size:20px;white-space:nowrap}.mut{color:var(--mut);font-size:13px;line-height:1.45}
.tag{display:inline-block;font-size:11px;font-weight:800;padding:2px 7px;border-radius:6px;background:#FBF0D6;color:#6b4a00;margin-left:6px}
.warn{color:#B00020;font-weight:800}
.btns{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.btn{border:0;border-radius:11px;padding:12px 16px;font:inherit;font-weight:800;cursor:pointer;text-decoration:none;display:inline-block;text-align:center}
.b-ok{background:var(--ok);color:#fff;flex:1}.b-no{background:#eee;color:#B00020}.b-dk{background:var(--k);color:#fff}.b-r{background:var(--r);color:#fff}
h1{font:400 24px/1.1 Anton,Inter,sans-serif;letter-spacing:.5px;text-transform:uppercase;margin:6px 0 12px}h2{font-size:16px;margin:16px 0 8px}
.kpis{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px}.kpi{background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px;box-shadow:var(--shadow)}.kpi b{display:block;font-size:22px}.kpi span{font-size:12px;color:var(--mut)}
.bars{display:flex;flex-direction:column;gap:8px}.bar{display:grid;grid-template-columns:62px 1fr 92px;gap:8px;align-items:center;font-size:13px}.bar .tr{height:16px;border-radius:5px;background:#EEE;overflow:hidden;display:flex}.bar .tr i{display:block;height:100%}
.lg{display:flex;gap:12px;flex-wrap:wrap;font-size:12px;color:var(--mut);margin:6px 0}.lg i{display:inline-block;width:10px;height:10px;border-radius:3px;margin-right:4px;vertical-align:-1px}
input,select{width:100%;padding:12px;border:1px solid #CFCAC0;border-radius:10px;font:inherit;font-size:16px;background:#fff;color:var(--k)}label{display:block;font-weight:700;font-size:13px;margin:10px 0 5px}
.ctr{max-width:400px;margin:40px auto}.logo{width:76px;height:76px;margin:0 auto 12px;display:block;border-radius:18px}.ctr h1{text-align:center}
.err{color:#B00020;font-size:13px;margin-top:8px}.ok{color:var(--ok);font-size:13px;margin-top:8px}
table{width:100%;border-collapse:collapse;font-size:13px}td,th{padding:8px 6px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}th{font-size:12px;color:var(--mut)}td.n{text-align:right;white-space:nowrap;font-weight:700}
.seg{display:flex;background:#E9E5DD;border-radius:10px;padding:3px;margin-bottom:12px}.seg button{flex:1;border:0;background:none;padding:8px;border-radius:8px;font:inherit;font-size:13px;cursor:pointer}.seg button.on{background:#fff;font-weight:700}
.empty{text-align:center;color:var(--mut);padding:30px 10px}
#inst{position:fixed;left:10px;right:10px;bottom:calc(76px + env(safe-area-inset-bottom));z-index:60;background:#111;color:#fff;border-radius:16px;padding:14px 14px 12px;box-shadow:0 10px 30px rgba(0,0,0,.35);font-size:14px;line-height:1.4;animation:iu .25s ease}
#inst b{font-size:15px}#inst .r{display:flex;gap:8px;margin-top:10px}#inst button{flex:1;border:0;border-radius:10px;padding:10px;font-weight:700;font-size:14px}
#inst .y{background:#C8283F;color:#fff}#inst .n{background:#333;color:#ddd}#inst .x{position:absolute;top:6px;right:10px;background:none;color:#aaa;font-size:20px;padding:0;flex:none}
@keyframes iu{from{transform:translateY(20px);opacity:0}to{transform:none;opacity:1}}
</style></head><body>
<div id="app"></div>
<script>
(function(){
var REST='{{REST}}',WPN='{{NONCE}}',TK=localStorage.getItem('bcjfin_t')||'',ME=null,D=null,TAB='ap',VAPID='',busy=false,period='month';
var C={coaching:'#C8283F',renewal:'#921D2D',reservation:'#E5B84B',ebook:'#3b7dd8'},LBL={coaching:'New coaching',renewal:'Renewals',reservation:'Reservations',ebook:'Ebooks'};
var app=document.getElementById('app');
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];});}
function peso(n){return '₱'+Math.round(Number(n||0)).toLocaleString('en-US');}
function api(path,body,method){var h={'Content-Type':'application/json'};if(TK)h['X-Fin-Token']=TK;if(WPN)h['X-WP-Nonce']=WPN;
 return fetch(REST+path,{method:method||(body?'POST':'GET'),headers:h,credentials:'same-origin',body:body?JSON.stringify(body):undefined}).then(function(r){return r.json().then(function(j){if(r.status===401&&j&&j.error==='login'){TK='';localStorage.removeItem('bcjfin_t');ME=null;boot();}return j;});});}
function shell(inner){var pc=D&&D.pending?D.pending.length:0;
 app.innerHTML='<header><img class="hl" src="https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-hor-dark-337x83-C8283F.webp" alt="Body by Coach Jeff" width="106" height="26"><div class="gr"><h2 class="ht">BCJ <span>Finance</span></h2><small>'+esc(ME.name)+' · '+(ME.role==='owner'?'Owner':'Partner')+'</small></div><small id="upd"></small></header><main id="mn">'+inner+'</main>'+
 '<nav>'+(ME.role==='owner'?[['ap','✅','Approvals'],['rp','📊','Reports'],['ad','📣','Ads'],['lg','🧾','Log'],['st','⚙️','Settings']]:[['ap','✅','Approvals'],['ad','📣','Meta Ads'],['st','⚙️','Settings']]).map(function(t){return '<button data-t="'+t[0]+'" class="'+(TAB===t[0]?'on':'')+'"><i>'+t[1]+'</i>'+t[2]+(t[0]==='ap'&&pc?'<span class="bd">'+pc+'</span>':'')+'</button>';}).join('')+'</nav>';
 [].forEach.call(app.querySelectorAll('nav button'),function(b){b.onclick=function(){TAB=b.getAttribute('data-t');render();window.scrollTo(0,0);};});}
// ---------- auth screens ----------
function setupScreen(){app.innerHTML='<div class="ctr card"><img class="logo" src="https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-300x300.png" alt="BCJ"><h1>Set up BCJ Finance</h1><p class="mut">Create the owner login. This is separate from WordPress. You can invite your partner after.</p>'+
 '<label>Your name</label><input id="nm" value="Jeff"><label>Username</label><input id="u" autocapitalize="off" autocomplete="username"><label>Password (10+ characters)</label><input id="p" type="password" autocomplete="new-password"><button class="btn b-r" style="width:100%;margin-top:14px" id="go">Create owner login</button><div id="m"></div></div>';
 document.getElementById('go').onclick=function(){api('setup',{name:val('nm'),u:val('u'),p:val('p')}).then(function(r){if(r.ok){WPN='';loginScreen('Owner login created. Log in now.');}else msg(r.error||'Could not set up');});};}
function loginScreen(note){app.innerHTML='<div class="ctr card"><img class="logo" src="https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-300x300.png" alt="BCJ"><h1>BCJ Finance</h1>'+(note?'<p class="ok">'+esc(note)+'</p>':'')+
 '<form id="f"><label>Username</label><input id="u" autocapitalize="off" autocomplete="username"><label>Password</label><input id="p" type="password" autocomplete="current-password"><button class="btn b-r" style="width:100%;margin-top:14px">Log in</button></form><div id="m"></div></div>';
 document.getElementById('f').onsubmit=function(e){e.preventDefault();api('login',{u:val('u'),p:val('p')}).then(function(r){if(r.ok){TK=r.token;localStorage.setItem('bcjfin_t',TK);ME=r.me;load(true);}else msg(r.error||'Login failed');});};}
function acceptScreen(u,code){app.innerHTML='<div class="ctr card"><img class="logo" src="https://bodybycoachjeff.com/wp-content/uploads/2026/11/logo-square-dark-bg-narrow-2000-C8283F-300x300.png" alt="BCJ"><h1>Welcome to BCJ Finance</h1><p class="mut">Username: <b>'+esc(u)+'</b>. Choose your password (10+ characters).</p><label>New password</label><input id="p" type="password" autocomplete="new-password"><label>Repeat password</label><input id="p2" type="password" autocomplete="new-password"><button class="btn b-r" style="width:100%;margin-top:14px" id="go">Save password</button><div id="m"></div></div>';
 document.getElementById('go').onclick=function(){if(val('p')!==val('p2'))return msg('Passwords don’t match');api('accept',{u:u,code:code,p:val('p')}).then(function(r){if(r.ok){history.replaceState(null,'','/finance/');loginScreen('Password saved. Log in now.');}else msg(r.error);});};}
function val(id){return (document.getElementById(id)||{}).value||'';}
function msg(t,ok){var m=document.getElementById('m');if(m)m.innerHTML='<p class="'+(ok?'ok':'err')+'">'+esc(t)+'</p>';}
// ---------- data ----------
function load(first){if(busy)return;busy=true;api('data').then(function(d){busy=false;if(d&&d.ok){D=d;var u=document.getElementById('upd');render();u=document.getElementById('upd');if(u)u.textContent='Updated '+new Date().toLocaleTimeString([], {hour:'numeric',minute:'2-digit'});}else if(first){render();}}).catch(function(){busy=false;if(first)render();});}
function render(){if(!ME)return;if(window.bcjfinInstall&&!window._bi){window._bi=1;bcjfinInstall();}if(ME.role!=='owner'&&(TAB==='rp'||TAB==='lg'))TAB='ad';var f={ap:approvals,rp:reports,ad:adsTab,lg:ledger,st:settings}[TAB];shell(D?f():'<div class="empty">Loading…</div>');if(TAB==='st')wireSettings();if(TAB==='ap')wireAp();if(TAB==='lg')wireLog();if(TAB==='ad')wireAdCsv();}
function card(x,p){var diff=Number(x.paid||0)-Number(x.expected||0);var src=x.src==='coaching'?'Coaching':/^coach/.test(x.product)?'Coaching (pay first)':x.product==='reserve'?'Reservation':'Ebook';
 return '<div class="card'+(p?' pend':'')+'"><div class="row"><div><b>'+esc(x.name)+'</b><span class="tag">'+src+'</span><div class="mut">'+esc(x.label)+'</div></div><div class="amt">'+peso(x.paid||x.expected)+'</div></div>'+
 '<div class="mut" style="margin-top:6px">Expected '+peso(x.expected)+(x.paid&&diff?' <span class="warn">⚠️ '+(diff>0?'+':'')+diff+'</span>':(x.paid?' ✓':''))+' · '+esc(x.method||'')+(x.ref?'<br>Ref no: <b>'+esc(x.ref)+'</b>':'')+'<br>'+esc(x.email||'')+(x.wa?' · '+esc(x.wa):'')+(x.start?' · start '+esc(x.start):'')+'<br>'+esc(x.id)+' · '+esc(x.created||'')+(p?'':' · <b>'+esc(x.status)+'</b> '+esc(x.decided||''))+'</div>'+
 '<div class="btns">'+(x.receipt?'<a class="btn b-dk" href="'+esc(x.receipt)+'" target="_blank" rel="noopener">View receipt</a>':'')+(p?'<button class="btn b-ok" data-a="ok" data-id="'+esc(x.id)+'">✅ Approve</button><button class="btn b-no" data-a="no" data-id="'+esc(x.id)+'">❌ Reject</button>':'')+'</div></div>';}
function approvals(){var P=D.pending||[],R=D.recent||[];return '<h1>Payments to check'+(P.length?' ('+P.length+')':'')+'</h1>'+(P.length?P.map(function(x){return card(x,true);}).join(''):'<div class="card empty">🎉 Nothing waiting. You’ll get a notification the moment someone pays.</div>')+
 (R.length?'<h2>Recent decisions</h2>'+R.slice(0,15).map(function(x){return card(x,false);}).join(''):'');}
function wireAp(){[].forEach.call(app.querySelectorAll('[data-a]'),function(b){b.onclick=function(){var a=b.getAttribute('data-a'),id=b.getAttribute('data-id');
 if(a==='no'&&!confirm('Reject '+id+'? The buyer gets an email asking for a clearer screenshot.'))return;
 b.disabled=true;b.textContent=a==='ok'?'Approving…':'Rejecting…';api('decide',{id:id,action:a}).then(function(r){b.textContent=(r&&r.ok)?(a==='ok'?'Approved ✓':'Rejected'):'Failed, retry';setTimeout(function(){load();},1500);}).catch(function(){b.disabled=false;b.textContent='Retry';});};});}
// ---------- reports ----------
function L(){return (D.ledger||[]).slice().sort(function(a,b){return String(b.date).localeCompare(String(a.date));});}
function ph(d){return new Date(new Date(d).toLocaleString('en-US',{timeZone:'Asia/Manila'}));}
function today(){var n=ph(Date.now());return n.getFullYear()+'-'+String(n.getMonth()+1).padStart(2,'0')+'-'+String(n.getDate()).padStart(2,'0');}
function weekStart(){var n=ph(Date.now());var d=(n.getDay()+6)%7;n.setDate(n.getDate()-d);return n.getFullYear()+'-'+String(n.getMonth()+1).padStart(2,'0')+'-'+String(n.getDate()).padStart(2,'0');}
function sum(a){return a.reduce(function(s,x){return s+Number(x.amount||0);},0);}
function reports(){var A=L(),t=today(),w=weekStart(),m=t.slice(0,7);
 var tA=A.filter(function(x){return x.date.slice(0,10)===t;}),wA=A.filter(function(x){return x.date.slice(0,10)>=w;}),mA=A.filter(function(x){return x.date.slice(0,7)===m;});
 var months={};A.forEach(function(x){var k=x.date.slice(0,7);if(!/^\d{4}-\d{2}$/.test(k))return;months[k]=months[k]||{coaching:0,renewal:0,reservation:0,ebook:0};months[k][x.kind]=(months[k][x.kind]||0)+Number(x.amount||0);});
 var ks=Object.keys(months).sort().slice(-12),max=Math.max.apply(null,ks.map(function(k){return sum(Object.keys(months[k]).map(function(j){return {amount:months[k][j]};}));}).concat([1]));
 var bars=ks.map(function(k){var o=months[k],tot=0;Object.keys(o).forEach(function(j){tot+=o[j];});return '<div class="bar"><span>'+new Date(k+'-01T00:00').toLocaleDateString('en-US',{month:'short',year:'2-digit'})+'</span><span class="tr">'+['coaching','renewal','reservation','ebook'].map(function(j){return o[j]?'<i style="width:'+(o[j]/max*100)+'%;background:'+C[j]+'"></i>':'';}).join('')+'</span><b style="text-align:right">'+peso(tot)+'</b></div>';}).join('');
 var byKind=function(a){var o={};a.forEach(function(x){o[x.kind]=(o[x.kind]||0)+Number(x.amount||0);});return Object.keys(o).map(function(k){return '<div class="row mut"><span>'+LBL[k]+'</span><b>'+peso(o[k])+'</b></div>';}).join('');};
 var ren=A.filter(function(x){return x.kind==='renewal';}).slice(0,10);
 return '<h1>Reports</h1><div class="kpis"><div class="kpi"><span>Today</span><b>'+peso(sum(tA))+'</b><span>'+tA.length+' paid</span></div><div class="kpi"><span>This week</span><b>'+peso(sum(wA))+'</b><span>'+wA.length+' paid</span></div>'+
 '<div class="kpi"><span>This month</span><b>'+peso(sum(mA))+'</b><span>'+mA.length+' paid</span></div><div class="kpi"><span>Waiting / rejected</span><b>'+(D.pending||[]).length+' / '+(D.rejected||0)+'</b><span>'+peso(sum((D.pending||[]).map(function(x){return {amount:x.paid||x.expected};})))+' pending</span></div></div>'+
 '<div class="card"><b>This month by type</b>'+(byKind(mA)||'<div class="mut">No payments yet this month</div>')+'</div>'+
 '<div class="card"><b>Revenue by month</b><div class="lg">'+Object.keys(C).map(function(k){return '<span><i style="background:'+C[k]+'"></i>'+LBL[k]+'</span>';}).join('')+'</div><div class="bars">'+(bars||'<div class="mut">No data yet</div>')+'</div></div>'+
 '<div class="card"><b>Coaching renewals</b>'+(ren.length?'<table><tr><th>Date</th><th>Client</th><th></th></tr>'+ren.map(function(x){return '<tr><td>'+esc(x.date.slice(0,10))+'</td><td>'+esc(x.name)+' <span class="mut">'+esc(x.id)+'</span><br><span class="mut">'+esc(x.label)+'</span></td><td class="n">'+peso(x.amount)+'</td></tr>';}).join('')+'</table>':'<div class="mut">No renewals yet</div>')+'</div>'+
 '<p class="mut">Coaching and renewals come from the Payments tab; reservations, ebooks and pay-first coaching come from approved /pay payments. Test payments are excluded.</p>';}

// ---------- ads ----------
function adsTab(){var A=(D.ads&&D.ads.daily)||[],B=(D.ads&&D.ads.billing)||[];
 if(!A.length)return '<h1>Ad spend</h1><div class="card empty">⏳ Waiting for the first Meta sync.<br><br><span class="mut">Once the Meta ad account token is connected in n8n, ad spend, leads and billing charges sync here every morning at 6:17 AM.</span></div>';
 var t=today(),w=weekStart(),m=t.slice(0,7),sp=function(a){return a.reduce(function(s,x){return s+x.spend;},0);},ld=function(a){return a.reduce(function(s,x){return s+x.leads+x.messages;},0);};
 var tA=A.filter(function(x){return x.date===t;}),wA=A.filter(function(x){return x.date>=w;}),mA=A.filter(function(x){return x.date.slice(0,7)===m;});
 var rev=sum(L().filter(function(x){return x.date.slice(0,7)===m;})),ms=sp(mA),ml=ld(mA);
 var camp={};mA.forEach(function(x){var c=camp[x.campaign]=camp[x.campaign]||{s:0,l:0};c.s+=x.spend;c.l+=x.leads+x.messages;});
 var months={};A.forEach(function(x){var k=x.date.slice(0,7);months[k]=(months[k]||0)+x.spend;});var ks=Object.keys(months).sort().slice(-12),mx=Math.max.apply(null,ks.map(function(k){return months[k];}).concat([1]));
 var revM={};L().forEach(function(x){var k=x.date.slice(0,7);revM[k]=(revM[k]||0)+Number(x.amount||0);});
 return '<h1>Ad spend</h1><div class="kpis"><div class="kpi"><span>Today</span><b>'+peso(sp(tA))+'</b><span>'+ld(tA)+' leads/chats</span></div><div class="kpi"><span>This week</span><b>'+peso(sp(wA))+'</b><span>'+ld(wA)+' leads/chats</span></div>'+
 '<div class="kpi"><span>This month</span><b>'+peso(ms)+'</b><span>'+(ml?peso(ms/ml)+' per lead/chat':'no leads yet')+'</span></div><div class="kpi"><span>Revenue ÷ ad spend (month)</span><b>'+(ms?(rev/ms).toFixed(1)+'×':'–')+'</b><span>'+peso(rev)+' revenue</span></div></div>'+
 '<div class="card"><b>This month by campaign</b><table><tr><th>Campaign</th><th>Leads</th><th></th></tr>'+Object.keys(camp).sort(function(a,b){return camp[b].s-camp[a].s;}).map(function(k){var c=camp[k];return '<tr><td>'+esc(k)+'<br><span class="mut">'+(c.l?peso(c.s/c.l)+' each':'')+'</span></td><td>'+c.l+'</td><td class="n">'+peso(c.s)+'</td></tr>';}).join('')+'</table></div>'+
 '<div class="card"><b>Spend vs revenue by month</b><div class="lg"><span><i style="background:#3b7dd8"></i>Ad spend</span><span><i style="background:#1d7a3a"></i>'+(ME.role==='owner'?'Revenue':'Reservations + ebooks')+'</span></div><div class="bars">'+ks.map(function(k){var r=revM[k]||0,M=Math.max(mx,r||0);return '<div class="bar"><span>'+new Date(k+'-01T00:00').toLocaleDateString('en-US',{month:'short',year:'2-digit'})+'</span><span><span class="tr" style="height:9px;margin-bottom:2px"><i style="width:'+(months[k]/M*100)+'%;background:#3b7dd8"></i></span><span class="tr" style="height:9px"><i style="width:'+(r/M*100)+'%;background:#1d7a3a"></i></span></span><b style="text-align:right;font-size:12px">'+peso(months[k])+'<br><span style="color:#1d7a3a">'+peso(r)+'</span></b></div>';}).join('')+'</div></div>'+
 '<div class="card"><div class="row"><b>Card charges from Meta</b>'+(B.length?'<button class="btn b-dk" id="bcsv" style="padding:6px 10px">⬇ CSV</button>':'')+'</div>'+(B.length?'<div class="mut" style="margin:4px 0 6px">Match these to the card statement by date and amount. '+esc(B[0].payment||'')+'</div><table><tr><th>Date (PH)</th><th>Meta transaction ID</th><th></th></tr>'+B.map(function(x){return '<tr><td>'+esc(x.date)+'</td><td class="mut" style="word-break:break-all;font-size:11px">'+esc(x.id||'')+'</td><td class="n">'+peso(x.amount)+'</td></tr>';}).join('')+'</table>'+'<div class="mut" style="margin-top:6px">This month: <b>'+peso(B.filter(function(x){return x.date.slice(0,7)===m;}).reduce(function(s,x){return s+x.amount;},0))+'</b> · All time: <b>'+peso(B.reduce(function(s,x){return s+x.amount;},0))+'</b></div>':'<div class="mut">No card charges yet.</div>')+'</div>'+
 '<p class="mut">Synced from Meta every morning'+(D.ads.synced?' (last: '+esc(D.ads.synced)+')':'')+'. Leads/chats = Meta leads + Messenger/WhatsApp conversations started.</p>';}

// ---------- install popup ----------
(function(){var DP=null,K='bcjfin_inst_skip';
 function inst(){return (window.matchMedia&&matchMedia('(display-mode: standalone)').matches)||navigator.standalone===true;}
 function skipped(){try{return Date.now()<Number(localStorage.getItem(K)||0);}catch(e){return false;}}
 function skip(d){try{localStorage.setItem(K,String(Date.now()+d*864e5));}catch(e){}}
 var ios=/iphone|ipad|ipod/i.test(navigator.userAgent)||(navigator.platform==='MacIntel'&&navigator.maxTouchPoints>1);
 function show(){if(inst()||skipped()||document.getElementById('inst')||!ME)return;
  var d=document.createElement('div');d.id='inst';
  d.innerHTML='<button class="x" aria-label="Close">×</button><b>📲 Install BCJ Finance</b><div style="margin-top:4px;color:#ccc">'+(ios&&!DP?'Tap <b>Share</b> <span style="font-size:16px">⎋</span> at the bottom of Safari, then <b>Add to Home Screen</b>. You need this on iPhone to get payment notifications.':'Open it like an app and get a notification the moment someone pays.')+'</div><div class="r">'+(DP?'<button class="y">Install</button>':'')+'<button class="n">'+(DP?'Later':'Got it')+'</button></div>';
  document.body.appendChild(d);
  d.querySelector('.x').onclick=function(){skip(7);d.remove();};
  d.querySelector('.n').onclick=function(){skip(DP?3:7);d.remove();};
  var y=d.querySelector('.y');if(y)y.onclick=function(){DP.prompt();DP.userChoice.then(function(c){if(c&&c.outcome==='accepted')skip(3650);else skip(3);DP=null;d.remove();});};}
 window.addEventListener('beforeinstallprompt',function(e){e.preventDefault();DP=e;setTimeout(show,1500);});
 window.addEventListener('appinstalled',function(){skip(3650);var d=document.getElementById('inst');if(d)d.remove();});
 window.bcjfinInstall=function(){setTimeout(show,2500);};
})();
function wireAdCsv(){var b=document.getElementById('bcsv');if(!b)return;b.onclick=function(){var B=(D.ads&&D.ads.billing)||[];var q=function(v){return '"'+String(v==null?'':v).replace(/"/g,'""')+'"';};
 var csv=[['Date (PH)','Meta transaction ID','Amount (PHP)','Card','Status']].concat(B.map(function(x){return [x.date,x.id,x.amount,x.payment,x.status];})).map(function(r){return r.map(q).join(',');}).join('\n');
 var u=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));var l=document.createElement('a');l.href=u;l.download='bcj-meta-card-charges-'+today()+'.csv';document.body.appendChild(l);l.click();setTimeout(function(){URL.revokeObjectURL(u);l.remove();},500);};}
// ---------- ledger ----------
function ledger(){return '<h1>Payment log</h1><input id="q" placeholder="Search name, client ID, ref no…" style="margin-bottom:10px"><div class="row" style="margin-bottom:10px"><span class="mut" id="cnt"></span><button class="btn b-dk" id="csv" style="padding:8px 12px">⬇ CSV</button></div><div class="card" style="padding:4px 10px"><table id="tb"></table></div>';}
function wireLog(){var q=document.getElementById('q'),tb=document.getElementById('tb');function draw(){var s=q.value.toLowerCase(),A=L().filter(function(x){return !s||JSON.stringify(x).toLowerCase().indexOf(s)>=0;});
 document.getElementById('cnt').textContent=A.length+' payments · '+peso(sum(A));
 tb.innerHTML='<tr><th>Date</th><th>Payment</th><th></th></tr>'+A.map(function(x){return '<tr><td>'+esc(x.date.slice(0,10))+'</td><td><b>'+esc(x.name)+'</b> <span class="mut">'+esc(x.id)+'</span><br><span class="mut">'+esc(LBL[x.kind]||x.kind)+' · '+esc(x.label)+' · '+esc(x.method||'')+(x.ref?' · '+esc(x.ref):'')+'</span>'+(x.receipt?' <a href="'+esc(x.receipt)+'" target="_blank" rel="noopener">receipt</a>':'')+'</td><td class="n">'+peso(x.amount)+'</td></tr>';}).join('');
 document.getElementById('csv').onclick=function(){var rows=[['date','type','name','id','label','amount','method','ref','approved_by','receipt']].concat(A.map(function(x){return [x.date,x.kind,x.name,x.id,x.label,x.amount,x.method,x.ref,x.by,x.receipt];}));
  var csv=rows.map(function(r){return r.map(function(c){return '"'+String(c==null?'':c).replace(/"/g,'""')+'"';}).join(',');}).join('\n');var a=document.createElement('a');a.href=URL.createObjectURL(new Blob([csv],{type:'text/csv'}));a.download='bcj-payments-'+today()+'.csv';a.click();};}
 q.oninput=draw;draw();}
// ---------- settings ----------
function settings(){var push=('Notification' in window)?Notification.permission:'unsupported';
 return '<h1>Settings</h1><div class="card"><b>🔔 Payment notifications</b><p class="mut">Get a notification on this device every time someone uploads a payment. Turn it on for each phone or computer you use. On iPhone, add this app to your Home Screen first (Share › Add to Home Screen), then open it from there.</p><button class="btn b-r" id="pn">'+(push==='granted'?'Re-register this device':'Turn on notifications')+'</button><div id="pm" class="mut" style="margin-top:8px">Status: '+esc(push)+'</div></div>'+
 '<div class="card"><b>🔑 Change password</b><label>Current password</label><input id="o" type="password" autocomplete="current-password"><label>New password (10+ characters)</label><input id="n" type="password" autocomplete="new-password"><button class="btn b-dk" id="cp" style="margin-top:12px">Save password</button><div id="m"></div></div>'+
 (ME.role==='owner'?'<div class="card"><b>👥 Logins</b><div id="ul" class="mut">Loading…</div><label>Invite a partner: name</label><input id="in"><label>Username</label><input id="iu" autocapitalize="off"><button class="btn b-dk" id="inv" style="margin-top:12px">Create invite link</button><div id="im"></div></div>':'')+
 '<button class="btn b-no" id="lo" style="width:100%">Log out</button>';}
function wireSettings(){document.getElementById('pn').onclick=enablePush;
 document.getElementById('cp').onclick=function(){api('password',{old:val('o'),new:val('n')}).then(function(r){msg(r.ok?'Password changed':(r.error||'Failed'),r.ok);});};
 document.getElementById('lo').onclick=function(){api('logout',{}).then(function(){TK='';localStorage.removeItem('bcjfin_t');ME=null;D=null;loginScreen();});};
 if(ME.role==='owner'){var ul=document.getElementById('ul');var drawU=function(r){if(!r||!r.ok)return;ul.innerHTML=r.users.map(function(x){return '<div class="row" style="padding:6px 0;border-bottom:1px solid var(--line)"><span><b>'+esc(x.name)+'</b> · '+esc(x.u)+' · '+esc(x.role)+(x.pending?' · <i>invite not accepted yet</i>':'')+'</span>'+(x.u!==ME.u?'<button class="btn b-no" style="padding:6px 10px" data-rm="'+esc(x.u)+'">Remove</button>':'')+'</div>';}).join('');
  [].forEach.call(ul.querySelectorAll('[data-rm]'),function(b){b.onclick=function(){if(confirm('Remove '+b.getAttribute('data-rm')+'? They are logged out everywhere.'))api('users',{op:'remove',u:b.getAttribute('data-rm')}).then(drawU);};});};
  api('users',{op:'list'}).then(drawU);
  document.getElementById('inv').onclick=function(){api('users',{op:'invite',name:val('in'),u:val('iu')}).then(function(r){var im=document.getElementById('im');if(!r.ok){im.innerHTML='<p class="err">'+esc(r.error)+'</p>';return;}
   im.innerHTML='<p class="ok">Send this link to your partner (works once, 3 days):</p><input readonly value="'+esc(r.link)+'" onclick="this.select()"><button class="btn b-dk" style="margin-top:8px" id="cpl">Copy link</button>';document.getElementById('cpl').onclick=function(){try{navigator.clipboard.writeText(r.link);this.textContent='Copied ✓';}catch(e){}};api('users',{op:'list'}).then(drawU);});};}}
function b64(s){var p='='.repeat((4-s.length%4)%4),b=(s+p).replace(/-/g,'+').replace(/_/g,'/'),r=atob(b),o=new Uint8Array(r.length);for(var i=0;i<r.length;i++)o[i]=r.charCodeAt(i);return o;}
function enablePush(){var pm=document.getElementById('pm');if(!('serviceWorker' in navigator)||!('PushManager' in window)){pm.textContent='This browser can’t receive notifications. On iPhone, add to Home Screen first.';return;}
 Notification.requestPermission().then(function(p){if(p!=='granted'){pm.textContent='Notifications are blocked. Allow them in your browser settings.';return;}
  navigator.serviceWorker.register('/finance/sw.js',{scope:'/finance/'}).then(function(reg){return navigator.serviceWorker.ready.then(function(){return reg.pushManager.getSubscription().then(function(s){return s||reg.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:b64(VAPID)});});});})
  .then(function(sub){return api('sub',{sub:sub.toJSON()});}).then(function(r){pm.textContent=r&&r.ok?'✅ This device will get payment notifications ('+r.devices+' device(s) total).':'Could not save this device.';}).catch(function(e){pm.textContent='Error: '+e.message;});});}
// ---------- boot ----------
function boot(){var q=new URLSearchParams(location.search);
 if('serviceWorker' in navigator){navigator.serviceWorker.register('/finance/sw.js',{scope:'/finance/'}).catch(function(){});navigator.serviceWorker.addEventListener('message',function(e){if(e.data==='refresh')load();});}
 if(q.get('invite')&&q.get('u'))return acceptScreen(q.get('u'),q.get('invite'));
 api('state').then(function(s){VAPID=s.vapid||'';if(s.setup){if(WPN)return setupScreen();app.innerHTML='<div class="ctr card"><h1>BCJ Finance</h1><p class="mut">Not set up yet. Open this page while logged in to WordPress as admin to create the owner login.</p></div>';return;}
  if(s.me){ME=s.me;shell('<div class="empty">Loading…</div>');load(true);}else loginScreen();});}
boot();setInterval(function(){if(ME&&document.visibilityState==='visible')load();},30000);
document.addEventListener('visibilitychange',function(){if(ME&&document.visibilityState==='visible')load();});
})();
</script></body></html>

BCJFINAPP;
}
