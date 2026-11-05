<?php
// =============================================
//  VCBI — Transactions API
//  api/transactions.php
//
//  GET  /api/transactions.php?type=deposits
//  GET  /api/transactions.php?type=loans
//  GET  /api/transactions.php?type=repayments
//  GET  /api/transactions.php?type=dashboard   → all stats in one call
//  POST /api/transactions.php?type=deposits    → record deposit
//  POST /api/transactions.php?type=loans       → issue loan
//  POST /api/transactions.php?type=repayments  → record repayment
// =============================================

require_once __DIR__ . '/config.php';
apiHeaders();

$db     = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$type   = $_GET['type'] ?? '';

// ============================================================
//  GET
// ============================================================
if ($method === 'GET') {

    // ---- Dashboard (single call for all stats) ----
    if ($type === 'dashboard') {
        $members    = $db->query('SELECT * FROM members ORDER BY savings DESC')->fetchAll();
        $deposits   = $db->query('SELECT * FROM deposits ORDER BY date DESC')->fetchAll();
        $loans      = $db->query('SELECT * FROM loans ORDER BY applied_date DESC')->fetchAll();
        $repayments = $db->query('SELECT * FROM repayments ORDER BY date DESC')->fetchAll();
        respond(compact('members', 'deposits', 'loans', 'repayments'));
    }

    // ---- Deposits ----
    if ($type === 'deposits') {
        $rows = $db->query('SELECT * FROM deposits ORDER BY date DESC')->fetchAll();
        respond($rows);
    }

    // ---- Loans ----
    if ($type === 'loans') {
        $rows = $db->query('SELECT * FROM loans ORDER BY applied_date DESC')->fetchAll();
        respond($rows);
    }

    // ---- Repayments ----
    if ($type === 'repayments') {
        $rows = $db->query('SELECT * FROM repayments ORDER BY date DESC')->fetchAll();
        respond($rows);
    }

    error('Unknown type. Use: dashboard, deposits, loans, repayments');
}

