// =============================================
//  VCBI — Village Community Banking Initiative
//  app.js  (Database-connected version + Statements)
// =============================================

'use strict';

// ---- API base path ----
const API = {
  members:      'api/members.php',
  transactions: 'api/transactions.php',
};

// ---- In-memory cache ----
let _members    = [];
let _deposits   = [];
let _loans      = [];
let _repayments = [];

// ---- HELPERS ----
const fmt      = (n) => 'ZMW ' + Number(n).toLocaleString('en-ZM', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtShort = (n) => 'ZMW ' + Number(n).toLocaleString('en-ZM', { maximumFractionDigits: 0 });
const today    = ()  => new Date().toISOString().split('T')[0];
const initials = (name) => name.split(' ').map(p => p[0]).join('').slice(0, 2).toUpperCase();

const avatarColors = ['#1A7A4A','#C9943A','#1A5276','#8E44AD','#C0392B','#16A085'];
const avatarColor  = (id) => avatarColors[(id - 1) % avatarColors.length];

// Returns either an <img> or an initials <div> depending on whether the member has a photo
function memberAvatar(member, size = 36) {
  const s = `width:${size}px;height:${size}px;border-radius:50%;object-fit:cover;flex-shrink:0;`;
  if (member && member.photo) {
    const fb = `<div class='member-avatar' style='background:${avatarColor(member.id)};width:${size}px;height:${size}px;font-size:${Math.round(size*0.35)}px'>${initials(member.name)}</div>`;
    return `<img src="${member.photo}" alt="${member.name}" style="${s}border:2px solid #ddd;" onerror="this.outerHTML=\`${fb}\`">`;
  }
  const fs = Math.round(size * 0.35);
  return `<div class="member-avatar" style="background:${avatarColor(member?.id||1)};width:${size}px;height:${size}px;font-size:${fs}px;flex-shrink:0">${initials(member?.name||'??')}</div>`;
}

// Reads a file input and returns a base64 data-URL (or null if nothing selected)
function readFileAsDataURL(input) {
  return new Promise((resolve) => {
    const file = input?.files?.[0];
    if (!file) { resolve(null); return; }
    const reader = new FileReader();
    reader.onload = (e) => resolve(e.target.result);
    reader.onerror = () => resolve(null);
    reader.readAsDataURL(file);
  });
}

function loanTotal(loan)   { return Number(loan.amount) + (Number(loan.amount) * Number(loan.interest) / 100); }
function loanBalance(loan) { return Math.max(0, loanTotal(loan) - Number(loan.repaid)); }
function getMemberById(id) { return _members.find(m => Number(m.id) === Number(id)); }

// ---- FETCH HELPERS ----
async function apiFetch(url, options = {}) {
  try {
    const res = await fetch(url, {
      headers: { 'Content-Type': 'application/json' },
      ...options,
    });
    const data = await res.json();
    if (!res.ok) throw new Error(data.error || 'Server error');
    return data;
  } catch (err) {
    toast(err.message || 'Network error', 'error');
    throw err;
  }
}

async function loadAll() {
  const data = await apiFetch(`${API.transactions}?type=dashboard`);
  _members    = data.members    || [];
  _deposits   = data.deposits   || [];
  _loans      = data.loans      || [];
  _repayments = data.repayments || [];
}

// ---- TOAST ----
function toast(msg, type = 'success') {
  const icons = { success: '✓', error: '✕', warning: '⚠' };
  const el = document.createElement('div');
  el.className = 'toast' + (type !== 'success' ? ' ' + type : '');
  el.innerHTML = `<span>${icons[type] || '✓'}</span> ${msg}`;
  document.getElementById('toastContainer').appendChild(el);
  setTimeout(() => el.remove(), 3500);
}

// ---- NAVIGATION ----
function navigateTo(pageId) {
  document.querySelectorAll('.page').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.nav-item').forEach(n => n.classList.remove('active'));

  const page = document.getElementById('page-' + pageId);
  if (page) page.classList.add('active');

  const nav = document.querySelector('[data-page="' + pageId + '"]');
  if (nav) nav.classList.add('active');

  const titles = {
    dashboard: 'Dashboard', members: 'Members', deposits: 'Deposits',
    loans: 'Loans', repayments: 'Repayments', reports: 'Reports & Year-End'
  };
  document.getElementById('topbarTitle').textContent = titles[pageId] || 'VCBI';
  document.getElementById('sidebar').classList.remove('open');
  renderPage(pageId);
}

function renderPage(id) {
  const renders = {
    dashboard:  renderDashboard,
    members:    renderMembers,
    deposits:   renderDeposits,
    loans:      renderLoans,
    repayments: renderRepayments,
    reports:    renderReports,
  };
  if (renders[id]) renders[id]();
}

// ---- DASHBOARD ----
async function renderDashboard() {
  await loadAll();

  const totalSavings  = _deposits.reduce((s, d) => s + Number(d.amount), 0);
  const activeLoans   = _loans.filter(l => l.status === 'active');
  const totalInterest = _repayments.reduce((s, r) => {
    const loan = _loans.find(l => Number(l.id) === Number(r.loan_id));
    return loan ? s + Math.max(0, Number(r.amount) - Number(loan.amount)) : s;
  }, 0);

  document.getElementById('dash-members').textContent  = _members.filter(m => m.status === 'active').length;
  document.getElementById('dash-savings').textContent  = fmtShort(totalSavings);
  document.getElementById('dash-loans').textContent    = activeLoans.length;
  document.getElementById('dash-interest').textContent = fmtShort(totalInterest);

  const activities = [
    ..._deposits.slice(-4).map(d => ({
      text: `<strong>${getMemberById(d.member_id)?.name || 'Member'}</strong> deposited <strong>${fmt(d.amount)}</strong>`,
      date: d.date, color: 'green'
    })),
    ..._loans.slice(-3).map(l => ({
      text: `Loan of <strong>${fmt(l.amount)}</strong> issued to <strong>${getMemberById(l.member_id)?.name || 'Member'}</strong>`,
      date: l.applied_date, color: 'gold'
    })),
    ..._repayments.slice(-2).map(r => ({
      text: `<strong>${getMemberById(r.member_id)?.name || 'Member'}</strong> repaid <strong>${fmt(r.amount)}</strong>`,
      date: r.date, color: 'green'
    })),
  ].sort((a, b) => new Date(b.date) - new Date(a.date)).slice(0, 7);

  document.getElementById('activity-feed').innerHTML = activities.map(a => `
    <div class="activity-item">
      <div class="activity-dot ${a.color}"></div>
      <div>
        <div class="activity-text">${a.text}</div>
        <div class="activity-time">${a.date}</div>
      </div>
    </div>
  `).join('') || '<p class="text-muted" style="font-size:.875rem">No activity yet.</p>';

  const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  const cur    = new Date().getMonth();
  document.getElementById('cycle-months').innerHTML = months.map((m, i) => {
    let cls = i < cur ? 'paid' : i === cur ? 'due' : 'future';
    return `<div class="cycle-month ${cls}" title="${m}">${m}</div>`;
  }).join('');

  const sorted = [..._members].sort((a, b) => Number(b.savings) - Number(a.savings)).slice(0, 5);
  document.getElementById('top-members').innerHTML = sorted.map(m => `
    <div class="activity-item" style="padding:10px 0;">
      ${memberAvatar(m, 36)}
      <div style="flex:1">
        <div class="member-name">${m.name}</div>
        <div class="member-id">ID #${String(m.id).padStart(4,'0')}</div>
      </div>
      <div class="text-mono text-green fw-600" style="font-size:.85rem">${fmtShort(m.savings)}</div>
    </div>
  `).join('');
}

// ---- MEMBERS ----
async function renderMembers() {
  _members = await apiFetch(API.members);

  const tbody = document.getElementById('members-tbody');
  tbody.innerHTML = _members.map(m => `
    <tr>
      <td>
        <div class="member-info">
          ${memberAvatar(m, 36)}
          <div>
            <div class="member-name">${m.name}</div>
            <div class="member-id">${m.national_id}</div>
          </div>
        </div>
      </td>
      <td>${m.phone}</td>
      <td>${m.join_date}</td>
      <td class="text-mono text-green">${fmtShort(m.savings)}</td>
      <td><span class="badge badge-${m.status === 'active' ? 'green' : 'gray'}">${m.status}</span></td>
      <td>
        <button class="btn btn-sm btn-secondary" onclick="viewMember(${m.id})">View</button>
        <button class="btn btn-sm" onclick="editMember(${m.id})"
          style="margin-left:4px;background:#1A7A4A;color:#fff;border:none">✏️ Edit</button>
        <button class="btn btn-sm" onclick="downloadStatement(${m.id},'pdf')"
          style="margin-left:4px;background:#C9943A;color:#fff;border:none">📄 PDF</button>
        <button class="btn btn-sm" onclick="downloadStatement(${m.id},'excel')"
          style="margin-left:4px;background:#1A5276;color:#fff;border:none">📊 Excel</button>
        <button class="btn btn-sm btn-danger" style="margin-left:4px" onclick="deleteMember(${m.id})">Remove</button>
      </td>
    </tr>
  `).join('') || '<tr><td colspan="6" class="text-center text-muted" style="padding:24px">No members yet.</td></tr>';

  document.getElementById('members-count').textContent = _members.length + ' members';
}

// ---- DOWNLOAD STATEMENT ----
function downloadStatement(memberId, type) {
  const urls = {
    pdf:   `api/statement_pdf.php?id=${memberId}`,
    excel: `api/statement_excel.php?id=${memberId}`,
  };
  const a = document.createElement('a');
  a.href = urls[type];
  a.download = '';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  toast(`Downloading ${type.toUpperCase()} statement...`);
}

async function addMember() {
  const name  = document.getElementById('m-name').value.trim();
  const id    = document.getElementById('m-id').value.trim();
  const phone = document.getElementById('m-phone').value.trim();
  const kin   = document.getElementById('m-kin').value.trim();
  const date  = document.getElementById('m-date').value || today();

  if (!name || !id || !phone) { toast('Please fill in all required fields.', 'error'); return; }

  const photo = await readFileAsDataURL(document.getElementById('m-photo'));

  try {
    await apiFetch(API.members, {
      method: 'POST',
      body: JSON.stringify({ name, nationalId: id, phone, nextOfKin: kin, joinDate: date, photo }),
    });
    closeModal('memberModal');
    renderMembers();
    toast(`Member "${name}" registered successfully.`);
    document.getElementById('addMemberForm').reset();
    document.getElementById('m-photo-preview').style.display = 'none';
  } catch (_) {}
}

// ---- EDIT MEMBER ----
async function editMember(id) {
  const m = await apiFetch(`${API.members}?id=${id}`);

  // Pre-fill the edit modal with current values
  document.getElementById('edit-m-id-hidden').value = m.id;
  document.getElementById('edit-m-name').value       = m.name        || '';
  document.getElementById('edit-m-nrc').value        = m.national_id || '';
  document.getElementById('edit-m-phone').value      = m.phone       || '';
  document.getElementById('edit-m-kin').value        = m.next_of_kin || '';
  document.getElementById('edit-m-date').value       = m.join_date   || '';
  document.getElementById('edit-m-status').value     = m.status      || 'active';

  // Show existing photo preview if available
  const preview = document.getElementById('edit-m-photo-preview');
  if (m.photo) {
    preview.src   = m.photo;
    preview.style.display = 'block';
  } else {
    preview.style.display = 'none';
  }
  // Store current photo so we don't clear it if no new one is chosen
  document.getElementById('edit-m-photo').dataset.currentPhoto = m.photo || '';

  openModal('editMemberModal');
}

async function updateMember() {
  const id    = document.getElementById('edit-m-id-hidden').value;
  const name  = document.getElementById('edit-m-name').value.trim();
  const nrc   = document.getElementById('edit-m-nrc').value.trim();
  const phone = document.getElementById('edit-m-phone').value.trim();
  const kin   = document.getElementById('edit-m-kin').value.trim();
  const date  = document.getElementById('edit-m-date').value;
  const status= document.getElementById('edit-m-status').value;

  if (!name || !nrc || !phone) { toast('Name, NRC and Phone are required.', 'error'); return; }

  // Use new photo if chosen, otherwise pass null (backend keeps existing)
  const photoInput = document.getElementById('edit-m-photo');
  const newPhoto   = await readFileAsDataURL(photoInput);
  const photo      = newPhoto || null;

  try {
    await apiFetch(`${API.members}?id=${id}`, {
      method: 'PUT',
      body: JSON.stringify({ name, nationalId: nrc, phone, nextOfKin: kin, joinDate: date, status, photo }),
    });
    closeModal('editMemberModal');
    renderMembers();
    toast(`${name}'s details updated successfully.`);
  } catch (_) {}
}

async function viewMember(id) {
  const m = await apiFetch(`${API.members}?id=${id}`);
  const loans    = m.loans    || [];
  const deposits = m.deposits || [];

  document.getElementById('member-detail-body').innerHTML = `
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:20px">
      ${memberAvatar(m, 54)}
      <div style="flex:1">
        <div style="font-size:1.15rem;font-weight:600">${m.name}</div>
        <div class="member-id">${m.national_id} · ${m.phone}</div>
      </div>
      <div style="display:flex;gap:8px">
        <button class="btn btn-sm" onclick="downloadStatement(${m.id},'pdf')"
          style="background:#C9943A;color:#fff;border:none">📄 PDF</button>
        <button class="btn btn-sm" onclick="downloadStatement(${m.id},'excel')"
          style="background:#1A5276;color:#fff;border:none">📊 Excel</button>
      </div>
    </div>
    <div class="two-col" style="gap:12px;margin-bottom:16px">
      <div><div class="stat-label">Total Savings</div><div class="stat-value text-green" style="font-size:1.2rem">${fmt(m.savings)}</div></div>
      <div><div class="stat-label">Next of Kin</div><div style="font-size:.9rem;font-weight:500">${m.next_of_kin || '—'}</div></div>
      <div><div class="stat-label">Join Date</div><div style="font-size:.9rem">${m.join_date}</div></div>
      <div><div class="stat-label">Deposits Made</div><div style="font-size:.9rem">${deposits.length}</div></div>
    </div>
    <div class="stat-label" style="margin-bottom:8px">Loans</div>
    ${loans.length === 0 ? '<p class="text-muted" style="font-size:.875rem">No loans on record.</p>' :
      loans.map(l => `
        <div class="loan-card">
          <div class="loan-card-header">
            <span class="loan-amount">${fmt(l.amount)}</span>
            <span class="badge badge-${l.status === 'active' ? 'gold' : 'green'}">${l.status}</span>
          </div>
          <div style="font-size:.8rem;color:var(--ink-light)">${l.purpose} · Due ${l.due_date}</div>
          <div class="progress-bar" style="margin-top:10px">
            <div class="progress-fill" style="width:${Math.round(Number(l.repaid) / loanTotal(l) * 100)}%"></div>
          </div>
          <div style="font-size:.75rem;color:var(--ink-light);margin-top:4px">Repaid: ${fmt(l.repaid)} / ${fmt(loanTotal(l))}</div>
        </div>
      `).join('')}
  `;
  openModal('memberDetailModal');
}

async function deleteMember(id) {
  const m = getMemberById(id) || { name: 'this member' };
  if (!confirm(`Remove ${m.name} from the system? This cannot be undone.`)) return;
  try {
    await apiFetch(`${API.members}?id=${id}`, { method: 'DELETE' });
    renderMembers();
    toast(`${m.name} has been removed.`, 'warning');
  } catch (_) {}
}

// ---- DEPOSITS ----
async function renderDeposits() {
  [_members, _deposits] = await Promise.all([
    apiFetch(API.members),
    apiFetch(`${API.transactions}?type=deposits`),
  ]);

  const sel = document.getElementById('dep-member');
  sel.innerHTML = '<option value="">Select member...</option>' +
    _members.map(m => `<option value="${m.id}">${m.name}</option>`).join('');

  const tbody  = document.getElementById('deposits-tbody');
  const sorted = [..._deposits].sort((a, b) => new Date(b.date) - new Date(a.date));
  tbody.innerHTML = sorted.map(d => {
    const mem = getMemberById(d.member_id);
    return `
      <tr>
        <td>${d.date}</td>
        <td>
          <div class="member-info">
            ${memberAvatar(mem, 28)}
            <span class="member-name">${mem?.name || 'Unknown'}</span>
          </div>
        </td>
        <td class="text-mono text-green fw-600">${fmt(d.amount)}</td>
        <td>${d.month}</td>
        <td>${d.note || '—'}</td>
        <td><span class="badge badge-green">Recorded</span></td>
      </tr>
    `;
  }).join('') || '<tr><td colspan="6" class="text-center text-muted" style="padding:24px">No deposits recorded.</td></tr>';

  const total = _deposits.reduce((s, d) => s + Number(d.amount), 0);
  document.getElementById('deposits-total').textContent = 'Total: ' + fmt(total);
}

async function recordDeposit() {
  const memberId = parseInt(document.getElementById('dep-member').value);
  const amount   = parseFloat(document.getElementById('dep-amount').value);
  const month    = document.getElementById('dep-month').value.trim();
  const date     = document.getElementById('dep-date').value || today();
  const note     = document.getElementById('dep-note').value.trim();

  if (!memberId || !amount || !month) { toast('Please fill in all required fields.', 'error'); return; }
  if (amount <= 0) { toast('Amount must be greater than zero.', 'error'); return; }

  try {
    await apiFetch(`${API.transactions}?type=deposits`, {
      method: 'POST',
      body: JSON.stringify({ memberId, amount, month, date, note }),
    });
    closeModal('depositModal');
    renderDeposits();
    toast('Deposit recorded successfully.');
    document.getElementById('depositForm').reset();
  } catch (_) {}
}

// ---- LOANS ----

// ── Overdue + penalty calculator ──────────────────────────────
const PENALTY_RATE_PER_DAY = 0.005; // 0.5% of balance per overdue day

function loanOverdueInfo(loan) {
  if (loan.status !== 'active') return null;
  const due  = new Date(loan.due_date);
  const now  = new Date(); now.setHours(0,0,0,0);
  const days = Math.floor((now - due) / 86400000);
  if (days <= 0) return null;
  const balance = loanBalance(loan);
  const penalty = +(balance * PENALTY_RATE_PER_DAY * days).toFixed(2);
  return { days, penalty, totalDue: +(balance + penalty).toFixed(2) };
}

// ── Mobile money phone builder for loan modal ─────────────────
function buildLoanPhone() {
  const netVal  = document.getElementById('loan-mm-network').value;
  const digits  = document.getElementById('loan-mm-digits').value.trim();
  const preview = document.getElementById('loan-mm-preview');
  const hidden  = document.getElementById('loan-mm-phone');

  if (!netVal || !digits) {
    hidden.value = '';
    preview.textContent = netVal ? '📱 Enter remaining ' + (7 - digits.length) + ' digit(s)' : 'Select a network first';
    preview.style.color = 'var(--ink-light)';
    return;
  }
  const prefix3 = netVal.split(':')[1];
  const full    = '0' + prefix3 + digits;
  hidden.value  = full;
  const pretty  = '+260 ' + prefix3[0] + prefix3[1] + '-' + digits;
  if (digits.length === 7) {
    preview.textContent = '✅ Loan will be sent to ' + pretty;
    preview.style.color = 'var(--green-dark)';
  } else {
    preview.textContent = pretty + '  (' + (7 - digits.length) + ' more digit(s))';
    preview.style.color = 'var(--ink-light)';
  }
}

function onLoanNetworkChange(val) { buildLoanPhone(); }

// Auto-fill phone from member's registered number when member is selected
function onLoanMemberChange(memberId) {
  updateLoanCalc();
  if (!memberId) return;
  const mem = getMemberById(parseInt(memberId));
  if (!mem || !mem.phone) return;
  const phone = mem.phone.replace(/\D/g,'');
  const local = phone.length >= 10 ? phone.slice(-7) : phone.slice(-7);
  const prefix3 = phone.length >= 10 ? phone.slice(-10,-7) : phone.slice(0,3);

  // Map prefix3 → dropdown value
  const map = {
    '076':'MTN MoMo:076','096':'MTN MoMo:096',
    '077':'Airtel Money:077','097':'Airtel Money:097','057':'Airtel Money:057',
  };
  const dropVal = map[prefix3];
  if (dropVal) {
    document.getElementById('loan-mm-network').value = dropVal;
    document.getElementById('loan-mm-digits').value  = local;
    buildLoanPhone();
  }
}

function updateLoanCalc() {
  const amount   = parseFloat(document.getElementById('loan-amount').value) || 0;
  const interest = parseFloat(document.getElementById('loan-interest').value) || 7;
  const dueDate  = document.getElementById('loan-due').value;
  const calc     = document.getElementById('loan-calc');
  if (!amount) { calc.textContent = ''; return; }
  const total    = amount + (amount * interest / 100);
  const repay    = dueDate ? ' | Due: ' + dueDate : '';
  const penalty  = 'Late penalty: 0.5%/day of balance after due date';
  calc.innerHTML = `Principal: ${fmt(amount)} | Interest: ${interest}% | <strong>Total repayable: ${fmt(total)}</strong>${repay}<br><span style="color:#dc2626;font-size:.78rem">⚠️ ${penalty}</span>`;
}

async function renderLoans() {
  [_members, _loans] = await Promise.all([
    apiFetch(API.members),
    apiFetch(`${API.transactions}?type=loans`),
  ]);

  const sel = document.getElementById('loan-member');
  sel.innerHTML = '<option value="">Select member...</option>' +
    _members.map(m => `<option value="${m.id}">${m.name} (Savings: ${fmtShort(m.savings)})</option>`).join('');

  const tbody  = document.getElementById('loans-tbody');
  const sorted = [..._loans].sort((a, b) => new Date(b.applied_date) - new Date(a.applied_date));
  tbody.innerHTML = sorted.map(l => {
    const mem      = getMemberById(l.member_id);
    const total    = loanTotal(l);
    const balance  = loanBalance(l);
    const pct      = Math.round(Number(l.repaid) / total * 100);
    const overdue  = loanOverdueInfo(l);
    const dueBadge = overdue
      ? `<br><span style="color:#dc2626;font-size:.72rem;font-weight:700">🔴 ${overdue.days}d overdue | +${fmt(overdue.penalty)} penalty</span>`
      : (l.status === 'active' && l.due_date < today()
          ? '<br><span style="color:#dc2626;font-size:.72rem">Overdue</span>' : '');
    return `
      <tr>
        <td class="text-mono" style="font-size:.78rem">#L${String(l.id).padStart(4,'0')}</td>
        <td>
          <div class="member-info">
            ${memberAvatar(mem, 28)}
            <span class="member-name">${mem?.name || 'Unknown'}</span>
          </div>
        </td>
        <td class="text-mono fw-600">${fmt(l.amount)}</td>
        <td>${l.interest}%/mo</td>
        <td>${l.purpose}</td>
        <td>${l.due_date}${dueBadge}</td>
        <td>
          <div class="progress-bar" style="height:6px;width:80px">
            <div class="progress-fill ${pct===100?'':'gold'}" style="width:${pct}%"></div>
          </div>
          <div style="font-size:.72rem;color:var(--ink-light);margin-top:3px">${pct}% repaid</div>
        </td>
        <td>
          <span class="badge badge-${l.status === 'active' ? (overdue ? 'red' : 'gold') : l.status === 'repaid' ? 'green' : 'red'}">${overdue ? 'OVERDUE' : l.status}</span>
        </td>
        <td class="text-mono text-red fw-600" style="font-size:.85rem">
          ${fmt(overdue ? overdue.totalDue : balance)}
          ${overdue ? `<div style="font-size:.7rem;color:#dc2626">(incl. penalty)</div>` : ''}
        </td>
      </tr>
    `;
  }).join('') || '<tr><td colspan="9" class="text-center text-muted" style="padding:24px">No loans recorded.</td></tr>';

  const outstanding = _loans.filter(l => l.status === 'active').reduce((s, l) => {
    const ov = loanOverdueInfo(l);
    return s + (ov ? ov.totalDue : loanBalance(l));
  }, 0);
  document.getElementById('loans-outstanding').textContent = 'Outstanding (incl. penalties): ' + fmt(outstanding);
}

async function issueLoan() {
  const memberId  = parseInt(document.getElementById('loan-member').value);
  const amount    = parseFloat(document.getElementById('loan-amount').value);
  const interest  = parseFloat(document.getElementById('loan-interest').value) || 7;
  const purpose   = document.getElementById('loan-purpose').value.trim();
  const dueDate   = document.getElementById('loan-due').value;
  const mmNetwork = document.getElementById('loan-mm-network').value;
  const mmPhone   = document.getElementById('loan-mm-phone').value.trim();

  if (!memberId || !amount || !purpose || !dueDate) { toast('Please fill in all required fields.', 'error'); return; }
  if (amount <= 0) { toast('Amount must be greater than zero.', 'error'); return; }
  if (!mmNetwork || !mmPhone || mmPhone.length < 10) { toast('Please select a network and enter a valid 10-digit phone number for disbursement.', 'error'); return; }

  // Confirm before disbursing
  const provider = mmNetwork.split(':')[0];
  const confirmed = confirm(
    `⚠️ CONFIRM LOAN DISBURSEMENT\n\n` +
    `Member: ${getMemberById(memberId)?.name}\n` +
    `Amount: ZMW ${amount.toFixed(2)}\n` +
    `Network: ${provider}\n` +
    `Send to: ${mmPhone}\n` +
    `Due Date: ${dueDate}\n\n` +
    `Are you sure you want to send this loan via ${provider}?`
  );
  if (!confirmed) return;

  try {
    await apiFetch(`${API.transactions}?type=loans`, {
      method: 'POST',
      body: JSON.stringify({ memberId, amount, interest, purpose, dueDate, mmPhone, mmNetwork: provider }),
    });
    closeModal('loanModal');
    renderLoans();
    toast(`✅ Loan of ZMW ${amount.toFixed(2)} disbursed to ${mmPhone} via ${provider}. Due: ${dueDate}`);
    document.getElementById('loanForm').reset();
    document.getElementById('loan-mm-preview').textContent = '';
  } catch (_) {}
}

// ---- REPAYMENTS ----

// Show overdue warning when a loan is selected in repayment modal
function onRepLoanChange(loanId) {
  const loan    = _loans.find(l => Number(l.id) === Number(loanId));
  const box     = document.getElementById('rep-overdue-box');
  const detail  = document.getElementById('rep-overdue-detail');
  const calcEl  = document.getElementById('rep-calc');

  if (!loan) { box.style.display = 'none'; calcEl.textContent = ''; return; }

  const overdue  = loanOverdueInfo(loan);
  const balance  = loanBalance(loan);
  const total    = loanTotal(loan);

  calcEl.textContent = `Principal: ${fmt(loan.amount)} | Total Due: ${fmt(total)} | Repaid: ${fmt(loan.repaid)} | Balance: ${fmt(balance)}`;

  if (overdue) {
    box.style.display = 'block';
    detail.innerHTML =
      `Days overdue: <strong>${overdue.days} day(s)</strong><br>` +
      `Original balance: ${fmt(balance)}<br>` +
      `Late penalty (0.5%/day): + ${fmt(overdue.penalty)}<br>` +
      `<strong>Total now due: ${fmt(overdue.totalDue)}</strong><br>` +
      `⚠️ Member must pay ${fmt(overdue.totalDue)} to fully clear this loan.`;
    // Pre-fill with total due
    document.getElementById('rep-amount').value = overdue.totalDue.toFixed(2);
  } else {
    box.style.display = 'none';
    document.getElementById('rep-amount').value = balance.toFixed(2);
  }
}

async function renderRepayments() {
  [_members, _loans, _repayments] = await Promise.all([
    apiFetch(API.members),
    apiFetch(`${API.transactions}?type=loans`),
    apiFetch(`${API.transactions}?type=repayments`),
  ]);

  const sel         = document.getElementById('rep-loan');
  const activeLoans = _loans.filter(l => l.status === 'active');
  sel.innerHTML = '<option value="">Select loan...</option>' +
    activeLoans.map(l => {
      const mem     = getMemberById(l.member_id);
      const overdue = loanOverdueInfo(l);
      const label   = overdue
        ? `⚠️ ${mem?.name} — ${fmt(l.amount)} [OVERDUE ${overdue.days}d | Due: ${fmt(overdue.totalDue)}]`
        : `${mem?.name} — ${fmt(l.amount)} (Balance: ${fmt(loanBalance(l))})`;
      return `<option value="${l.id}">${label}</option>`;
    }).join('');

  const tbody  = document.getElementById('repayments-tbody');
  const sorted = [..._repayments].sort((a, b) => new Date(b.date) - new Date(a.date));
  tbody.innerHTML = sorted.map(r => {
    const loan     = _loans.find(l => Number(l.id) === Number(r.loan_id));
    const mem      = getMemberById(r.member_id);
    const interest = loan ? Math.max(0, Number(r.amount) - Number(loan.amount)) : 0;
    return `
      <tr>
        <td>${r.date}</td>
        <td>
          <div class="member-info">
            ${memberAvatar(mem, 28)}
            <span class="member-name">${mem?.name || 'Unknown'}</span>
          </div>
        </td>
        <td class="text-mono" style="font-size:.78rem">#L${String(r.loan_id).padStart(4,'0')}</td>
        <td class="text-mono fw-600">${fmt(r.amount)}</td>
        <td class="text-mono text-green">${fmt(interest)}</td>
        <td>${r.note || '—'}</td>
        <td><span class="badge badge-green">✓ Received</span></td>
      </tr>
    `;
  }).join('') || '<tr><td colspan="7" class="text-center text-muted" style="padding:24px">No repayments recorded.</td></tr>';

  const totalRec = _repayments.reduce((s, r) => s + Number(r.amount), 0);
  document.getElementById('repayments-total').textContent = 'Total Received: ' + fmt(totalRec);
}

async function recordRepayment() {
  const loanId = parseInt(document.getElementById('rep-loan').value);
  const amount = parseFloat(document.getElementById('rep-amount').value);
  const note   = document.getElementById('rep-note').value.trim();
  const date   = document.getElementById('rep-date').value || today();

  if (!loanId || !amount) { toast('Please select a loan and enter an amount.', 'error'); return; }
  if (amount <= 0) { toast('Amount must be greater than zero.', 'error'); return; }

  // Warn if paying less than overdue total
  const loan    = _loans.find(l => Number(l.id) === Number(loanId));
  const overdue = loan ? loanOverdueInfo(loan) : null;
  if (overdue && amount < overdue.totalDue - 0.01) {
    const proceed = confirm(
      `⚠️ SHORT PAYMENT WARNING\n\n` +
      `This loan is ${overdue.days} day(s) overdue.\n` +
      `Total due (incl. penalty): ZMW ${overdue.totalDue.toFixed(2)}\n` +
      `You are recording: ZMW ${amount.toFixed(2)}\n\n` +
      `The remaining balance will continue to accrue penalties.\n` +
      `Do you want to record this partial payment?`
    );
    if (!proceed) return;
  }

  try {
    await apiFetch(`${API.transactions}?type=repayments`, {
      method: 'POST',
      body: JSON.stringify({ loanId, amount, note, date }),
    });
    closeModal('repaymentModal');
    renderRepayments();
    toast('Repayment recorded successfully.');
    document.getElementById('repaymentForm').reset();
    document.getElementById('rep-overdue-box').style.display = 'none';
  } catch (_) {}
}

// ---- REPORTS ----
async function renderReports() {
  await loadAll();

  const totalSavings   = _deposits.reduce((s, d) => s + Number(d.amount), 0);
  const totalLoanDisb  = _loans.reduce((s, l) => s + Number(l.amount), 0);
  const totalRepaid    = _repayments.reduce((s, r) => s + Number(r.amount), 0);
  const interestEarned = _loans.reduce((s, l) => s + Math.max(0, Number(l.repaid) - Number(l.amount)), 0);
  const totalFund      = totalSavings + interestEarned;

  document.getElementById('rep-savings').textContent   = fmt(totalSavings);
  document.getElementById('rep-disbursed').textContent = fmt(totalLoanDisb);
  document.getElementById('rep-repaid').textContent    = fmt(totalRepaid);
  document.getElementById('rep-interest').textContent  = fmt(interestEarned);
  document.getElementById('rep-totalfund').textContent = fmt(totalFund);
  document.getElementById('rep-members').textContent   = _members.filter(m => m.status === 'active').length;

  const active         = _members.filter(m => m.status === 'active');
  const perMemberShare = active.length > 0 ? interestEarned / active.length : 0;
  document.getElementById('payout-tbody').innerHTML = active.map(m => {
    const memberDeps = _deposits.filter(d => Number(d.member_id) === Number(m.id)).reduce((s, d) => s + Number(d.amount), 0);
    const payout     = memberDeps + perMemberShare;
    const returnPct  = memberDeps > 0 ? ((payout - memberDeps) / memberDeps * 100).toFixed(1) : '0.0';
    return `
      <tr>
        <td>
          <div class="member-info">
            ${memberAvatar(m, 28)}
            <span class="member-name">${m.name}</span>
          </div>
        </td>
        <td class="text-mono">${fmt(memberDeps)}</td>
        <td class="text-mono text-green">${fmt(perMemberShare)}</td>
        <td class="text-mono fw-600">${fmt(payout)}</td>
        <td><span class="badge badge-green">+${returnPct}%</span></td>
      </tr>
    `;
  }).join('') || '<tr><td colspan="5" class="text-center text-muted" style="padding:24px">No active members.</td></tr>';

  document.getElementById('rep-payout-total').textContent = 'Total Fund to Distribute: ' + fmt(totalFund);
}

// ---- MODAL HELPERS ----
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.addEventListener('DOMContentLoaded', () => {
  document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
      if (e.target === overlay) overlay.classList.remove('open');
    });
  });
});

