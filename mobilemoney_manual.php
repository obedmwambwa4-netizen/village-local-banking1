<?php
// ============================================================
// VCBI — Village Community Banking Initiative
// Manual Mobile Money Verification System
// Supports: MTN MoMo + Airtel Money (Zambia)
// Database: MySQL (uses shared config.php)
// Mode: MANUAL (No API keys needed — works TODAY)
// ============================================================

// Use the shared DB config — avoids duplicate constants/connection
require_once __DIR__ . '/api/config.php';

// ─── VCBI SETTINGS ──────────────────────────────────────────
define('VCBI_NAME',     'VCBI Village Community Banking');
define('VCBI_CURRENCY', 'ZMW');
define('ADMIN_PIN',     '0000');  // Change this to a secure PIN

// ─── TRANSACTION TYPES ──────────────────────────────────────
define('TX_DEPOSIT',           'deposit');
define('TX_WITHDRAWAL',        'withdrawal');
define('TX_LOAN_REPAY',        'loan_repayment');
define('TX_LOAN_DISBURSEMENT', 'loan_disbursement');
define('TX_SAVINGS',           'savings');
define('TX_TRANSFER',          'transfer');
define('TX_SHARE',             'share_purchase');
define('TX_FEE',               'fee');

// ─── MOBILE MONEY PROVIDERS ─────────────────────────────────
define('PROVIDER_MTN',    'MTN MoMo');
define('PROVIDER_AIRTEL', 'Airtel Money');

