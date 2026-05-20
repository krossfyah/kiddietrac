---
title: Video sharing feed
category: Family Engagement
order: 22
---
# Video feed

Like photos but for short videos — same upload + tagging + reaction flow.

## Staff: uploading

1. Sidebar → **Video feed**.
2. Tap **+ Upload video**.
3. Pick an MP4 / MOV / WebM file (max 64MB after v22p60's PHP limit bump).
4. Optionally add a caption + tag the children featured.
5. Upload.

## Parent view

- Sidebar → **Videos** under Your child.
- See videos featuring your children, sorted newest first.
- Tap to play; tap reaction buttons (❤️ 😊 😮 🎉 👏) to react.
- Caption + uploader name + date shown beneath each video.

## Reactions

Same reaction system as photos. Parents tap an emoji button to acknowledge or thank. Staff see the count per reaction beneath each video.

## Limits

- Max 64MB per video (PHP `upload_max_filesize`)
- Max 10 minutes duration per video (`duration_seconds` field, enforced client-side)
- Files stored in `/storage/videos/YYYY/MM/`

## When to use videos

- First steps / first words
- Special program performances (recital, presentation)
- Family-wide events (parade, field-trip arrival)
- Showing a teaching moment in action

For static moments, **photos** remain the better choice — they download faster, share more easily, and use less storage.
