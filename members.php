<?php
// ============================================================
// VCBI — Member Management   http://localhost/localbanking/members.php
// Register, search, edit and suspend/activate members.
// ============================================================
require_once __DIR__ . '/api/vcbi_db.php';

const MEMBER_PIN = '0000';   // same PIN as the mobile money portal — change it!

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function normalisePhone(string $p): string {
    $d = preg_replace('/\D/', '', $p);
    if (strlen($d) === 12 && str_starts_with($d, '260')) $d = '0' . substr($d, 3);
    if (strlen($d) === 9) $d = '0' . $d;
    return $d;
}
function networkOf(string $phone): array {   // returns [mtn, airtel]
    $pre = substr($phone, 0, 3);
    if (in_array($pre, ['076', '096'])) return [$phone, null];
    if (in_array($pre, ['077', '057', '097'])) return [null, $phone];
    return [null, null];
}

$pdo = getVcbiDB();
$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['admin_pin'] ?? '') !== MEMBER_PIN) throw new Exception('Invalid admin PIN.');
        $action = $_POST['action'] ?? '';

        if ($action === 'add' || $action === 'edit') {
            $name  = trim($_POST['full_name'] ?? '');
            $phone = normalisePhone($_POST['phone_number'] ?? '');
            $nrc   = trim($_POST['national_id'] ?? '');
            $addr  = trim($_POST['address'] ?? '');
            $joined = $_POST['joined_date'] ?: date('Y-m-d');
            if ($name === '')  throw new Exception('Full name is required.');
            if ($phone === '' || strlen($phone) < 10) throw new Exception('Enter a valid phone number, e.g. 0977123456.');
            [$mtn, $airtel] = networkOf($phone);

            if ($action === 'add') {
                $dup = $pdo->prepare("SELECT id FROM vcbi_members WHERE phone_number = ? OR (national_id <> '' AND national_id = ?) LIMIT 1");
                $dup->execute([$phone, $nrc]);
                if ($dup->fetch()) throw new Exception('A member with this phone number or NRC already exists.');

                $next = (int)$pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(member_number,'-',-1) AS UNSIGNED)),0)+1 FROM vcbi_members WHERE member_number LIKE 'VCBI-%'")->fetchColumn();
                $num = 'VCBI-' . str_pad((string)$next, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO vcbi_members
                    (member_number, full_name, phone_number, phone_mtn, phone_airtel, national_id, address, status, joined_date)
                    VALUES (?,?,?,?,?,?,?, 'active', ?)")
                    ->execute([$num, $name, $phone, $mtn, $airtel, $nrc, $addr, $joined]);
                $msg = "Member registered: $num — $name";
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $pdo->prepare("UPDATE vcbi_members SET full_name=?, phone_number=?, phone_mtn=?, phone_airtel=?, national_id=?, address=?, joined_date=? WHERE id=?")
                    ->execute([$name, $phone, $mtn, $airtel, $nrc, $addr, $joined, $id]);
                $msg = "Member updated: $name";
            }
        } elseif ($action === 'status') {
            $id = (int)($_POST['id'] ?? 0);
            $st = in_array($_POST['status'] ?? '', ['active','suspended','closed']) ? $_POST['status'] : 'active';
            $pdo->prepare("UPDATE vcbi_members SET status=? WHERE id=?")->execute([$st, $id]);
            $msg = "Member status set to $st.";
        }
    } catch (Throwable $e) {
        $err = $e->getMessage();
        if ($e instanceof PDOException && str_contains($err, 'Duplicate')) $err = 'Duplicate entry — member number, phone or NRC already used.';
    }
}

$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $st = $pdo->prepare("SELECT * FROM vcbi_members WHERE full_name LIKE ? OR phone_number LIKE ? OR member_number LIKE ? OR national_id LIKE ? ORDER BY id DESC");
    $like = "%$q%"; $st->execute([$like, $like, $like, $like]);
} else {
    $st = $pdo->query("SELECT * FROM vcbi_members ORDER BY id DESC");
}
$members = $st->fetchAll();

