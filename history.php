<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

session_start();

$pdo = getDB();

/* ─── Actions: delete single / clear all ──────────────────── */
if (isset($_GET['delete']) && ctype_digit((string)$_GET['delete'])) {
    $stmt = $pdo->prepare('DELETE FROM scan_history WHERE id = :id');
    $stmt->execute([':id' => (int)$_GET['delete']]);
    header('Location: history.php');
    exit;
}

if (isset($_GET['clear']) && $_GET['clear'] === '1') {
    $pdo->exec('DELETE FROM scan_history');
    header('Location: history.php');
    exit;
}

/* ─── Filters ──────────────────────────────────────────────── */
$verdictFilter = $_GET['verdict'] ?? 'all';
$dateFrom      = $_GET['date_from'] ?? '';
$dateTo        = $_GET['date_to']   ?? '';
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = 10;
$offset        = ($page - 1) * $perPage;

$where  = [];
$params = [];

if (in_array($verdictFilter, ['AI-Generated', 'Natural/Real'], true)) {
    $where[]                   = 'verdict = :verdict';
    $params[':verdict']        = $verdictFilter;
}

if ($dateFrom !== '') {
    $where[]              = 'DATE(created_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[]            = 'DATE(created_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ─── Count for pagination ─────────────────────────────────── */
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM scan_history $whereClause");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = (int)ceil($totalRows / $perPage);

/* ─── Fetch page ───────────────────────────────────────────── */
$dataStmt = $pdo->prepare(
    "SELECT * FROM scan_history $whereClause ORDER BY created_at DESC LIMIT :limit OFFSET :offset"
);
$dataStmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
foreach ($params as $key => $val) {
    $dataStmt->bindValue($key, $val);
}
$dataStmt->execute();
$rows = $dataStmt->fetchAll();

/* ─── Stats ────────────────────────────────────────────────── */
$totalScans = getTotalScans();
$aiCount    = (int)$pdo->query("SELECT COUNT(*) FROM scan_history WHERE verdict='AI-Generated'")->fetchColumn();
$realCount  = (int)$pdo->query("SELECT COUNT(*) FROM scan_history WHERE verdict='Natural/Real'")->fetchColumn();

/* ─── Build pagination URL helper ─────────────────────────── */
function pageUrl(int $pg): string {
    $params               = $_GET;
    $params['page']       = $pg;
    return 'history.php?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Scan History — VeriLens</title>

  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/css/custom.css">

  <script>
    tailwind.config = { darkMode: 'class', corePlugins: { preflight: false } };
  </script>
</head>
<body class="dark">

<!-- ─── Navbar ──────────────────────────────────────────────────── -->
<nav class="vl-navbar px-3 px-md-4">
  <div class="container-xl d-flex align-items-center justify-content-between py-3">
    <div class="d-flex align-items-center gap-3">
      <a href="index.php" class="brand d-flex align-items-center gap-2">
        <i class="fas fa-eye" style="color:#58a6ff;-webkit-text-fill-color:initial"></i>
        VeriLens
      </a>
      <span class="scan-badge d-none d-sm-inline">
        <i class="fas fa-database me-1"></i><?= $totalScans ?> scan<?= $totalScans !== 1 ? 's' : '' ?>
      </span>
    </div>
    <ul class="nav gap-1 mb-0">
      <li class="nav-item">
        <a class="nav-link" href="index.php">
          <i class="fas fa-home me-1"></i><span class="d-none d-sm-inline">Dashboard</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link active" href="history.php">
          <i class="fas fa-history me-1"></i><span class="d-none d-sm-inline">History</span>
        </a>
      </li>
    </ul>
  </div>
</nav>

<!-- ─── Hero ─────────────────────────────────────────────────────── -->
<div class="vl-hero">
  <div class="container-xl px-3 px-md-4">
    <h1><i class="fas fa-clock-rotate-left me-2" style="color:#58a6ff"></i>Scan History</h1>
    <p class="mb-0">Browse, search, and manage all past media analyses.</p>
  </div>
</div>

<main class="container-xl px-3 px-md-4 py-4">

  <!-- ─── Stat Cards Row ──────────────────────────────────────── -->
  <div class="row g-3 mb-4">
    <div class="col-sm-4">
      <div class="stat-card">
        <div class="stat-icon" style="background:rgba(88,166,255,.12)">
          <i class="fas fa-database" style="color:#58a6ff"></i>
        </div>
        <div>
          <div class="stat-value"><?= $totalScans ?></div>
          <div class="stat-label">Total Scans</div>
        </div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--ai-bg)">
          <i class="fas fa-robot" style="color:var(--ai-color)"></i>
        </div>
        <div>
          <div class="stat-value" style="color:var(--ai-color)"><?= $aiCount ?></div>
          <div class="stat-label">AI-Generated</div>
        </div>
      </div>
    </div>
    <div class="col-sm-4">
      <div class="stat-card">
        <div class="stat-icon" style="background:var(--real-bg)">
          <i class="fas fa-leaf" style="color:var(--real-color)"></i>
        </div>
        <div>
          <div class="stat-value" style="color:var(--real-color)"><?= $realCount ?></div>
          <div class="stat-label">Natural/Real</div>
        </div>
      </div>
    </div>
  </div>

  <!-- ─── Filter Bar ──────────────────────────────────────────── -->
  <div class="filter-bar mb-4">
    <form method="GET" action="history.php" class="row g-3 align-items-end">
      <!-- Verdict filter buttons -->
      <div class="col-md-auto">
        <div class="section-title">Filter by Verdict</div>
        <div class="filter-btn-group d-flex gap-2 flex-wrap">
          <?php foreach (['all' => 'All', 'AI-Generated' => 'AI-Generated', 'Natural/Real' => 'Natural/Real'] as $val => $label): ?>
          <button type="submit" name="verdict" value="<?= h($val) ?>"
                  class="filter-btn <?= $verdictFilter === $val ? 'active' : '' ?>">
            <?php if ($val === 'AI-Generated'): ?><i class="fas fa-robot me-1"></i><?php endif; ?>
            <?php if ($val === 'Natural/Real'): ?><i class="fas fa-leaf me-1"></i><?php endif; ?>
            <?php if ($val === 'all'):          ?><i class="fas fa-list me-1"></i><?php endif; ?>
            <?= h($label) ?>
          </button>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Date range -->
      <div class="col-md-auto">
        <div class="section-title">Date From</div>
        <input type="date" name="date_from" value="<?= h($dateFrom) ?>" class="vl-input" style="width:auto">
      </div>
      <div class="col-md-auto">
        <div class="section-title">Date To</div>
        <input type="date" name="date_to" value="<?= h($dateTo) ?>" class="vl-input" style="width:auto">
      </div>

      <div class="col-md-auto d-flex gap-2">
        <button type="submit" class="btn-vl-primary">
          <i class="fas fa-filter"></i> Apply
        </button>
        <a href="history.php" class="btn-vl-secondary">
          <i class="fas fa-xmark"></i> Reset
        </a>
      </div>

      <!-- Hidden page reset on filter change -->
      <input type="hidden" name="page" value="1">
    </form>
  </div>

  <!-- ─── Results count + Clear All ──────────────────────────── -->
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <span style="color:var(--text-muted);font-size:.85rem">
      <?= $totalRows ?> record<?= $totalRows !== 1 ? 's' : '' ?> found
      <?= $totalPages > 1 ? "· Page $page of $totalPages" : '' ?>
    </span>
    <?php if ($totalRows > 0): ?>
    <button id="clearAllBtn" class="btn-vl-danger">
      <i class="fas fa-trash-can me-1"></i> Clear All History
    </button>
    <?php endif; ?>
  </div>

  <!-- ─── Table ───────────────────────────────────────────────── -->
  <div class="vl-card overflow-hidden mb-4">
    <?php if (empty($rows)): ?>
    <div class="p-5 text-center">
      <i class="fas fa-box-open fa-3x mb-3" style="color:var(--text-muted)"></i>
      <p style="color:var(--text-muted)">No scan records found. <?= $whereClause ? 'Try changing your filters.' : 'Start by analyzing an image on the Dashboard.' ?></p>
      <a href="index.php" class="btn-vl-primary mt-2">
        <i class="fas fa-upload me-1"></i> Analyze an Image
      </a>
    </div>
    <?php else: ?>
    <div class="table-responsive">
      <table class="vl-table">
        <thead>
          <tr>
            <th>Thumbnail</th>
            <th>Verdict</th>
            <th>Confidence</th>
            <th>Risk</th>
            <th>Date</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
          <?php
            $objects = json_decode($row['detected_objects'] ?? '[]', true) ?: [];
            $reasons = json_decode($row['reasons'] ?? '[]',          true) ?: [];
            $isUrl   = filter_var($row['image_path'], FILTER_VALIDATE_URL);
          ?>
          <tr class="history-row" data-verdict="<?= h($row['verdict']) ?>">
            <!-- Thumb -->
            <td>
              <?php if ($isUrl): ?>
              <img src="<?= h($row['image_path']) ?>" alt="" class="history-thumb"
                   onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
              <div class="thumb-placeholder" style="display:none"><i class="fas fa-link"></i></div>
              <?php else: ?>
              <div class="thumb-placeholder"><i class="fas fa-file-image"></i></div>
              <?php endif; ?>
            </td>

            <!-- Verdict -->
            <td>
              <span class="<?= verdictBadgeClass($row['verdict']) ?>">
                <?= $row['verdict'] === 'AI-Generated'
                    ? '<i class="fas fa-robot"></i>'
                    : '<i class="fas fa-leaf"></i>' ?>
                <?= h($row['verdict']) ?>
              </span>
            </td>

            <!-- Confidence -->
            <td>
              <div class="d-flex align-items-center gap-2" style="min-width:110px">
                <div class="confidence-track flex-grow-1" style="height:6px">
                  <div class="confidence-fill <?= $row['verdict'] === 'AI-Generated' ? 'ai' : 'real' ?>"
                       style="width:<?= $row['confidence'] ?>%"></div>
                </div>
                <span style="font-size:.8rem;font-weight:600;color:var(--text-primary)"><?= $row['confidence'] ?>%</span>
              </div>
            </td>

            <!-- Risk -->
            <td><span class="<?= riskBadgeClass($row['risk_level']) ?>"><?= h($row['risk_level']) ?></span></td>

            <!-- Date -->
            <td style="white-space:nowrap;font-size:.8rem"><?= formatDate($row['created_at']) ?></td>

            <!-- Actions -->
            <td>
              <div class="d-flex gap-2">
                <!-- View details -->
                <button class="btn-vl-secondary" style="font-size:.78rem;padding:.3rem .7rem"
                        data-bs-toggle="modal"
                        data-bs-target="#detailModal"
                        data-id="<?= $row['id'] ?>"
                        data-verdict="<?= h($row['verdict']) ?>"
                        data-confidence="<?= $row['confidence'] ?>"
                        data-risk="<?= h($row['risk_level']) ?>"
                        data-source="<?= h($row['source_type']) ?>"
                        data-path="<?= h($row['image_path']) ?>"
                        data-date="<?= h(formatDate($row['created_at'])) ?>"
                        data-objects="<?= h(json_encode($objects)) ?>"
                        data-reasons="<?= h(json_encode($reasons)) ?>">
                  <i class="fas fa-eye"></i> View
                </button>
                <!-- Delete -->
                <button class="btn-vl-danger" data-delete-id="<?= $row['id'] ?>">
                  <i class="fas fa-trash-can"></i>
                </button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- ─── Pagination ──────────────────────────────────────────── -->
  <?php if ($totalPages > 1): ?>
  <div class="d-flex justify-content-center">
    <nav class="vl-pagination">
      <!-- Prev -->
      <?php if ($page > 1): ?>
      <a href="<?= pageUrl($page - 1) ?>"><i class="fas fa-chevron-left"></i></a>
      <?php else: ?>
      <span class="disabled"><i class="fas fa-chevron-left"></i></span>
      <?php endif; ?>

      <?php
      $start = max(1, $page - 2);
      $end   = min($totalPages, $page + 2);
      if ($start > 1): ?><span>…</span><?php endif;
      for ($p = $start; $p <= $end; $p++):
        if ($p === $page): ?>
        <span class="current"><?= $p ?></span>
        <?php else: ?>
        <a href="<?= pageUrl($p) ?>"><?= $p ?></a>
        <?php endif;
      endfor;
      if ($end < $totalPages): ?><span>…</span><?php endif; ?>

      <!-- Next -->
      <?php if ($page < $totalPages): ?>
      <a href="<?= pageUrl($page + 1) ?>"><i class="fas fa-chevron-right"></i></a>
      <?php else: ?>
      <span class="disabled"><i class="fas fa-chevron-right"></i></span>
      <?php endif; ?>
    </nav>
  </div>
  <?php endif; ?>

</main>

<!-- ─── Detail Modal ──────────────────────────────────────────────── -->
<div class="modal fade" id="detailModal" tabindex="-1" aria-labelledby="detailModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title fw-semibold" id="detailModalLabel">
          <i class="fas fa-chart-bar me-2" style="color:var(--accent)"></i>Scan Detail
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="modalBody">
        <!-- Populated by JS -->
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-vl-secondary" data-bs-dismiss="modal">
          <i class="fas fa-xmark me-1"></i>Close
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ─── Footer ───────────────────────────────────────────────────── -->
<footer class="text-center py-3" style="border-top:1px solid var(--border);color:var(--text-muted);font-size:.78rem">
  VeriLens &mdash; Powered by Hugging Face &middot; <?= date('Y') ?>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/app.js"></script>
<script>
/* ── Populate detail modal ─────────────────────────────────── */
document.getElementById('detailModal').addEventListener('show.bs.modal', function (e) {
  const btn        = e.relatedTarget;
  const verdict    = btn.dataset.verdict;
  const confidence = parseInt(btn.dataset.confidence, 10);
  const risk       = btn.dataset.risk;
  const source     = btn.dataset.source;
  const path       = btn.dataset.path;
  const date       = btn.dataset.date;
  const objects    = JSON.parse(btn.dataset.objects || '[]');
  const reasons    = JSON.parse(btn.dataset.reasons || '[]');

  const isAI       = verdict === 'AI-Generated';
  const isUrl      = path.startsWith('http://') || path.startsWith('https://');

  const riskClass = risk === 'High' ? 'badge-risk-high' : risk === 'Medium' ? 'badge-risk-medium' : 'badge-risk-low';
  const verdClass = isAI ? 'badge-ai' : 'badge-real';
  const fillClass = isAI ? 'ai' : 'real';
  const fillColor = isAI ? 'linear-gradient(90deg,#f85149,#ff7b72)' : 'linear-gradient(90deg,#2ea043,#3fb950)';

  const thumbHtml = isUrl
    ? `<img src="${path}" alt="" style="max-height:200px;max-width:100%;border-radius:.45rem;border:1px solid var(--border);object-fit:cover"
             onerror="this.outerHTML='<div style=\\'height:80px;display:flex;align-items:center;justify-content:center;color:var(--text-muted)\\'><i class=\\'fas fa-link\\'></i></div>'">`
    : `<div style="height:60px;display:flex;align-items:center;justify-content:center;color:var(--text-muted)">
         <i class="fas fa-file-image fa-2x"></i>
       </div>`;

  const objsHtml = objects.length
    ? objects.map(o => `<span class="obj-tag">${escHtml(o)}</span>`).join(' ')
    : '<span style="color:var(--text-muted);font-size:.8rem">—</span>';

  const reasonsHtml = reasons.length
    ? reasons.map(r => `<div class="reason-item"><span class="reason-dot"></span><span>${escHtml(r)}</span></div>`).join('')
    : '<span style="color:var(--text-muted);font-size:.8rem">—</span>';

  document.getElementById('modalBody').innerHTML = `
    <div class="row g-3">
      <div class="col-sm-5 text-center">${thumbHtml}</div>
      <div class="col-sm-7">
        <div class="section-title">Verdict</div>
        <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
          <span class="${verdClass}" style="font-size:.9rem;padding:.4rem 1rem">
            ${isAI ? '<i class="fas fa-robot"></i>' : '<i class="fas fa-leaf"></i>'}
            ${escHtml(verdict)}
          </span>
          <span class="${riskClass}"><i class="fas fa-shield-halved me-1"></i>${escHtml(risk)} Risk</span>
        </div>
        <div class="section-title">Confidence</div>
        <div class="d-flex align-items-center gap-2 mb-3">
          <div class="confidence-track flex-grow-1">
            <div class="confidence-fill ${fillClass}" style="width:${confidence}%"></div>
          </div>
          <span class="fw-bold" style="color:var(--text-primary);min-width:40px">${confidence}%</span>
        </div>
        <div class="section-title">Source</div>
        <p style="font-size:.8rem;color:var(--text-secondary)">${escHtml(source)} — ${escHtml(path.length > 60 ? path.slice(0,57)+'…' : path)}</p>
        <div class="section-title">Analyzed</div>
        <p style="font-size:.8rem;color:var(--text-secondary)">${escHtml(date)}</p>
      </div>
    </div>
    <hr class="divider">
    <div class="section-title"><i class="fas fa-tag me-1"></i>Detected Objects</div>
    <div class="d-flex flex-wrap gap-2 mb-3">${objsHtml}</div>
    <div class="section-title"><i class="fas fa-list-check me-1"></i>Key Observations</div>
    ${reasonsHtml}
  `;
});

function escHtml(str) {
  return String(str)
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;');
}
</script>
</body>
</html>
