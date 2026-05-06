<?php
// ─────────────────────────────────────────────────────────────
//  API Backend – tugas-232013
//  Hosting: Railway
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

// Preflight request (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ── Konfigurasi Database (Railway) ───────────────────────────
// Railway otomatis inject environment variable ke service.
// Jika PHP di-deploy di Railway (project yang sama), gunakan internal host.
// Jika PHP di-deploy di luar Railway, ganti MYSQLHOST dengan public host.

$db_host = getenv('MYSQLHOST')     ?: 'mysql.railway.internal';
$db_name = getenv('MYSQLDATABASE') ?: 'railway';
$db_port = (int)(getenv('MYSQLPORT')     ?: 3306);
$db_user = getenv('MYSQLUSER')     ?: 'root';
$db_pass = getenv('MYSQLPASSWORD') ?: 'hITOWEbxOZmYvNiRUxneBBWfMXfOUkvp';

// !! Jika PHP TIDAK di Railway (misal Koyeb, Render, dll),
// ganti ke public host di bawah ini:
// $db_host = 'trolley.proxy.rlwy.net';
// $db_port = 49337;

// ── Base URL aplikasi ─────────────────────────────────────────
// Ganti dengan URL Railway service PHP kamu setelah deploy.
// Contoh: https://tugas-232013-production.up.railway.app
$baseUrl = getenv('BASE_URL') ?: 'https://tugas-232013-production.up.railway.app';

// ── Koneksi Database ─────────────────────────────────────────
$conn = new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
$conn->set_charset('utf8mb4');

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Koneksi database gagal: ' . $conn->connect_error
    ]);
    exit();
}

// ── Routing berdasarkan ?action= ─────────────────────────────
$action = $_GET['action'] ?? '';

