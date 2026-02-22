<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/helpers.php';

$totalScans = getTotalScans();

// Pull flash result from session (set by analyze.php)
session_start();
$result     = $_SESSION['vl_result']   ?? null;
$error      = $_SESSION['vl_error']    ?? null;
$resultImg  = $_SESSION['vl_img_src']  ?? null;
$sourceType = $_SESSION['vl_src_type'] ?? null;
unset($_SESSION['vl_result'], $_SESSION['vl_error'], $_SESSION['vl_img_src'], $_SESSION['vl_src_type']);
?>
<!DOCTYPE html>
<html lang="en" class="h-100">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>VeriLens — AI Media Detector</title>

  <!-- Bootstrap 5 -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
  <!-- Tailwind CSS -->
  <script src="https://cdn.tailwindcss.com"></script>
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <!-- Google Fonts: Inter -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <!-- Custom CSS -->
  <link rel="stylesheet" href="assets/css/custom.css">

  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: { extend: {} },
      corePlugins: { preflight: false }
    };
  </script>
</head>
<body class="dark">

<!-- ─── Loading Overlay ─────────────────────────────────────────── -->
<div id="loadingOverlay">
  <div class="vl-spinner"></div>
  <p class="loading-text"><i class="fas fa-brain me-2"></i>Hugging Face models are analyzing your media…</p>
</div>

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
        <a class="nav-link active" href="index.php">
          <i class="fas fa-home me-1"></i><span class="d-none d-sm-inline">Dashboard</span>
        </a>
      </li>
      <li class="nav-item">
        <a class="nav-link" href="history.php">
          <i class="fas fa-history me-1"></i><span class="d-none d-sm-inline">History</span>
        </a>
      </li>
    </ul>
  </div>
</nav>

<!-- ─── Hero ─────────────────────────────────────────────────────── -->
<div class="vl-hero">
  <div class="container-xl px-3 px-md-4">
    <h1><i class="fas fa-shield-halved me-2" style="color:#58a6ff"></i>AI Media Detector</h1>
        <p class="mb-0">Upload an image or paste a URL — Hugging Face models will determine if it's AI-generated or real.</p>
  </div>
</div>

