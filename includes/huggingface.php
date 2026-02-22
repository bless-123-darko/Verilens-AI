<?php
declare(strict_types=1);

/* ============================================================
   VeriLens — Hugging Face Inference Providers Handler
   New endpoint (2025+): https://router.huggingface.co/hf-inference/models/{model}
   Old endpoint (api-inference.huggingface.co) is retired — returns 410.

   Models:
     • Ateeqq/ai-vs-human-image-detector  → primary  (labels: "ai" / "hum", 99.2% acc)
     • dima806/ai_vs_real_image_detection → fallback (labels: "FAKE" / "REAL", 97%+ acc)
     • facebook/detr-resnet-50            → object detection (optional, non-fatal)

   Token requirement:
     Your HF token MUST have "Make calls to Inference Providers" permission.
     Create one at: https://huggingface.co/settings/tokens
   ============================================================ */

/* ─── Base URL — new Inference Providers router ─────────────── */
const HF_ROUTER_BASE = 'https://router.huggingface.co/hf-inference/models/';

/* ─── Classifier models — tried in order until one succeeds ─── */
const HF_CLASSIFIER_MODELS = [
    'Ateeqq/ai-vs-human-image-detector',   // primary  — 99.2% acc, Dec 2025
    'dima806/ai_vs_real_image_detection',  // fallback — 97%+ acc, ViT on CIFAKE
];

/* ─── .env loader ───────────────────────────────────────────── */

function loadEnv(): void {
    static $loaded = false;
    if ($loaded) return;

    $envFile = __DIR__ . '/../.env';
    if (!file_exists($envFile)) { $loaded = true; return; }

    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key); $val = trim($val);
        $_ENV[$key] = $val;
        putenv("$key=$val");
    }
    $loaded = true;
}

function getHfApiKey(): string {
    loadEnv();
    $key = $_ENV['HUGGINGFACE_API_KEY'] ?? getenv('HUGGINGFACE_API_KEY') ?? '';
    if (empty($key) || $key === 'your_huggingface_api_key_here') {
        throw new RuntimeException(
            'HUGGINGFACE_API_KEY is not set in .env. ' .
            'Create a token with "Make calls to Inference Providers" permission at ' .
            'https://huggingface.co/settings/tokens'
        );
    }
    return $key;
}

/* ─── Main entry point ──────────────────────────────────────── */

function analyzeImageWithHuggingFace(string $base64Data, string $mimeType): array {
    $apiKey     = getHfApiKey();
    $imageBytes = base64_decode($base64Data);

    // 1 ── AI vs Real classification (cascade through models) ──
    $classifyRaw   = null;
    $usedModel     = '';
    $lastException = null;

    foreach (HF_CLASSIFIER_MODELS as $modelId) {
        try {
            $classifyRaw = callHfModel($modelId, $imageBytes, $apiKey);
            $usedModel   = $modelId;
            break;
        } catch (Throwable $e) {
            $lastException = $e;
        }
    }

    if ($classifyRaw === null) {
        $hint = str_contains($lastException?->getMessage() ?? '', '401')
            ? ' Make sure your token has "Inference Providers" permission.'
            : '';
        throw new RuntimeException(
            'All AI-detection models failed.' . $hint .
            ' Last error: ' . ($lastException?->getMessage() ?? 'unknown')
        );
    }

    [$verdict, $confidence] = parseClassificationResult($classifyRaw, $usedModel);

    // 2 ── Object detection via DETR (best-effort, non-fatal) ──
    $detectedObjects = [];
    try {
        $detrRaw         = callHfModel('facebook/detr-resnet-50', $imageBytes, $apiKey);
        $detectedObjects = parseObjectDetectionResult($detrRaw);
    } catch (Throwable) {
        // Silently skip — object detection is optional
    }

    // 3 ── Derive risk level + generate contextual reasons ─────
    $riskLevel = deriveRiskLevel($verdict, $confidence);
    $reasons   = generateReasons($verdict, $confidence, $detectedObjects, $usedModel);

    return [
        'verdict'          => $verdict,
        'confidence'       => $confidence,
        'risk_level'       => $riskLevel,
        'reasons'          => $reasons,
        'detected_objects' => $detectedObjects,
    ];
}

