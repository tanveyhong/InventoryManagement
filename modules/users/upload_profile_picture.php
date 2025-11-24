<?php
require_once '../../config.php';
require_once '../../sql_db.php';
require_once '../../functions.php';

session_start();

header('Content-Type: application/json');

if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['avatar'];
$userId = $_SESSION['user_id'];

// Validate file type
$allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mimeType = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

if (!in_array($mimeType, $allowedTypes)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPG, PNG, GIF, and WebP are allowed.']);
    exit;
}

// Validate file size (max 5MB)
if ($file['size'] > 5 * 1024 * 1024) {
    echo json_encode(['success' => false, 'error' => 'File size too large. Max 5MB allowed.']);
    exit;
}

// Create upload directory if it doesn't exist
$uploadDir = '../../assets/images/avatars/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

// Generate unique filename
$extension = pathinfo($file['name'], PATHINFO_EXTENSION);
$filename = 'user_' . $userId . '_' . time() . '.' . $extension;
$targetPath = $uploadDir . $filename;
$dbPath = 'assets/images/avatars/' . $filename; // Path relative to web root

if (move_uploaded_file($file['tmp_name'], $targetPath)) {
    // Update database
    try {
        // Update PostgreSQL
        $sqlDb = SQLDatabase::getInstance();
        $sqlDb->execute('UPDATE users SET profile_picture = ? WHERE id = ?', [$dbPath, $userId]);
        
        // Update Firebase (if used)
        try {
            $db = getDB();
            $db->update('users', $userId, ['profile_picture' => $dbPath]);
        } catch (Exception $e) {
            // Ignore Firebase error if it fails, as long as SQL works
            error_log("Firebase update failed: " . $e->getMessage());
        }
        
        // Update session
        $_SESSION['profile_picture'] = $dbPath;
        
        echo json_encode(['success' => true, 'imageUrl' => $dbPath]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => 'Database update failed: ' . $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file']);
}
?>
