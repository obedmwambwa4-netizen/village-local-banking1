<?php
// =============================================
//  VCBI — Member Statement (PDF)
//  api/statement_pdf.php?id=1
//
//  Uses FPDF — pure PHP, no Composer needed
//  Download FPDF: http://www.fpdf.org/
//  Place fpdf.php in: api/lib/fpdf.php
// =============================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/fpdf.php';

$db = getDB();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { http_response_code(400); die('Member ID required'); }

// ---- Fetch all data ----
$mq = $db->prepare('SELECT * FROM members WHERE id = ?');
$mq->execute([$id]);
$member = $mq->fetch();
if (!$member) { http_response_code(404); die('Member not found'); }

$dq = $db->prepare('SELECT * FROM deposits WHERE member_id = ? ORDER BY date ASC');
$dq->execute([$id]);
$deposits = $dq->fetchAll();

$lq = $db->prepare('SELECT * FROM loans WHERE member_id = ? ORDER BY applied_date ASC');
$lq->execute([$id]);
$loans = $lq->fetchAll();

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
$interestEarned = array_sum(array_map(fn($l) => max(0, $l['repaid'] - $l['amount']), $loans));

// ---- Payout share (proportional interest) ----
$allMembers     = $db->query("SELECT id FROM members WHERE status='active'")->fetchAll();
$allLoans       = $db->query("SELECT repaid, amount FROM loans")->fetchAll();
$totalInterest  = array_sum(array_map(fn($l) => max(0, $l['repaid'] - $l['amount']), $allLoans));
$memberCount    = count($allMembers) ?: 1;
$payoutShare    = $totalInterest / $memberCount;
$totalPayout    = $totalDeposits + $payoutShare;
$returnRate     = $totalDeposits > 0 ? round(($payoutShare / $totalDeposits) * 100, 1) : 0;

// ---- FPDF helpers ----
$GREEN  = [11,  77,  46];
$GOLD   = [201, 148,  58];
$WHITE  = [255, 255, 255];
$LIGHT  = [250, 247, 240];
$BORDER = [221, 216, 206];
$INK    = [26,  26,  26];
$MUTED  = [136, 136, 136];

class VCBIStatement extends FPDF {
    public $accentColor = [11, 77, 46];

    function Header() {}
    function Footer() {
        $this->SetY(-14);
        $this->SetFont('Arial', 'I', 7.5);
        $this->SetTextColor(136, 136, 136);
        $this->Cell(0, 5, 'VCBI Village Community Banking Initiative  |  Cycle Year 2026  |  Confidential Member Statement', 0, 0, 'C');
        $this->Ln(4);
        $this->Cell(0, 5, 'Page ' . $this->PageNo() . '/{nb}', 0, 0, 'C');
    }

    function sectionTitle($title) {
        $this->Ln(6);
        $this->SetFillColor(11, 77, 46);
        $this->SetTextColor(255, 255, 255);
        $this->SetFont('Arial', 'B', 9);
        $this->Cell(0, 8, '  ' . strtoupper($title), 0, 1, 'L', true);
        $this->SetTextColor(26, 26, 26);
        $this->Ln(2);
    }

    function tableHeader($cols, $widths) {
        $this->SetFillColor(240, 237, 230);
        $this->SetTextColor(68, 68, 68);
        $this->SetFont('Arial', 'B', 7.5);
        foreach ($cols as $i => $col) {
            $align = in_array($i, [2,3,4]) ? 'R' : 'L';
            $this->Cell($widths[$i], 7, $col, 'B', 0, $align, true);
        }
        $this->Ln();
        $this->SetTextColor(26, 26, 26);
    }

    function tableRow($cells, $widths, $shade = false) {
        if ($shade) $this->SetFillColor(250, 247, 240);
        else        $this->SetFillColor(255, 255, 255);
        $this->SetFont('Arial', '', 8);
        foreach ($cells as $i => $cell) {
            $align = in_array($i, [2,3,4]) ? 'R' : 'L';
            $this->Cell($widths[$i], 6.5, $cell, 'B', 0, $align, true);
        }
        $this->Ln();
    }

    function summaryRow($label, $value, $bold = false, $color = null) {
        $this->SetFont('Arial', $bold ? 'B' : '', 9);
        if ($color) $this->SetTextColor(...$color);
        $this->Cell(110, 7, $label, 0, 0, 'L');
        $this->Cell(0,   7, $value, 0, 1, 'R');
        $this->SetTextColor(26, 26, 26);
    }
}

// ---- Build PDF ----
$pdf = new VCBIStatement();
$pdf->AliasNbPages();
$pdf->AddPage();
$pdf->SetMargins(15, 15, 15);
$pdf->SetAutoPageBreak(true, 20);

