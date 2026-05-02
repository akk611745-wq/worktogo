<?php

class StoryController {
    private PDO $db;

    public function __construct() {
        $this->db = getDB();
    }

    public function uploadStory() {
        $auth = AuthMiddleware::requireRole(ROLE_VENDOR_SERVICE, ROLE_VENDOR_SHOPPING, ROLE_ADMIN);
        $vendorId = $auth['user_id'];
        
        // Ensure upload directory exists
        $uploadDir = SYSTEM_ROOT . '/uploads/stories/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true)) {
                Response::error('Upload failed, try again', 500);
            }
        }

        if (!is_writable($uploadDir)) {
            Response::error('Upload failed, try again', 500);
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            Response::error('Valid file is required', 400);
        }

        $file = $_FILES['file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'mp4'];
        if (!in_array($ext, $allowedExts)) {
            Response::error('Only jpg, png, webp, mp4 allowed', 400);
        }

        $isImage = in_array($ext, ['jpg', 'jpeg', 'png', 'webp']);
        $maxSize = $isImage ? 5 * 1024 * 1024 : 10 * 1024 * 1024;

        if ($file['size'] > $maxSize) {
            Response::error('Image max 5MB, video max 10MB', 400);
        }

        $filename = uniqid('story_') . '.' . $ext;
        $destPath = $uploadDir . $filename;

        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            Response::error('Upload failed, try again', 500);
        }

        $caption = $_POST['caption'] ?? '';
        $mediaType = $isImage ? 'image' : 'video';
        $mediaUrl = '/uploads/stories/' . $filename;
        $expiresAt = time() + (24 * 3600);

        $stmt = $this->db->prepare("
            INSERT INTO stories (vendor_id, media_url, media_type, caption, expires_at)
            VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))
        ");
        $stmt->execute([$vendorId, $mediaUrl, $mediaType, $caption]);
        $storyId = $this->db->lastInsertId();

        Response::json([
            'success' => true,
            'story_id' => $storyId,
            'media_url' => $mediaUrl,
            'expires_at' => date('Y-m-d H:i:s', $expiresAt)
        ]);
    }

    public function getFeedStories() {
        $lat = $_GET['lat'] ?? null;
        $lng = $_GET['lng'] ?? null;
        
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        $userId = null;
        $isGuest = true;
        
        if ($authHeader) {
            $token = str_replace('Bearer ', '', $authHeader);
            try {
                $decoded = JWT::decode($token);
                if ($decoded && empty($decoded['is_guest'])) {
                    $userId = $decoded['user_id'];
                    $isGuest = false;
                }
            } catch (Exception $e) {
                // Ignore invalid tokens for this public feed endpoint
            }
        }

        $stories = [];

        if (!$isGuest && $userId) {
            // Logged in user: prioritize followed vendors
            $stmt = $this->db->prepare("
                SELECT s.id as story_id, s.vendor_id, v.business_name as vendor_name, v.logo_url as vendor_avatar,
                       s.media_url, s.media_type, s.caption, s.expires_at, s.views_count
                FROM stories s
                JOIN vendors v ON s.vendor_id = v.id
                LEFT JOIN followers f ON f.target_id = v.id AND f.target_type = 'vendor' AND f.follower_user_id = ?
                WHERE s.expires_at > NOW()
                ORDER BY f.id IS NOT NULL DESC, s.created_at DESC
                LIMIT 20
            ");
            $stmt->execute([$userId]);
            $stories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Guest or no JWT: top-rated vendors
            $stmt = $this->db->prepare("
                SELECT s.id as story_id, s.vendor_id, v.business_name as vendor_name, v.logo_url as vendor_avatar,
                       s.media_url, s.media_type, s.caption, s.expires_at, s.views_count
                FROM stories s
                JOIN vendors v ON s.vendor_id = v.id
                WHERE s.expires_at > NOW()
                ORDER BY v.rating DESC, s.created_at DESC
                LIMIT 20
            ");
            $stmt->execute();
            $stories = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        // Basic proximity filter if lat/lng are provided and not empty
        if ($lat && $lng) {
            // A real proximity filter would involve distance calculation, but we'll simulate it for now.
            // Assuming vendors have lat/lng or city columns, but keeping it simple as per spec.
        }

        if (empty($stories)) {
            Response::json([
                'success' => true,
                'data' => [],
                'message' => 'No stories right now'
            ]);
        }

        Response::json([
            'success' => true,
            'data' => $stories
        ]);
    }

    public function viewStory(int $id) {
        $stmt = $this->db->prepare("SELECT id FROM stories WHERE id = ? AND expires_at > NOW()");
        $stmt->execute([$id]);
        if (!$stmt->fetch()) {
            Response::error('Story not found or expired', 404);
        }

        $update = $this->db->prepare("UPDATE stories SET views_count = views_count + 1 WHERE id = ?");
        $update->execute([$id]);

        $stmt = $this->db->prepare("SELECT views_count FROM stories WHERE id = ?");
        $stmt->execute([$id]);
        $views = $stmt->fetchColumn();

        Response::json([
            'success' => true,
            'story_id' => $id,
            'views_count' => $views
        ]);
    }
}
