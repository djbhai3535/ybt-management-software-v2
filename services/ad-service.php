<?php
/**
 * DJ CRM Software — Advertisement Service
 * Handles platform advertisement retrieval, display, and telemetry (impressions & clicks).
 */

class AdService {
    /**
     * Get active advertisements for a specific placement
     */
    public static function getActive(string $placement, int $limit = 1): array {
        $today = date('Y-m-d');
        try {
            return Database::fetchAll(
                "SELECT * FROM advertisements 
                 WHERE placement = ? 
                   AND is_active = 1 
                   AND (start_date IS NULL OR start_date <= ?) 
                   AND (end_date IS NULL OR end_date >= ?) 
                 ORDER BY id DESC LIMIT ?",
                [$placement, $today, $today, $limit]
            );
        } catch (\Throwable $e) {
            // Defensive: if table does not exist yet
            return [];
        }
    }

    /**
     * Record an impression for an advertisement
     */
    public static function recordImpression(int $adId): void {
        try {
            Database::query("UPDATE advertisements SET impressions_count = impressions_count + 1 WHERE id = ?", [$adId]);
        } catch (\Throwable $e) {}
    }

    /**
     * Record a click for an advertisement
     */
    public static function recordClick(int $adId): void {
        try {
            Database::query("UPDATE advertisements SET clicks_count = clicks_count + 1 WHERE id = ?", [$adId]);
        } catch (\Throwable $e) {}
    }

    /**
     * Render an advertisement banner HTML safely
     */
    public static function render(string $placement): string {
        $ads = self::getActive($placement, 1);
        if (empty($ads)) {
            return '';
        }

        $ad = $ads[0];
        self::recordImpression((int)$ad['id']);

        $targetUrl = !empty($ad['target_url']) ? e($ad['target_url']) : '#';
        $imageUrl = !empty($ad['image_url']) ? e($ad['image_url']) : '';
        $title = e($ad['title']);

        if ($placement === 'dashboard_top') {
            return '<div class="ad-banner ad-dashboard-top mb-4" style="background: linear-gradient(135deg, #1e293b, #0f172a); border: 1px solid #334155; border-radius: 8px; overflow: hidden; padding: 12px 18px; display: flex; align-items: center; justify-content: space-between;">' .
                   '<div style="display: flex; align-items: center; gap: 14px;">' .
                   (!empty($imageUrl) ? '<img src="' . $imageUrl . '" alt="' . $title . '" style="max-height: 48px; border-radius: 4px; object-fit: contain;">' : '<i class="ti ti-speakerphone" style="font-size: 24px; color: #f59e0b;"></i>') .
                   '<div>' .
                   '<div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; font-weight: 700;">Promoted</div>' .
                   '<div style="font-size: 14px; font-weight: 600; color: #f8fafc;">' . $title . '</div>' .
                   '</div>' .
                   '</div>' .
                   '<a href="' . $targetUrl . '" target="_blank" rel="noopener noreferrer" class="btn btn-warning btn-sm" style="font-weight: 600;">Learn More <i class="ti ti-external-link ml-1"></i></a>' .
                   '</div>';
        }

        if ($placement === 'sidebar_bottom') {
            return '<div class="ad-banner ad-sidebar-bottom p-3" style="margin: 12px; background: rgba(245, 158, 11, 0.08); border: 1px dashed rgba(245, 158, 11, 0.3); border-radius: 8px; text-align: center;">' .
                   (!empty($imageUrl) ? '<img src="' . $imageUrl . '" alt="' . $title . '" style="width: 100%; max-height: 80px; object-fit: cover; border-radius: 4px; margin-bottom: 6px;">' : '') .
                   '<div style="font-size: 12px; font-weight: 600; color: var(--color-text); margin-bottom: 6px;">' . $title . '</div>' .
                   '<a href="' . $targetUrl . '" target="_blank" rel="noopener noreferrer" class="btn btn-outline-primary btn-xs" style="font-size: 11px;">Visit Partner</a>' .
                   '</div>';
        }

        return '';
    }
}
