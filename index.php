<?php
/*
 * Pareja Planner – PHP plano, un solo archivo, sin DB.
 * Guarda tareas en data/tasks.json y bloques fijos en data/fixed.json
 *
 * Usa "Él / Ella / Ambos" (keys: el/ella/ambos).
 * Compatibilidad con datos antiguos: yo->el, pareja->ella.
 * Admin de bloques fijos con PIN (simple).
 *
 * NUEVO: Los bloques fijos y las píldoras del calendario
 *        SOLO se muestran al usuario actual si son "ambos"
 *        o coinciden con su vista (el/ella). Así no “ensucia”
 *        el calendario de la otra persona.
 */

declare(strict_types=1);

# ======================= CONFIG =======================
const DATA_DIR    = __DIR__ . '/data';
const TASKS_FILE  = DATA_DIR . '/tasks.json';
const FIXED_FILE  = DATA_DIR . '/fixed.json';
const APP_TITLE   = 'Pareja Planner';
const TZ_NAME     = 'America/Santiago';
const ADMIN_PIN   = 'teamo'; // cambia el PIN

# ====================== UTILIDAD ======================
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function tz(): DateTimeZone { return new DateTimeZone(TZ_NAME); }
function now(): DateTime { return new DateTime('now', tz()); }
function today(): string { return now()->format('Y-m-d'); }