// ============================================================
//  POST
// ============================================================
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);

    // ---- Record Deposit ----
    if ($type === 'deposits') {
        $memberId = (int)($body['memberId'] ?? 0);
        $amount   = (float)($body['amount']  ?? 0);
        $month    = trim($body['month']       ?? '');
        $date     = $body['date']              ?? date('Y-m-d');
        $note     = trim($body['note']         ?? '');

        if (!$memberId || !$amount || !$month) error('memberId, amount and month are required');
        if ($amount <= 0) error('Amount must be greater than zero');

        // Check member exists
        $check = $db->prepare('SELECT id FROM members WHERE id = ?');
        $check->execute([$memberId]);
        if (!$check->fetch()) error('Member not found', 404);

        $db->prepare(
            'INSERT INTO deposits (member_id, amount, month, date, note) VALUES (?, ?, ?, ?, ?)'
        )->execute([$memberId, $amount, $month, $date, $note ?: null]);

        // Update member savings total
        $db->prepare('UPDATE members SET savings = savings + ? WHERE id = ?')
           ->execute([$amount, $memberId]);

        $newId = (int)$db->lastInsertId();
        $row   = $db->prepare('SELECT * FROM deposits WHERE id = ?');
        $row->execute([$newId]);
        respond($row->fetch(), 201);
    }

    // ---- Issue Loan ----
    if ($type === 'loans') {
        $memberId  = (int)($body['memberId']   ?? 0);
        $amount    = (float)($body['amount']   ?? 0);
        $interest  = (float)($body['interest'] ?? 7);
        $purpose   = trim($body['purpose']     ?? '');
        $dueDate   = $body['dueDate']           ?? '';
        $mmPhone   = trim($body['mmPhone']      ?? '');
        $mmNetwork = trim($body['mmNetwork']    ?? '');
        $date      = date('Y-m-d');

        if (!$memberId || !$amount || !$purpose || !$dueDate) {
            error('memberId, amount, purpose and dueDate are required');
        }
        if ($amount <= 0) error('Amount must be greater than zero');
        if (!$mmPhone || !$mmNetwork) error('Mobile money phone number and network are required for disbursement');

        // Eligibility: loan ≤ 3× savings
        $mem = $db->prepare('SELECT * FROM members WHERE id = ?');
        $mem->execute([$memberId]);
        $member = $mem->fetch();
        if (!$member) error('Member not found', 404);
        if ($member['savings'] < $amount / 3) {
            error('Loan exceeds 3× member savings (max: ' . number_format($member['savings'] * 3, 2) . ')');
        }

        // No double active loan
        $active = $db->prepare("SELECT id FROM loans WHERE member_id = ? AND status = 'active'");
        $active->execute([$memberId]);
        if ($active->fetch()) error('Member already has an active loan');

        // Validate due date is in the future
        if ($dueDate <= $date) error('Due date must be a future date');

        $db->beginTransaction();
        try {
            // (mm_phone, mm_network and loan_id columns are created by vcbi_banking.sql —
            //  ALTER TABLE inside a transaction would silently commit it)

            $db->prepare(
                'INSERT INTO loans (member_id, amount, interest, purpose, applied_date, due_date, status, repaid, mm_phone, mm_network)
                 VALUES (?, ?, ?, ?, ?, ?, "active", 0.00, ?, ?)'
            )->execute([$memberId, $amount, $interest, $purpose, $date, $dueDate, $mmPhone, $mmNetwork]);

            $newId = (int)$db->lastInsertId();

            // Log as a mobile money disbursement transaction

            $ref = 'VCBI-LOAN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
            try {
                $db->prepare("
                    INSERT INTO vcbi_mobile_transactions
                        (transaction_ref, mm_transaction_id, provider, transaction_type,
                         member_id, member_number, member_name, phone_number,
                         amount, currency, description, status, verified_by, verified_at)
                    VALUES (?, ?, ?, 'loan_disbursement', ?, ?, ?, ?, ?, 'ZMW', ?, 'verified', 'system', NOW())
                ")->execute([
                    $ref, $ref, $mmNetwork,
                    $memberId,
                    $member['national_id'],
                    $member['name'],
                    $mmPhone,
                    $amount,
                    "Loan #L" . str_pad($newId, 4, '0', STR_PAD_LEFT) . " disbursed to $mmPhone via $mmNetwork — Purpose: $purpose",
                ]);
            } catch (PDOException) { /* vcbi_mobile_transactions may not exist yet — skip */ }

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            error('Failed to issue loan: ' . $e->getMessage(), 500);
        }

        $row = $db->prepare('SELECT * FROM loans WHERE id = ?');
        $row->execute([$newId]);
        respond($row->fetch(), 201);
    }

    // ---- Record Repayment ----
    if ($type === 'repayments') {
        $loanId = (int)($body['loanId']  ?? 0);
        $amount = (float)($body['amount'] ?? 0);
        $note   = trim($body['note']       ?? '');
        $date   = $body['date']             ?? date('Y-m-d');

        if (!$loanId || !$amount) error('loanId and amount are required');
        if ($amount <= 0) error('Amount must be greater than zero');

        // Fetch loan
        $lq = $db->prepare('SELECT * FROM loans WHERE id = ?');
        $lq->execute([$loanId]);
        $loan = $lq->fetch();
        if (!$loan) error('Loan not found', 404);
        if ($loan['status'] !== 'active') error('This loan is already fully repaid');

        // ── Overdue penalty calculation (0.5% of balance per overdue day) ──
        $today    = date('Y-m-d');
        $daysLate = max(0, (int)((strtotime($today) - strtotime($loan['due_date'])) / 86400));
        $baseTotal   = $loan['amount'] + ($loan['amount'] * $loan['interest'] / 100);
        $baseBalance = max(0, $baseTotal - $loan['repaid']);
        $penalty     = round($baseBalance * 0.005 * $daysLate, 2);
        $totalDue    = round($baseBalance + $penalty, 2);

        if ($amount > $totalDue + 0.01) {
            error('Amount exceeds total due of ' . number_format($totalDue, 2) .
                  ($daysLate > 0 ? " (incl. ZMW $penalty penalty for $daysLate overdue day(s))" : ''));
        }

        // Build note with penalty info if overdue
        $fullNote = $note;
        if ($daysLate > 0 && $penalty > 0) {
            $fullNote = trim("$note | Overdue $daysLate day(s), penalty ZMW $penalty included");
        }

        // Save repayment
        $db->prepare(
            'INSERT INTO repayments (loan_id, member_id, amount, date, note) VALUES (?, ?, ?, ?, ?)'
        )->execute([$loanId, $loan['member_id'], $amount, $date, $fullNote ?: null]);

        // Determine new repaid amount — treat as clearing balance+penalty proportionally
        $newRepaid = $loan['repaid'] + $amount;
        // Mark as repaid if payment covers total due (balance + penalty)
        $newStatus = ($amount >= $totalDue - 0.01) ? 'repaid' : 'active';
        $db->prepare('UPDATE loans SET repaid = ?, status = ? WHERE id = ?')
           ->execute([$newRepaid, $newStatus, $loanId]);

        $newId = (int)$db->lastInsertId();
        $row   = $db->prepare('SELECT * FROM repayments WHERE id = ?');
        $row->execute([$newId]);
        respond($row->fetch(), 201);
    }

    error('Unknown type. Use: deposits, loans, repayments');
}

error('Method not allowed', 405);