switch ($action) {

    // ── LIST: Ambil semua data ────────────────────────────────
    case 'list':
        $result = $conn->query("SELECT * FROM youtube_232013 ORDER BY id DESC");

        if (!$result) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => $conn->error]);
            break;
        }

        $data = [];
        while ($row = $result->fetch_assoc()) {
            // Konstruksi URL lengkap untuk thumbnail jika hanya berisi filename
            if (!str_starts_with($row['thumbnail'], 'http')) {
                $row['thumbnail'] = $baseUrl . '/thumbnail/' . $row['thumbnail'];
            }
            $data[] = $row;
        }
        echo json_encode($data);
        break;

    // ── ADD: Simpan data + upload thumbnail + video ───────────
    case 'add':
        $title = trim($_POST['title'] ?? '');

        if ($title === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Title wajib diisi.']);
            break;
        }

        // Upload thumbnail
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
            echo json_encode(['success' => false, 'message' => 'Format thumbnail tidak didukung. Gunakan jpg/png/webp/gif.']);
            break;
        }

        $thumbName = uniqid('thumb_', true) . '.' . $thumbExt;
        $thumbDir  = __DIR__ . '/thumbnail/';
        $thumbPath = $thumbDir . $thumbName;

        if (!is_dir($thumbDir)) {
            mkdir($thumbDir, 0755, true);
        }

        if (!move_uploaded_file($thumbFile['tmp_name'], $thumbPath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Gagal memindahkan thumbnail.']);
            break;
        }

        // Upload video
        if (!isset($_FILES['video']) || $_FILES['video']['error'] !== UPLOAD_ERR_OK) {
            @unlink($thumbPath);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Upload video gagal.']);
            break;
        }

        $videoFile    = $_FILES['video'];
        $videoExt     = strtolower(pathinfo($videoFile['name'], PATHINFO_EXTENSION));
        $videoAllowed = ['mp4', 'webm', 'avi', 'mov', 'mkv', 'flv', 'wmv'];

        if (!in_array($videoExt, $videoAllowed)) {
            @unlink($thumbPath);
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Format video tidak didukung. Gunakan mp4/webm/avi/mov/mkv.']);
            break;
        }

        $videoName = uniqid('video_', true) . '.' . $videoExt;
        $videoDir  = __DIR__ . '/video/';
        $videoPath = $videoDir . $videoName;

        if (!is_dir($videoDir)) {
            mkdir($videoDir, 0755, true);
        }

        if (!move_uploaded_file($videoFile['tmp_name'], $videoPath)) {
            @unlink($thumbPath);
            http_response_code(500);
            echo json_encode([
                'success'   => false,
                'message'   => 'Gagal memindahkan video.',
                'debug'     => [
                    'tmp_name'    => $videoFile['tmp_name'],
                    'tmp_exists'  => file_exists($videoFile['tmp_name']),
                    'tmp_size'    => filesize($videoFile['tmp_name'] ?: '/dev/null'),
                    'video_dir'   => $videoDir,
                    'dir_exists'  => is_dir($videoDir),
                    'dir_writable'=> is_writable($videoDir),
                    'video_path'  => $videoPath,
                    'upload_err'  => $videoFile['error'],
                    'php_tmp_dir' => ini_get('upload_tmp_dir') ?: sys_get_temp_dir(),
                ],
            ]);
            break;
        }

        $videoUrl = $baseUrl . '/video/' . $videoName;

        $stmt = $conn->prepare(
            "INSERT INTO youtube_232013 (title, thumbnail, video) VALUES (?, ?, ?)"
        );
        $stmt->bind_param('sss', $title, $thumbName, $videoUrl);

        if ($stmt->execute()) {
            echo json_encode([
                'success'   => true,
                'message'   => 'Data berhasil disimpan.',
                'id'        => $conn->insert_id,
                'thumbnail' => $baseUrl . '/thumbnail/' . $thumbName,
                'video'     => $videoUrl,
            ]);
        } else {
            @unlink($thumbPath);
            @unlink($videoPath);
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Query gagal: ' . $stmt->error]);
        }

        $stmt->close();
        break;

    // ── UPDATE: Update data ───────────────────────────────────
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
        $result  = $stmt->get_result();
        $oldData = $result->fetch_assoc();
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

        $thumbName = $oldData['thumbnail'];
        $videoUrl  = $oldData['video'];

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

            $newThumbName = uniqid('thumb_', true) . '.' . $thumbExt;
            $thumbDir     = __DIR__ . '/thumbnail/';
            $newThumbPath = $thumbDir . $newThumbName;
            $oldThumbPath = $thumbDir . $oldData['thumbnail'];

            if (!is_dir($thumbDir)) mkdir($thumbDir, 0755, true);

            if (!move_uploaded_file($thumbFile['tmp_name'], $newThumbPath)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Gagal memindahkan thumbnail baru.']);
                break;
            }

            if (file_exists($oldThumbPath)) @unlink($oldThumbPath);
            $thumbName = $newThumbName;
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

            $newVideoName = uniqid('video_', true) . '.' . $videoExt;
            $videoDir     = __DIR__ . '/video/';
            $newVideoPath = $videoDir . $newVideoName;

            if (!is_dir($videoDir)) mkdir($videoDir, 0755, true);

            if (!move_uploaded_file($videoFile['tmp_name'], $newVideoPath)) {
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Gagal memindahkan video baru.']);
                break;
            }

            $oldVideoPath = $videoDir . basename($oldData['video']);
            if (file_exists($oldVideoPath)) @unlink($oldVideoPath);

            $videoUrl = $baseUrl . '/video/' . $newVideoName;
        }

        $stmt = $conn->prepare(
            "UPDATE youtube_232013 SET title = ?, thumbnail = ?, video = ? WHERE id = ?"
        );
        $stmt->bind_param('sssi', $title, $thumbName, $videoUrl, $id);

        if ($stmt->execute()) {
            echo json_encode([
                'success' => true,
                'message' => 'Data berhasil diperbarui.',
                'id'      => $id,
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Query gagal: ' . $stmt->error]);
        }

        $stmt->close();
        break;

    // ── DELETE: Hapus data + file ─────────────────────────────
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
        $result = $stmt->get_result();
        $data   = $result->fetch_assoc();
        $stmt->close();

        if (!$data) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'Data tidak ditemukan.']);
            break;
        }

        $thumbPath = __DIR__ . '/thumbnail/' . $data['thumbnail'];
        if (file_exists($thumbPath)) @unlink($thumbPath);

        $videoPath = __DIR__ . '/video/' . basename($data['video']);
        if (file_exists($videoPath)) @unlink($videoPath);

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