// ===== HEADER BANNER =====
$pdf->SetFillColor(11, 77, 46);
$pdf->Rect(0, 0, 210, 38, 'F');
$pdf->SetTextColor(255, 255, 255);
$pdf->SetFont('Arial', 'B', 20);
$pdf->SetY(8);
$pdf->Cell(0, 10, 'VCBI', 0, 1, 'C');
$pdf->SetFont('Arial', '', 9);
$pdf->Cell(0, 6, 'Village Community Banking Initiative  |  Member Account Statement', 0, 1, 'C');
$pdf->SetFont('Arial', 'I', 8);
$pdf->SetTextColor(200, 230, 210);
$pdf->Cell(0, 6, 'Cycle Year: 2026  |  Generated: ' . date('d M Y, H:i'), 0, 1, 'C');
$pdf->SetTextColor(26, 26, 26);
$pdf->Ln(6);

// ===== MEMBER INFO BOX =====
$pdf->SetFillColor(250, 247, 240);
$pdf->SetDrawColor(221, 216, 206);
$pdf->Rect(15, $pdf->GetY(), 180, 28, 'FD');
$pdf->SetXY(20, $pdf->GetY() + 4);
$pdf->SetFont('Arial', 'B', 12);
$pdf->SetTextColor(11, 77, 46);
$pdf->Cell(100, 7, $member['name'], 0, 0);
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetTextColor(68, 68, 68);
$pdf->Cell(0, 7, 'Member ID: #' . str_pad($member['id'], 4, '0', STR_PAD_LEFT), 0, 1, 'R');
$pdf->SetX(20);
$pdf->SetFont('Arial', '', 8.5);
$pdf->SetTextColor(68, 68, 68);
$pdf->Cell(60, 6, 'NRC: ' . $member['national_id'], 0, 0);
$pdf->Cell(60, 6, 'Phone: ' . $member['phone'], 0, 0);
$pdf->Cell(0,  6, 'Joined: ' . $member['join_date'], 0, 1, 'R');
$pdf->SetX(20);
$pdf->Cell(60, 6, 'Next of Kin: ' . ($member['next_of_kin'] ?: '—'), 0, 0);
$pdf->SetFont('Arial', 'B', 8.5);
$pdf->SetTextColor(11, 77, 46);
$status = strtoupper($member['status']);
$pdf->Cell(0, 6, 'Status: ' . $status, 0, 1, 'R');
$pdf->SetTextColor(26, 26, 26);
$pdf->Ln(6);

// ===== SUMMARY STATS (4 boxes) =====
$pdf->sectionTitle('Account Summary');
$boxes = [
    ['Total Deposits', 'ZMW ' . number_format($totalDeposits, 2), [11,77,46]],
    ['Active Loans',   count(array_filter($loans, fn($l) => $l['status']==='active')) . ' loan(s)', [201,148,58]],
    ['Interest Earned','ZMW ' . number_format($interestEarned, 2), [26,82,118]],
    ['Projected Payout','ZMW ' . number_format($totalPayout, 2), [11,77,46]],
];
$bw = 42; $bh = 20; $bx = 15;
foreach ($boxes as $i => $box) {
    $by = $pdf->GetY();
    $pdf->SetFillColor(250, 247, 240);
    $pdf->SetDrawColor($box[2][0], $box[2][1], $box[2][2]);
    $pdf->Rect($bx + $i*46, $by, $bw, $bh, 'FD');
    $pdf->SetXY($bx + $i*46 + 2, $by + 2);
    $pdf->SetFont('Arial', '', 6.5);
    $pdf->SetTextColor(136, 136, 136);
    $pdf->Cell($bw-4, 5, strtoupper($box[0]), 0, 1);
    $pdf->SetX($bx + $i*46 + 2);
    $pdf->SetFont('Arial', 'B', 9);
    $pdf->SetTextColor($box[2][0], $box[2][1], $box[2][2]);
    $pdf->Cell($bw-4, 8, $box[1], 0, 1);
}
$pdf->SetTextColor(26, 26, 26);
$pdf->Ln(24);

// ===== DEPOSITS TABLE =====
$pdf->sectionTitle('Deposits History');
if (count($deposits) === 0) {
    $pdf->SetFont('Arial', 'I', 9); $pdf->SetTextColor(136,136,136);
    $pdf->Cell(0, 8, 'No deposits recorded.', 0, 1);
    $pdf->SetTextColor(26,26,26);
} else {
    $dw = [28, 60, 32, 32, 28];
    $pdf->tableHeader(['Date', 'Month', 'Amount (ZMW)', 'Running Total', 'Note'], $dw);
    $running = 0;
    foreach ($deposits as $i => $d) {
        $running += $d['amount'];
        $pdf->tableRow([
            $d['date'],
            $d['month'],
            number_format($d['amount'], 2),
            number_format($running, 2),
            $d['note'] ?: '—',
        ], $dw, $i % 2 === 0);
    }
    // Total row
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(211, 240, 226);
    $pdf->SetTextColor(11, 77, 46);
    $pdf->Cell(array_sum(array_slice($dw,0,2)), 7, 'TOTAL DEPOSITS', 'T', 0, 'L', true);
    $pdf->Cell($dw[2], 7, 'ZMW ' . number_format($totalDeposits, 2), 'T', 0, 'R', true);
    $pdf->Cell($dw[3]+$dw[4], 7, '', 'T', 1, 'R', true);
    $pdf->SetTextColor(26,26,26);
}