<!-- ─── Main Content ──────────────────────────────────────────────── -->
<main class="container-xl px-3 px-md-4 py-4">
  <div class="row g-4">

    <!-- Left column: upload panel -->
    <div class="col-lg-5 col-xl-4">
      <div class="vl-card p-4">
        <h2 class="fw-semibold mb-3" style="font-size:1rem;color:var(--text-primary)">
          <i class="fas fa-upload me-2" style="color:var(--accent)"></i>Upload Media
        </h2>

        <?php if ($error): ?>
        <div class="vl-alert vl-alert-error mb-3">
          <i class="fas fa-exclamation-circle"></i>
          <?= h($error) ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <ul class="nav vl-tabs gap-2 mb-3" id="uploadTabs" role="tablist">
          <li class="nav-item" role="presentation">
            <button class="nav-link active" id="file-tab" data-bs-toggle="tab"
                    data-bs-target="#filePane" type="button" role="tab">
              <i class="fas fa-file-image me-1"></i>Upload File
            </button>
          </li>
          <li class="nav-item" role="presentation">
            <button class="nav-link" id="url-tab" data-bs-toggle="tab"
                    data-bs-target="#urlPane" type="button" role="tab">
              <i class="fas fa-link me-1"></i>Paste URL
            </button>
          </li>
        </ul>

        <div class="tab-content">

          <!-- TAB 1: File upload -->
          <div class="tab-pane fade show active" id="filePane" role="tabpanel">
            <form id="uploadForm" method="POST" action="analyze.php" enctype="multipart/form-data">
              <input type="hidden" name="source_type" value="upload">

              <!-- Drop zone -->
              <div id="dropZone">
                <i class="fas fa-cloud-upload-alt drop-icon"></i>
                <p class="mb-1 fw-medium" style="color:var(--text-primary)">Drag & drop your image here</p>
                <p class="mb-2" style="font-size:.8rem;color:var(--text-muted)">JPEG · PNG · WebP · GIF · up to 5 MB</p>
                <span class="btn-vl-secondary" style="font-size:.8rem;pointer-events:none">
                  <i class="fas fa-folder-open"></i> Browse Files
                </span>
              </div>
              <input type="file" id="fileInput" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif,video/*" class="d-none">

              <!-- Preview -->
              <div id="filePreviewWrap" class="mt-3 text-center d-none">
                <div class="img-preview-wrap mx-auto">
                  <img id="filePreviewImg" src="" alt="Preview">
                  <button type="button" id="fileRemoveBtn" class="remove-btn" title="Remove">
                    <i class="fas fa-times"></i>
                  </button>
                </div>
              </div>

              <button type="submit" id="analyzeBtn" class="btn-vl-primary w-100 mt-3 justify-content-center" disabled>
                <i class="fas fa-magnifying-glass"></i> Analyze Image
              </button>
            </form>
          </div>

          <!-- TAB 2: URL -->
          <div class="tab-pane fade" id="urlPane" role="tabpanel">
            <form id="urlForm" method="POST" action="analyze.php">
              <input type="hidden" name="source_type" value="url">

              <div class="d-flex gap-2 mb-3">
                <input type="url" id="urlInput" name="image_url" class="vl-input"
                       placeholder="https://example.com/photo.jpg" required>
                <button type="button" id="urlPreviewBtn" class="btn-vl-secondary flex-shrink-0">
                  <i class="fas fa-eye"></i>
                </button>
              </div>

              <!-- URL preview -->
              <div id="urlPreviewWrap" class="mb-3 text-center d-none">
                <div class="img-preview-wrap mx-auto">
                  <img id="urlPreviewImg" src="" alt="URL Preview" style="max-height:180px">
                </div>
              </div>

              <button type="submit" id="analyzeUrlBtn" class="btn-vl-primary w-100 justify-content-center" disabled>
                <i class="fas fa-magnifying-glass"></i> Analyze Image
              </button>
            </form>
          </div>

        </div><!-- /tab-content -->
      </div><!-- /vl-card -->

      <!-- Stat cards -->
      <div class="mt-3 d-flex flex-column gap-2">
        <?php
        $pdo      = getDB();
        $aiCount  = (int)$pdo->query("SELECT COUNT(*) FROM scan_history WHERE verdict='AI-Generated'")->fetchColumn();
        $realCount= (int)$pdo->query("SELECT COUNT(*) FROM scan_history WHERE verdict='Natural/Real'")->fetchColumn();
        ?>
        <div class="stat-card">
          <div class="stat-icon" style="background:var(--ai-bg)">
            <i class="fas fa-robot" style="color:var(--ai-color)"></i>
          </div>
          <div>
            <div class="stat-value" style="color:var(--ai-color)"><?= $aiCount ?></div>
            <div class="stat-label">AI-Generated</div>
          </div>
        </div>
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

    </div><!-- /left col -->

    <!-- Right column: results -->
    <div class="col-lg-7 col-xl-8">

      <?php if ($result): ?>
      <!-- ─── Results Panel ──────────────────────────────────── -->
      <div id="resultsPanel" class="vl-card p-4">
        <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
          <h2 class="fw-semibold mb-0" style="font-size:1rem;color:var(--text-primary)">
            <i class="fas fa-chart-bar me-2" style="color:var(--accent)"></i>Analysis Result
          </h2>
          <span style="font-size:.78rem;color:var(--text-muted)">
            <i class="fas fa-clock me-1"></i><?= formatDate(date('Y-m-d H:i:s')) ?>
          </span>
        </div>

        <div class="row g-3">
          <!-- Thumbnail -->
          <div class="col-sm-4">
            <?php if ($resultImg): ?>
            <img src="<?= h($resultImg) ?>" alt="Analyzed image" class="result-thumb">
            <?php else: ?>
            <div class="result-thumb d-flex align-items-center justify-content-center"
                 style="background:var(--bg-input);color:var(--text-muted)">
              <i class="fas fa-image fa-2x"></i>
            </div>
            <?php endif; ?>
          </div>

          <!-- Verdict & Confidence -->
          <div class="col-sm-8">
            <div class="section-title">Verdict</div>
            <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
              <span class="<?= verdictBadgeClass($result['verdict']) ?>" style="font-size:.9rem;padding:.4rem 1rem">
                <?php if ($result['verdict'] === 'AI-Generated'): ?>
                  <i class="fas fa-robot"></i>
                <?php else: ?>
                  <i class="fas fa-leaf"></i>
                <?php endif; ?>
                <?= h($result['verdict']) ?>
              </span>
              <span class="<?= riskBadgeClass($result['risk_level']) ?>">
                <i class="fas fa-shield-halved me-1"></i><?= h($result['risk_level']) ?> Risk
              </span>
            </div>

            <div class="section-title">Confidence</div>
            <div class="d-flex align-items-center gap-2 mb-1">
              <div class="confidence-track flex-grow-1">
                <div class="confidence-fill <?= $result['verdict'] === 'AI-Generated' ? 'ai' : 'real' ?>"
                     data-target="<?= $result['confidence'] ?>"></div>
              </div>
              <span class="fw-bold" style="font-size:1rem;color:var(--text-primary);min-width:42px;text-align:right">
                <?= $result['confidence'] ?>%
              </span>
            </div>
          </div>
        </div>

        <hr class="divider">

        <!-- Detected Objects -->
        <?php if (!empty($result['detected_objects'])): ?>
        <div class="mb-3">
          <div class="section-title"><i class="fas fa-tag me-1"></i>Detected Objects</div>
          <div class="d-flex flex-wrap gap-2">
            <?php foreach ($result['detected_objects'] as $obj): ?>
            <span class="obj-tag"><?= h($obj) ?></span>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>

        <!-- Reasons -->
        <?php if (!empty($result['reasons'])): ?>
        <div class="mb-3">
          <div class="section-title"><i class="fas fa-list-check me-1"></i>Key Observations</div>
          <?php foreach ($result['reasons'] as $reason): ?>
          <div class="reason-item">
            <span class="reason-dot"></span>
            <span><?= h($reason) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <hr class="divider">
        <button id="analyzeAnotherBtn" class="btn-vl-secondary">
          <i class="fas fa-rotate-left"></i> Analyze Another
        </button>
      </div>

      <?php else: ?>
      <!-- ─── Empty State ─────────────────────────────────────── -->
      <div id="resultsPanel" class="vl-card p-5 text-center" style="border:2px dashed var(--border)">
        <i class="fas fa-magnifying-glass-chart fa-3x mb-3" style="color:var(--text-muted)"></i>
        <h3 class="fw-semibold mb-1" style="font-size:1.1rem;color:var(--text-primary)">Ready to Analyze</h3>
        <p style="color:var(--text-muted);font-size:.875rem;max-width:360px;margin:0 auto">
          Upload an image or enter a URL on the left to get an AI-generated vs real verdict from Claude.
        </p>

        <div class="mt-4 d-flex flex-wrap justify-content-center gap-3">
          <div class="d-flex flex-column align-items-center gap-1" style="color:var(--text-muted);font-size:.78rem">
            <i class="fas fa-robot fa-lg mb-1" style="color:var(--ai-color)"></i>AI-Generated Detection
          </div>
          <div class="d-flex flex-column align-items-center gap-1" style="color:var(--text-muted);font-size:.78rem">
            <i class="fas fa-percent fa-lg mb-1" style="color:var(--warn-color)"></i>Confidence Score
          </div>
          <div class="d-flex flex-column align-items-center gap-1" style="color:var(--text-muted);font-size:.78rem">
            <i class="fas fa-list fa-lg mb-1" style="color:var(--real-color)"></i>Detailed Reasons
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /right col -->
  </div><!-- /row -->
</main>

<!-- ─── Footer ───────────────────────────────────────────────────── -->
<footer class="text-center py-3" style="border-top:1px solid var(--border);color:var(--text-muted);font-size:.78rem">
  VeriLens &mdash; Powered by Hugging Face &middot; <?= date('Y') ?>
</footer>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- App JS -->
<script src="assets/js/app.js"></script>
</body>
</html>
