<?php
// =============================================
//  VCBI — Member Statement (Excel/CSV)
//  api/statement_excel.php?id=1
//
//  Pure PHP — no libraries needed
//  Downloads as .csv which opens in Excel
// =============================================

require_once __DIR__ . '/config.php';

$db = getDB();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { http_response_code(400); die('Member ID required'); }

// ---- Fetch member ----
$mq = $db->prepare('SELECT * FROM members WHERE id = ?');
$mq->execute([$id]);
$member = $mq->fetch();
if (!$member) { http_response_code(404); die('Member not found'); }

// ---- Fetch deposits ----
$dq = $db->prepare('SELECT * FROM deposits WHERE member_id = ? ORDER BY date ASC');
$dq->execute([$id]);
$deposits = $dq->fetchAll();

// ---- Fetch loans ----
$lq = $db->prepare('SELECT * FROM loans WHERE member_id = ? ORDER BY applied_date ASC');
$lq->execute([$id]);
$loans = $lq->fetchAll();

// ---- Fetch repayments ----
$rq = $db->prepare('
    SELECT r.*, l.amount AS loan_principal
    FROM repayments r
    JOIN loans l ON r.loan_id = l.id
    WHERE r.member_id = ?
    ORDER BY r.date ASC
');
$rq->execute([$id]);
$repayments = $rq->fetchAll();

// ---- Totals ----
$totalDeposits  = array_sum(array_column($deposits, 'amount'));
$totalLoans     = array_sum(array_column($loans, 'amount'));
$totalRepaid    = array_sum(array_column($repayments, 'amount'));

$interestEarned = 0;
foreach ($loans as $l) {
    $interestEarned += max(0, $l['repaid'] - $l['amount']);
}

$allMembers    = $db->query("SELECT id FROM members WHERE status='active'")->fetchAll();
$allLoans      = $db->query("SELECT repaid, amount FROM loans")->fetchAll();
$totalInterest = 0;
foreach ($allLoans as $l) {
    $totalInterest += max(0, $l['repaid'] - $l['amount']);
}

$memberCount = count($allMembers) ?: 1;
$payoutShare = $totalInterest / $memberCount;
$totalPayout = $totalDeposits + $payoutShare;
$returnRate  = $totalDeposits > 0 ? round(($payoutShare / $totalDeposits) * 100, 1) : 0;

// ---- Output CSV ----
$filename = 'VCBI_Statement_' . preg_replace('/\s+/', '_', $member['name']) . '_' . date('Ymd') . '.csv';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache');

echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel

$out = fopen('php://output', 'w');

// HEADER
fputcsv($out, ['VCBI - VILLAGE COMMUNITY BANKING INITIATIVE']);
fputcsv($out, ['Member Account Statement - Cycle Year 2026']);
fputcsv($out, ['Generated: ' . date('d M Y, H:i')]);
fputcsv($out, []);

// MEMBER INFO
fputcsv($out, ['====== MEMBER INFORMATION ======']);
fputcsv($out, ['Full Name',   $member['name']]);
fputcsv($out, ['National ID', $member['national_id']]);
fputcsv($out, ['Phone',       $member['phone']]);
fputcsv($out, ['Next of Kin', $member['next_of_kin'] ?: '-']);
fputcsv($out, ['Join Date',   $member['join_date']]);
fputcsv($out, ['Status',      strtoupper($member['status'])]);
fputcsv($out, ['Member ID',   '#' . str_pad($member['id'], 4, '0', STR_PAD_LEFT)]);
fputcsv($out, []);

// ACCOUNT SUMMARY
fputcsv($out, ['====== ACCOUNT SUMMARY ======']);
fputcsv($out, ['Item', 'Amount']);
fputcsv($out, ['Total Deposits Contributed',   'ZMW ' . number_format($totalDeposits, 2)]);
fputcsv($out, ['Total Loans Received',         'ZMW ' . number_format($totalLoans, 2)]);
fputcsv($out, ['Total Repaid',                 'ZMW ' . number_format($totalRepaid, 2)]);
fputcsv($out, ['Interest Earned',              'ZMW ' . number_format($interestEarned, 2)]);
fputcsv($out, ['Payout Share (Pool Interest)', 'ZMW ' . number_format($payoutShare, 2)]);
fputcsv($out, ['Return Rate',                  '+' . $returnRate . '%']);
fputcsv($out, ['PROJECTED YEAR-END PAYOUT',    'ZMW ' . number_format($totalPayout, 2)]);
fputcsv($out, []);

// DEPOSITS
fputcsv($out, ['====== DEPOSITS HISTORY ======']);
fputcsv($out, ['Date', 'Month', 'Amount (ZMW)', 'Running Total (ZMW)', 'Note']);
$running = 0;
foreach ($deposits as $d) {
    $running += $d['amount'];
    fputcsv($out, [
        $d['date'],
        $d['month'],
        number_format($d['amount'], 2),
        number_format($running, 2),
        $d['note'] ?: '-',
    ]);
}
fputcsv($out, ['TOTAL', '', number_format($totalDeposits, 2), '', '']);
fputcsv($out, []);

// LOANS
fputcsv($out, ['====== LOANS REGISTER ======']);
fputcsv($out, ['Loan ID', 'Principal (ZMW)', 'Total Due (ZMW)', 'Rate', 'Purpose', 'Applied Date', 'Due Date', 'Repaid (ZMW)', 'Status']);
foreach ($loans as $l) {
    $total = $l['amount'] + ($l['amount'] * $l['interest'] / 100);
    fputcsv($out, [
        '#L' . str_pad($l['id'], 4, '0', STR_PAD_LEFT),
        number_format($l['amount'], 2),
        number_format($total, 2),
        $l['interest'] . '%',
        $l['purpose'],
        $l['applied_date'],
        $l['due_date'],
        number_format($l['repaid'], 2),
        strtoupper($l['status']),
    ]);
}
fputcsv($out, ['TOTAL', number_format($totalLoans, 2), '', '', '', '', '', '', '']);
fputcsv($out, []);

// REPAYMENTS
fputcsv($out, ['====== REPAYMENT HISTORY ======']);
fputcsv($out, ['Date', 'Loan ID', 'Amount Paid (ZMW)', 'Principal Part (ZMW)', 'Interest Part (ZMW)', 'Note']);
foreach ($repayments as $r) {
    $interest  = max(0, $r['amount'] - $r['loan_principal']);
    $principal = min($r['amount'], $r['loan_principal']);
    fputcsv($out, [
        $r['date'],
        '#L' . str_pad($r['loan_id'], 4, '0', STR_PAD_LEFT),
        number_format($r['amount'], 2),
        number_format($principal, 2),
        number_format($interest, 2),
        $r['note'] ?: '-',
    ]);
}
fputcsv($out, ['TOTAL', '', number_format($totalRepaid, 2), '', '', '']);
fputcsv($out, []);

// YEAR-END PAYOUT
fputcsv($out, ['====== YEAR-END PAYOUT PROJECTION ======']);
fputcsv($out, ['Description', 'Amount']);
fputcsv($out, ['Total Savings Contributed', 'ZMW ' . number_format($totalDeposits, 2)]);
fputcsv($out, ['Interest Share (' . $memberCount . ' members)', 'ZMW ' . number_format($payoutShare, 2)]);
fputcsv($out, ['Return Rate', '+' . $returnRate . '%']);
fputcsv($out, ['PROJECTED YEAR-END PAYOUT', 'ZMW ' . number_format($totalPayout, 2)]);
fputcsv($out, []);
fputcsv($out, ['Note: Payout = deposits + equal share of pooled interest. Confirmed at year-end close.']);

fclose($out);
exit;