$editing = null;
if (isset($_GET['edit'])) {
    $e = $pdo->prepare("SELECT * FROM vcbi_members WHERE id = ?");
    $e->execute([(int)$_GET['edit']]);
    $editing = $e->fetch() ?: null;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VCBI — Members</title>
<style>
  :root { --gold:#F5A623; --green:#00C48C; --red:#E84040; --dark:#0D0F14; --panel:#141820; --card:#1C2230; --border:#2A3245; --text:#E8EAF0; --muted:#6B7894; }
  * { box-sizing:border-box; margin:0; padding:0; }
  body { background:var(--dark); color:var(--text); font-family:system-ui,Segoe UI,Arial,sans-serif; font-size:14px; }
  .topbar { background:var(--panel); border-bottom:1px solid var(--border); padding:0 24px; height:58px; display:flex; align-items:center; justify-content:space-between; }
  .logo { font-family:monospace; font-weight:700; font-size:16px; color:var(--gold); letter-spacing:1px; }
  a { color:var(--gold); text-decoration:none; }
  .wrap { max-width:1100px; margin:0 auto; padding:24px; }
  h1 { font-size:22px; margin-bottom:4px; } .sub { color:var(--muted); margin-bottom:20px; }
  .card { background:var(--card); border:1px solid var(--border); border-radius:12px; padding:20px; margin-bottom:20px; }
  .card h2 { font-size:15px; margin-bottom:14px; }
  .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:14px; }
  label { display:block; font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; margin-bottom:5px; }
  input, textarea, select { width:100%; background:var(--dark); border:1px solid var(--border); color:var(--text); padding:10px 12px; border-radius:8px; font-size:14px; }
  input:focus, textarea:focus { outline:none; border-color:var(--gold); }
  button, .btn { background:var(--gold); color:#000; border:0; padding:10px 18px; border-radius:8px; font-weight:700; cursor:pointer; font-size:13px; display:inline-block; }
  .btn-sm { padding:5px 10px; font-size:12px; } .btn-ghost { background:transparent; color:var(--text); border:1px solid var(--border); }
  .btn-red { background:var(--red); color:#fff; } .btn-green { background:var(--green); }
  .alert { padding:12px 16px; border-radius:8px; margin-bottom:16px; }
  .ok { background:rgba(0,196,140,.12); border:1px solid var(--green); color:var(--green); }
  .bad { background:rgba(232,64,64,.12); border:1px solid var(--red); color:#ff8a8a; }
  table { width:100%; border-collapse:collapse; } th { text-align:left; font-size:11px; color:var(--muted); text-transform:uppercase; letter-spacing:1px; padding:8px; border-bottom:1px solid var(--border); }
  td { padding:10px 8px; border-bottom:1px solid var(--border); vertical-align:middle; }
  .scroll { overflow-x:auto; }
  .badge { padding:3px 9px; border-radius:20px; font-size:11px; font-weight:700; }
  .b-active { background:rgba(0,196,140,.15); color:var(--green); } .b-suspended { background:rgba(245,166,35,.15); color:var(--gold); } .b-closed { background:rgba(232,64,64,.15); color:#ff8a8a; }
  .mono { font-family:monospace; color:var(--gold); }
  form.inline { display:inline; } .row { display:flex; gap:8px; flex-wrap:wrap; align-items:end; }
</style>
</head>
<body>
<div class="topbar">
  <div class="logo">VCBI · MEMBERS</div>
  <a href="mobilemoney_manual.php">← Back to Mobile Money Portal</a>
</div>
<div class="wrap">
  <h1>Member Management</h1>
  <p class="sub"><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?> <?= $q !== '' ? 'matching “' . h($q) . '”' : 'registered' ?></p>

  <?php if ($msg): ?><div class="alert ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert bad"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <h2><?= $editing ? 'Edit member — ' . h($editing['member_number']) : 'Register a new member' ?></h2>
    <form method="post" action="members.php">
      <input type="hidden" name="action" value="<?= $editing ? 'edit' : 'add' ?>">
      <?php if ($editing): ?><input type="hidden" name="id" value="<?= (int)$editing['id'] ?>"><?php endif; ?>
      <div class="grid">
        <div><label>Full name *</label><input name="full_name" required value="<?= h($editing['full_name'] ?? '') ?>"></div>
        <div><label>Phone number * (0977123456)</label><input name="phone_number" required value="<?= h($editing['phone_number'] ?? '') ?>"></div>
        <div><label>NRC / National ID</label><input name="national_id" value="<?= h($editing['national_id'] ?? '') ?>"></div>
        <div><label>Date joined</label><input type="date" name="joined_date" value="<?= h($editing['joined_date'] ?? date('Y-m-d')) ?>"></div>
      </div>
      <div style="margin-top:14px"><label>Address</label><textarea name="address" rows="2"><?= h($editing['address'] ?? '') ?></textarea></div>
      <div class="row" style="margin-top:14px">
        <div style="width:160px"><label>Admin PIN *</label><input type="password" name="admin_pin" required></div>
        <button type="submit"><?= $editing ? 'Save changes' : 'Register member' ?></button>
        <?php if ($editing): ?><a class="btn btn-ghost" href="members.php">Cancel</a><?php endif; ?>
      </div>
      <p style="color:var(--muted);font-size:12px;margin-top:10px">The member number (VCBI-0001, VCBI-0002…) is created automatically. MTN/Airtel is detected from the phone prefix.</p>
    </form>
  </div>

  <div class="card">
    <form method="get" class="row" style="margin-bottom:14px">
      <div style="flex:1;min-width:220px"><label>Search</label><input name="q" placeholder="Name, phone, member number or NRC" value="<?= h($q) ?>"></div>
      <button type="submit">Search</button>
      <?php if ($q !== ''): ?><a class="btn btn-ghost" href="members.php">Clear</a><?php endif; ?>
    </form>
    <div class="scroll">
    <table>
      <thead><tr><th>Member #</th><th>Name</th><th>Phone</th><th>Network</th><th>NRC</th><th>Balance (ZMW)</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php if (!$members): ?><tr><td colspan="8" style="color:var(--muted)">No members found.</td></tr><?php endif; ?>
      <?php foreach ($members as $m):
        $net = $m['phone_mtn'] ? 'MTN' : ($m['phone_airtel'] ? 'Airtel' : '—'); ?>
        <tr>
          <td class="mono"><?= h($m['member_number']) ?></td>
          <td><?= h($m['full_name']) ?></td>
          <td><?= h($m['phone_number']) ?></td>
          <td><?= $net ?></td>
          <td><?= h($m['national_id']) ?></td>
          <td><?= number_format((float)$m['account_balance'], 2) ?></td>
          <td><span class="badge b-<?= h($m['status']) ?>"><?= h($m['status']) ?></span></td>
          <td>
            <a class="btn btn-sm btn-ghost" href="?edit=<?= (int)$m['id'] ?>">Edit</a>
            <form class="inline" method="post" onsubmit="var p=prompt('Enter admin PIN'); if(!p) return false; this.admin_pin.value=p;">
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="id" value="<?= (int)$m['id'] ?>">
              <input type="hidden" name="admin_pin" value="">
              <input type="hidden" name="status" value="<?= $m['status'] === 'active' ? 'suspended' : 'active' ?>">
              <button class="btn-sm <?= $m['status'] === 'active' ? 'btn-red' : 'btn-green' ?>" type="submit"><?= $m['status'] === 'active' ? 'Suspend' : 'Activate' ?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>
</body>
</html>