// ============================================================
// DATABASE SETUP — Run once to create tables
// ============================================================
function setupDatabase(): void {
    $pdo = getDB();

    // Members table — mirrors the main `members` table structure used by the app
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vcbi_members (
            id              INT AUTO_INCREMENT PRIMARY KEY,
            member_number   VARCHAR(20) UNIQUE NOT NULL,
            full_name       VARCHAR(150) NOT NULL,
            phone_number    VARCHAR(20),
            phone_mtn       VARCHAR(20),
            phone_airtel    VARCHAR(20),
            national_id     VARCHAR(30),
            address         TEXT,
            account_balance  DECIMAL(15,2) DEFAULT 0.00,
            savings_balance  DECIMAL(15,2) DEFAULT 0.00,
            share_balance    DECIMAL(15,2) DEFAULT 0.00,
            status          ENUM('active','suspended','closed') DEFAULT 'active',
            joined_date     DATE NOT NULL,
            created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vcbi_mobile_transactions (
            id                INT AUTO_INCREMENT PRIMARY KEY,
            transaction_ref   VARCHAR(50) UNIQUE NOT NULL,
            mm_transaction_id VARCHAR(100) UNIQUE NOT NULL,
            provider          VARCHAR(20) NOT NULL,
            transaction_type  VARCHAR(30) NOT NULL,
            member_id         INT,
            member_number     VARCHAR(20),
            member_name       VARCHAR(150),
            phone_number      VARCHAR(20) NOT NULL,
            amount            DECIMAL(15,2) NOT NULL,
            currency          VARCHAR(5) DEFAULT 'ZMW',
            description       TEXT,
            status            ENUM('pending','verified','rejected','reversed') DEFAULT 'pending',
            verified_by       VARCHAR(100),
            verified_at       TIMESTAMP NULL,
            rejection_reason  TEXT,
            admin_notes       TEXT,
            created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vcbi_loans (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            loan_number      VARCHAR(30) UNIQUE NOT NULL,
            member_id        INT NOT NULL,
            principal_amount DECIMAL(15,2) NOT NULL,
            interest_rate    DECIMAL(5,2) NOT NULL,
            loan_term_months INT NOT NULL,
            monthly_payment  DECIMAL(15,2) NOT NULL,
            total_repayable  DECIMAL(15,2) NOT NULL,
            amount_paid      DECIMAL(15,2) DEFAULT 0.00,
            balance_due      DECIMAL(15,2) NOT NULL,
            status           ENUM('active','completed','defaulted','written_off') DEFAULT 'active',
            disbursed_date   DATE,
            due_date         DATE,
            created_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (member_id) REFERENCES vcbi_members(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS vcbi_audit_log (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            action       VARCHAR(100) NOT NULL,
            table_name   VARCHAR(50),
            record_id    INT,
            details      TEXT,
            performed_by VARCHAR(100),
            ip_address   VARCHAR(45),
            created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

// ============================================================
// CORE FUNCTIONS
// ============================================================

function generateRef(string $prefix = 'VCBI'): string {
    return $prefix . '-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

function logAudit(string $action, string $table, int $recordId, string $details, string $by = 'admin'): void {
    $pdo  = getDB();
    $stmt = $pdo->prepare("
        INSERT INTO vcbi_audit_log (action, table_name, record_id, details, performed_by, ip_address)
        VALUES (?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$action, $table, $recordId, $details, $by, $_SERVER['REMOTE_ADDR'] ?? 'CLI']);
}

// ─── RECORD A NEW MANUAL MOBILE MONEY TRANSACTION ───────────
function recordManualTransaction(array $data): array {
    $pdo = getDB();

    $required = ['mm_transaction_id', 'provider', 'transaction_type', 'phone_number', 'amount'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            return ['success' => false, 'message' => "Missing required field: $field"];
        }
    }

    // Duplicate check
    $stmt = $pdo->prepare("SELECT id FROM vcbi_mobile_transactions WHERE mm_transaction_id = ?");
    $stmt->execute([$data['mm_transaction_id']]);
    if ($stmt->fetch()) {
        return ['success' => false, 'message' => 'Transaction ID already exists. Duplicate entry prevented.'];
    }

    // Auto-detect member from the main members table using phone number
    $member = null;
    if (!empty($data['phone_number'])) {
        $phone  = $data['phone_number'];
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 12 && str_starts_with($digits, '260')) {
            $digits = '0' . substr($digits, 3);
        }
        $stmt = $pdo->prepare("
            SELECT id,
                   national_id AS member_number,
                   name        AS full_name,
                   phone       AS phone_number,
                   savings     AS account_balance,
                   status
            FROM members
            WHERE (phone LIKE ? OR name LIKE ?)
               AND status = 'active'
            LIMIT 1
        ");
        $stmt->execute(['%' . $digits . '%', '%' . trim($phone) . '%']);
        $member = $stmt->fetch() ?: null;
    }

    $ref  = generateRef();
    $stmt = $pdo->prepare("
        INSERT INTO vcbi_mobile_transactions
            (transaction_ref, mm_transaction_id, provider, transaction_type,
             member_id, member_number, member_name, phone_number,
             amount, currency, description, status, verified_by, verified_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ZMW', ?, 'verified', ?, NOW())
    ");

    $stmt->execute([
        $ref,
        strtoupper(trim($data['mm_transaction_id'])),
        $data['provider'],
        $data['transaction_type'],
        $member['id']            ?? null,
        $member['member_number'] ?? null,
        $member['full_name']     ?? ($data['member_name'] ?? 'Walk-in / Unknown'),
        $data['phone_number'],
        $data['amount'],
        $data['description']  ?? '',
        $data['verified_by']  ?? 'admin',
    ]);

    $txId = (int)$pdo->lastInsertId();

    if ($member) {
        updateMemberBalance($member['id'], $data['transaction_type'], (float)$data['amount'], $pdo);
    }

    logAudit('MANUAL_TX_RECORDED', 'vcbi_mobile_transactions', $txId,
        "Ref: $ref | Provider: {$data['provider']} | Type: {$data['transaction_type']} | Amount: ZMW {$data['amount']}",
        $data['verified_by'] ?? 'admin'
    );

    return [
        'success'         => true,
        'message'         => 'Transaction recorded and verified successfully.',
        'transaction_ref' => $ref,
        'transaction_id'  => $txId,
        'member_found'    => $member !== null,
        'member_name'     => $member['full_name'] ?? 'Not linked to a member',
    ];
}

// ─── UPDATE MEMBER ACCOUNT BALANCE ──────────────────────────
function updateMemberBalance(int $memberId, string $txType, float $amount, PDO $pdo): void {
    $creditTypes = [TX_DEPOSIT, TX_SAVINGS, TX_LOAN_REPAY, TX_SHARE];
    $debitTypes  = [TX_WITHDRAWAL, TX_LOAN_DISBURSEMENT, TX_FEE, TX_TRANSFER];

    if (in_array($txType, $creditTypes)) {
        // All credit types increase savings in the main members table
        $pdo->prepare("UPDATE members SET savings = savings + ? WHERE id = ?")
            ->execute([$amount, $memberId]);

        if ($txType === TX_LOAN_REPAY) {
            // Also update the loan repaid amount in the main loans table
            $pdo->prepare("
                UPDATE loans SET repaid = repaid + ?
                WHERE member_id = ? AND status = 'active'
                ORDER BY applied_date ASC LIMIT 1
            ")->execute([$amount, $memberId]);
        }
    } elseif (in_array($txType, $debitTypes)) {
        // Debit reduces savings
        $pdo->prepare("UPDATE members SET savings = savings - ? WHERE id = ?")
            ->execute([$amount, $memberId]);
    }
}

// ─── VERIFY TRANSACTION ──────────────────────────────────────
function verifyTransaction(int $txId, string $verifiedBy): array {
    $pdo = getDB();
    $pdo->prepare("UPDATE vcbi_mobile_transactions SET status='verified', verified_by=?, verified_at=NOW() WHERE id=?")
        ->execute([$verifiedBy, $txId]);
    logAudit('TX_VERIFIED', 'vcbi_mobile_transactions', $txId, "Verified by: $verifiedBy", $verifiedBy);
    return ['success' => true, 'message' => 'Transaction verified.'];
}

// ─── REJECT TRANSACTION ──────────────────────────────────────
function rejectTransaction(int $txId, string $reason, string $by): array {
    $pdo = getDB();
    $pdo->prepare("UPDATE vcbi_mobile_transactions SET status='rejected', rejection_reason=?, verified_by=?, verified_at=NOW() WHERE id=?")
        ->execute([$reason, $by, $txId]);
    logAudit('TX_REJECTED', 'vcbi_mobile_transactions', $txId, "Reason: $reason", $by);
    return ['success' => true, 'message' => 'Transaction rejected.'];
}

// ─── LOOKUP MEMBER — searches the main `members` table ────────
function lookupMemberMM(string $query): ?array {
    $query = trim($query);
    if ($query === '') return null;

    $pdo    = getDB();
    $digits = preg_replace('/\D/', '', $query);

    // Normalise +260977… → 0977…
    if (strlen($digits) === 12 && str_starts_with($digits, '260')) {
        $digits = '0' . substr($digits, 3);
    }

    $select = "
        SELECT id,
               national_id AS member_number,
               name        AS full_name,
               phone       AS phone_number,
               savings     AS account_balance,
               savings     AS savings_balance,
               0.00        AS share_balance,
               status
        FROM members
    ";

    // ─── Helper: assign phone to the correct provider field ─────
    $assignProvider = function(?array $row): ?array {
        if (!$row) return null;
        $phone  = preg_replace('/\D/', '', $row['phone_number'] ?? '');
        // Normalise +260… → 0…
        if (strlen($phone) === 12 && str_starts_with($phone, '260')) {
            $phone = '0' . substr($phone, 3);
        }
        $prefix3 = substr($phone, 0, 3);
        // Zambia MTN prefixes:   076, 096
        // Zambia Airtel prefixes: 077, 057, 097
        $isMTN    = in_array($prefix3, ['076', '096']);
        $isAirtel = in_array($prefix3, ['077', '057', '097']);
        $row['phone_mtn']    = $isMTN    ? $row['phone_number'] : null;
        $row['phone_airtel'] = $isAirtel ? $row['phone_number'] : null;
        return $row;
    };

    // 1. Exact NRC / national ID match
    $stmt = $pdo->prepare($select . "WHERE national_id = ? LIMIT 1");
    $stmt->execute([strtoupper($query)]);
    $row = $stmt->fetch() ?: null;
    if ($row) return $assignProvider($row);

    // 2. Name match (case-insensitive partial)
    $stmt = $pdo->prepare($select . "WHERE name LIKE ? LIMIT 1");
    $stmt->execute(['%' . $query . '%']);
    $row = $stmt->fetch() ?: null;
    if ($row) return $assignProvider($row);

    // 3. Phone match — only run if the query actually contains digits
    if ($digits !== '') {
        $stmt = $pdo->prepare($select . "WHERE phone LIKE ? LIMIT 1");
        $stmt->execute(['%' . $digits . '%']);
        $row = $stmt->fetch() ?: null;
        if ($row) return $assignProvider($row);
    }

    return null;
}

// ─── GET RECENT TRANSACTIONS ─────────────────────────────────
function getRecentTransactions(int $limit = 20, string $status = ''): array {
    $pdo   = getDB();
    $where = $status ? "WHERE t.status = " . $pdo->quote($status) : '';
    $stmt  = $pdo->prepare("
        SELECT t.*, m.name AS linked_member
        FROM vcbi_mobile_transactions t
        LEFT JOIN members m ON t.member_id = m.id
        $where
        ORDER BY t.created_at DESC
        LIMIT " . (int)$limit . "
    ");
    $stmt->execute();
    return $stmt->fetchAll();
}

// ─── TRANSACTION STATS ───────────────────────────────────────
function getTransactionStats(): array {
    $pdo   = getDB();
    $today = date('Y-m-d');
    $stats = [];

    $stmt = $pdo->query("SELECT COUNT(*) AS total, SUM(amount) AS volume FROM vcbi_mobile_transactions WHERE status='verified'");
    $stats['all_time'] = $stmt->fetch();

    $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(amount) AS volume FROM vcbi_mobile_transactions WHERE status='verified' AND DATE(created_at) = ?");
    $stmt->execute([$today]);
    $stats['today'] = $stmt->fetch();

    $stmt = $pdo->query("SELECT COUNT(*) AS total FROM vcbi_mobile_transactions WHERE status='pending'");
    $stats['pending'] = $stmt->fetch()['total'];

    $stmt = $pdo->query("SELECT provider, COUNT(*) AS count, SUM(amount) AS volume FROM vcbi_mobile_transactions WHERE status='verified' GROUP BY provider");
    $stats['by_provider'] = $stmt->fetchAll();

    $stmt = $pdo->query("SELECT transaction_type, COUNT(*) AS count, SUM(amount) AS volume FROM vcbi_mobile_transactions WHERE status='verified' GROUP BY transaction_type ORDER BY volume DESC");
    $stats['by_type'] = $stmt->fetchAll();

    return $stats;
}

// ─── MEMBER STATEMENT ────────────────────────────────────────
function getMemberStatement(int $memberId, string $from = '', string $to = ''): array {
    $pdo  = getDB();
    $from = $from ?: date('Y-m-01');
    $to   = $to   ?: date('Y-m-d');

    $stmt = $pdo->prepare("
        SELECT * FROM vcbi_mobile_transactions
        WHERE member_id = ? AND status = 'verified'
          AND DATE(created_at) BETWEEN ? AND ?
        ORDER BY created_at ASC
    ");
    $stmt->execute([$memberId, $from, $to]);
    $txns = $stmt->fetchAll();

    $mStmt = $pdo->prepare("
        SELECT id, national_id AS member_number, name AS full_name,
               phone AS phone_number, savings AS account_balance, status
        FROM members WHERE id = ?
    ");
    $mStmt->execute([$memberId]);

    $creditTypes = [TX_DEPOSIT, TX_SAVINGS, TX_LOAN_REPAY, TX_SHARE];
    $debitTypes  = [TX_WITHDRAWAL, TX_LOAN_DISBURSEMENT, TX_FEE];

    return [
        'member'       => $mStmt->fetch(),
        'transactions' => $txns,
        'period'       => ['from' => $from, 'to' => $to],
        'total_in'     => array_sum(array_column(array_filter($txns, fn($t) => in_array($t['transaction_type'], $creditTypes)), 'amount')),
        'total_out'    => array_sum(array_column(array_filter($txns, fn($t) => in_array($t['transaction_type'], $debitTypes)), 'amount')),
    ];
}

// ============================================================
// AJAX / API HANDLER
// ============================================================
if (isset($_POST['action']) || isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? $_GET['action'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $pin = $_POST['admin_pin'] ?? '';
        if ($pin !== ADMIN_PIN) {
            echo json_encode(['success' => false, 'message' => 'Invalid admin PIN.']);
            exit;
        }
    }

    try {
    switch ($action) {
        case 'setup':
            setupDatabase();
            echo json_encode(['success' => true, 'message' => 'Database tables created successfully.']);
            break;

        case 'record_transaction':
            echo json_encode(recordManualTransaction($_POST));
            break;

        case 'lookup_member':
            $member = lookupMemberMM($_GET['query'] ?? '');
            echo json_encode($member
                ? ['success' => true, 'member' => $member]
                : ['success' => false, 'message' => 'Member not found.']
            );
            break;

        case 'recent_transactions':
            echo json_encode(['success' => true, 'data' => getRecentTransactions(30, $_GET['status'] ?? '')]);
            break;

        case 'stats':
            echo json_encode(['success' => true, 'data' => getTransactionStats()]);
            break;

        case 'member_statement':
            echo json_encode(getMemberStatement((int)$_GET['member_id'], $_GET['from'] ?? '', $_GET['to'] ?? ''));
            break;

        case 'verify':
            echo json_encode(verifyTransaction((int)$_POST['tx_id'], $_POST['verified_by'] ?? 'admin'));
            break;

        case 'reject':
            echo json_encode(rejectTransaction((int)$_POST['tx_id'], $_POST['reason'] ?? '', $_POST['verified_by'] ?? 'admin'));
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    }
    exit;
}
// ADMIN UI
// ============================================================
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>VCBI — Mobile Money Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=Sora:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --gold:    #F5A623;
    --green:   #00C48C;
    --red:     #E84040;
    --blue:    #2563EB;
    --mtn:     #FFCC00;
    --airtel:  #E40000;
    --dark:    #0D0F14;
    --panel:   #141820;
    --card:    #1C2230;
    --border:  #2A3245;
    --text:    #E8EAF0;
    --muted:   #6B7894;
    --font-mono: 'Space Mono', monospace;
    --font:      'Sora', sans-serif;
  }

  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--dark);
    color: var(--text);
    font-family: var(--font);
    font-size: 14px;
    min-height: 100vh;
  }

  /* ── TOP BAR ── */
  .topbar {
    background: var(--panel);
    border-bottom: 1px solid var(--border);
    padding: 0 24px;
    height: 58px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 100;
  }
  .logo {
    font-family: var(--font-mono);
    font-weight: 700;
    font-size: 16px;
    letter-spacing: 1px;
    color: var(--gold);
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .logo-badge {
    background: var(--gold);
    color: var(--dark);
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 3px;
    letter-spacing: 1.5px;
  }
  .topbar-right {
    display: flex;
    align-items: center;
    gap: 14px;
    font-size: 12px;
    color: var(--muted);
  }
  .status-dot {
    width: 8px; height: 8px;
    border-radius: 50%;
    background: var(--green);
    display: inline-block;
    margin-right: 5px;
    box-shadow: 0 0 6px var(--green);
  }

  /* ── LAYOUT ── */
  .layout { display: flex; height: calc(100vh - 58px); }

  /* ── SIDEBAR ── */
  .sidebar {
    width: 220px;
    background: var(--panel);
    border-right: 1px solid var(--border);
    padding: 20px 0;
    flex-shrink: 0;
  }
  .nav-section { padding: 0 16px 8px; font-size: 10px; color: var(--muted); letter-spacing: 1.5px; text-transform: uppercase; margin-top: 16px; }
  .nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 20px;
    color: var(--muted);
    cursor: pointer;
    transition: all 0.15s;
    border-left: 3px solid transparent;
    font-size: 13px;
  }
  .nav-item:hover { color: var(--text); background: rgba(255,255,255,0.03); }
  .nav-item.active { color: var(--gold); border-left-color: var(--gold); background: rgba(245,166,35,0.06); }
  .nav-icon { font-size: 16px; width: 20px; text-align: center; }

  /* ── MAIN ── */
  .main {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
  }

  /* ── PAGE HEADER ── */
  .page-header {
    margin-bottom: 24px;
  }
  .page-title {
    font-size: 22px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 4px;
  }
  .page-sub {
    color: var(--muted);
    font-size: 13px;
  }

  /* ── STAT CARDS ── */
  .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 24px; }
  .stat-card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 18px;
    position: relative;
    overflow: hidden;
  }
  .stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
  }
  .stat-card.gold::before  { background: var(--gold); }
  .stat-card.green::before { background: var(--green); }
  .stat-card.blue::before  { background: var(--blue); }
  .stat-card.red::before   { background: var(--red); }
  .stat-label { font-size: 11px; color: var(--muted); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 10px; }
  .stat-value { font-family: var(--font-mono); font-size: 22px; font-weight: 700; color: var(--text); }
  .stat-sub   { font-size: 11px; color: var(--muted); margin-top: 6px; }

  /* ── CARDS / PANELS ── */
  .card {
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 22px;
    margin-bottom: 20px;
  }
  .card-title {
    font-weight: 600;
    font-size: 15px;
    margin-bottom: 18px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .card-title span { font-size: 18px; }

  /* ── GRID ── */
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }

  /* ── FORMS ── */
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
  .form-group { margin-bottom: 14px; }
  .form-group label {
    display: block;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    margin-bottom: 6px;
  }
  .form-control {
    width: 100%;
    background: var(--dark);
    border: 1px solid var(--border);
    color: var(--text);
    padding: 10px 14px;
    border-radius: 7px;
    font-family: var(--font);
    font-size: 13px;
    transition: border-color 0.15s;
    outline: none;
  }
  .form-control:focus { border-color: var(--gold); }
  select.form-control { cursor: pointer; }

  /* ── PROVIDER TOGGLE ── */
  .provider-toggle { display: flex; gap: 10px; margin-bottom: 16px; }
  .provider-btn {
    flex: 1; padding: 14px 12px; border-radius: 10px; border: 2px solid var(--border);
    cursor: pointer; text-align: center; font-weight: 700; font-size: 13px;
    transition: all 0.2s; background: var(--dark);
    display: flex; flex-direction: column; align-items: center; gap: 8px;
  }
  .provider-btn .provider-logo { display: flex; align-items: center; justify-content: center; }
  .provider-btn .provider-label { font-size: 12px; letter-spacing: 0.5px; }
  .provider-btn.mtn   { color: var(--mtn); }
  .provider-btn.airtel { color: var(--airtel); }
  .provider-btn.selected-mtn    { border-color: var(--mtn);    background: rgba(255,204,0,0.08); box-shadow: 0 0 12px rgba(255,204,0,0.15); }
  .provider-btn.selected-airtel { border-color: var(--airtel); background: rgba(228,0,0,0.08);  box-shadow: 0 0 12px rgba(228,0,0,0.15); }

  /* ── BUTTONS ── */
  .btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 20px;
    border-radius: 7px;
    border: none;
    cursor: pointer;
    font-family: var(--font);
    font-weight: 600;
    font-size: 13px;
    transition: all 0.15s;
  }
  .btn-primary { background: var(--gold); color: var(--dark); }
  .btn-primary:hover { background: #e8961a; }
  .btn-success { background: var(--green); color: var(--dark); }
  .btn-danger  { background: var(--red);   color: #fff; }
  .btn-ghost   { background: transparent; border: 1px solid var(--border); color: var(--text); }
  .btn-ghost:hover { border-color: var(--gold); color: var(--gold); }
  .btn-sm { padding: 6px 12px; font-size: 12px; }
  .btn:disabled { opacity: 0.5; cursor: not-allowed; }

  /* ── MEMBER LOOKUP BOX ── */
  .member-found {
    background: rgba(0,196,140,0.08);
    border: 1px solid var(--green);
    border-radius: 8px;
    padding: 12px 16px;
    margin-bottom: 14px;
    display: none;
  }
  .member-found.show { display: block; }
  .member-found-name { font-weight: 600; color: var(--green); font-size: 15px; }
  .member-found-detail { font-size: 12px; color: var(--muted); margin-top: 3px; }

  /* ── TABLE ── */
  table { width: 100%; border-collapse: collapse; }
  th {
    text-align: left;
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    padding: 8px 12px;
    border-bottom: 1px solid var(--border);
  }
  td { padding: 11px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); font-size: 13px; vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: rgba(255,255,255,0.02); }

  /* ── BADGES ── */
  .badge {
    display: inline-block;
    padding: 3px 9px;
    border-radius: 20px;
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
  }
  .badge-verified  { background: rgba(0,196,140,0.15); color: var(--green); }
  .badge-pending   { background: rgba(245,166,35,0.15); color: var(--gold); }
  .badge-rejected  { background: rgba(232,64,64,0.15);  color: var(--red); }
  .badge-mtn       { background: rgba(255,204,0,0.15);  color: var(--mtn); }
  .badge-airtel    { background: rgba(228,0,0,0.15);    color: #ff6b6b; }

  /* ── TX TYPE COLORS ── */
  .tx-credit { color: var(--green); font-family: var(--font-mono); }
  .tx-debit  { color: var(--red);   font-family: var(--font-mono); }

  /* ── ALERT ── */
  .alert {
    padding: 12px 16px;
    border-radius: 8px;
    margin-bottom: 16px;
    font-size: 13px;
    display: none;
  }
  .alert.show { display: block; }
  .alert-success { background: rgba(0,196,140,0.12); border: 1px solid var(--green); color: var(--green); }
  .alert-error   { background: rgba(232,64,64,0.12);  border: 1px solid var(--red);   color: var(--red); }

  /* ── TABS ── */
  .tabs { display: flex; border-bottom: 1px solid var(--border); margin-bottom: 20px; }
  .tab {
    padding: 10px 20px;
    cursor: pointer;
    color: var(--muted);
    font-size: 13px;
    font-weight: 600;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    transition: all 0.15s;
  }
  .tab.active { color: var(--gold); border-bottom-color: var(--gold); }
  .tab-content { display: none; }
  .tab-content.active { display: block; }

  /* ── PAGES ── */
  .page { display: none; }
  .page.active { display: block; }

  /* ── REF BOX ── */
  .ref-box {
    background: var(--dark);
    border: 1px solid var(--border);
    border-radius: 6px;
    padding: 14px 18px;
    font-family: var(--font-mono);
    font-size: 18px;
    color: var(--gold);
    text-align: center;
    letter-spacing: 2px;
    margin: 12px 0;
  }

  /* ── RESPONSIVE ── */
  @media (max-width: 900px) {
    .stats-row { grid-template-columns: 1fr 1fr; }
    .grid-2    { grid-template-columns: 1fr; }
    .sidebar   { display: none; }
  }
</style>
</head>
<body>

<!-- TOP BAR -->
<div class="topbar">
  <div class="logo">
    <span>🏦</span> VCBI
    <span class="logo-badge">MANUAL MODE</span>
  </div>
  <div class="topbar-right">
    <span><span class="status-dot"></span>System Online</span>
    <span id="clock"></span>
  </div>
</div>

<div class="layout">

  <!-- SIDEBAR -->
  <div class="sidebar">
    <div class="nav-section">Main</div>
    <div class="nav-item active" onclick="showPage('dashboard')">
      <span class="nav-icon">📊</span> Dashboard
    </div>
    <div class="nav-item" onclick="showPage('record')">
      <span class="nav-icon">➕</span> Record Transaction
    </div>
    <div class="nav-item" onclick="showPage('transactions')">
      <span class="nav-icon">📋</span> All Transactions
    </div>

    <div class="nav-section">Members</div>
    <div class="nav-item" onclick="showPage('lookup')">
      <span class="nav-icon">🔍</span> Member Lookup
    </div>
    <div class="nav-item" onclick="showPage('statement')">
      <span class="nav-icon">📄</span> Statement
    </div>

    <div class="nav-section">System</div>
    <div class="nav-item" onclick="runSetup()">
      <span class="nav-icon">⚙️</span> Setup DB Tables
    </div>
  </div>

  <!-- MAIN -->
  <div class="main">

    <!-- ═══ DASHBOARD PAGE ═══ -->
    <div class="page active" id="page-dashboard">
      <div class="page-header">
        <div class="page-title">Dashboard</div>
        <div class="page-sub">VCBI Village Community Banking — Mobile Money Overview</div>
      </div>

      <div class="stats-row" id="stats-row">
        <div class="stat-card gold">
          <div class="stat-label">Today's Volume</div>
          <div class="stat-value" id="stat-today-vol">—</div>
          <div class="stat-sub" id="stat-today-count">Loading...</div>
        </div>
        <div class="stat-card green">
          <div class="stat-label">All-Time Volume</div>
          <div class="stat-value" id="stat-alltime-vol">—</div>
          <div class="stat-sub" id="stat-alltime-count">Loading...</div>
        </div>
        <div class="stat-card blue">
          <div class="stat-label">Pending Review</div>
          <div class="stat-value" id="stat-pending">—</div>
          <div class="stat-sub">Awaiting verification</div>
        </div>
        <div class="stat-card red">
          <div class="stat-label">Providers</div>
          <div class="stat-value">2</div>
          <div class="stat-sub">MTN MoMo + Airtel Money</div>
        </div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-title"><span>📡</span> By Provider</div>
          <div id="provider-stats">Loading...</div>
        </div>
        <div class="card">
          <div class="card-title"><span>🔄</span> By Transaction Type</div>
          <div id="type-stats">Loading...</div>
        </div>
      </div>

      <div class="card">
        <div class="card-title"><span>🕐</span> Recent Transactions</div>
        <div id="recent-table">Loading...</div>
      </div>
    </div>

    <!-- ═══ RECORD TRANSACTION PAGE ═══ -->
    <div class="page" id="page-record">
      <div class="page-header">
        <div class="page-title">Record Mobile Money Transaction</div>
        <div class="page-sub">Manually enter and verify an MTN/Airtel transaction</div>
      </div>

      <div class="grid-2">
        <div class="card">
          <div class="card-title"><span>📲</span> Transaction Details</div>

          <div id="record-alert" class="alert"></div>

          <!-- Provider -->
          <div class="form-group">
            <label>Mobile Money Provider *</label>
            <div class="provider-toggle">
              <!-- MTN MoMo Logo Button -->
              <div class="provider-btn mtn" id="btn-mtn" onclick="selectProvider('MTN MoMo')">
                <div class="provider-logo">
                  <!-- MTN: Yellow bg + blue oval + white MTN text -->
                  <svg width="90" height="42" viewBox="0 0 90 42" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="90" height="42" rx="8" fill="#FFCC00"/>
                    <!-- Blue oval -->
                    <ellipse cx="45" cy="21" rx="34" ry="14" fill="#006EAF"/>
                    <!-- MTN bold white text -->
                    <text x="45" y="26" text-anchor="middle"
                      font-family="Arial Black, Arial, sans-serif"
                      font-weight="900" font-size="17"
                      fill="#FFFFFF" letter-spacing="1.5">MTN</text>
                    <!-- Small red/yellow accent dot under N (brand detail) -->
                    <rect x="62" y="27" width="5" height="2.5" rx="1" fill="#FFCC00"/>
                    <rect x="62" y="27" width="2.5" height="2.5" rx="1" fill="#E40000"/>
                  </svg>
                </div>
                <div class="provider-label" style="color:var(--mtn); font-size:11px; font-weight:700; letter-spacing:1px;">MoMo</div>
              </div>

              <!-- Airtel Money Logo Button -->
              <div class="provider-btn airtel" id="btn-airtel" onclick="selectProvider('Airtel Money')">
                <div class="provider-logo">
                  <!-- Airtel: Red bg + white swirl + white airtel text -->
                  <svg width="90" height="42" viewBox="0 0 90 42" fill="none" xmlns="http://www.w3.org/2000/svg">
                    <rect width="90" height="42" rx="8" fill="#E40000"/>
                    <!-- Airtel swirl/curl icon (left side) -->
                    <g transform="translate(10, 5)">
                      <!-- Outer curl shape -->
                      <path d="M16 2 C8 2, 2 8, 2 16 C2 24, 8 30, 16 30 C20 30, 24 28, 26 24 C24 26, 20 27, 16 27 C10 27, 5 22, 5 16 C5 10, 10 5, 16 5 C19 5, 22 6.5, 24 9 L26 6 C23 3.5, 19.5 2, 16 2 Z"
                        fill="white"/>
                      <!-- Inner swirl tail -->
                      <path d="M22 14 C22 11, 19.5 8.5, 16 8.5 C12.5 8.5, 10 11, 10 14 C10 17.5, 12.5 20, 16 20 C18 20, 19.5 19, 21 17.5 L25 22 C22.5 25, 19.5 26.5, 16 26.5"
                        stroke="white" stroke-width="1" fill="none" opacity="0"/>
                      <!-- Simplified bold curl -->
                      <path d="M26 8 C26 8, 22 4, 15 5 C7 6, 2 12, 3 19 C4 26, 10 31, 18 30 C23 29.5, 27 27, 29 23 L25 20 C23.5 23, 21 25, 17.5 25.5 C12 26, 7.5 22, 7 17 C6.5 12, 10 8, 15 7.5 C19 7, 22 9, 23 12 L26 8 Z"
                        fill="white"/>
                    </g>
                    <!-- airtel lowercase text -->
                    <text x="56" y="26" text-anchor="middle"
                      font-family="Arial, Helvetica, sans-serif"
                      font-weight="700" font-size="14"
                      fill="#FFFFFF" letter-spacing="0.5">airtel</text>
                  </svg>
                </div>
                <div class="provider-label" style="color:#ff6b6b; font-size:11px; font-weight:700; letter-spacing:1px;">Money</div>
              </div>
            </div>
            <input type="hidden" id="f-provider" value="">
          </div>

          <!-- Transaction ID -->
          <div class="form-group">
            <label>MTN/Airtel Transaction ID *</label>
            <input type="text" class="form-control" id="f-txid" placeholder="e.g. AGX12345678 or A12345678" style="font-family:var(--font-mono); text-transform:uppercase">
          </div>

          <!-- Phone -->
          <div class="form-row">
            <div class="form-group">
              <label>Phone Number *</label>
              <div style="display:flex; align-items:center; border:1px solid var(--border); border-radius:7px; overflow:hidden; background:var(--dark);">
                <!-- Network + country code picker -->
                <select id="f-phone-prefix" onchange="onPrefixChange(this.value)"
                  style="background:var(--card); color:var(--text); border:none; border-right:1px solid var(--border);
                         padding:10px 8px; font-size:13px; font-family:var(--font-mono); font-weight:700;
                         cursor:pointer; outline:none; flex-shrink:0; width:auto; max-width:160px;">
                  <option value="">+260 —</option>
                  <optgroup label="MTN MoMo">
                    <option value="MTN MoMo:076">MTN  +260-76</option>
                    <option value="MTN MoMo:096">MTN  +260-96</option>
                  </optgroup>
                  <optgroup label="Airtel Money">
                    <option value="Airtel Money:077">Airtel +260-77</option>
                    <option value="Airtel Money:097">Airtel +260-97</option>
                    <option value="Airtel Money:057">Airtel +260-57</option>
                  </optgroup>
                </select>
                <!-- Remaining 7 digits typed directly after the code -->
                <input type="text" id="f-phone-local" maxlength="7" placeholder="0000000"
                  oninput="this.value=this.value.replace(/\D/g,''); buildPhone();"
                  style="background:transparent; border:none; color:var(--text); padding:10px 10px;
                         font-family:var(--font-mono); font-size:14px; letter-spacing:1px;
                         outline:none; flex:1; min-width:0;">
              </div>
              <input type="hidden" id="f-phone" value="">
              <div id="f-phone-preview" style="font-size:11px; color:var(--muted); margin-top:5px; min-height:16px;"></div>
            </div>
            <div class="form-group">
              <label>Amount (ZMW) *</label>
              <input type="number" class="form-control" id="f-amount" placeholder="0.00" step="0.01" min="1">
            </div>
          </div>

          <!-- Member found -->
          <div id="member-found-box" class="member-found">
            <div class="member-found-name" id="mf-name">—</div>
            <div class="member-found-detail" id="mf-detail">—</div>
          </div>

          <!-- Type -->
          <div class="form-row">
            <div class="form-group">
              <label>Transaction Type *</label>
              <select class="form-control" id="f-type">
                <option value="">— Select Type —</option>
                <option value="deposit">💰 Deposit</option>
                <option value="withdrawal">💸 Withdrawal</option>
                <option value="savings">🏦 Savings Deposit</option>
                <option value="loan_repayment">🔄 Loan Repayment</option>
                <option value="loan_disbursement">📤 Loan Disbursement</option>
                <option value="share_purchase">📈 Share Purchase</option>
                <option value="transfer">↔️ Transfer</option>
                <option value="fee">🏷️ Fee/Charge</option>
              </select>
            </div>
            <div class="form-group">
              <label>Admin PIN *</label>
              <input type="password" class="form-control" id="f-pin" placeholder="••••" maxlength="8">
            </div>
          </div>

          <!-- Description -->
          <div class="form-group">
            <label>Description / Notes</label>
            <input type="text" class="form-control" id="f-desc" placeholder="Optional notes">
          </div>

          <button class="btn btn-primary" onclick="submitTransaction()" style="width:100%; justify-content:center; padding:13px;">
            ✅ Record & Verify Transaction
          </button>
        </div>

        <!-- Result panel -->
        <div>
          <div class="card" id="result-card" style="display:none;">
            <div class="card-title"><span>✅</span> Transaction Recorded</div>
            <div class="ref-box" id="result-ref">—</div>
            <table>
              <tr><td style="color:var(--muted)">Member</td><td id="res-member">—</td></tr>
              <tr><td style="color:var(--muted)">Status</td><td><span class="badge badge-verified">VERIFIED</span></td></tr>
            </table>
            <div id="res-confirmation-link" style="display:none; margin-bottom:10px;"><a id="res-conf-url" href="#" target="_blank" class="btn btn-success" style="width:100%; justify-content:center;">🧾 View Confirmation Receipt</a></div>
            <button class="btn btn-ghost" style="width:100%; margin-top:8px; justify-content:center;" onclick="clearForm()">+ Record Another</button>
          </div>

          <div class="card">
            <div class="card-title"><span>💡</span> How This Works</div>
            <p style="color:var(--muted); font-size:13px; line-height:1.8;">
              <strong style="color:var(--text)">Step 1:</strong> Customer sends money via MTN MoMo or Airtel Money<br>
              <strong style="color:var(--text)">Step 2:</strong> Customer shares their transaction ID with you<br>
              <strong style="color:var(--text)">Step 3:</strong> You enter it here — system records and links to member<br>
              <strong style="color:var(--text)">Step 4:</strong> When API keys arrive, switch to automatic mode
            </p>
          </div>
        </div>
      </div>
    </div>

    <!-- ═══ TRANSACTIONS PAGE ═══ -->
    <div class="page" id="page-transactions">
      <div class="page-header">
        <div class="page-title">All Transactions</div>
        <div class="page-sub">Full transaction history</div>
      </div>
      <div class="card">
        <div class="tabs">
          <div class="tab active" onclick="filterTx('')">All</div>
          <div class="tab" onclick="filterTx('verified')">Verified</div>
          <div class="tab" onclick="filterTx('pending')">Pending</div>
          <div class="tab" onclick="filterTx('rejected')">Rejected</div>
        </div>
        <div id="all-tx-table">Loading...</div>
      </div>
    </div>

    <!-- ═══ MEMBER LOOKUP PAGE ═══ -->
    <div class="page" id="page-lookup">
      <div class="page-header">
        <div class="page-title">Member Lookup</div>
        <div class="page-sub">Search by phone number or member number</div>
      </div>
      <div class="card" style="max-width:500px;">
        <div class="card-title"><span>🔍</span> Search Member</div>
        <div class="form-group">
          <label>Phone Number or Member Number</label>
          <input type="text" class="form-control" id="lookup-query" placeholder="0977123456 or VCBI-001">
        </div>
        <button class="btn btn-primary" onclick="doLookup()">Search</button>
        <div id="lookup-result" style="margin-top:16px;"></div>
      </div>
    </div>

    <!-- ═══ STATEMENT PAGE ═══ -->
    <div class="page" id="page-statement">
      <div class="page-header">
        <div class="page-title">Member Statement</div>
        <div class="page-sub">Transaction statement by member ID and date range</div>
      </div>
      <div class="card" style="max-width:500px;">
        <div class="form-row">
          <div class="form-group">
            <label>Member ID</label>
            <input type="number" class="form-control" id="stmt-mid" placeholder="1">
          </div>
          <div class="form-group">
            <label>Admin PIN</label>
            <input type="password" class="form-control" id="stmt-pin" placeholder="••••">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>From</label>
            <input type="date" class="form-control" id="stmt-from">
          </div>
          <div class="form-group">
            <label>To</label>
            <input type="date" class="form-control" id="stmt-to">
          </div>
        </div>
        <button class="btn btn-primary" onclick="loadStatement()">Load Statement</button>
      </div>
      <div id="statement-result"></div>
    </div>

  </div><!-- /main -->
</div><!-- /layout -->

<script>
// ── CLOCK ──
setInterval(() => {
  const d = new Date();
  document.getElementById('clock').textContent = d.toLocaleTimeString('en-ZM');
}, 1000);

// ── PAGE NAV ──
function showPage(name) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));
  document.getElementById('page-' + name).classList.add('active');
  event.currentTarget.classList.add('active');
  if (name === 'dashboard') loadDashboard();
  if (name === 'transactions') loadAllTx('');
}

// ── PROVIDER SELECT ──
let selectedProvider = '';
function selectProvider(p) {
  selectedProvider = p;
  document.getElementById('f-provider').value = p;
  document.getElementById('btn-mtn').classList.remove('selected-mtn');
  document.getElementById('btn-airtel').classList.remove('selected-airtel');
  if (p === 'MTN MoMo') document.getElementById('btn-mtn').classList.add('selected-mtn');
  else document.getElementById('btn-airtel').classList.add('selected-airtel');
}

// ── PHONE PREFIX / NETWORK SELECTOR ──
function onPrefixChange(val) {
  if (!val) return;
  const [network] = val.split(':');
  // Auto-select the provider to match chosen network
  selectProvider(network);
  buildPhone();
}

function buildPhone() {
  const prefixVal = document.getElementById('f-phone-prefix').value;
  const local     = document.getElementById('f-phone-local').value.trim();
  const preview   = document.getElementById('f-phone-preview');
  const hidden    = document.getElementById('f-phone');

  if (!prefixVal || !local) {
    hidden.value = '';
    preview.textContent = prefixVal ? '📱 Enter remaining ' + (7 - local.length) + ' digit(s)' : 'Select a network first';
    preview.style.color = 'var(--muted)';
    hideMember();
    return;
  }

  const prefix3 = prefixVal.split(':')[1];        // e.g. "077"
  const full    = '0' + prefix3 + local;           // e.g. 0771234567
  hidden.value  = full;

  // Pretty: +260 77-1234567
  const pretty = '+260 ' + prefix3[0] + prefix3[1] + '-' + local;
  if (local.length === 7) {
    preview.textContent = '✅ ' + pretty;
    preview.style.color = 'var(--green)';
    autoLookup(full);
  } else {
    preview.textContent = pretty + '  (' + (7 - local.length) + ' more digit(s))';
    preview.style.color = 'var(--muted)';
    hideMember();
  }
}

// ── AUTO MEMBER LOOKUP ──
let lookupTimer;
function autoLookup(phone) {
  clearTimeout(lookupTimer);
  if (phone.length < 9) { hideMember(); return; }
  lookupTimer = setTimeout(() => {
    fetch(`?action=lookup_member&query=${encodeURIComponent(phone)}`)
      .then(r => r.json())
      .then(d => {
        if (d.success) {
          const m = d.member;
          document.getElementById('member-found-box').classList.add('show');
          document.getElementById('mf-name').textContent = m.full_name + ' (' + m.member_number + ')';
          document.getElementById('mf-detail').textContent =
            `Balance: ZMW ${parseFloat(m.account_balance).toFixed(2)} | Savings: ZMW ${parseFloat(m.savings_balance).toFixed(2)} | Status: ${m.status}`;
        } else { hideMember(); }
      });
  }, 500);
}
function hideMember() {
  document.getElementById('member-found-box').classList.remove('show');
}

// ── SUBMIT TRANSACTION ──
async function submitTransaction() {
  const provider = document.getElementById('f-provider').value;
  const txid     = document.getElementById('f-txid').value.trim();
  const phone    = document.getElementById('f-phone').value.trim();
  const amount   = document.getElementById('f-amount').value;
  const type     = document.getElementById('f-type').value;
  const pin      = document.getElementById('f-pin').value;
  const desc     = document.getElementById('f-desc').value;

  const alertEl = document.getElementById('record-alert');
  const showAlert = (msg, success) => {
    alertEl.className = 'alert show ' + (success ? 'alert-success' : 'alert-error');
    alertEl.textContent = msg;
    setTimeout(() => alertEl.className = 'alert', 5000);
  };

  if (!provider) return showAlert('Please select a provider (MTN or Airtel).', false);
  if (!txid)     return showAlert('Please enter the transaction ID.', false);
  if (!phone)    return showAlert('Please enter the phone number.', false);
  if (!amount || amount <= 0) return showAlert('Please enter a valid amount.', false);
  if (!type)     return showAlert('Please select a transaction type.', false);
  if (!pin)      return showAlert('Please enter your admin PIN.', false);

  const body = new FormData();
  body.append('action', 'record_transaction');
  body.append('provider', provider);
  body.append('mm_transaction_id', txid.toUpperCase());
  body.append('phone_number', phone);
  body.append('amount', amount);
  body.append('transaction_type', type);
  body.append('description', desc);
  body.append('admin_pin', pin);
  body.append('verified_by', 'admin');

  const res = await fetch('', { method: 'POST', body });
  const data = await res.json();

  if (data.success) {
    if (type === 'withdrawal') {
      // Redirect to branded confirmation screen
      window.location.href = 'withdrawal_confirmation.php?tx_id=' + data.transaction_id;
      return;
    }
    // For all other transaction types, show the result card
    document.getElementById('result-card').style.display = 'block';
    document.getElementById('result-ref').textContent = data.transaction_ref;
    document.getElementById('res-member').textContent = data.member_name;
    document.getElementById('res-confirmation-link').style.display = 'none';
    showAlert('✅ ' + data.message, true);
  } else {
    showAlert('❌ ' + data.message, false);
  }
}

function clearForm() {
  ['f-txid','f-phone','f-amount','f-desc','f-pin'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('f-type').value = '';
  document.getElementById('result-card').style.display = 'none';
  document.getElementById('f-phone-prefix').value = '';
  document.getElementById('f-phone-local').value  = '';
  document.getElementById('f-phone-preview').textContent = '';
  selectedProvider = '';
  document.getElementById('btn-mtn').className = 'provider-btn mtn';
  document.getElementById('btn-airtel').className = 'provider-btn airtel';
  hideMember();
}

// ── DASHBOARD ──
async function loadDashboard() {
  const res = await fetch('?action=stats');
  const raw = await res.text();
  let data;
  try { data = JSON.parse(raw); }
  catch (e) {
    document.getElementById('recent-table').innerHTML =
      '<div style="color:#E84040;white-space:pre-wrap;font-family:monospace;font-size:12px">Server did not return JSON:\n\n' + raw.replace(/</g,'&lt;').substring(0, 1500) + '</div>';
    return;
  }
  if (!data.success) {
    document.getElementById('recent-table').innerHTML =
      '<div style="color:#E84040;font-family:monospace;font-size:12px">' + (data.message || 'Unknown error') + '</div>';
    return;
  }
  const s = data.data;

  document.getElementById('stat-today-vol').textContent = 'ZMW ' + (parseFloat(s.today.volume || 0)).toLocaleString('en-ZM', {minimumFractionDigits:2});
  document.getElementById('stat-today-count').textContent = (s.today.total || 0) + ' transactions today';
  document.getElementById('stat-alltime-vol').textContent = 'ZMW ' + (parseFloat(s.all_time.volume || 0)).toLocaleString('en-ZM', {minimumFractionDigits:2});
  document.getElementById('stat-alltime-count').textContent = (s.all_time.total || 0) + ' total verified';
  document.getElementById('stat-pending').textContent = s.pending || 0;

  // Provider stats
  let pHtml = '<table>';
  for (const p of (s.by_provider || [])) {
    pHtml += `<tr>
      <td>${p.provider === 'MTN MoMo' ? MTN_LOGO : AIRTEL_LOGO}<span class="badge ${p.provider === 'MTN MoMo' ? 'badge-mtn' : 'badge-airtel'}" style="vertical-align:middle">${p.provider}</span></td>
      <td style="color:var(--muted)">${p.count} txns</td>
      <td style="font-family:var(--font-mono); color:var(--text)">ZMW ${parseFloat(p.volume).toLocaleString('en-ZM', {minimumFractionDigits:2})}</td>
    </tr>`;
  }
  if (!s.by_provider?.length) pHtml += '<tr><td colspan="3" style="color:var(--muted)">No data yet</td></tr>';
  document.getElementById('provider-stats').innerHTML = pHtml + '</table>';

  // Type stats
  let tHtml = '<table>';
  for (const t of (s.by_type || [])) {
    tHtml += `<tr>
      <td style="text-transform:capitalize">${t.transaction_type.replace(/_/g,' ')}</td>
      <td style="color:var(--muted)">${t.count}</td>
      <td style="font-family:var(--font-mono)">ZMW ${parseFloat(t.volume).toLocaleString('en-ZM', {minimumFractionDigits:2})}</td>
    </tr>`;
  }
  if (!s.by_type?.length) tHtml += '<tr><td colspan="3" style="color:var(--muted)">No data yet</td></tr>';
  document.getElementById('type-stats').innerHTML = tHtml + '</table>';

  // Recent
  const rRes = await fetch('?action=recent_transactions&status=verified');
  const rData = await rRes.json();
  document.getElementById('recent-table').innerHTML = renderTxTable(rData.data || []);
}

// ── ALL TRANSACTIONS ──
async function loadAllTx(status) {
  const res = await fetch(`?action=recent_transactions&status=${status}`);
  const data = await res.json();
  document.getElementById('all-tx-table').innerHTML = renderTxTable(data.data || []);
}

function filterTx(status) {
  document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
  event.currentTarget.classList.add('active');
  loadAllTx(status);
}

const CREDIT = ['deposit','savings','loan_repayment','share_purchase'];
const MTN_LOGO = `<svg width="44" height="18" viewBox="0 0 44 18" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right:5px"><rect width="44" height="18" rx="4" fill="#FFCC00"/><ellipse cx="22" cy="9" rx="17" ry="7" fill="#006EAF"/><text x="22" y="13" text-anchor="middle" font-family="Arial Black,Arial,sans-serif" font-weight="900" font-size="8" fill="#fff" letter-spacing="0.8">MTN</text></svg>`;
const AIRTEL_LOGO = `<svg width="50" height="18" viewBox="0 0 50 18" fill="none" xmlns="http://www.w3.org/2000/svg" style="vertical-align:middle;margin-right:5px"><rect width="50" height="18" rx="4" fill="#E40000"/><g transform="translate(4,2) scale(0.45)"><path d="M26 8 C26 8, 22 4, 15 5 C7 6, 2 12, 3 19 C4 26, 10 31, 18 30 C23 29.5, 27 27, 29 23 L25 20 C23.5 23, 21 25, 17.5 25.5 C12 26, 7.5 22, 7 17 C6.5 12, 10 8, 15 7.5 C19 7, 22 9, 23 12 L26 8 Z" fill="white"/></g><text x="33" y="12" text-anchor="middle" font-family="Arial,sans-serif" font-weight="700" font-size="7" fill="#fff" letter-spacing="0.3">airtel</text></svg>`;

function renderTxTable(txns) {
  if (!txns.length) return '<p style="color:var(--muted); padding:20px 0">No transactions found.</p>';
  let html = `<table>
    <thead><tr>
      <th>Ref</th><th>Provider</th><th>Type</th><th>Phone</th><th>Member</th>
      <th>Amount</th><th>Status</th><th>Date</th>
    </tr></thead><tbody>`;
  for (const t of txns) {
    const isCredit = CREDIT.includes(t.transaction_type);
    html += `<tr>
      <td style="font-family:var(--font-mono); font-size:11px; color:var(--gold)">${t.transaction_ref}</td>
      <td>${t.provider === 'MTN MoMo' ? MTN_LOGO : AIRTEL_LOGO}<span class="badge ${t.provider === 'MTN MoMo' ? 'badge-mtn' : 'badge-airtel'}" style="vertical-align:middle">${t.provider === 'MTN MoMo' ? 'MTN' : 'AIRTEL'}</span></td>
      <td style="text-transform:capitalize">${t.transaction_type.replace(/_/g,' ')}</td>
      <td style="font-family:var(--font-mono)">${t.phone_number}</td>
      <td>${t.member_name || '<span style="color:var(--muted)">Unknown</span>'}</td>
      <td class="${isCredit ? 'tx-credit' : 'tx-debit'}">${isCredit ? '+' : '-'} ZMW ${parseFloat(t.amount).toLocaleString('en-ZM', {minimumFractionDigits:2})}</td>
      <td><span class="badge badge-${t.status}">${t.status}</span></td>
      <td style="color:var(--muted); font-size:12px">${new Date(t.created_at).toLocaleDateString('en-ZM')}</td>
    </tr>`;
  }
  return html + '</tbody></table>';
}

// ── MEMBER LOOKUP ──
async function doLookup() {
  const q = document.getElementById('lookup-query').value.trim();
  if (!q) return;
  const res = await fetch(`?action=lookup_member&query=${encodeURIComponent(q)}`);
  const data = await res.json();
  const el = document.getElementById('lookup-result');
  if (data.success) {
    const m = data.member;
    el.innerHTML = `<div class="card">
      <table>
        <tr><td style="color:var(--muted)">Name</td><td><strong>${m.full_name}</strong></td></tr>
        <tr><td style="color:var(--muted)">Member No.</td><td style="font-family:var(--font-mono)">${m.member_number}</td></tr>
        <tr><td style="color:var(--muted)">MTN</td><td>${m.phone_mtn || '—'}</td></tr>
        <tr><td style="color:var(--muted)">Airtel</td><td>${m.phone_airtel || '—'}</td></tr>
        <tr><td style="color:var(--muted)">Balance</td><td class="tx-credit">ZMW ${parseFloat(m.account_balance).toFixed(2)}</td></tr>
        <tr><td style="color:var(--muted)">Savings</td><td class="tx-credit">ZMW ${parseFloat(m.savings_balance).toFixed(2)}</td></tr>
        <tr><td style="color:var(--muted)">Status</td><td><span class="badge badge-verified">${m.status}</span></td></tr>
      </table>
    </div>`;
  } else {
    el.innerHTML = `<p style="color:var(--red)">Member not found.</p>`;
  }
}

// ── STATEMENT ──
async function loadStatement() {
  const mid  = document.getElementById('stmt-mid').value;
  const from = document.getElementById('stmt-from').value;
  const to   = document.getElementById('stmt-to').value;
  const res  = await fetch(`?action=member_statement&member_id=${mid}&from=${from}&to=${to}`);
  const data = await res.json();
  const el   = document.getElementById('statement-result');
  if (!data.member) { el.innerHTML = '<p style="color:var(--red)">Member not found.</p>'; return; }
  const m = data.member;
  el.innerHTML = `
    <div class="card">
      <div class="card-title"><span>📄</span> Statement — ${m.full_name} (${m.member_number})</div>
      <div class="grid-3" style="margin-bottom:16px;">
        <div class="stat-card green"><div class="stat-label">Total In</div><div class="stat-value" style="font-size:16px">ZMW ${parseFloat(data.total_in||0).toFixed(2)}</div></div>
        <div class="stat-card red"><div class="stat-label">Total Out</div><div class="stat-value" style="font-size:16px">ZMW ${parseFloat(data.total_out||0).toFixed(2)}</div></div>
        <div class="stat-card gold"><div class="stat-label">Net</div><div class="stat-value" style="font-size:16px">ZMW ${(data.total_in - data.total_out).toFixed(2)}</div></div>
      </div>
      ${renderTxTable(data.transactions)}
    </div>`;
}

// ── SETUP DB ──
async function runSetup() {
  const res = await fetch('?action=setup');
  const data = await res.json();
  alert(data.message);
}

// ── INIT ──
loadDashboard();

// Set default dates for statement
const today = new Date().toISOString().split('T')[0];
const firstOfMonth = new Date(new Date().setDate(1)).toISOString().split('T')[0];
document.getElementById('stmt-from').value = firstOfMonth;
document.getElementById('stmt-to').value   = today;
</script>

</body>
</html>