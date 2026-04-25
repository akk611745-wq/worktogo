<?php
/**
 * VIDEO FEED RANKING RULES — PLACEHOLDER
 * Status: RESERVED — Not yet active
 *
 * When video module is connected:
 * 1. Accept video dwell-time events from video-engine
 * 2. Add video_weight to FeedRankingEngine
 * 3. Mix videos with products/services in unified feed
 * 4. Feed brain_events with 'video_view','video_like','video_complete'
 *
 * DO NOT activate until /body/video-engine/ is live
 */
class VideoRankingPlaceholder {
    public function getVideoScore(int $video_id, int $user_id): int {
        return 0; // Returns 0 until video module activated
    }
    public function isActive(): bool {
        return false; // Hard-coded false — do not change
    }
}
