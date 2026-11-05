<?php
// ============================================================
//  VCBI — Withdrawal Confirmation Page
//  withdrawal_confirmation.php?tx_id=123
//
//  Shows a branded confirmation screen after a successful
//  withdrawal. Supports MTN MoMo and Airtel Money.
//  Query params:
//    tx_id   — vcbi_mobile_transactions.id  (required)
//    preview — mtn | airtel                 (optional demo mode)
// ============================================================

// Use shared DB config — single source of truth
require_once __DIR__ . '/api/config.php';

// ── DEMO / PREVIEW MODE ──────────────────────────────────────
// Visit ?preview=mtn or ?preview=airtel to see mock screen
$preview = $_GET['preview'] ?? '';
if ($preview) {
    $tx = [
        'id'               => 0,
        'transaction_ref'  => $preview === 'mtn' ? 'VCBI-20260429-DEMO1' : 'VCBI-20260429-DEMO2',
        'mm_transaction_id'=> $preview === 'mtn' ? 'AGX20260429-7834K'   : 'A20260429-9921Z',
        'provider'         => $preview === 'mtn' ? 'MTN MoMo'            : 'Airtel Money',
        'transaction_type' => 'withdrawal',
        'member_name'      => 'Demo Member',
        'phone_number'     => $preview === 'mtn' ? '0964 123 456'        : '0977 456 789',
        'amount'           => '500.00',
        'currency'         => 'ZMW',
        'status'           => 'verified',
        'description'      => 'Year-end payout withdrawal',
        'created_at'       => date('Y-m-d H:i:s'),
        'member_balance'   => '2,350.00',
    ];
    $member = ['full_name' => 'Demo Member', 'member_number' => 'VCBI-0000'];
} else {
    // ── LIVE MODE ────────────────────────────────────────────
    $txId = (int)($_GET['tx_id'] ?? 0);
    if (!$txId) {
        http_response_code(400);
        die('Transaction ID required. Add ?tx_id=123 to the URL.');
    }

    $pdo = getDB();

    $stmt = $pdo->prepare("
        SELECT t.*, m.savings AS member_balance, m.name AS full_name, m.national_id AS member_number
        FROM vcbi_mobile_transactions t
        LEFT JOIN members m ON t.member_id = m.id
        WHERE t.id = ?
        LIMIT 1
    ");
    $stmt->execute([$txId]);
    $tx = $stmt->fetch();

    if (!$tx) {
        http_response_code(404);
        die('Transaction not found.');
    }
    if ($tx['transaction_type'] !== 'withdrawal') {
        http_response_code(400);
        die('This confirmation page is for withdrawals only.');
    }

    // ── If member_id linked, use it; otherwise search by phone number ──
    if (!empty($tx['full_name'])) {
        $member = ['full_name' => $tx['full_name'], 'member_number' => $tx['member_number']];
    } else {
        // Fallback: look up member by phone number
        $phoneSearch = $pdo->prepare("
            SELECT name AS full_name, national_id AS member_number
            FROM members
            WHERE phone = ?
            LIMIT 1
        ");
        $phoneSearch->execute([$tx['phone_number']]);
        $foundMember = $phoneSearch->fetch();
        if ($foundMember) {
            $member = $foundMember;
            // Also update the transaction record to link this member
            $updateLink = $pdo->prepare("
                UPDATE vcbi_mobile_transactions t
                JOIN members m ON m.phone = t.phone_number
                SET t.member_id = m.id, t.member_name = m.name, t.member_number = m.national_id
                WHERE t.id = ?
            ");
            $updateLink->execute([$txId]);
        } else {
            $member = ['full_name' => 'Walk-in / Unregistered', 'member_number' => '—'];
        }
    }
    $tx['member_balance'] = number_format((float)($tx['member_balance'] ?? 0), 2);
}

// ── HELPERS ──────────────────────────────────────────────────
$isMTN    = ($tx['provider'] === 'MTN MoMo');
$isAirtel = ($tx['provider'] === 'Airtel Money');
$rawAmount = (float)$tx['amount'];
$amount   = number_format($rawAmount, 2);
$date     = date('d M Y, H:i', strtotime($tx['created_at']));

// ── NETWORK CHARGE CALCULATION ────────────────────────────────
// Based on ZICTA / MTN MoMo & Airtel Money Zambia tariff bands (ZMW)
function calcNetworkCharge(float $amount, string $provider): float {
    if ($provider === 'MTN MoMo') {
        // MTN MoMo Zambia withdrawal tariff bands
        if ($amount <= 20)        return 0.50;
        elseif ($amount <= 50)    return 1.00;
        elseif ($amount <= 100)   return 2.00;
        elseif ($amount <= 200)   return 3.00;
        elseif ($amount <= 500)   return 5.00;
        elseif ($amount <= 1000)  return 9.00;
        elseif ($amount <= 2000)  return 15.00;
        elseif ($amount <= 5000)  return 25.00;
        elseif ($amount <= 10000) return 40.00;
        else                      return 60.00;
    } else {
        // Airtel Money Zambia withdrawal tariff bands
        if ($amount <= 20)        return 0.50;
        elseif ($amount <= 50)    return 1.00;
        elseif ($amount <= 100)   return 2.00;
        elseif ($amount <= 200)   return 3.50;
        elseif ($amount <= 500)   return 6.00;
        elseif ($amount <= 1000)  return 10.00;
        elseif ($amount <= 2000)  return 16.00;
        elseif ($amount <= 5000)  return 27.00;
        elseif ($amount <= 10000) return 42.00;
        else                      return 65.00;
    }
}
$networkCharge    = calcNetworkCharge($rawAmount, $tx['provider']);
$totalDeducted    = $rawAmount + $networkCharge;
$fmtCharge        = number_format($networkCharge, 2);
$fmtTotal         = number_format($totalDeducted, 2);
$success  = ($tx['status'] === 'verified');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
<title>VCBI — Withdrawal Confirmation</title>
<link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;600;700&display=swap" rel="stylesheet">
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }

  :root {
    --mtn-yellow: #FFCC00;
    --mtn-blue:   #006EAF;
    --air-red:    #E40000;
    --air-dark:   #b80000;
    --success:    #1a7a1a;
    --success-bg: #e8f8e8;
    --text:       #1a1a1a;
    --muted:      #666;
    --border:     rgba(0,0,0,0.1);
    --font: 'Sora', sans-serif;
  }

  body {
    font-family: var(--font);
    background: #f0f0f0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
  }

  /* ── PHONE FRAME ── */
  .phone {
    width: 340px;
    border-radius: 36px;
    border: 7px solid #1a1a1a;
    overflow: hidden;
    background: #000;
    position: relative;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  }
  .notch {
    position: absolute; top: 0; left: 50%; transform: translateX(-50%);
    width: 90px; height: 24px; background: #1a1a1a;
    border-radius: 0 0 16px 16px; z-index: 10;
  }

  /* ── SHARED SCREEN ── */
  .screen { width: 100%; }

  /* ── MTN HEADER ── */
  .mtn-header {
    background: var(--mtn-yellow);
    padding: 40px 20px 18px;
    text-align: center;
  }
  .mtn-oval {
    display: inline-flex; align-items: center; justify-content: center;
    background: var(--mtn-blue); border-radius: 50px;
    padding: 7px 26px; margin-bottom: 4px;
  }
  .mtn-oval-text {
    color: #fff; font-weight: 700; font-size: 22px; letter-spacing: 3px;
    font-family: Arial Black, Arial, sans-serif;
  }
  .mtn-tagline { font-size: 9px; color: #6a5000; letter-spacing: 1.5px; }

  /* ── AIRTEL HEADER ── */
  .airtel-header {
    background: var(--air-red);
    padding: 40px 20px 16px;
    text-align: center;
  }
  .airtel-logo-row {
    display: flex; align-items: center; justify-content: center; gap: 8px;
  }
  .airtel-wordmark { color: #fff; font-size: 22px; font-weight: 700; letter-spacing: 0.5px; }
  .airtel-tagline { color: rgba(255,255,255,0.7); font-size: 9px; letter-spacing: 2px; margin-top: 3px; }

  /* ── BODY ── */
  .body {
    background: #fff;
    padding: 22px 20px 28px;
  }

  /* ── CHECK CIRCLE ── */
  .check-wrap { text-align: center; margin-bottom: 14px; }
  .check-circle {
    width: 60px; height: 60px; border-radius: 50%;
    display: inline-flex; align-items: center; justify-content: center;
    margin-bottom: 4px;
  }
  .check-circle.mtn    { background: var(--mtn-yellow); border: 3px solid #d4a800; }
  .check-circle.airtel { background: var(--air-red);    border: 3px solid var(--air-dark); }
  .check-circle svg { display: block; }

  .confirm-title {
    font-size: 15px; font-weight: 600; color: var(--text);
    text-align: center; margin-bottom: 4px;
  }
  .confirm-amount {
    font-size: 32px; font-weight: 700; text-align: center; margin-bottom: 18px;
  }
  .confirm-amount.mtn    { color: var(--mtn-blue); }
  .confirm-amount.airtel { color: var(--air-red); }

  /* ── ROWS ── */
  .row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 6px 0;
  }
  .row-label { font-size: 11px; color: var(--muted); }
  .row-value { font-size: 12px; font-weight: 600; color: var(--text); text-align: right; max-width: 62%; }
  .divider { height: 0.5px; background: var(--border); margin: 8px 0; }

  /* ── STATUS BADGE ── */
  .status-badge {
    display: inline-block; padding: 3px 10px; border-radius: 20px;
    font-size: 10px; font-weight: 700; letter-spacing: 0.5px;
    background: var(--success-bg); color: var(--success);
  }

  /* ── REF BOX ── */
  .ref-box {
    border-radius: 8px; padding: 10px 14px; text-align: center;
    margin-top: 16px;
  }
  .ref-box.mtn    { background: #FFF8D6; border: 0.5px solid #d4a800; }
  .ref-box.airtel { background: #fff0f0; border: 0.5px solid var(--air-red); }
  .ref-label { font-size: 10px; letter-spacing: 1px; margin-bottom: 3px; }
  .ref-label.mtn    { color: #6a5000; }
  .ref-label.airtel { color: #7a0000; }
  .ref-value { font-size: 13px; font-weight: 700; letter-spacing: 1.5px; font-family: monospace; }
  .ref-value.mtn    { color: var(--mtn-blue); }
  .ref-value.airtel { color: var(--air-red); }
  .vcbi-ref { font-size: 10px; color: var(--muted); margin-top: 4px; font-family: monospace; }

  /* ── FOOTER ── */
  .footer { padding: 10px; text-align: center; font-size: 9px; letter-spacing: 0.8px; }
  .footer.mtn    { background: var(--mtn-yellow); color: #6a5000; }
  .footer.airtel { background: var(--air-red); color: rgba(255,255,255,0.8); }

  /* ── PRINT BUTTON (outside frame) ── */
  .actions {
    text-align: center; margin-top: 20px;
    display: flex; gap: 10px; justify-content: center; flex-wrap: wrap;
  }
  .btn-print, .btn-back {
    padding: 10px 22px; border-radius: 8px; border: none;
    font-family: var(--font); font-size: 13px; font-weight: 600;
    cursor: pointer; transition: opacity 0.15s;
  }
  .btn-print { background: #1a1a1a; color: #fff; }
  .btn-back  { background: #fff; color: #1a1a1a; border: 1.5px solid #ccc; }
  .btn-print:hover, .btn-back:hover { opacity: 0.8; }

  /* ── FAILED STATE ── */
  .failed-badge {
    background: #fde8e8; color: #a00; border-radius: 20px;
    font-size: 10px; font-weight: 700; padding: 3px 10px;
  }

  @media print {
    body { background: #fff; padding: 0; }
    .phone { box-shadow: none; border-color: #ccc; }
    .actions { display: none; }
  }
</style>
</head>
<body>

<div>
  <div class="phone">
    <div class="notch"></div>

    <?php if ($isMTN): ?>
    <!-- ══════════════ MTN MoMo SCREEN ══════════════ -->
    <div class="screen">

      <div class="mtn-header">
        <div class="mtn-oval"><span class="mtn-oval-text">MTN</span></div>
        <div class="mtn-tagline">MOBILE MONEY · ZAMBIA</div>
      </div>

      <div class="body">
        <div class="check-wrap">
          <div class="check-circle mtn">
            <?php if ($success): ?>
            <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
              <path d="M7 15.5l6 6 11-12" stroke="#006EAF" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php else: ?>
            <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
              <path d="M8 8l14 14M22 8L8 22" stroke="#E40000" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <?php endif; ?>
          </div>
        </div>

        <div class="confirm-title">
          <?= $success ? 'Withdrawal Successful' : 'Withdrawal Failed' ?>
        </div>
        <div class="confirm-amount mtn">ZMW <?= htmlspecialchars($amount) ?></div>

        <div class="row">
          <span class="row-label">Status</span>
          <?php if ($success): ?>
            <span class="status-badge">Completed</span>
          <?php else: ?>
            <span class="failed-badge">Failed</span>
          <?php endif; ?>
        </div>
        <div class="row">
          <span class="row-label">Member</span>
          <span class="row-value"><?= htmlspecialchars($member['full_name'] ?? 'Walk-in / Unregistered') ?><?= !empty($member['member_number']) && $member['member_number'] !== '—' ? ' <span style="font-size:10px;color:#999;">(' . htmlspecialchars($member['member_number']) . ')</span>' : '' ?></span>
        </div>
        <div class="row">
          <span class="row-label">Phone</span>
          <span class="row-value"><?= htmlspecialchars($tx['phone_number']) ?></span>
        </div>
        <div class="divider"></div>
        <div class="row">
          <span class="row-label">Amount</span>
          <span class="row-value">ZMW <?= htmlspecialchars($amount) ?></span>
        </div>
        <div class="row">
          <span class="row-label">Network Charge</span>
          <span class="row-value" style="color:#c0392b;">ZMW <?= $fmtCharge ?></span>
        </div>
        <div class="row">
          <span class="row-label" style="font-weight:600;">Total Deducted</span>
          <span class="row-value" style="font-weight:600;">ZMW <?= $fmtTotal ?></span>
        </div>
        <?php if (!empty($tx['member_balance'])): ?>
        <div class="row">
          <span class="row-label">VCBI Balance</span>
          <span class="row-value">ZMW <?= htmlspecialchars($tx['member_balance']) ?></span>
        </div>
        <?php endif; ?>
        <div class="divider"></div>
        <div class="row">
          <span class="row-label">Date &amp; Time</span>
          <span class="row-value"><?= htmlspecialchars($date) ?></span>
        </div>
        <div class="row">
          <span class="row-label">Provider</span>
          <span class="row-value">MTN MoMo</span>
        </div>
        <?php if (!empty($tx['description'])): ?>
        <div class="row">
          <span class="row-label">Note</span>
          <span class="row-value"><?= htmlspecialchars($tx['description']) ?></span>
        </div>
        <?php endif; ?>

        <div class="ref-box mtn">
          <div class="ref-label mtn">MTN Transaction ID</div>
          <div class="ref-value mtn"><?= htmlspecialchars($tx['mm_transaction_id']) ?></div>
          <div class="vcbi-ref">VCBI Ref: <?= htmlspecialchars($tx['transaction_ref']) ?></div>
        </div>
      </div>

      <div class="footer mtn">MoMo · Powered by MTN Zambia · VCBI Village Banking</div>
    </div>

    <?php elseif ($isAirtel): ?>
    <!-- ══════════════ AIRTEL MONEY SCREEN ══════════════ -->
    <div class="screen">

      <div class="airtel-header">
        <div class="airtel-logo-row">
          <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
            <path d="M26 8 C26 8, 22 3.5, 14 4.5 C6 5.5, 1 12, 2 19.5 C3 27, 10 32, 18.5 30.5 C23 29.5, 27 27, 29 22.5 L24.5 19 C23 22.5, 20 25, 16 25.5 C10 26.5, 5 22, 4.5 16 C4 10, 8.5 6, 14 5.5 C18 5, 21.5 7.5, 22.5 11 L26 8 Z" fill="white"/>
          </svg>
          <span class="airtel-wordmark">airtel</span>
        </div>
        <div class="airtel-tagline">MONEY · ZAMBIA</div>
      </div>

      <div class="body">
        <div class="check-wrap">
          <div class="check-circle airtel">
            <?php if ($success): ?>
            <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
              <path d="M7 15.5l6 6 11-12" stroke="#fff" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <?php else: ?>
            <svg width="30" height="30" viewBox="0 0 30 30" fill="none">
              <path d="M8 8l14 14M22 8L8 22" stroke="#fff" stroke-width="3" stroke-linecap="round"/>
            </svg>
            <?php endif; ?>
          </div>
        </div>

        <div class="confirm-title">
          <?= $success ? 'Withdrawal Successful' : 'Withdrawal Failed' ?>
        </div>
        <div class="confirm-amount airtel">ZMW <?= htmlspecialchars($amount) ?></div>

        <div class="row">
          <span class="row-label">Status</span>
          <?php if ($success): ?>
            <span class="status-badge">Completed</span>
          <?php else: ?>
            <span class="failed-badge">Failed</span>
          <?php endif; ?>
        </div>
        <div class="row">
          <span class="row-label">Member</span>
          <span class="row-value"><?= htmlspecialchars($member['full_name'] ?? 'Walk-in / Unregistered') ?><?= !empty($member['member_number']) && $member['member_number'] !== '—' ? ' <span style="font-size:10px;color:#999;">(' . htmlspecialchars($member['member_number']) . ')</span>' : '' ?></span>
        </div>
        <div class="row">
          <span class="row-label">Phone</span>
          <span class="row-value"><?= htmlspecialchars($tx['phone_number']) ?></span>
        </div>
        <div class="divider"></div>
        <div class="row">
          <span class="row-label">Amount</span>
          <span class="row-value">ZMW <?= htmlspecialchars($amount) ?></span>
        </div>
        <div class="row">
          <span class="row-label">Network Charge</span>
          <span class="row-value" style="color:#c0392b;">ZMW <?= $fmtCharge ?></span>
        </div>
        <div class="row">
          <span class="row-label" style="font-weight:600;">Total Deducted</span>
          <span class="row-value" style="font-weight:600;">ZMW <?= $fmtTotal ?></span>
        </div>
        <?php if (!empty($tx['member_balance'])): ?>
        <div class="row">
          <span class="row-label">VCBI Balance</span>
          <span class="row-value">ZMW <?= htmlspecialchars($tx['member_balance']) ?></span>
        </div>
        <?php endif; ?>
        <div class="divider"></div>
        <div class="row">
          <span class="row-label">Date &amp; Time</span>
          <span class="row-value"><?= htmlspecialchars($date) ?></span>
        </div>
        <div class="row">
          <span class="row-label">Provider</span>
          <span class="row-value">Airtel Money</span>
        </div>
        <?php if (!empty($tx['description'])): ?>
        <div class="row">
          <span class="row-label">Note</span>
          <span class="row-value"><?= htmlspecialchars($tx['description']) ?></span>
        </div>
        <?php endif; ?>

        <div class="ref-box airtel">
          <div class="ref-label airtel">Airtel Transaction ID</div>
          <div class="ref-value airtel"><?= htmlspecialchars($tx['mm_transaction_id']) ?></div>
          <div class="vcbi-ref">VCBI Ref: <?= htmlspecialchars($tx['transaction_ref']) ?></div>
        </div>
      </div>

      <div class="footer airtel">Airtel Money · Powered by Airtel Zambia · VCBI Village Banking</div>
    </div>

    <?php else: ?>
    <!-- Unknown provider fallback -->
    <div class="body" style="padding:40px 20px; text-align:center;">
      <p style="color:#666;">Unknown provider: <?= htmlspecialchars($tx['provider']) ?></p>
    </div>
    <?php endif; ?>

  </div><!-- /phone -->

  <div class="actions">
    <button class="btn-back" onclick="history.back()">← Back</button>
    <button class="btn-print" onclick="window.print()">🖨 Print Receipt</button>
  </div>
</div>

</body>
</html>