// ---- SEARCH FILTER ----
function filterTable(inputId, tbodyId) {
  const q = document.getElementById(inputId).value.toLowerCase();
  document.getElementById(tbodyId).querySelectorAll('tr').forEach(row => {
    row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
  });
}

// ---- INIT ----
document.addEventListener('DOMContentLoaded', () => {
  navigateTo('dashboard');

  const ham     = document.getElementById('hamburger');
  const sidebar = document.getElementById('sidebar');
  if (ham) ham.addEventListener('click', () => sidebar.classList.toggle('open'));

  const loanAmountEl = document.getElementById('loan-amount');
  const loanIntEl    = document.getElementById('loan-interest');
  const loanCalc     = document.getElementById('loan-calc');
  function updateLoanCalc() {
    const amt  = parseFloat(loanAmountEl?.value) || 0;
    const rate = parseFloat(loanIntEl?.value) || 7;
    if (amt > 0 && loanCalc) {
      const total = amt + (amt * rate / 100);
      loanCalc.textContent = `Total to repay: ${fmt(total)} (Principal ${fmt(amt)} + Interest ${fmt(amt * rate / 100)})`;
    }
  }
  if (loanAmountEl) loanAmountEl.addEventListener('input', updateLoanCalc);
  if (loanIntEl)    loanIntEl.addEventListener('input', updateLoanCalc);

  const repLoanSel = document.getElementById('rep-loan');
  const repAmtEl   = document.getElementById('rep-amount');
  const repCalc    = document.getElementById('rep-calc');
  if (repLoanSel) repLoanSel.addEventListener('change', () => {
    const id   = parseInt(repLoanSel.value);
    const loan = _loans.find(l => Number(l.id) === id);
    if (loan && repCalc) {
      const bal = loanBalance(loan);
      repCalc.textContent = `Outstanding balance: ${fmt(bal)}`;
      if (repAmtEl) repAmtEl.value = bal.toFixed(2);
    } else if (repCalc) { repCalc.textContent = ''; }
  });
});
