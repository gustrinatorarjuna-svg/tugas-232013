<?php
// ─────────────────────────────────────────────────────────────
//  API Backend – tugas-232013
//  Hosting: Railway + Cloudflare R2 Storage
// ─────────────────────────────────────────────────────────────
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '50M');
ini_set('max_execution_time', '300');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── Konfigurasi Database ─────────────────────────────────────
$db_host = getenv('MYSQLHOST')     ?: 'mysql.railway.internal';
$db_name = getenv('MYSQLDATABASE') ?: 'railway';
$db_port = (int)(getenv('MYSQLPORT') ?: 3306);
$db_user = getenv('MYSQLUSER')     ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: 'hITOWEbxOZmYvNiRUxneBBWfMXfOUkvp';

// ── Konfigurasi Cloudflare R2 ────────────────────────────────
$r2AccessKey  = getenv('R2_ACCESS_KEY_ID')     ?: '';
$r2SecretKey  = getenv('R2_SECRET_ACCESS_KEY') ?: '';
$r2BucketName = getenv('R2_BUCKET_NAME')       ?: '';
$r2AccountId  = getenv('R2_ACCOUNT_ID')        ?: '';
$r2PublicUrl  = rtrim(getenv('R2_PUBLIC_URL')  ?: '', '/');
$r2Endpoint   = "https://{$r2AccountId}.r2.cloudflarestorage.com";

// ── Koneksi Database ─────────────────────────────────────────
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Koneksi database gagal: ' . $conn->connect_error]);
    exit();
}

// ═════════════════════════════════════════════════════════════
//  HELPER: Upload file ke Cloudflare R2 (VERSI DEBUGGING)
// ═════════════════════════════════════════════════════════════
function uploadToR2(string $localPath, string $r2Key, string $contentType): bool {
    global $r2AccessKey, $r2SecretKey, $r2BucketName, $r2AccountId, $r2Endpoint;

    $fileContent = file_get_contents($localPath);
    if ($fileContent === false) {
        // Error 1: PHP Railway gagal membaca file yang diupload Flutter
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'DEBUG ERROR: Gagal membaca file temporary di server Railway.']);
        exit();
    }

    $region      = 'auto';
    $service     = 's3';
    $host        = "{$r2AccountId}.r2.cloudflarestorage.com";
    $amzDate     = gmdate('Ymd\THis\Z');
    $dateStamp   = gmdate('Ymd');
    $payloadHash = hash('sha256', $fileContent);

    $canonicalHeaders = "content-type:{$contentType}\nhost:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
    $signedHeaders    = 'content-type;host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = implode("\n", ['PUT', "/{$r2BucketName}/{$r2Key}", '', $canonicalHeaders, $signedHeaders, $payloadHash]);

    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign    = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $credentialScope, hash('sha256', $canonicalRequest)]);

    $signingKey = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $region, hash_hmac('sha256', $dateStamp, 'AWS4' . $r2SecretKey, true), true), true), true);
    $signature = hash_hmac('sha256', $stringToSign, $signingKey);
    $authHeader = "AWS4-HMAC-SHA256 Credential={$r2AccessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $url = "{$r2Endpoint}/{$r2BucketName}/{$r2Key}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'PUT',
        CURLOPT_POSTFIELDS     => $fileContent,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$authHeader}",
            "Content-Type: {$contentType}",
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$amzDate}",
            "Content-Length: " . strlen($fileContent),
        ],
    ]);

    $response   = curl_exec($ch);
    $httpCode   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError  = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        // Error 2: R2 menolak upload. Kita paksa print response aslinya!
        http_response_code(500);
        echo json_encode([
            'success' => false, 
            'message' => "DEBUG R2 (HTTP $httpCode): " . ($response ?: $curlError)
        ]);
        exit();
    }

    return true;
}

