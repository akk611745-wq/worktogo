<?php
/**
 * VideoController — PLACEHOLDER
 * ================================
 * Status: NOT ACTIVE
 * All methods return 'coming_soon' response.
 * Zero database queries. Zero file operations.
 *
 * Activate per README.md instructions only.
 */
class VideoController {

    /**
     * GET /api/video/feed
     * Returns empty feed until video module activated
     */
    public function getFeed(): array {
        return [
            'status'  => 'coming_soon',
            'message' => 'Video feed launching soon! 🎬',
            'data'    => []
        ];
    }

    /**
     * POST /api/vendor/video
     * Returns placeholder until creator module activated
     */
    public function uploadVideo(): array {
        return [
            'status'  => 'coming_soon',
            'message' => 'Creator features launching soon!',
            'data'    => null
        ];
    }

    /**
     * POST /api/video/{id}/like
     * Placeholder
     */
    public function likeVideo(): array {
        return [
            'status'  => 'coming_soon',
            'message' => 'Video features launching soon!'
        ];
    }
}