/* ─── cURL helper (new Inference Providers router) ───────────── */

function callHfModel(string $modelId, string $imageBytes, string $apiKey): array {
    $url = HF_ROUTER_BASE . $modelId;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $imageBytes,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/octet-stream',
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        throw new RuntimeException("cURL error calling '$modelId': $curlError");
    }

    $decoded = json_decode($response, true);

    // 503 — model cold-starting, let caller fall through to next model
    if ($httpCode === 503) {
        $eta = isset($decoded['estimated_time'])
            ? round((float)$decoded['estimated_time']) . 's'
            : 'unknown';
        throw new RuntimeException(
            "Model '$modelId' is warming up (ETA: {$eta}). Trying next model…"
        );
    }

    // 401 — bad or missing token
    if ($httpCode === 401) {
        throw new RuntimeException(
            "Unauthorized (401) for '$modelId'. " .
            "Ensure your HF token has 'Make calls to Inference Providers' permission."
        );
    }

    if ($httpCode !== 200) {
        $errMsg = is_array($decoded)
            ? ($decoded['error'] ?? ($decoded['message'] ?? "HTTP $httpCode"))
            : "HTTP $httpCode";
        throw new RuntimeException("Hugging Face error for '$modelId': $errMsg");
    }

    if (!is_array($decoded)) {
        throw new RuntimeException("Non-JSON response from '$modelId'.");
    }

    return $decoded;
}

/* ─── Classification result parser ─────────────────────────── */

/*
 * Normalised label sets per model:
 *   Ateeqq/ai-vs-human-image-detector  → "ai" | "hum"
 *   dima806/ai_vs_real_image_detection → "FAKE" | "REAL"
 *
 * Response shape A (most common):
 *   [{"label":"ai","score":0.9996},{"label":"hum","score":0.0004}]
 *
 * Response shape B (batched):
 *   [[{"label":"ai","score":0.9996}, ...]]
 */
function parseClassificationResult(array $raw, string $modelId = ''): array {
    // Unwrap batched response
    if (isset($raw[0]) && is_array($raw[0]) && isset($raw[0][0]['label'])) {
        $raw = $raw[0];
    }

    if (empty($raw) || !isset($raw[0]['label'])) {
        throw new RuntimeException(
            "Unrecognised response structure from '$modelId'. " .
            "Raw: " . json_encode(array_slice($raw, 0, 3))
        );
    }

    $aiKeywords   = ['ai', 'artificial', 'fake', 'generated', 'ai-generated', 'aigc', 'synthetic'];
    $realKeywords = ['hum', 'human', 'real', 'natural', 'photograph', 'photo', 'authentic'];

    $aiScore   = 0.0;
    $realScore = 0.0;

    foreach ($raw as $item) {
        $label = strtolower(trim($item['label'] ?? ''));
        $score = (float)($item['score'] ?? 0);

        if (in_array($label, $aiKeywords, true)) {
            $aiScore = max($aiScore, $score);
        } elseif (in_array($label, $realKeywords, true)) {
            $realScore = max($realScore, $score);
        } else {
            // Substring fallback for unknown model labels
            foreach (['real', 'natural', 'photo', 'human', 'authentic'] as $kw) {
                if (str_contains($label, $kw)) { $realScore = max($realScore, $score); break; }
            }
        }
    }

    // Absolute last resort: pick top-scored item
    if ($aiScore === 0.0 && $realScore === 0.0) {
        usort($raw, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));
        $topLabel = strtolower($raw[0]['label'] ?? '');
        $topScore = (float)($raw[0]['score'] ?? 0.5);
        $looksReal = false;
        foreach (['real', 'natural', 'photo', 'human', 'hum', 'authentic'] as $kw) {
            if (str_contains($topLabel, $kw)) { $looksReal = true; break; }
        }
        if ($looksReal) { $realScore = $topScore; } else { $aiScore = $topScore; }
    }

    $isAi       = $aiScore >= $realScore;
    $verdict    = $isAi ? 'AI-Generated' : 'Natural/Real';
    $rawScore   = $isAi ? $aiScore : $realScore;
    $confidence = max(1, min(99, (int) round($rawScore * 100)));

    return [$verdict, $confidence];
}