// ═════════════════════════════════════════════════════════════
//  HELPER: Hapus file dari Cloudflare R2
// ═════════════════════════════════════════════════════════════
function deleteFromR2(string $r2Key): void {
    global $r2AccessKey, $r2SecretKey, $r2BucketName, $r2AccountId, $r2Endpoint;

    $region      = 'auto';
    $service     = 's3';
    $host        = "{$r2AccountId}.r2.cloudflarestorage.com";
    $amzDate     = gmdate('Ymd\THis\Z');
    $dateStamp   = gmdate('Ymd');
    $payloadHash = hash('sha256', '');

    $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
    $signedHeaders    = 'host;x-amz-content-sha256;x-amz-date';
    $canonicalRequest = implode("\n", [
        'DELETE',
        "/{$r2BucketName}/{$r2Key}",
        '',
        $canonicalHeaders,
        $signedHeaders,
        $payloadHash,
    ]);

    $credentialScope = "{$dateStamp}/{$region}/{$service}/aws4_request";
    $stringToSign    = implode("\n", [
        'AWS4-HMAC-SHA256',
        $amzDate,
        $credentialScope,
        hash('sha256', $canonicalRequest),
    ]);

    $signingKey = hash_hmac('sha256', 'aws4_request',
        hash_hmac('sha256', $service,
            hash_hmac('sha256', $region,
                hash_hmac('sha256', $dateStamp, 'AWS4' . $r2SecretKey, true),
            true),
        true),
    true);

    $signature  = hash_hmac('sha256', $stringToSign, $signingKey);
    $authHeader = "AWS4-HMAC-SHA256 Credential={$r2AccessKey}/{$credentialScope}, SignedHeaders={$signedHeaders}, Signature={$signature}";

    $url = "{$r2Endpoint}/{$r2BucketName}/{$r2Key}";

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            "Authorization: {$authHeader}",
            "x-amz-content-sha256: {$payloadHash}",
            "x-amz-date: {$amzDate}",
        ],
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// ── Routing berdasarkan ?action= ─────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {

    // ── LIST ─────────────────────────────────────────────────
    case 'list':
        $result = $conn->query("SELECT * FROM youtube_232013 ORDER BY id DESC");

        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $conn->error]);
            break;
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            $data[] = $row;
        }
        echo json_encode($data);
        break;

    // ── ADD ──────────────────────────────────────────────────
    case 'add':
        $title = trim($_POST['title'] ?? '');

        if ($title === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Title wajib diisi.']);
            break;
        }

        // Validasi thumbnail
        if (!isset($_FILES['thumbnail']) || $_FILES['thumbnail']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Upload thumbnail gagal.']);
            break;
        }

        $thumbFile    = $_FILES['thumbnail'];
        $thumbExt     = strtolower(pathinfo($thumbFile['name'], PATHINFO_EXTENSION));
        $thumbAllowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (!in_array($thumbExt, $thumbAllowed)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Format thumbnail tidak didukung.']);
            break;
        }

        // Validasi video
        if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Upload video gagal.']);
            break;
        }

        $videoFile    = $_FILES['video'];
        $videoExt     = strtolower(pathinfo($videoFile['name'], PATHINFO_EXTENSION));
        $videoAllowed = ['mp4', 'webm', 'avi', 'mov', 'mkv', 'flv', 'wmv'];

        if (!in_array($videoExt, $videoAllowed)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Format video tidak didukung.']);
            break;
        }

        // Upload thumbnail ke R2
        $thumbKey  = 'thumbnail/' . uniqid('thumb_', true) . '.' . $thumbExt;
        $thumbMime = $thumbFile['type'] ?: 'image/jpeg';

        if (!uploadToR2($thumbFile['tmp_name'], $thumbKey, $thumbMime)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal upload thumbnail ke R2.']);
            break;
        }

        // Upload video ke R2
        $videoKey  = 'video/' . uniqid('video_', true) . '.' . $videoExt;
        $videoMime = $videoFile['type'] ?: 'video/mp4';

        if (!uploadToR2($videoFile['tmp_name'], $videoKey, $videoMime)) {
            deleteFromR2($thumbKey);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal upload video ke R2.']);
            break;
        }

        $thumbUrl = $r2PublicUrl . '/' . $thumbKey;
        $videoUrl = $r2PublicUrl . '/' . $videoKey;

        $stmt = $conn->prepare(
            "INSERT INTO youtube_232013 (title, thumbnail, video) VALUES (?, ?, ?)"
        );
        $stmt->bind_param('sss', $title, $thumbUrl, $videoUrl);

        if ($stmt->execute()) {
            echo json_encode([
                'success'   => true,
                'message'   => 'Data berhasil disimpan.',
                'id'        => $conn->insert_id,
                'thumbnail' => $thumbUrl,
                'video'     => $videoUrl,
            ]);
        } else {
            deleteFromR2($thumbKey);
            deleteFromR2($videoKey);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Query gagal: ' . $stmt->error]);
        }

        $stmt->close();
        break;

    // ── UPDATE ───────────────────────────────────────────────
    case 'update':
        $id = intval($_GET['id'] ?? 0);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
            break;
        }

        $stmt = $conn->prepare("SELECT * FROM youtube_232013 WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $oldData = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$oldData) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan.']);
            break;
        }

        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Title wajib diisi.']);
            break;
        }

        $thumbUrl = $oldData['thumbnail'];
        $videoUrl = $oldData['video'];

        // Update thumbnail jika ada file baru
        if (isset($_FILES['thumbnail']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
            $thumbFile    = $_FILES['thumbnail'];
            $thumbExt     = strtolower(pathinfo($thumbFile['name'], PATHINFO_EXTENSION));
            $thumbAllowed = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

            if (!in_array($thumbExt, $thumbAllowed)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Format thumbnail tidak didukung.']);
                break;
            }

            $newThumbKey  = 'thumbnail/' . uniqid('thumb_', true) . '.' . $thumbExt;
            $thumbMime    = $thumbFile['type'] ?: 'image/jpeg';

            if (!uploadToR2($thumbFile['tmp_name'], $newThumbKey, $thumbMime)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Gagal upload thumbnail baru ke R2.']);
                break;
            }

            // Hapus thumbnail lama dari R2
            $oldThumbKey = parse_url($oldData['thumbnail'], PHP_URL_PATH);
            $oldThumbKey = ltrim($oldThumbKey, '/');
            deleteFromR2($oldThumbKey);

            $thumbUrl = $r2PublicUrl . '/' . $newThumbKey;
        }

        // Update video jika ada file baru
        if (isset($_FILES['video']) && $_FILES['video']['error'] === UPLOAD_ERR_OK) {
            $videoFile    = $_FILES['video'];
            $videoExt     = strtolower(pathinfo($videoFile['name'], PATHINFO_EXTENSION));
            $videoAllowed = ['mp4', 'webm', 'avi', 'mov', 'mkv', 'flv', 'wmv'];

            if (!in_array($videoExt, $videoAllowed)) {
                http_response_code(400);
                echo json_encode(['success' => false, 'message' => 'Format video tidak didukung.']);
                break;
            }

            $newVideoKey = 'video/' . uniqid('video_', true) . '.' . $videoExt;
            $videoMime   = $videoFile['type'] ?: 'video/mp4';

            if (!uploadToR2($videoFile['tmp_name'], $newVideoKey, $videoMime)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Gagal upload video baru ke R2.']);
                break;
            }

            // Hapus video lama dari R2
            $oldVideoKey = parse_url($oldData['video'], PHP_URL_PATH);
            $oldVideoKey = ltrim($oldVideoKey, '/');
            deleteFromR2($oldVideoKey);

            $videoUrl = $r2PublicUrl . '/' . $newVideoKey;
        }

        $stmt = $conn->prepare(
            "UPDATE youtube_232013 SET title = ?, thumbnail = ?, video = ? WHERE id = ?"
        );
        $stmt->bind_param('sssi', $title, $thumbUrl, $videoUrl, $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Data berhasil diperbarui.', 'id' => $id]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Query gagal: ' . $stmt->error]);
        }

        $stmt->close();
        break;

    // ── DELETE ───────────────────────────────────────────────
    case 'delete':
        $id = intval($_GET['id'] ?? 0);

        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
            break;
        }

        $stmt = $conn->prepare("SELECT thumbnail, video FROM youtube_232013 WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $data = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$data) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan.']);
            break;
        }

        // Hapus file dari R2
        $thumbKey = ltrim(parse_url($data['thumbnail'], PHP_URL_PATH), '/');
        $videoKey = ltrim(parse_url($data['video'], PHP_URL_PATH), '/');
        deleteFromR2($thumbKey);
        deleteFromR2($videoKey);

        $stmt = $conn->prepare("DELETE FROM youtube_232013 WHERE id = ?");
        $stmt->bind_param('i', $id);

        if ($stmt->execute()) {
            echo json_encode(['success' => true, 'message' => 'Data berhasil dihapus.']);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Query gagal: ' . $stmt->error]);
        }

        $stmt->close();
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Action tidak dikenali.']);
        break;
}

$conn->close();
?>