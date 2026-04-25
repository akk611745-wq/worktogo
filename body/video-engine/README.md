# Video Engine — Placeholder
Status: RESERVED — Not yet active
Last updated: 2026

## When to activate this module:
- Shopping + Service modules stable with 100+ active vendors
- Separate video CDN/server contracted
- Creator onboarding flow built and tested

## Activation Steps (in order):
1. Search routes.php for "VIDEO_ENGINE" comment marker
2. Uncomment the video routes
3. Execute video_content table from master_schema.sql comment block
4. Connect VideoController to your video CDN config
5. Feed brain_events with video events:
   - 'video_view' — user watched video
   - 'video_like' — user liked video
   - 'video_complete' — user watched full video
6. Activate /brain/rules/video_ranking_placeholder.php
   Remove return 0 and implement real scoring

## Dependencies needed before activation:
- Video CDN (Cloudflare Stream / Bunny.net / AWS S3)
- Creator verification flow
- video_content database table (see master_schema.sql comment)

## DO NOT activate until all above are ready.