function safe_json_read(string $file, $default) {
    if (!is_file($file)) return $default;
    $json = @file_get_contents($file);
    if ($json === false || trim($json) === '') return $default;
    $data = json_decode($json, true);
    return is_array($data) ? $data : $default;
}
function safe_json_write(string $file, $value): bool {
    if (!is_dir(DATA_DIR)) { @mkdir(DATA_DIR, 0775, true); }
    $fp = fopen($file, 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    ftruncate($fp, 0);
    $ok = fwrite($fp, json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}

function normalize_who($w): string {
    $w = strtolower(trim((string)$w));
    if ($w === 'yo')      return 'el';     // legacy
    if ($w === 'pareja')  return 'ella';   // legacy
    if (in_array($w, ['el','ella','ambos'], true)) return $w;
    return 'ambos';
}

function tasks_load(): array {
    $arr = safe_json_read(TASKS_FILE, []);
    foreach ($arr as &$t) { $t['who'] = normalize_who($t['who'] ?? 'ambos'); }
    unset($t);
    return $arr;
}
function tasks_save(array $arr): bool { return safe_json_write(TASKS_FILE, $arr); }

function fixed_load(): array {
    $arr = safe_json_read(FIXED_FILE, []);
    foreach ($arr as &$b) { $b['who'] = normalize_who($b['who'] ?? 'ambos'); }
    unset($b);
    return $arr;
}
function fixed_save(array $arr): bool { return safe_json_write(FIXED_FILE, $arr); }

function gen_id(): string { return bin2hex(random_bytes(6)); }

function week_range(): array {
    $d = now();
    $dow = (int)$d->format('N'); // 1..7 (Lun..Dom)
    $monday = (clone $d)->modify('-' . ($dow - 1) . ' days')->setTime(0,0,0);
    $sunday = (clone $monday)->modify('+6 days')->setTime(23,59,59);
    return [$monday, $sunday];
}
function in_week(?string $date): bool {
    if (!$date) return false;
    [$mon,$sun] = week_range();
    $dt = DateTime::createFromFormat('Y-m-d', $date, tz());
    if (!$dt) return false;
    $dt->setTime(12,0,0);
    return ($dt >= $mon && $dt <= $sun);
}
function is_today(?string $date): bool { return $date === today(); }

function compare_tasks(array $a, array $b): int {
    $orderStatus = ['fijo'=>-1,'pendiente'=>0,'hecho'=>1];
    $pa = $orderStatus[$a['status']] ?? 2;
    $pb = $orderStatus[$b['status']] ?? 2;
    if ($pa !== $pb) return $pa <=> $pb;

    $da = $a['date'] ?? '';
    $db = $b['date'] ?? '';
    if ($da && $db && $da !== $db) return strcmp($da, $db);
    if ($da === '' && $db !== '') return 1;
    if ($da !== '' && $db === '') return -1;

    $prio = ['alta'=>0, 'media'=>1, 'baja'=>2];
    $pra = $prio[$a['priority']] ?? 3;
    $prb = $prio[$b['priority']] ?? 3;
    if ($pra !== $prb) return $pra <=> $prb;

    return strcmp($b['created_at'] ?? '', $a['created_at'] ?? '');
}

function ensure_session(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
}
function csrf_token(): string {
    ensure_session();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    ensure_session();
    $ok = isset($_POST['csrf']) && hash_equals($_SESSION['csrf'] ?? '', $_POST['csrf']);
    if (!$ok) { http_response_code(400); exit('CSRF inválido'); }
}

/** Año/mes actual (con navegación) */
function current_year_month(): array {
    $y = isset($_GET['y']) ? (int)$_GET['y'] : (int)now()->format('Y');
    $m = isset($_GET['m']) ? (int)$_GET['m'] : (int)now()->format('n');
    if ($m < 1) { $m = 12; $y -= 1; }
    if ($m > 12) { $m = 1; $y += 1; }
    return [$y,$m];
}
/** Grilla mensual (6x7) */
function build_month_grid(int $year, int $month): array {
    $first = new DateTime(sprintf('%04d-%02d-01', $year, $month), tz());
    $startDow = (int)$first->format('N'); // 1..7 (Lun..Dom)
    $start = (clone $first)->modify('-' . ($startDow-1) . ' days');
    $grid = [];
    for ($i=0; $i<42; $i++) {
        $d = (clone $start)->modify("+$i days");
        $grid[] = ['date'=>$d->format('Y-m-d'), 'in_month'=>((int)$d->format('n')===$month), 'is_today'=>($d->format('Y-m-d')===today())];
    }
    return $grid;
}
function cut(string $s, int $max=22): string {
    $s = trim($s);
    return mb_strlen($s) <= $max ? $s : (mb_substr($s,0,$max-1).'…');
}

/* Aceptar strings "1".."7" además de enteros y nombres */
function normalize_day($d): ?int {
    if (is_int($d) || (is_string($d) && ctype_digit($d))) {
        $n = (int)$d;
        return ($n >= 1 && $n <= 7) ? $n : null;
    }
    $map = ['mon'=>1,'tue'=>2,'wed'=>3,'thu'=>4,'fri'=>5,'sat'=>6,'sun'=>7,
            'lun'=>1,'mar'=>2,'mie'=>3,'mié'=>3,'jue'=>4,'vie'=>5,'sab'=>6,'sáb'=>6,'dom'=>7];
    $k = strtolower((string)$d);
    return $map[$k] ?? null;
}

/* === Regla de visibilidad por vista (el/ella/ambos) === */
function visible_for_user(string $itemWho, string $currentUser): bool {
    $itemWho = normalize_who($itemWho);
    $currentUser = normalize_who($currentUser);
    return $itemWho === 'ambos' || $itemWho === $currentUser;
}

/** Bloques fijos aplicables a una fecha (sin filtrar por usuario) */
function fixed_blocks_for_date(string $ymd): array {
    $all = fixed_load();
    if (!$all) return [];
    $out = [];
    $dt = DateTime::createFromFormat('Y-m-d', $ymd, tz());
    if (!$dt) return $out;
    $dow = (int)$dt->format('N');
    foreach ($all as $b) {
        $days = array_values(array_filter(array_map('normalize_day', (array)($b['days'] ?? []))));
        if (!$days || !in_array($dow, $days, true)) continue;
        $okRange = true;
        if (!empty($b['date_start'])) $okRange = $okRange && ($ymd >= $b['date_start']);
        if (!empty($b['date_end']))   $okRange = $okRange && ($ymd <= $b['date_end']);
        if (!$okRange) continue;
        $out[] = [
            'label'=>$b['label'], 'who'=>$b['who'], 'date'=>$ymd,
            'start'=>$b['start'], 'end'=>$b['end'], 'location'=>$b['location'] ?? '',
        ];
    }
    return $out;
}
/** Igual que la anterior pero filtrada por usuario visible (evita “ensuciar” vistas) */
function fixed_blocks_for_date_user(string $ymd, string $currentUser): array {
    return array_values(array_filter(
        fixed_blocks_for_date($ymd),
        fn($b) => visible_for_user($b['who'] ?? 'ambos', $currentUser)
    ));
}

# ======================== RUTEO ========================
ensure_session();
$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$filter = $_GET['filter'] ?? 'semana';     // hoy | semana | todas
$view   = $_GET['view']   ?? 'lista';      // lista | calendario
$user   = normalize_who($_GET['user'] ?? 'ambos'); // el | ella | ambos
$csrf   = csrf_token();

# ======================= ACCIONES ======================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if ($action === 'admin_login') {
        check_csrf();
        $pin = trim($_POST['pin'] ?? '');
        if (hash_equals(ADMIN_PIN, $pin)) { $_SESSION['admin_ok'] = true; header('Location: ?msg=Admin+OK#fixed'); exit; }
        header('Location: ?msg=PIN+incorrecto#fixed'); exit;
    }
    if ($action === 'admin_logout') {
        check_csrf(); unset($_SESSION['admin_ok']); header('Location: ?msg=Admin+salio#fixed'); exit;
    }
    if ($action === 'fixed_create') {
        check_csrf(); if (empty($_SESSION['admin_ok'])) { http_response_code(403); exit('PIN requerido'); }
        $label=trim($_POST['label']??''); $who=normalize_who($_POST['who']??'ambos');
        $start=$_POST['start']??''; $end=$_POST['end']??''; $loc=trim($_POST['location']??'');
        $ds=$_POST['date_start']??''; $de=$_POST['date_end']??''; $days=$_POST['days']??[];
        if ($label===''||$start===''||$end===''||!$days){ header('Location:?msg=Faltan+datos+del+bloque#fixed'); exit; }
        $all=fixed_load();
        $all[]=['id'=>gen_id(),'label'=>$label,'who'=>$who,'days'=>array_values($days),
                'start'=>$start,'end'=>$end,'location'=>$loc,
                'date_start'=>$ds!==''?$ds:null,'date_end'=>$de!==''?$de:null];
        fixed_save($all); header('Location:?msg=Bloque+fijo+creado#fixed'); exit;
    }
    if ($action === 'fixed_delete') {
        check_csrf(); if (empty($_SESSION['admin_ok'])) { http_response_code(403); exit('PIN requerido'); }
        $id=$_POST['id']??''; $all=fixed_load();
        $all=array_values(array_filter($all,fn($b)=>($b['id']??'')!==$id)); fixed_save($all);
        header('Location:?msg=Bloque+fijo+eliminado#fixed'); exit;
    }

    // Tareas
    if (in_array($action, ['create','toggle','delete','edit'], true)) {
        check_csrf();
        $tasks = tasks_load();

        if ($action==='create') {
            $title = trim($_POST['title'] ?? '');
            if ($title === '') { header('Location: ?msg=Titulo+requerido'); exit; }
            $task = [
                'id'=>gen_id(),'title'=>$title,'notes'=>trim($_POST['notes']??''),
                'who'=>normalize_who($_POST['who']??'ambos'),
                'priority'=>$_POST['priority']??'media','date'=>($_POST['date']??'')?:'',
                'time'=>($_POST['time']??'')?:'','status'=>'pendiente',
                'created_at'=>now()->format('Y-m-d H:i:s')
            ];
            $tasks[]=$task; tasks_save($tasks);
            header('Location: ?msg=Tarea+creada'); exit;
        }
        if ($action==='toggle') {
            $id=$_POST['id']??''; foreach($tasks as &$t){ if($t['id']===$id){ $t['status']=$t['status']==='hecho'?'pendiente':'hecho'; break; } }
            unset($t); tasks_save($tasks); header('Location: ?msg=Estado+actualizado'); exit;
        }
        if ($action==='delete') {
            $id=$_POST['id']??''; $tasks=array_values(array_filter($tasks,fn($t)=>$t['id']!==$id));
            tasks_save($tasks); header('Location: ?msg=Tarea+eliminada'); exit;
        }
        if ($action==='edit') {
            $id=$_POST['id']??''; foreach($tasks as &$t){
                if($t['id']===$id){
                    $t['title']=trim($_POST['title']??$t['title']);
                    $t['notes']=trim($_POST['notes']??$t['notes']);
                    $t['who']=normalize_who($_POST['who']??$t['who']);
                    $t['priority']=$_POST['priority']??$t['priority'];
                    $t['date']=($_POST['date']??'')?:'';
                    $t['time']=($_POST['time']??'')?:'';
                    break;
                }
            }
            unset($t); tasks_save($tasks); header('Location: ?msg=Tarea+actualizada'); exit;
        }
    }

    http_response_code(400); exit('Acción no válida');
}