// ===== LOANS TABLE =====
$pdf->sectionTitle('Loans Register');
if (count($loans) === 0) {
    $pdf->SetFont('Arial', 'I', 9); $pdf->SetTextColor(136,136,136);
    $pdf->Cell(0, 8, 'No loans on record.', 0, 1);
    $pdf->SetTextColor(26,26,26);
} else {
    $lw = [18, 28, 28, 16, 32, 22, 28, 8];
    $pdf->tableHeader(['Loan ID','Principal','Total Due','Rate','Purpose','Due Date','Repaid','Status'], $lw);
    foreach ($loans as $i => $l) {
        $total = $l['amount'] + ($l['amount'] * $l['interest'] / 100);
        $pdf->tableRow([
            '#L' . str_pad($l['id'], 4, '0', STR_PAD_LEFT),
            number_format($l['amount'], 2),
            number_format($total, 2),
            $l['interest'] . '%',
            $l['purpose'],
            $l['due_date'],
            number_format($l['repaid'], 2),
            strtoupper($l['status'][0]), // A/R/D
        ], $lw, $i % 2 === 0);
    }
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(255, 243, 205);
    $pdf->SetTextColor(122, 81, 0);
    $pdf->Cell($lw[0]+$lw[1], 7, 'TOTAL DISBURSED', 'T', 0, 'L', true);
    $pdf->Cell($lw[2], 7, 'ZMW ' . number_format($totalLoans, 2), 'T', 0, 'R', true);
    $pdf->Cell(array_sum(array_slice($lw,3)), 7, '', 'T', 1, 'R', true);
    $pdf->SetTextColor(26,26,26);
}

// ===== REPAYMENTS TABLE =====
$pdf->sectionTitle('Repayment History');
if (count($repayments) === 0) {
    $pdf->SetFont('Arial', 'I', 9); $pdf->SetTextColor(136,136,136);
    $pdf->Cell(0, 8, 'No repayments recorded.', 0, 1);
    $pdf->SetTextColor(26,26,26);
} else {
    $rw = [28, 22, 35, 35, 35, 25];
    $pdf->tableHeader(['Date','Loan ID','Amount Paid','Principal Part','Interest Part','Note'], $rw);
    foreach ($repayments as $i => $r) {
        $interest = max(0, $r['amount'] - $r['loan_principal']);
        $principal = min($r['amount'], $r['loan_principal']);
        $pdf->tableRow([
            $r['date'],
            '#L' . str_pad($r['loan_id'], 4, '0', STR_PAD_LEFT),
            number_format($r['amount'], 2),
            number_format($principal, 2),
            number_format($interest, 2),
            $r['note'] ?: '—',
        ], $rw, $i % 2 === 0);
    }
    $pdf->SetFont('Arial', 'B', 8.5);
    $pdf->SetFillColor(211, 240, 226);
    $pdf->SetTextColor(11, 77, 46);
    $pdf->Cell($rw[0]+$rw[1], 7, 'TOTAL REPAID', 'T', 0, 'L', true);
    $pdf->Cell($rw[2], 7, 'ZMW ' . number_format($totalRepaid, 2), 'T', 0, 'R', true);
    $pdf->Cell(array_sum(array_slice($rw,3)), 7, '', 'T', 1, 'R', true);
    $pdf->SetTextColor(26,26,26);
}

// ===== YEAR-END PAYOUT SECTION =====
$pdf->sectionTitle('Year-End Payout Projection');
$pdf->SetFillColor(250, 247, 240);
$pdf->SetDrawColor(221, 216, 206);
$pdf->Rect(15, $pdf->GetY(), 180, 40, 'FD');
$startY = $pdf->GetY() + 4;
$pdf->SetXY(20, $startY);

$rows = [
    ['Total Savings Contributed',    'ZMW ' . number_format($totalDeposits, 2),   false, null],
    ['Interest Earned (Pool Share)', 'ZMW ' . number_format($payoutShare, 2),     false, [11,77,46]],
    ['Return Rate',                  '+' . $returnRate . '%',                      false, [11,77,46]],
    ['PROJECTED YEAR-END PAYOUT',    'ZMW ' . number_format($totalPayout, 2),     true,  [11,77,46]],
];
foreach ($rows as $row) {
    $pdf->SetXY(20, $pdf->GetY());
    $pdf->summaryRow($row[0], $row[1], $row[2], $row[3]);
}

$pdf->Ln(6);
$pdf->SetFont('Arial', 'I', 7.5);
$pdf->SetTextColor(136,136,136);
$pdf->MultiCell(0, 5,
    'Note: Payout is projected based on current cycle data. Interest is distributed equally among all ' .
    $memberCount . ' active members. Final amounts are confirmed at year-end close.', 0, 'L');

// ===== OUTPUT =====
$filename = 'VCBI_Statement_' . preg_replace('/\s+/', '_', $member['name']) . '_' . date('Ymd') . '.pdf';
$pdf->Output('D', $filename);