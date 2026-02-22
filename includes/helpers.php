<?php

const ALLOWED_MIME_TYPES = [
    'image/jpeg' => 'jpeg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/gif'  => 'gif',
];

const MAX_UPLOAD_BYTES = 5 * 1024 * 1024; // 5 MB

function validateUploadedFile(array $file): void {
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $messages = [
            UPLOAD_ERR_INI_SIZE   => 'File exceeds server upload limit.',
            UPLOAD_ERR_FORM_SIZE  => 'File exceeds form upload limit.',
            UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
            UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder.',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
            UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
        ];
        throw new InvalidArgumentException($messages[$file['error']] ?? 'Unknown upload error.');
    }

    if ($file['size'] > MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException('File size exceeds the 5 MB limit.');
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    if (!isset(ALLOWED_MIME_TYPES[$mimeType])) {
        throw new InvalidArgumentException("Unsupported file type: $mimeType. Allowed: JPEG, PNG, WebP, GIF.");
    }
}

function getImageBase64FromUpload(array $file): array {
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);
    $data     = base64_encode(file_get_contents($file['tmp_name']));
    return [$data, $mimeType];
}

function fetchImageFromUrl(string $url): array {
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new InvalidArgumentException('Invalid URL provided.');
    }

    $allowedSchemes = ['http', 'https'];
    $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
    if (!in_array($scheme, $allowedSchemes, true)) {
        throw new InvalidArgumentException('Only HTTP/HTTPS URLs are allowed.');
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'VeriLens/1.0',
    ]);

    $body     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        throw new RuntimeException('Failed to fetch URL: ' . $curlErr);
    }
    if ($httpCode !== 200) {
        throw new RuntimeException("URL returned HTTP $httpCode.");
    }
    if (strlen($body) > MAX_UPLOAD_BYTES) {
        throw new InvalidArgumentException('Remote image exceeds the 5 MB limit.');
    }

    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->buffer($body);

    if (!isset(ALLOWED_MIME_TYPES[$mimeType])) {
        throw new InvalidArgumentException("URL does not point to a supported image type ($mimeType).");
    }

    return [base64_encode($body), $mimeType];
}

function maybeExtractVideoFrame(array $file): ?array {
    $finfo    = new finfo(FILEINFO_MIME_TYPE);
    $mimeType = $finfo->file($file['tmp_name']);

    $videoTypes = ['video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm'];
    if (!in_array($mimeType, $videoTypes, true)) {
        return null;
    }

    if (!commandExists('ffmpeg')) {
        throw new RuntimeException('Video frame extraction requires ffmpeg. Please install ffmpeg or upload an image instead.');
    }

    $tmpFrame = tempnam(sys_get_temp_dir(), 'verilens_frame_') . '.jpg';
    $input    = escapeshellarg($file['tmp_name']);
    $output   = escapeshellarg($tmpFrame);

    exec("ffmpeg -i $input -vframes 1 -q:v 2 $output 2>/dev/null", $out, $code);

    if ($code !== 0 || !file_exists($tmpFrame)) {
        throw new RuntimeException('ffmpeg failed to extract a frame from the video.');
    }

    $data = base64_encode(file_get_contents($tmpFrame));
    @unlink($tmpFrame);

    return [$data, 'image/jpeg'];
}

function commandExists(string $cmd): bool {
    $which = PHP_OS_FAMILY === 'Windows' ? 'where' : 'which';
    exec("$which $cmd 2>&1", $out, $code);
    return $code === 0;
}

function saveScanRecord(
    string $imagePath,
    string $sourceType,
    string $verdict,
    int $confidence,
    string $riskLevel,
    array $detectedObjects,
    array $reasons
): int {
    $pdo  = \getDB();
    $stmt = $pdo->prepare("
        INSERT INTO scan_history
            (image_path, source_type, verdict, confidence, risk_level, detected_objects, reasons)
        VALUES
            (:image_path, :source_type, :verdict, :confidence, :risk_level, :detected_objects, :reasons)
    ");
    $stmt->execute([
        ':image_path'       => $imagePath,
        ':source_type'      => $sourceType,
        ':verdict'          => $verdict,
        ':confidence'       => $confidence,
        ':risk_level'       => $riskLevel,
        ':detected_objects' => json_encode($detectedObjects),
        ':reasons'          => json_encode($reasons),
    ]);
    return (int) $pdo->lastInsertId();
}

function verdictBadgeClass(string $verdict): string {
    return $verdict === 'AI-Generated' ? 'badge-ai' : 'badge-real';
}

function riskBadgeClass(string $risk): string {
    return match ($risk) {
        'High'   => 'badge-risk-high',
        'Medium' => 'badge-risk-medium',
        default  => 'badge-risk-low',
    };
}

function formatDate(string $datetime): string {
    try {
        $dt = new DateTime($datetime);
        return $dt->format('M j, Y · g:i A');
    } catch (Exception) {
        return $datetime;
    }
}

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