# ===================== CARGA ESTADO ====================
$tasks = tasks_load();
usort($tasks, 'compare_tasks');

$filtered = array_filter($tasks, function ($t) use ($filter) {
    if ($filter==='hoy') return is_today($t['date']??null);
    if ($filter==='semana') return in_week($t['date']??null);
    return true;
});
$msg = $_GET['msg'] ?? null;

# Theme por user
$theme = $user==='ella' ? 'ella' : ($user==='el' ? 'el' : 'neutral');

# Calendario
[$Y,$M] = current_year_month();
$grid = build_month_grid($Y,$M);
$byDate = [];
foreach ($tasks as $t) {
    $d = $t['date'] ?? '';
    if ($d==='') continue;
    $byDate[$d][] = $t;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?=h(APP_TITLE)?> – <?=h(ucfirst($view))?></title>
<style>
:root{
  --bg:#0f1220; --card:#171b2e; --ink:#e8ecff; --muted:#aab2d8;
  --ok:#6ef3a5; --warn:#ffd166; --err:#ff6b6b; --link:#9cc1ff;
  --chip:#212745; --line:#2a3256; --btn:#0c1028; --accent:#8fb2ff;
}
/* Tema Ella (rosadito/ternura) */
body.theme-ella{
  --bg:#141018; --card:#1b1420; --ink:#fff0f6; --muted:#f7b9d1;
  --link:#ffc4e0; --chip:#251624; --line:#3a2036; --btn:#201423; --accent:#ff7ab6;
}
/* Tema Él (sobrio/pro) */
body.theme-el{
  --bg:#0e1116; --card:#141920; --ink:#e6edf3; --muted:#a4b1c3;
  --link:#9cc1ff; --chip:#18202a; --line:#243040; --btn:#0f151d; --accent:#6aa0ff;
}
*{box-sizing:border-box;font-family:system-ui,Segoe UI,Roboto,Arial,sans-serif}
html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--ink);}
.container{max-width:1100px;margin:24px auto;padding:16px;}
.header{position:sticky;top:0;background:linear-gradient(180deg,rgba(0,0,0,.35),transparent);backdrop-filter:saturate(120%) blur(6px);z-index:5;border-radius:0 0 16px 16px;padding-bottom:8px}
.header-inner{display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap}
h1{margin:0;font-size:24px;letter-spacing:.3px}
/* Nav */
.nav, .who{display:flex;gap:8px;flex-wrap:wrap}
@media (max-width: 640px){ .nav, .who{flex-wrap:nowrap;overflow-x:auto;padding-bottom:6px} .nav::-webkit-scrollbar,.who::-webkit-scrollbar{display:none} }
.nav a, .who a{color:var(--ink);text-decoration:none;padding:10px 14px;border-radius:12px;background:var(--chip);border:1px solid var(--line);font-size:14px;white-space:nowrap}
.nav a.active, .who a.active{outline:2px solid var(--accent)}
.card{background:var(--card);border:1px solid var(--line);border-radius:16px;padding:16px;margin-top:16px}
/* Formularios */
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.form-row-3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px}
@media (max-width: 900px){ .form-row{grid-template-columns:1fr} .form-row-3{grid-template-columns:1fr} }
input[type=text], textarea, select, input[type=date], input[type=time], input[type=password]{
  width:100%;padding:12px;border-radius:12px;border:1px solid var(--line);background:var(--btn);color:var(--ink)
}
button{padding:12px 16px;border-radius:12px;border:1px solid var(--line);background:var(--btn);color:var(--ink);cursor:pointer}
button.primary{background:linear-gradient(180deg,var(--accent),#3a4a7a);border-color:#2a3568;color:#091221}
.badge{padding:6px 10px;border-radius:999px;border:1px solid var(--line);background:var(--chip);font-size:12px;color:var(--muted)}
.list{display:flex;flex-direction:column;gap:10px}
.item{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;padding:12px;border:1px dashed var(--line);border-radius:12px;background:rgba(255,255,255,.02)}
@media (max-width: 640px){ .item{flex-direction:column;gap:8px} }
.meta{display:flex;flex-wrap:wrap;gap:6px}
.title{font-weight:600}
.done .title{text-decoration:line-through;color:#86a3ff99}
.notes{color:var(--muted);white-space:pre-wrap;margin-top:6px}
small{color:var(--muted)}
hr{border:0;border-top:1px solid var(--line);margin:12px 0}
.msg{margin-top:12px;color:var(--ok)}
footer{margin:24px 0;color:var(--muted);font-size:12px;text-align:center}
.inline{display:inline}
.actions{display:flex;gap:6px;flex-wrap:wrap}
/* Etiquetas */
.tag-el{background:#13273a;border-color:#27405a;color:#9cc1ff}
.tag-ella{background:#3a1b2e;border-color:#5a2a4a;color:#ff9ec7}
.tag-ambos{background:#242a3a;border-color:#3a4a6a;color:#b7c7ff}
.prio-alta{background:#3a1a1a;border-color:#6a2a2a;color:#ff9c9c}
.prio-media{background:#2d2a3a;border-color:#46406a;color:#c1b8ff}
.prio-baja{background:#20322a;border-color:#335a48;color:#a7f1c7}
/* Calendario */
.cal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:8px;flex-wrap:wrap}
.cal-head a{padding:8px 12px;border:1px solid var(--line);border-radius:10px;background:var(--chip);text-decoration:none;color:var(--ink)}
.weekdays{display:grid;grid-template-columns:repeat(7,1fr);gap:8px;margin-bottom:6px}
.weekdays div{font-size:12px;color:var(--muted);text-align:center}
.cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:8px}
@media (max-width: 1024px){ .cal-grid{grid-template-columns:repeat(4,1fr)} .weekdays{grid-template-columns:repeat(4,1fr)} .weekdays div:nth-child(n+5){display:none} }
@media (max-width: 540px){ .cal-grid{grid-template-columns:repeat(2,1fr)} .weekdays{grid-template-columns:repeat(2,1fr)} .weekdays div:nth-child(n+3){display:none} }
.cal-cell{border:1px solid var(--line);border-radius:12px;min-height:110px;padding:8px;background:var(--card)}
@media (max-width: 540px){ .cal-cell{min-height:90px} }
.cal-out{opacity:.45}
.cal-date{font-size:12px;color:var(--muted)}
.cal-today{outline:2px solid var(--accent)}
.cal-list{margin-top:6px;display:flex;flex-direction:column;gap:6px}
.cal-pill{font-size:12px;padding:4px 6px;border-radius:999px;border:1px solid var(--line);display:inline-block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cal-pill.el{background:#13273a;border-color:#27405a;color:#9cc1ff}
.cal-pill.ella{background:#3a1b2e;border-color:#5a2a4a;color:#ff9ec7}
.cal-pill.ambos{background:#242a3a;border-color:#3a4a6a;color:#b7c7ff}
/* Bloques fijos */
.fixed-wrap{display:flex;flex-direction:column;gap:8px;margin-top:8px}
.fixed-item{display:flex;gap:10px;align-items:center;background:rgba(255,255,255,.03);border:1px solid var(--line);border-radius:12px;padding:10px}
.fixed-badge{font-size:12px;padding:4px 8px;border-radius:999px;background:#2a2030;border:1px solid #4a3a50;color:#ffb3d0}
.fixed-time{font-size:12px;color:var(--muted)}
/* Admin */
.admin-card{background:rgba(255,255,255,.03);border:1px solid var(--line);border-radius:12px;padding:12px;margin-top:10px}
.table{width:100%;border-collapse:separate;border-spacing:0 8px}
.table td,.table th{padding:8px 10px;border-bottom:1px solid var(--line)}
.table th{color:var(--muted);text-align:left;font-weight:600}
.chips{display:flex;gap:6px;flex-wrap:wrap}
.chip{padding:4px 8px;border-radius:999px;border:1px solid var(--line);background:var(--chip);font-size:12px}
</style>
</head>
<?php $themeClass = $theme==='ella' ? 'theme-ella' : ($theme==='el' ? 'theme-el' : ''); ?>
<body class="<?=h($themeClass)?>">
<div class="container">
  <div class="header">
    <div class="header-inner">
      <h1 style="padding:6px 0"><?=h(APP_TITLE)?></h1>
      <div style="display:flex;gap:12px;flex-wrap:wrap;max-width:100%">
        <nav class="nav">
          <a href="?filter=hoy&view=lista&user=<?=h($user)?>"   class="<?= $filter==='hoy'    && $view==='lista'?'active':'' ?>">Hoy</a>
          <a href="?filter=semana&view=lista&user=<?=h($user)?>" class="<?= $filter==='semana' && $view==='lista'?'active':'' ?>">Semana</a>
          <a href="?filter=todas&view=lista&user=<?=h($user)?>"  class="<?= $filter==='todas'  && $view==='lista'?'active':'' ?>">Todas</a>
          <a href="?view=calendario&user=<?=h($user)?>"          class="<?= $view==='calendario'?'active':'' ?>">Calendario</a>
          <a href="#fixed">🔒 Fijos</a>
        </nav>
        <nav class="who">
          <a href="?view=<?=h($view)?>&filter=<?=h($filter)?>&user=ella" class="<?= $user==='ella'?'active':'' ?>">Ella 💗</a>
          <a href="?view=<?=h($view)?>&filter=<?=h($filter)?>&user=el"   class="<?= $user==='el'  ?'active':'' ?>">Él 💼</a>
          <a href="?view=<?=h($view)?>&filter=<?=h($filter)?>&user=ambos" class="<?= $user==='ambos'?'active':'' ?>">Ambos 👥</a>
        </nav>
      </div>
    </div>
  </div>

  <?php if ($msg): ?><div class="msg"><?=h($msg)?></div><?php endif; ?>

  <div class="card">
    <h3 style="margin-top:0">Nueva tarea</h3>
    <form method="post">
      <input type="hidden" name="csrf" value="<?=h($csrf)?>">
      <input type="hidden" name="action" value="create">
      <div class="form-row">
        <div>
          <label>Título *</label>
          <input type="text" name="title" required placeholder="Ej: Comprar entradas cine">
        </div>
        <div>
          <label>¿Para quién?</label>
          <select name="who">
            <option value="ambos" <?= $user==='ambos'?'selected':'' ?>>Ambos</option>
            <option value="el"    <?= $user==='el'?'selected':'' ?>>Él</option>
            <option value="ella"  <?= $user==='ella'?'selected':'' ?>>Ella</option>
          </select>
        </div>
      </div>
      <div class="form-row-3" style="margin-top:10px">
        <div>
          <label>Prioridad</label>
          <select name="priority">
            <option value="alta">Alta</option>
            <option value="media" selected>Media</option>
            <option value="baja">Baja</option>
          </select>
        </div>
        <div>
          <label>Fecha</label>
          <input type="date" name="date">
        </div>
        <div>
          <label>Hora</label>
          <input type="time" name="time">
        </div>
      </div>
      <div style="margin-top:10px">
        <label>Notas (opcional)</label>
        <textarea name="notes" rows="4" placeholder="Detalles, lugar, presupuesto, etc."></textarea>
      </div>
      <div style="margin-top:12px">
        <button class="primary" type="submit">Agregar</button>
      </div>
    </form>
  </div>

  <?php if ($view === 'calendario'): ?>
    <?php
      // Incrustar bloques fijos filtrados por usuario en las fechas del calendario
      foreach ($grid as $cell) {
        $d = $cell['date'];
        $fb = fixed_blocks_for_date_user($d, $user); // << filtrado por vista
        if (!$fb) continue;
        foreach ($fb as $b) {
          $byDate[$d][] = [
            'id'=>'fixed-'.md5($d.$b['label'].$b['start'].$b['end'].$b['who']),
            'title'=>$b['label'].' ('.$b['start'].'-'.$b['end'].')',
            'who'=>$b['who'],'priority'=>'media','date'=>$d,'time'=>$b['start'],
            'status'=>'fijo','created_at'=>'','_fixed'=>true,
          ];
        }
        if (isset($byDate[$d])) usort($byDate[$d],'compare_tasks');
      }
    ?>
    <div class="card">
      <div class="cal-head">
        <div>
          <?php $prevY=$M===1?$Y-1:$Y; $prevM=$M===1?12:$M-1; $nextY=$M===12?$Y+1:$Y; $nextM=$M===12?1:$M+1; ?>
          <a href="?view=calendario&user=<?=h($user)?>&y=<?=$prevY?>&m=<?=$prevM?>">← Mes anterior</a>
        </div>
        <h3 style="margin:0">
          <?php
            if (class_exists('IntlDateFormatter')) {
              $fmt=new IntlDateFormatter('es_CL',IntlDateFormatter::LONG,IntlDateFormatter::NONE,TZ_NAME,NULL,'LLLL y');
              echo ucfirst($fmt->format(DateTime::createFromFormat('Y-n-j',"$Y-$M-1",tz()) ?: now()));
            } else { echo sprintf('%02d / %04d',$M,$Y); }
          ?>
        </h3>
        <div><a href="?view=calendario&user=<?=h($user)?>&y=<?=$nextY?>&m=<?=$nextM?>">Mes siguiente →</a></div>
      </div>

      <div class="weekdays">
        <div>Lun</div><div>Mar</div><div>Mié</div><div>Jue</div><div>Vie</div><div>Sáb</div><div>Dom</div>
      </div>

      <div class="cal-grid">
        <?php foreach ($grid as $cell): $d=$cell['date']; $in=$cell['in_month']; $isT=$cell['is_today']; $items=$byDate[$d]??[]; usort($items,'compare_tasks'); ?>
          <div class="cal-cell <?= $in?'':'cal-out' ?> <?= $isT?'cal-today':'' ?>">
            <div class="cal-date"><?=h(date('j', strtotime($d)))?></div>
            <div class="cal-list">
              <?php foreach ($items as $it):
                $cls = ($it['who']==='el'?'el':($it['who']==='ella'?'ella':'ambos'));
                $label = ($it['_fixed']??false)?'🔒 '.cut($it['title']):cut($it['title']); ?>
                <div class="cal-pill <?=$cls?>"><?=h($label)?></div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <h3 style="margin-top:0">
        Tareas <?= $filter==='todas'?'(todas)':($filter==='hoy'?'de hoy':'de la semana') ?>
        <?php if ($user==='ella'): ?> · <span class="badge tag-ella">Vista: Ella</span><?php endif; ?>
        <?php if ($user==='el'): ?>   · <span class="badge tag-el">Vista: Él</span><?php endif; ?>
      </h3>

      <?php
        // Bloques fijos visibles para la vista actual (hoy/semana)
        $showFixed = ($filter==='hoy' || $filter==='semana');
        if ($showFixed) {
          $dates = ($filter==='hoy') ? [today()] : (function(){ [$m,$s]=week_range(); $arr=[]; $p=(clone $m); while($p<=$s){$arr[]=$p->format('Y-m-d'); $p->modify('+1 day');} return $arr; })();
          $fixedList=[];
          foreach($dates as $d){ foreach(fixed_blocks_for_date_user($d, $user) as $b){ $fixedList[]=$b; } } // << filtrado por vista
          if ($fixedList) {
            echo '<div class="fixed-wrap">';
            foreach ($fixedList as $fb) {
              $who = $fb['who'];
              echo '<div class="fixed-item">';
              echo '<span class="fixed-badge">🔒 '.h($fb['label']).'</span>';
              echo '<span class="fixed-time">'.h($fb['date']).' · '.h($fb['start']).'-'.h($fb['end']).'</span>';
              if (!empty($fb['location'])) echo '<span class="badge" style="margin-left:6px">'.h($fb['location']).'</span>';
              echo '<span class="badge tag-'.h($who).'" style="margin-left:auto">Para: '.($who==='el'?'Él':($who==='ella'?'Ella':'Ambos')).'</span>';
              echo '</div>';
            }
            echo '</div><hr>';
          }
        }
      ?>

      <div class="list">
        <?php if (count($filtered)===0): ?>
          <div class="item"><div>No hay tareas en este filtro.</div></div>
        <?php endif; ?>

        <?php $edit_id = $_GET['edit'] ?? null; ?>
        <?php foreach ($filtered as $t): $isEditing = ($edit_id && $edit_id===$t['id']); $who = $t['who']; ?>
          <div class="item<?= $t['status']==='hecho'?' done':'' ?>">
            <div style="flex:1">
              <?php if ($isEditing): ?>
                <form method="post">
                  <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                  <input type="hidden" name="action" value="edit">
                  <input type="hidden" name="id" value="<?=h($t['id'])?>">
                  <div class="form-row">
                    <div>
                      <label>Título *</label>
                      <input type="text" name="title" required value="<?=h($t['title'])?>">
                    </div>
                    <div>
                      <label>¿Para quién?</label>
                      <select name="who">
                        <option value="ambos" <?=$who==='ambos'?'selected':''?>>Ambos</option>
                        <option value="el"    <?=$who==='el'?'selected':''?>>Él</option>
                        <option value="ella"  <?=$who==='ella'?'selected':''?>>Ella</option>
                      </select>
                    </div>
                  </div>
                  <div class="form-row-3" style="margin-top:10px">
                    <div>
                      <label>Prioridad</label>
                      <select name="priority">
                        <option value="alta"  <?=$t['priority']==='alta'?'selected':''?>>Alta</option>
                        <option value="media" <?=$t['priority']==='media'?'selected':''?>>Media</option>
                        <option value="baja"  <?=$t['priority']==='baja'?'selected':''?>>Baja</option>
                      </select>
                    </div>
                    <div>
                      <label>Fecha</label>
                      <input type="date" name="date" value="<?=h($t['date'])?>">
                    </div>
                    <div>
                      <label>Hora</label>
                      <input type="time" name="time" value="<?=h($t['time'])?>">
                    </div>
                  </div>
                  <div style="margin-top:10px">
                    <label>Notas</label>
                    <textarea name="notes" rows="3"><?=h($t['notes'])?></textarea>
                  </div>
                  <div style="margin-top:12px" class="actions">
                    <button class="primary" type="submit">Guardar</button>
                    <a class="badge" href="?filter=<?=h($filter)?>&view=<?=h($view)?>&user=<?=h($user)?>">Cancelar</a>
                  </div>
                </form>
              <?php else: ?>
                <div class="title"><?=h($t['title'])?></div>
                <div class="meta" style="margin-top:6px">
                  <span class="badge tag-<?=h($who)?>">Para: <?= $who==='el'?'Él':($who==='ella'?'Ella':'Ambos') ?></span>
                  <span class="badge prio-<?=h($t['priority'])?>">Prioridad: <?=h(ucfirst($t['priority']))?></span>
                  <?php if (!empty($t['date'])): ?>
                    <span class="badge">Fecha: <?=h($t['date'])?><?= $t['time']?' '.h($t['time']):'' ?></span>
                  <?php else: ?>
                    <span class="badge">Sin fecha</span>
                  <?php endif; ?>
                  <span class="badge">Estado: <?= $t['status']==='hecho'?'✅ Hecho':'⏳ Pendiente' ?></span>
                </div>
                <?php if (!empty($t['notes'])): ?>
                  <div class="notes"><?=nl2br(h($t['notes']))?></div>
                <?php endif; ?>
                <small>Creado: <?=h($t['created_at'] ?? '')?></small>
              <?php endif; ?>
            </div>

            <div class="actions">
              <?php if (!$isEditing): ?>
                <form class="inline" method="post" onsubmit="return confirm('¿Cambiar estado?');">
                  <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?=h($t['id'])?>">
                  <button type="submit"><?= $t['status']==='hecho'?'Marcar pendiente':'Marcar hecho' ?></button>
                </form>
                <a class="badge" href="?filter=<?=h($filter)?>&view=<?=h($view)?>&user=<?=h($user)?>&edit=<?=h($t['id'])?>">Editar</a>
                <form class="inline" method="post" onsubmit="return confirm('¿Eliminar tarea?');">
                  <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?=h($t['id'])?>">
                  <button type="submit" style="border-color:#5a2740;background:#2a0f1e;color:#ffb3c1">Eliminar</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <!-- ======= ADMIN BLOQUES FIJOS ======= -->
  <div class="card" id="fixed">
    <h3 style="margin-top:0">Bloques fijos (trabajo, clases) 🔒</h3>

    <?php if (empty($_SESSION['admin_ok'])): ?>
      <div class="admin-card">
        <form method="post" style="max-width:420px">
          <input type="hidden" name="csrf" value="<?=h($csrf)?>">
          <input type="hidden" name="action" value="admin_login">
          <label>PIN de administración</label>
          <input type="password" name="pin" placeholder="Ingresa el PIN" required>
          <div style="margin-top:10px">
            <button class="primary" type="submit">Entrar</button>
          </div>
          <div style="margin-top:8px;color:var(--muted);font-size:12px">Cámbialo en la constante <code>ADMIN_PIN</code> (arriba del archivo).</div>
        </form>
      </div>
    <?php else: ?>
      <div class="admin-card">
        <form method="post" style="display:inline">
          <input type="hidden" name="csrf" value="<?=h($csrf)?>">
          <input type="hidden" name="action" value="admin_logout">
          <button type="submit">Salir del modo admin</button>
        </form>
      </div>

      <div class="admin-card">
        <h4 style="margin:0 0 10px 0">Agregar bloque fijo</h4>
        <form method="post">
          <input type="hidden" name="csrf" value="<?=h($csrf)?>">
          <input type="hidden" name="action" value="fixed_create">
          <div class="form-row">
            <div>
              <label>Título *</label>
              <input type="text" name="label" required placeholder="Trabajo / Clases Universidad">
            </div>
            <div>
              <label>¿Para quién?</label>
              <select name="who">
                <option value="ambos">Ambos</option>
                <option value="el">Él</option>
                <option value="ella">Ella</option>
              </select>
            </div>
          </div>
          <div class="form-row-3" style="margin-top:10px">
            <div>
              <label>Hora inicio *</label>
              <input type="time" name="start" required>
            </div>
            <div>
              <label>Hora fin *</label>
              <input type="time" name="end" required>
            </div>
            <div>
              <label>Lugar (opcional)</label>
              <input type="text" name="location" placeholder="Oficina / Campus">
            </div>
          </div>
          <div class="form-row" style="margin-top:10px">
            <div>
              <label>Días (elige varios) *</label>
              <div class="chips">
                <?php
                  $days = [['1','Lunes'],['2','Martes'],['3','Miércoles'],['4','Jueves'],['5','Viernes'],['6','Sábado'],['7','Domingo']];
                foreach ($days as $d) echo '<label class="chip"><input type="checkbox" name="days[]" value="'.h($d[0]).'"> '.h($d[1]).'</label>';
                ?>
              </div>
            </div>
            <div>
              <label>Rango de fechas (opcional)</label>
              <div class="form-row" style="grid-template-columns:1fr 1fr;gap:8px">
                <input type="date" name="date_start" placeholder="Desde">
                <input type="date" name="date_end" placeholder="Hasta">
              </div>
              <small class="muted">Déjalo en blanco para que aplique siempre.</small>
            </div>
          </div>
          <div style="margin-top:12px">
            <button class="primary" type="submit">Agregar bloque fijo</button>
          </div>
        </form>
      </div>

      <div class="admin-card">
        <h4 style="margin:0 0 10px 0">Lista de bloques fijos</h4>
        <?php $all = fixed_load(); ?>
        <?php if (!$all): ?>
          <div>No hay bloques fijos. Agrega uno arriba.</div>
        <?php else: ?>
          <table class="table">
            <tr><th>Título</th><th>Quién</th><th>Días</th><th>Horario</th><th>Rango</th><th>Lugar</th><th></th></tr>
            <?php
              $names=[1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
              foreach ($all as $b):
                $diasTxt=[];
                foreach ((array)$b['days'] as $d){ $n=normalize_day($d); if($n) $diasTxt[]=$names[$n]; }
            ?>
              <tr>
                <td><?=h($b['label'])?></td>
                <td><?=h($b['who']==='el'?'Él':($b['who']==='ella'?'Ella':'Ambos'))?></td>
                <td><?=h(implode(', ',$diasTxt))?></td>
                <td><?=h(($b['start']??'').' - '.($b['end']??''))?></td>
                <td><?=h(($b['date_start'] ?? '—').' → '.($b['date_end'] ?? '—'))?></td>
                <td><?=h($b['location'] ?? '')?></td>
                <td style="text-align:right">
                  <form method="post" onsubmit="return confirm('¿Eliminar bloque fijo?');" style="display:inline">
                    <input type="hidden" name="csrf" value="<?=h($csrf)?>">
                    <input type="hidden" name="action" value="fixed_delete">
                    <input type="hidden" name="id" value="<?=h($b['id'] ?? '')?>">
                    <button type="submit" style="border-color:#5a2740;background:#2a0f1e;color:#ffb3c1">Eliminar</button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          </table>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
  <!-- ======= /ADMIN BLOQUES FIJOS ======= -->

  <footer>
    <?=h(APP_TITLE)?> · PHP plano · Archivos: <code>data/tasks.json</code> / <code>data/fixed.json</code>
  </footer>
</div>
</body>
</html>
