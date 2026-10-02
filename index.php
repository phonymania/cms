<?php
declare(strict_types=1);
/* 置いてアクセスするだけのブログCMS (PHP 8+ / pdo_sqlite)  初回アクセスで初期設定画面が出ます */
date_default_timezone_set('Asia/Tokyo');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
ini_set('display_errors', '0');
ini_set('session.use_strict_mode', '1');
session_name($https ? '__Host-sid' : 'sid');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

$nonce = base64_encode(random_bytes(16));
header_remove('X-Powered-By');
header("Content-Security-Policy: default-src 'none'; style-src 'nonce-$nonce'; img-src 'self' data:; form-action 'self'; base-uri 'self'; frame-ancestors 'none'");
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
if ($https) header('Strict-Transport-Security: max-age=31536000');

/* ---------- DB (data/ は自動作成・外部アクセス拒否) ---------- */
$dir = __DIR__ . '/data';
if (!is_dir($dir)) {
    mkdir($dir, 0750, true);
    file_put_contents("$dir/.htaccess", "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
    file_put_contents("$dir/index.html", '');
}
$file = glob("$dir/db_*.sqlite")[0] ?? "$dir/db_" . bin2hex(random_bytes(12)) . '.sqlite';
$db = new PDO("sqlite:$file", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
@chmod($file, 0640);
$db->exec("CREATE TABLE IF NOT EXISTS users(id INTEGER PRIMARY KEY, name TEXT UNIQUE, pass TEXT);
CREATE TABLE IF NOT EXISTS posts(id INTEGER PRIMARY KEY, title TEXT, body TEXT, status TEXT, created INTEGER, updated INTEGER);
CREATE TABLE IF NOT EXISTS meta(k TEXT PRIMARY KEY, v TEXT);
CREATE TABLE IF NOT EXISTS attempts(ip TEXT, t INTEGER);
CREATE TABLE IF NOT EXISTS comments(id INTEGER PRIMARY KEY, post_id INTEGER, name TEXT, body TEXT, status TEXT, created INTEGER, ip TEXT);");
try { $db->exec("ALTER TABLE posts ADD COLUMN tags TEXT DEFAULT ''"); } catch (Throwable) {}

foreach (['cat' => "''", 'tags' => "''", 'type' => "'post'"] as $c => $d) if (!in_array($c, array_column($db->query('PRAGMA table_info(posts)')->fetchAll(), 'name'))) $db->exec("ALTER TABLE posts ADD COLUMN $c TEXT DEFAULT $d");
$db->exec('CREATE TABLE IF NOT EXISTS comments(id INTEGER PRIMARY KEY, post INTEGER, name TEXT, body TEXT, status TEXT, created INTEGER)');
$M = array_column($db->query('SELECT k,v FROM meta')->fetchAll(), 'v', 'k');
$base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/';
$up = __DIR__ . '/uploads';
if (!is_dir($up)) { mkdir($up, 0755, true); file_put_contents("$up/.htaccess", "Options -ExecCGI -Indexes\n<FilesMatch \"\\.(?i:php|phtml|phar|html?)\$\">\nRequire all denied\n</FilesMatch>\n"); }
/* プラグイン: plugins/*.php を置くと自動読込。add_hook('post_html'|'sidebar'|'footer'|'css'|'route', fn) で改造可能 */
$H = [];
function add_hook(string $n, callable $f): void { global $H; $H[$n][] = $f; }
function hk(string $n, mixed $v, mixed ...$a): mixed { global $H; foreach ($H[$n] ?? [] as $f) $v = $f($v, ...$a); return $v; }
foreach (glob(__DIR__ . '/plugins/*.php') ?: [] as $p) require $p;

/* ---------- helpers ---------- */
function q(string $sql, array $p = []): PDOStatement { global $db; $s = $db->prepare($sql); $s->execute($p); return $s; }
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function go(string $to): never { header("Location: $to"); exit; }
function tok(): string { return '<input type="hidden" name="_t" value="' . h($_SESSION['csrf']) . '">'; }
function meta(string $k, string $d): string { return (string)(q('SELECT v FROM meta WHERE k=?', [$k])->fetchColumn() ?: $d); }
function tagl(string $t): string {
    $o = '';
    foreach (array_filter(explode(',', $t)) as $x) $o .= ' <a href="?tag=' . h(urlencode($x)) . '">#' . h($x) . '</a>';
    return $o ? '<p class="m">' . $o . '</p>' : '';
}
function normtags(string $s): string {
    $a = [];
    foreach (preg_split('/[,、\s]+/u', $s) as $x) {
        $x = mb_substr(trim($x), 0, 30);
        if ($x !== '' && !preg_match('/[,<>"\']/', $x)) $a[mb_strtolower($x)] = $x;
    }
    return $a ? ',' . implode(',', array_slice($a, 0, 10)) . ',' : '';
}
function cform(array $r): string {
    $o = '<section><h3>コメント</h3>';
    foreach (q("SELECT * FROM comments WHERE post_id=? AND status='ok' ORDER BY id", [$r['id']]) as $c)
        $o .= '<p><strong>' . h($c['name']) . '</strong> <span class="m">' . date('Y/m/d H:i', (int)$c['created']) . '</span><br>' . nl2br(h($c['body'])) . '</p>';
    if ($r['status'] !== 'published') return $o . '</section>';
    return $o . '<form method="post" action="?a=comment">' . tok() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><label>名前<input name="name" required maxlength="50"></label><label>コメント(承認後に表示されます)<textarea name="body" required maxlength="2000" class="c"></textarea></label><input name="website" class="hp" tabindex="-1" autocomplete="off"><button>送信</button></form></section>';
}
function inl(string $t): string {
    $t = preg_replace('/`([^`]+)`/', '<code>$1</code>', $t);
    $t = preg_replace('/!\[([^\]]*)\]\((uploads\/[a-f0-9]{32}\.(?:jpg|png|gif|webp))\)/', '<img src="$2" alt="$1" loading="lazy">', $t);
    $t = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $t);
    $t = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $t);
    $t = preg_replace('/!\[([^\]]*)\]\((uploads\/[a-f0-9]{16}\.(?:jpg|png|gif|webp))\)/', '<img src="$2" alt="$1" loading="lazy">', $t);
    return preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', fn($m) => '<a href="' . $m[2] . '" rel="noopener nofollow ugc">' . $m[1] . '</a>', $t);
}
function md(string $s): string { // 先に全エスケープ → 安全なタグだけ生成
    $s = h(str_replace(["\r\n", "\r"], "\n", $s));
    $o = [];
    foreach (preg_split('/\n{2,}/', trim($s)) as $b) {
        $b = trim($b);
        if ($b === '') continue;
        if (preg_match('/^(#{1,3}) (.+)$/s', $b, $m)) { $n = strlen($m[1]) + 1; $o[] = "<h$n>" . inl($m[2]) . "</h$n>"; }
        elseif (preg_match('/^(- .+(\n|$))+$/', $b)) {
            $o[] = '<ul>' . implode('', array_map(fn($l) => '<li>' . inl(substr($l, 2)) . '</li>', explode("\n", $b))) . '</ul>';
        } else $o[] = '<p>' . nl2br(inl($b)) . '</p>';
    }
    return implode("\n", $o);
}
function S(string $k, string $d = ''): string { global $M; return ($M[$k] ?? '') !== '' ? $M[$k] : $d; }
function L(string ...$v): array { return array_combine($v, $v); }
function sel(string $n, array $l, string $cur): string { $o = ''; foreach ($l as $k => $v) $o .= '<option value="' . h((string)$k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . h((string)$v) . '</option>'; return '<select name="' . h($n) . '">' . $o . '</select>'; }
function like(string $s): string { return '%' . addcslashes($s, '%_\\') . '%'; }
function U(string $t, string $v = ''): string {
    global $base; $p = S('pretty') === '1'; $e = rawurlencode($v);
    return match ($t) { 'post' => $p ? "{$base}post/$v" : "?a=post&amp;id=$v", 'cat' => $p ? "{$base}cat/$e" : "?cat=$e", default => $p ? "{$base}tag/$e" : "?tag=$e" };
}
function cards(array $rows): string {
    $b = '';
    foreach ($rows as $r) {
        $ex = mb_substr(preg_replace('/[#*`\[\]()>!-]/u', '', $r['body']), 0, 120);
        $b .= '<article><h2><a href="' . U('post', (string)$r['id']) . '">' . h($r['title']) . '</a></h2><p class="m">' . date('Y/m/d', (int)$r['created']) . ($r['cat'] ? ' · <a href="' . U('cat', $r['cat']) . '">' . h($r['cat']) . '</a>' : '') . '</p><p>' . h($ex) . '…</p></article>';
    }
    return $b ?: '<p>記事がありません。</p>';
}
function widgets(): string {
    $o = '';
    foreach (explode(',', S('sidebar', 'about,search,recent,cats,tags')) as $w) {
        $w = trim($w);
        if ($w === 'about') $o .= '<section><h3>About</h3>' . md(S('about', 'ようこそ')) . '</section>';
        elseif ($w === 'search') $o .= '<section><h3>検索</h3><form action="./" method="get"><input name="s" maxlength="50"><button>検索</button></form></section>';
        elseif ($w === 'recent') { $o .= '<section><h3>最近の記事</h3><ul>'; foreach (q("SELECT id,title FROM posts WHERE status='published' AND type='post' ORDER BY created DESC LIMIT 5") as $r) $o .= '<li><a href="' . U('post', (string)$r['id']) . '">' . h($r['title']) . '</a></li>'; $o .= '</ul></section>'; }
        elseif ($w === 'cats') { $o .= '<section><h3>カテゴリ</h3><ul>'; foreach (q("SELECT cat,COUNT(*) n FROM posts WHERE status='published' AND type='post' AND cat!='' GROUP BY cat") as $r) $o .= '<li><a href="' . U('cat', $r['cat']) . '">' . h($r['cat']) . "</a> ({$r['n']})</li>"; $o .= '</ul></section>'; }
        elseif ($w === 'tags') { $t = []; foreach (q("SELECT tags FROM posts WHERE status='published' AND tags!=''") as $r) foreach (array_filter(explode(',', $r['tags'])) as $x) $t[$x] = 1; $o .= '<section><h3>タグ</h3>'; foreach ($t as $k => $_) $o .= '<a class="tg" href="' . U('tag', (string)$k) . '">#' . h((string)$k) . '</a> '; $o .= '</section>'; }
    }
    return hk('sidebar', $o);
}
function page(string $title, string $body): never {
    global $nonce, $base, $authed;
    $T = ['light' => ['#fff', '#222', '#f5f5f5', '#ddd'], 'dark' => ['#16181d', '#e6e6e6', '#22252c', '#3a3f4a'], 'sepia' => ['#f4ecd8', '#433422', '#eadfc4', '#cdbd98']][S('theme', 'light')] ?? ['#fff', '#222', '#f5f5f5', '#ddd'];
    $F = ['sans' => 'system-ui,sans-serif', 'serif' => '"Hiragino Mincho ProN",Georgia,serif', 'mono' => 'ui-monospace,monospace'][S('font', 'sans')] ?? 'system-ui,sans-serif';
    $ac = preg_match('/^#[0-9a-f]{6}$/i', S('accent')) ? S('accent') : '#0a58ca';
    $w = max(480, min(1400, (int)S('width', '720')));
    $lay = S('layout', 'right'); $site = S('title', 'Blog');
    $adm = in_array($_GET['a'] ?? '', ['admin', 'edit', 'media', 'comments', 'settings', 'pw', 'login', 'setup'], true);
    $side = ($lay !== 'none' && !$adm) ? '<aside>' . widgets() . '</aside>' : '';
    $menu = '';
    foreach (preg_split('/\R/', S('menu')) as $l) { [$n, $u] = array_pad(explode('|', $l, 2), 2, ''); $u = trim($u); if (trim($n) !== '' && preg_match('~^(https?://|/|\?|#)~', $u)) $menu .= '<a href="' . h($u) . '">' . h(trim($n)) . '</a>'; }
    foreach (q("SELECT id,title FROM posts WHERE type='page' AND status='published' ORDER BY id") as $r) $menu .= '<a href="' . U('post', (string)$r['id']) . '">' . h($r['title']) . '</a>';
    $menu .= $authed ? '<a href="?a=admin">管理</a><form method="post" action="?a=logout" class="i">' . tok() . '<button>ログアウト</button></form>' : '<a href="?a=login">ログイン</a>';
    $hero = preg_match('~^uploads/[a-f0-9]{16}\.(jpg|png|gif|webp)$~', S('hero')) ? '<img class="hero" src="' . h(S('hero')) . '" alt="">' : '';
    $css = ':root{--bg:' . $T[0] . ';--fg:' . $T[1] . ';--c:' . $T[2] . ';--bd:' . $T[3] . ';--a:' . $ac . '}body{margin:0;background:var(--bg);color:var(--fg);font:16px/1.8 ' . $F . '}.in{max-width:' . ($w + ($side ? 280 : 0)) . 'px;margin:0 auto;padding:0 1rem}a{color:var(--a)}header{border-bottom:1px solid var(--bd);margin-bottom:2rem;padding:.6rem 0}header .t{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap}header nav a{margin-left:1rem}h1.s{margin:0}h1.s a{color:var(--fg);text-decoration:none}.m{color:#888;font-size:.85rem}.hero{width:100%;max-height:260px;object-fit:cover;margin-bottom:1rem}.g{display:grid;gap:2rem;grid-template-columns:' . ($side ? ($lay === 'left' ? '240px minmax(0,1fr)' : 'minmax(0,1fr) 240px') : 'minmax(0,1fr)') . '}' . ($lay === 'left' ? 'aside{order:-1}' : '') . 'aside section,.cm{background:var(--c);padding:.6rem 1rem;margin-bottom:1rem;border-radius:6px}aside ul{padding-left:1.2rem}.tg{display:inline-block;margin-right:.4rem}img{max-width:100%}input,textarea,select{width:100%;padding:.5rem;margin:.3rem 0 1rem;box-sizing:border-box;font:inherit;background:var(--bg);color:var(--fg);border:1px solid var(--bd)}textarea{min-height:300px}.sm{min-height:100px}.hp{position:absolute;left:-9999px;width:1px}button{padding:.4rem 1rem;cursor:pointer}.i{display:inline}.i button{border:0;background:none;color:var(--a);font:inherit}.e{color:#c62828}code{background:var(--c);padding:0 .3em}table{width:100%;border-collapse:collapse}td{padding:.4rem;border-bottom:1px solid var(--bd)}footer{border-top:1px solid var(--bd);margin-top:2rem;padding:1rem 0;color:#888}@media(max-width:760px){.g{grid-template-columns:1fr}aside{order:2}}' . str_replace('<', '', S('css'));
    echo '<!doctype html><html lang="ja"><head><meta charset="utf-8"><base href="' . h($base) . '"><meta name="viewport" content="width=device-width,initial-scale=1">' . ($adm ? '<meta name="robots" content="noindex">' : '') . '<title>' . h($title) . ' | ' . h($site) . '</title><link rel="alternate" type="application/rss+xml" href="?a=feed"><style nonce="' . $nonce . '">' . hk('css', $css) . '</style></head><body><div class="in"><header><div class="t"><h1 class="s"><a href="./">' . h($site) . '</a></h1><nav>' . $menu . '</nav></div><div class="m">' . h(S('tagline')) . '</div></header>' . $hero . '<div class="g"><main>' . $body . '</main>' . $side . '</div><footer>' . md(S('footer', '© ' . $site)) . hk('footer', '') . '</footer></div></body></html>';
    exit;
}

/* ---------- セッション / CSRF ---------- */
if (isset($_SESSION['uid'])) {
    if (time() - ($_SESSION['t'] ?? 0) > 7200) { session_unset(); session_regenerate_id(true); } else $_SESSION['t'] = time();
}
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$post = $_SERVER['REQUEST_METHOD'] === 'POST';
if ($post && !hash_equals($_SESSION['csrf'], (string)($_POST['_t'] ?? ''))) { http_response_code(403); exit('Invalid token'); }

$a = (string)($_GET['a'] ?? 'home');
$authed = isset($_SESSION['uid']);
if ((int)q('SELECT COUNT(*) FROM users')->fetchColumn() === 0) $a = 'setup';
elseif ($a === 'setup') $a = 'home';
if (in_array($a, ['admin', 'edit', 'save', 'delete', 'comments', 'cmod', 'media', 'upload', 'mdel', 'settings'], true) && !$authed) go('?a=login');

/* ---------- actions ---------- */
if ($a === 'setup') {
    $err = '';
    if ($post) {
        $n = (string)($_POST['name'] ?? ''); $p = (string)($_POST['pass'] ?? ''); $t = trim((string)($_POST['title'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_-]{3,32}$/', $n)) $err = 'ユーザー名は英数字・_-の3〜32文字です。';
        elseif (mb_strlen($p) < 12) $err = 'パスワードは12文字以上にしてください。';
        elseif ($t === '' || mb_strlen($t) > 100) $err = 'ブログ名を入力してください(100文字以内)。';
        else {
            q('INSERT INTO users(name,pass) VALUES(?,?)', [$n, password_hash($p, PASSWORD_DEFAULT)]);
            q("INSERT OR REPLACE INTO meta VALUES('title',?)", [$t]);
            go('?a=login');
        }
    }
    page('初期設定', '<h2>初期設定</h2><p class="e">' . h($err) . '</p><form method="post" action="?a=setup">' . tok() . '<label>ブログ名<input name="title" required maxlength="100"></label><label>管理者ユーザー名<input name="name" required autocomplete="username"></label><label>パスワード(12文字以上)<input type="password" name="pass" required minlength="12" autocomplete="new-password"></label><button>作成</button></form>');
}

if ($a === 'login') {
    $err = '';
    if ($post) {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        q('DELETE FROM attempts WHERE t<?', [time() - 900]);
        if ((int)q('SELECT COUNT(*) FROM attempts WHERE ip=?', [$ip])->fetchColumn() >= 5) $err = '試行回数が多すぎます。15分後にお試しください。';
        else {
            $u = q('SELECT * FROM users WHERE name=?', [(string)($_POST['name'] ?? '')])->fetch();
            $ok = password_verify((string)($_POST['pass'] ?? ''), $u['pass'] ?? password_hash('x', PASSWORD_DEFAULT));
            if ($u && $ok) {
                session_regenerate_id(true);
                $_SESSION['uid'] = $u['id']; $_SESSION['t'] = time();
                $_SESSION['csrf'] = bin2hex(random_bytes(32));
                q('DELETE FROM attempts WHERE ip=?', [$ip]);
                go('?a=admin');
            }
            q('INSERT INTO attempts VALUES(?,?)', [$ip, time()]);
            $err = 'ユーザー名またはパスワードが違います。';
        }
    }
    page('ログイン', '<h2>ログイン</h2><p class="e">' . h($err) . '</p><form method="post" action="?a=login">' . tok() . '<label>ユーザー名<input name="name" required autocomplete="username"></label><label>パスワード<input type="password" name="pass" required autocomplete="current-password"></label><button>ログイン</button></form>');
}

if ($a === 'logout' && $post) { session_unset(); session_destroy(); go('./'); }

if ($a === 'post') {
    $r = q('SELECT * FROM posts WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch();
    if (!$r || ($r['status'] !== 'published' && !$authed)) { http_response_code(404); page('404', '<h2>記事が見つかりません</h2>'); }
    $b = '<article><h2>' . h($r['title']) . '</h2>';
    if ($r['type'] === 'post') { $b .= '<p class="m">' . date('Y/m/d', (int)$r['created']) . ($r['status'] === 'draft' ? ' (下書き)' : '') . ($r['cat'] ? ' · <a href="' . U('cat', $r['cat']) . '">' . h($r['cat']) . '</a>' : ''); foreach (array_filter(explode(',', $r['tags'])) as $t) $b .= ' <a class="tg" href="' . U('tag', (string)$t) . '">#' . h((string)$t) . '</a>'; $b .= '</p>'; }
    $b .= hk('post_html', md($r['body']), $r) . '</article>';
    if ($r['type'] === 'post' && S('comments', '1') === '1') {
        $b .= '<h3 id="c">コメント</h3>';
        foreach (q("SELECT * FROM comments WHERE post=? AND status='ok' ORDER BY id", [$r['id']]) as $c) $b .= '<div class="cm"><b>' . h($c['name']) . '</b> <span class="m">' . date('Y/m/d H:i', (int)$c['created']) . '</span><br>' . nl2br(h($c['body'])) . '</div>';
        $b .= '<form method="post" action="?a=comment">' . tok() . '<input type="hidden" name="id" value="' . $r['id'] . '"><label>名前<input name="name" required maxlength="40"></label><label>コメント<textarea name="body" class="sm" required maxlength="2000"></textarea></label><input name="website" class="hp" tabindex="-1" autocomplete="off"><button>送信</button></form>';
    }
    page($r['title'], $b);
}
if ($a === 'comment' && $post) {
    $id = (int)($_POST['id'] ?? 0); $n = trim((string)($_POST['name'] ?? '')); $b = trim((string)($_POST['body'] ?? '')); $ip = 'c' . ($_SERVER['REMOTE_ADDR'] ?? '');
    $ok = q("SELECT 1 FROM posts WHERE id=? AND status='published' AND type='post'", [$id])->fetch() && S('comments', '1') === '1';
    if (!$ok || $n === '' || $b === '' || mb_strlen($n) > 40 || mb_strlen($b) > 2000) { http_response_code(400); page('エラー', '<p class="e">コメントを送信できませんでした。</p>'); }
    if (($_POST['website'] ?? '') === '') {
        q('DELETE FROM attempts WHERE t<?', [time() - 900]);
        if ((int)q('SELECT COUNT(*) FROM attempts WHERE ip=?', [$ip])->fetchColumn() >= 3) { http_response_code(429); page('エラー', '<p class="e">投稿が多すぎます。しばらくお待ちください。</p>'); }
        q('INSERT INTO attempts VALUES(?,?)', [$ip, time()]);
        q('INSERT INTO comments(post,name,body,status,created) VALUES(?,?,?,?,?)', [$id, $n, $b, S('mod', '1') === '1' ? 'wait' : 'ok', time()]);
    }
    page('受付', '<p>コメントを受け付けました' . (S('mod', '1') === '1' ? '(承認後に表示されます)' : '') . '。</p><p><a href="' . U('post', (string)$id) . '">戻る</a></p>');
}
if ($a === 'feed') {
    $o = ($https ? 'https' : 'http') . '://' . preg_replace('/[^A-Za-z0-9.:-]/', '', $_SERVER['HTTP_HOST'] ?? 'localhost') . $base;
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0"><channel><title>' . h(S('title')) . '</title><link>' . h($o) . '</link><description>' . h(S('tagline', '-')) . '</description>';
    foreach (q("SELECT id,title,body,created FROM posts WHERE status='published' AND type='post' ORDER BY created DESC LIMIT 20") as $r) echo '<item><title>' . h($r['title']) . '</title><link>' . h($o) . '?a=post&amp;id=' . $r['id'] . '</link><pubDate>' . date(DATE_RSS, (int)$r['created']) . '</pubDate><description>' . h(mb_substr(strip_tags(md($r['body'])), 0, 300)) . '</description></item>';
    exit('</channel></rss>');
}
$tabs = '<p><a href="?a=admin">記事</a> | <a href="?a=edit">新規</a> | <a href="?a=media">メディア</a> | <a href="?a=comments">コメント</a> | <a href="?a=settings">設定</a> | <a href="?a=pw">パスワード</a></p>';
if ($a === 'admin') {
    $b = $tabs . '<h2>記事一覧</h2><table>';
    foreach (q('SELECT id,title,status,type FROM posts ORDER BY created DESC') as $r) $b .= '<tr><td><a href="' . U('post', (string)$r['id']) . '">' . h($r['title']) . '</a> <span class="m">' . ($r['type'] === 'page' ? '固定' : '記事') . '/' . ($r['status'] === 'draft' ? '下書き' : '公開') . '</span></td><td><a href="?a=edit&amp;id=' . $r['id'] . '">編集</a></td><td><details><summary>削除</summary><form method="post" action="?a=delete">' . tok() . '<input type="hidden" name="id" value="' . $r['id'] . '"><button>本当に削除</button></form></details></td></tr>';
    page('管理', $b . '</table>');
}
if ($a === 'edit') {
    $r = q('SELECT * FROM posts WHERE id=?', [(int)($_GET['id'] ?? 0)])->fetch() ?: ['id' => 0, 'title' => '', 'body' => '', 'status' => 'draft', 'type' => 'post', 'cat' => '', 'tags' => ''];
    page('編集', $tabs . '<h2>' . ($r['id'] ? '編集' : '新規') . '</h2><form method="post" action="?a=save">' . tok() . '<input type="hidden" name="id" value="' . (int)$r['id'] . '"><label>タイトル<input name="title" required maxlength="200" value="' . h($r['title']) . '"></label><label>本文(Markdown: # 見出し / **太字** / *斜体* / [text](https://..) / ![alt](uploads/..) / - リスト)<textarea name="body" maxlength="100000">' . h($r['body']) . '</textarea></label><label>種類' . sel('type', ['post' => '記事', 'page' => '固定ページ'], $r['type']) . '</label><label>カテゴリ<input name="cat" maxlength="30" value="' . h($r['cat']) . '"></label><label>タグ(カンマ区切り)<input name="tags" value="' . h(implode(', ', array_filter(explode(',', $r['tags'])))) . '"></label><label>状態' . sel('status', ['draft' => '下書き', 'published' => '公開'], $r['status']) . '</label><button>保存</button></form>');
}
if ($a === 'save' && $post) {
    $id = (int)($_POST['id'] ?? 0); $t = trim((string)($_POST['title'] ?? '')); $b = (string)($_POST['body'] ?? '');
    $s = ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft'; $ty = ($_POST['type'] ?? '') === 'page' ? 'page' : 'post';
    $c = mb_substr(trim((string)($_POST['cat'] ?? '')), 0, 30);
    $tg = array_slice(array_unique(array_filter(array_map(fn($x) => mb_substr(trim($x), 0, 30), explode(',', (string)($_POST['tags'] ?? ''))))), 0, 10);
    $tags = $tg ? ',' . implode(',', $tg) . ',' : '';
    if ($t === '' || mb_strlen($t) > 200 || mb_strlen($b) > 100000) { http_response_code(400); page('エラー', '<p class="e">入力が不正です。</p>'); }
    if ($id && q('SELECT 1 FROM posts WHERE id=?', [$id])->fetch()) q('UPDATE posts SET title=?,body=?,status=?,type=?,cat=?,tags=?,updated=? WHERE id=?', [$t, $b, $s, $ty, $c, $tags, time(), $id]);
    else q('INSERT INTO posts(title,body,status,type,cat,tags,created,updated) VALUES(?,?,?,?,?,?,?,?)', [$t, $b, $s, $ty, $c, $tags, time(), time()]);
    go('?a=admin');
}
if ($a === 'delete' && $post) { $i = (int)($_POST['id'] ?? 0); q('DELETE FROM posts WHERE id=?', [$i]); q('DELETE FROM comments WHERE post=?', [$i]); go('?a=admin'); }
if ($a === 'media') {
    $m = '';
    if ($post && ($_FILES['f']['error'] ?? 1) === 0 && $_FILES['f']['size'] <= 5000000 && is_uploaded_file($_FILES['f']['tmp_name'])) {
        $i = @getimagesize($_FILES['f']['tmp_name']);
        $x = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'][$i[2] ?? 0] ?? null;
        $m = $x && move_uploaded_file($_FILES['f']['tmp_name'], "$up/" . ($n = bin2hex(random_bytes(8)) . ".$x")) ? "アップロード完了: uploads/$n" : '画像(JPEG/PNG/GIF/WebP, 5MB以下)のみ可能です。';
    }
    if ($post && preg_match('/^[a-f0-9]{16}\.(jpg|png|gif|webp)$/', (string)($_POST['del'] ?? ''))) @unlink("$up/" . $_POST['del']);
    $b = $tabs . '<h2>メディア</h2><p>' . h($m) . '</p><form method="post" enctype="multipart/form-data" action="?a=media">' . tok() . '<input type="file" name="f" accept="image/*"><button>アップロード</button></form><p class="m">本文には ![説明](uploads/ファイル名) で挿入します。</p>';
    foreach (glob("$up/*.*") ?: [] as $f) { $n = basename($f); $b .= '<div class="cm"><img src="uploads/' . h($n) . '" alt="" width="120"> <code>uploads/' . h($n) . '</code> <form method="post" action="?a=media" class="i">' . tok() . '<input type="hidden" name="del" value="' . h($n) . '"><button>削除</button></form></div>'; }
    page('メディア', $b);
}
if ($a === 'comments') {
    if ($post && ($i = (int)($_POST['id'] ?? 0))) { if (($_POST['op'] ?? '') === 'ok') q("UPDATE comments SET status='ok' WHERE id=?", [$i]); else q('DELETE FROM comments WHERE id=?', [$i]); go('?a=comments'); }
    $b = $tabs . '<h2>コメント</h2>';
    foreach (q('SELECT c.*,p.title FROM comments c LEFT JOIN posts p ON p.id=c.post ORDER BY c.id DESC LIMIT 100') as $c) $b .= '<div class="cm"><b>' . h($c['name']) . '</b> <span class="m">' . h((string)$c['title']) . ' / ' . ($c['status'] === 'ok' ? '公開' : '承認待ち') . '</span><br>' . nl2br(h($c['body'])) . '<form method="post" action="?a=comments" class="i">' . tok() . '<input type="hidden" name="id" value="' . $c['id'] . '"><button name="op" value="ok">承認</button> <button name="op" value="del">削除</button></form></div>';
    page('コメント', $b);
}
if ($a === 'settings') {
    $sp = ['title' => ['ブログ名', 't'], 'tagline' => ['サブタイトル', 't'], 'theme' => ['テーマ', 's', L('light', 'dark', 'sepia')], 'accent' => ['アクセント色', 'c'], 'font' => ['フォント', 's', L('sans', 'serif', 'mono')], 'width' => ['本文幅(px 480〜1400)', 't'], 'layout' => ['サイドバー位置', 's', L('right', 'left', 'none')], 'perpage' => ['1ページの記事数(1〜50)', 't'], 'hero' => ['ヘッダー画像(例 uploads/xxxxxxxxxxxxxxxx.jpg)', 't'], 'menu' => ['メニュー(1行1件: 名前|URL)', 'a'], 'sidebar' => ['ウィジェットと順序(about,search,recent,cats,tags)', 't'], 'about' => ['About(Markdown)', 'a'], 'footer' => ['フッター(Markdown)', 'a'], 'css' => ['カスタムCSS', 'a'], 'comments' => ['コメント', 's', ['1' => '許可', '0' => '禁止']], 'mod' => ['コメント承認', 's', ['1' => '承認制', '0' => '即時公開']], 'pretty' => ['URL形式(要mod_rewrite)', 's', ['0' => '?a=post&id=1', '1' => '/post/1']]];
    $m = '';
    if ($post) {
        foreach ($sp as $k => $d) {
            $v = trim(str_replace("\r", '', (string)($_POST[$k] ?? '')));
            if ($d[1] === 's' && !array_key_exists($v, $d[2])) continue;
            if ($d[1] === 'c' && !preg_match('/^#[0-9a-fA-F]{6}$/', $v)) continue;
            if (mb_strlen($v) > ($d[1] === 'a' ? 8000 : 200)) continue;
            q('INSERT OR REPLACE INTO meta VALUES(?,?)', [$k, $v]); $M[$k] = $v;
        }
        if (S('pretty') === '1') {
            $ht = __DIR__ . '/.htaccess'; $f = basename(__FILE__);
            if (!is_file($ht) || str_contains((string)file_get_contents($ht), '# BLOGCMS')) file_put_contents($ht, "# BLOGCMS\n<IfModule mod_rewrite.c>\nRewriteEngine On\nRewriteRule ^post/([0-9]+)/?\$ $f?a=post&id=\$1 [L]\nRewriteRule ^cat/([^/]+)/?\$ $f?cat=\$1 [B,L]\nRewriteRule ^tag/([^/]+)/?\$ $f?tag=\$1 [B,L]\n</IfModule>\n");
        }
        $m = '保存しました。';
    }
    $b = $tabs . '<h2>サイト設定・見た目の改造</h2><p>' . h($m) . '</p><form method="post" action="?a=settings">' . tok();
    foreach ($sp as $k => $d) $b .= '<label>' . h($d[0]) . match ($d[1]) { 's' => sel($k, $d[2], S($k)), 'a' => '<textarea name="' . $k . '" class="sm">' . h(S($k)) . '</textarea>', 'c' => '<input type="color" name="' . $k . '" value="' . h(S($k, '#0a58ca')) . '">', default => '<input name="' . $k . '" value="' . h(S($k)) . '">' } . '</label>';
    page('設定', $b . '<button>保存</button></form>');
}
if ($a === 'pw') {
    $m = '';
    if ($post) {
        $u = q('SELECT * FROM users WHERE id=?', [$_SESSION['uid']])->fetch(); $n = (string)($_POST['new'] ?? '');
        if (!password_verify((string)($_POST['old'] ?? ''), $u['pass'])) $m = '現在のパスワードが違います。';
        elseif (mb_strlen($n) < 12) $m = '12文字以上にしてください。';
        else { q('UPDATE users SET pass=? WHERE id=?', [password_hash($n, PASSWORD_DEFAULT), $u['id']]); session_regenerate_id(true); $m = '変更しました。'; }
    }
    page('パスワード', $tabs . '<h2>パスワード変更</h2><p>' . h($m) . '</p><form method="post" action="?a=pw">' . tok() . '<label>現在のパスワード<input type="password" name="old" required autocomplete="current-password"></label><label>新しいパスワード(12文字以上)<input type="password" name="new" required minlength="12" autocomplete="new-password"></label><button>変更</button></form>');
}
$out = hk('route', null, $a);
if (is_string($out)) page($a, $out);
/* ---------- 一覧(トップ/カテゴリ/タグ/検索) ---------- */
$pp = max(1, min(50, (int)S('perpage', '10'))); $pg = max(1, (int)($_GET['pg'] ?? 1));
$w = ["status='published'", "type='post'"]; $p = []; $hd = '';
if (($c = (string)($_GET['cat'] ?? '')) !== '') { $w[] = 'cat=?'; $p[] = $c; $hd = '<h2>カテゴリ: ' . h($c) . '</h2>'; }
elseif (($t = (string)($_GET['tag'] ?? '')) !== '') { $w[] = "tags LIKE ? ESCAPE '\\'"; $p[] = like(",$t,"); $hd = '<h2>タグ: ' . h($t) . '</h2>'; }
elseif (($sq = mb_substr((string)($_GET['s'] ?? ''), 0, 50)) !== '') { $w[] = "(title LIKE ? ESCAPE '\\' OR body LIKE ? ESCAPE '\\')"; $p[] = $p[] = like($sq); $hd = '<h2>検索: ' . h($sq) . '</h2>'; }
$rows = q('SELECT * FROM posts WHERE ' . implode(' AND ', $w) . ' ORDER BY created DESC LIMIT ' . ($pp + 1) . ' OFFSET ' . (($pg - 1) * $pp), $p)->fetchAll();
$qs = h(http_build_query(array_intersect_key($_GET, ['cat' => 1, 'tag' => 1, 's' => 1]))); $qs = $qs ? "$qs&amp;" : '';
$b = $hd . cards(array_slice($rows, 0, $pp));
if ($pg > 1) $b .= '<a href="?' . $qs . 'pg=' . ($pg - 1) . '">← 新しい記事</a> ';
if (count($rows) > $pp) $b .= '<a href="?' . $qs . 'pg=' . ($pg + 1) . '">古い記事 →</a>';
page('ホーム', $b);
