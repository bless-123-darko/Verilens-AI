<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/huggingface.php';
require_once __DIR__ . '/includes/helpers.php';

session_start();

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$sourceType = $_POST['source_type'] ?? '';

try {
    [$base64Data, $mimeType, $imagePath, $imgSrc] = match ($sourceType) {
        'upload' => handleUpload(),
        'url'    => handleUrl(),
        default  => throw new InvalidArgumentException('Invalid source type.'),
    };

    // Call Hugging Face
    $result = analyzeImageWithHuggingFace($base64Data, $mimeType);

    // Persist to DB
    saveScanRecord(
        $imagePath,
        $sourceType,
        $result['verdict'],
        $result['confidence'],
        $result['risk_level'],
        $result['detected_objects'],
        $result['reasons']
    );

    // Pass result to index.php via session
    $_SESSION['vl_result']   = $result;
    $_SESSION['vl_img_src']  = $imgSrc;
    $_SESSION['vl_src_type'] = $sourceType;

} catch (Throwable $e) {
    $_SESSION['vl_error'] = $e->getMessage();
}

header('Location: index.php');
exit;

/* ─────────────────────────────────────────────────────────── */

function handleUpload(): array {
    $file = $_FILES['image_file'] ?? null;
    if (!$file) {
        throw new InvalidArgumentException('No file received.');
    }

    // Check for video first (needs ffmpeg)
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $videoTypes = ['video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm'];

    if (in_array($mimeType, $videoTypes, true)) {
        $frame = maybeExtractVideoFrame($file);
        if ($frame === null) {
            throw new RuntimeException('Video frame extraction requires ffmpeg. Please upload an image instead.');
        }
        [$b64, $mime] = $frame;
        return [$b64, $mime, 'video_frame_' . time() . '.jpg', ''];
    }

    // Regular image
    validateUploadedFile($file);
    [$b64, $mime] = getImageBase64FromUpload($file);

    // Build a data-URI for preview (pass to session)
    $imgSrc = 'data:' . $mime . ';base64,' . $b64;

    // Don't keep the uploaded file — we only needed the binary
    @unlink($file['tmp_name']);

    return [$b64, $mime, 'upload_' . time() . '_' . basename((string)$file['name']), $imgSrc];
}

function handleUrl(): array {
    $url = trim($_POST['image_url'] ?? '');
    if (empty($url)) {
        throw new InvalidArgumentException('No URL provided.');
    }

    [$b64, $mime] = fetchImageFromUrl($url);
    $imgSrc = $url; // use original URL for preview

    return [$b64, $mime, $url, $imgSrc];
}