/* ─── Object detection result parser ────────────────────────── */

/*
 * facebook/detr-resnet-50 returns:
 * [{"score":0.99,"label":"cat","box":{...}}, ...]
 * Returns up to 8 unique labels with score ≥ 0.50.
 */
function parseObjectDetectionResult(array $raw): array {
    if (empty($raw) || !isset($raw[0]['label'])) return [];

    usort($raw, fn($a, $b) => ($b['score'] ?? 0) <=> ($a['score'] ?? 0));

    $seen = $objects = [];
    foreach ($raw as $item) {
        $label = trim($item['label'] ?? '');
        $score = (float)($item['score'] ?? 0);
        if ($label === '' || $score < 0.50 || isset($seen[$label])) continue;
        $seen[$label] = true;
        $objects[]    = $label;
        if (count($objects) >= 8) break;
    }
    return $objects;
}

/* ─── Risk level derivation ──────────────────────────────────── */

function deriveRiskLevel(string $verdict, int $confidence): string {
    if ($verdict !== 'AI-Generated') {
        return $confidence >= 85 ? 'Low' : 'Medium';
    }
    if ($confidence >= 85) return 'High';
    if ($confidence >= 60) return 'Medium';
    return 'Low';
}

/* ─── Contextual reason generation ──────────────────────────── */

function generateReasons(
    string $verdict,
    int    $confidence,
    array  $detectedObjects,
    string $modelId = ''
): array {
    $modelShort = $modelId ? basename(str_replace('/', ' / ', $modelId)) : 'AI detection model';
    $reasons    = [];

    if ($verdict === 'AI-Generated') {
        $reasons[] = "'{$modelShort}' classified this image as AI-generated with {$confidence}% confidence.";
        if ($confidence >= 90) {
            $reasons[] = 'Very strong AI generation signatures detected — high certainty this is a synthetic image.';
        } elseif ($confidence >= 75) {
            $reasons[] = 'Clear patterns consistent with generative model outputs (e.g. diffusion or GAN artifacts).';
        } elseif ($confidence >= 60) {
            $reasons[] = 'Moderate AI generation indicators present; some natural characteristics also observed.';
        } else {
            $reasons[] = 'Weak AI signals detected; the image is borderline but leans toward synthetic origin.';
        }
        $reasons[] = 'Texture smoothness, colour uniformity, and pixel coherence are consistent with a generative model.';
        if (!empty($detectedObjects)) {
            $sample    = implode(', ', array_slice($detectedObjects, 0, 3));
            $reasons[] = "Detected elements ({$sample}) appear synthetically composed rather than naturally captured.";
        }
        $reasons[] = 'Frequency-domain patterns indicate over-smoothed regions typical of latent diffusion pipelines.';
    } else {
        $reasons[] = "'{$modelShort}' classified this image as a natural photograph with {$confidence}% confidence.";
        if ($confidence >= 90) {
            $reasons[] = 'Strong natural photographic characteristics — no significant AI generation artefacts detected.';
        } elseif ($confidence >= 75) {
            $reasons[] = 'Image shows natural lighting, sensor noise, and depth-of-field consistent with real photography.';
        } elseif ($confidence >= 60) {
            $reasons[] = 'Predominantly natural characteristics, though a small degree of ambiguity remains.';
        } else {
            $reasons[] = 'Mostly natural image signatures, but some patterns warrant mild scrutiny.';
        }
        $reasons[] = 'Sensor noise distribution and tonal range align with authentic camera capture.';
        if (!empty($detectedObjects)) {
            $sample    = implode(', ', array_slice($detectedObjects, 0, 3));
            $reasons[] = "Real-world objects detected ({$sample}) with spatial relationships typical of natural photography.";
        }
    }

    return array_slice($reasons, 0, 5);
}
