<?php
/**
 * DJ CRM Software 5.2.2 — Central Advertisement Management Service
 * Multi-tenant safe ad placement, impression tracking, plan-based targeting, and HTML/JS code snippets.
 */

if (!defined('YBT_ROOT')) exit;

class AdService {
    /**
     * Get active advertisements for a specific placement and optional plan
     */
    public static function getActive(string $placement, int $limit = 1, ?string $planSlug = null): array {
        $today = date('Y-m-d');
        try {
            Database::ensureSchemaCompatibility();
            $ads = Database::fetchAll(
                "SELECT * FROM advertisements 
                 WHERE placement = ? 
                   AND is_active = 1 
                   AND (start_date IS NULL OR start_date <= ?) 
                   AND (end_date IS NULL OR end_date >= ?) 
                 ORDER BY priority DESC, id DESC",
                [$placement, $today, $today]
            );

            if (empty($ads)) {
                return [];
            }

            // Plan-based targeting filter
            if ($planSlug !== null) {
                $filtered = [];
                foreach ($ads as $ad) {
                    $target = trim((string)($ad['target_plans'] ?? 'all'));
                    if ($target === '' || $target === 'all') {
                        $filtered[] = $ad;
                    } else {
                        $targetPlans = array_map('trim', explode(',', $target));
                        if (in_array($planSlug, $targetPlans, true)) {
                            $filtered[] = $ad;
                        }
                    }
                }
                return array_slice($filtered, 0, $limit);
            }

            return array_slice($ads, 0, $limit);
        } catch (\Throwable $e) {
            error_log('AdService::getActive error: ' . $e->getMessage());
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
    public static function render(string $placement, ?string $planSlug = null): string {
        $ads = self::getActive($placement, 1, $planSlug);
        if (empty($ads)) {
            return '';
        }

        $ad = $ads[0];
        $adId = (int)$ad['id'];
        self::recordImpression($adId);

        $targetUrl = !empty($ad['target_url']) ? e($ad['target_url']) : '#';
        $imageUrl = !empty($ad['image_url']) ? e($ad['image_url']) : '';
        $title = e($ad['title']);
        $company = !empty($ad['company_name']) ? ' · ' . e($ad['company_name']) : '';
        $clickUrl = url("api/ad-click.php?id={$adId}");

        // If Super Admin provided custom HTML/JS ad snippet
        if (!empty($ad['ad_code'])) {
            return '<div class="dj-ad-slot dj-ad-' . e($placement) . ' mb-4" style="text-align: center; overflow: hidden; max-width: 100%; border-radius: 8px;">' .
                   '<div style="font-size: 10px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px;">Sponsored' . $company . '</div>' .
                   '<div class="dj-ad-code-wrapper">' . $ad['ad_code'] . '</div>' .
                   '</div>';
        }

        if ($placement === 'dashboard_top' || $placement === 'dashboard_content') {
            return '<div class="ad-banner ad-' . e($placement) . ' mb-4" style="background: linear-gradient(135deg, rgba(30, 41, 59, 0.8), rgba(15, 23, 42, 0.9)); border: 1px solid rgba(148, 163, 184, 0.2); border-radius: 8px; overflow: hidden; padding: 12px 18px; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;">' .
                   '<div style="display: flex; align-items: center; gap: 14px;">' .
                   (!empty($imageUrl) ? '<img src="' . $imageUrl . '" alt="' . $title . '" style="max-height: 48px; max-width: 120px; border-radius: 4px; object-fit: contain;">' : '<i class="ti ti-speakerphone" style="font-size: 24px; color: #f59e0b;"></i>') .
                   '<div>' .
                   '<div style="font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: #f59e0b; font-weight: 700;">Promoted' . $company . '</div>' .
                   '<div style="font-size: 14px; font-weight: 600; color: #f8fafc;">' . $title . '</div>' .
                   '</div>' .
                   '</div>' .
                   '<a href="' . $targetUrl . '" target="_blank" rel="noopener sponsored" onclick="if(navigator.sendBeacon){navigator.sendBeacon(\'' . $clickUrl . '\');}" class="btn btn-warning btn-sm" style="font-weight: 600; white-space: nowrap;">Learn More <i class="ti ti-external-link ml-1"></i></a>' .
                   '</div>';
        }

        if ($placement === 'sidebar_bottom') {
            return '<div class="ad-banner ad-sidebar-bottom p-3" style="margin: 12px; background: rgba(245, 158, 11, 0.08); border: 1px dashed rgba(245, 158, 11, 0.3); border-radius: 8px; text-align: center;">' .
                   (!empty($imageUrl) ? '<img src="' . $imageUrl . '" alt="' . $title . '" style="width: 100%; max-height: 80px; object-fit: cover; border-radius: 4px; margin-bottom: 6px;">' : '') .
                   '<div style="font-size: 10px; text-transform: uppercase; color: #f59e0b; font-weight: 700; margin-bottom: 2px;">Sponsored' . $company . '</div>' .
                   '<div style="font-size: 12px; font-weight: 600; color: var(--color-text); margin-bottom: 6px;">' . $title . '</div>' .
                   '<a href="' . $targetUrl . '" target="_blank" rel="noopener sponsored" onclick="if(navigator.sendBeacon){navigator.sendBeacon(\'' . $clickUrl . '\');}" class="btn btn-outline-primary btn-xs" style="font-size: 11px;">Visit Partner <i class="ti ti-external-link ml-1"></i></a>' .
                   '</div>';
        }

        if ($placement === 'footer') {
            return '<div class="ad-banner ad-footer text-center my-3 p-2" style="background: rgba(30, 41, 59, 0.4); border-top: 1px solid rgba(148, 163, 184, 0.15); border-radius: 6px;">' .
                   '<span style="font-size: 10px; text-transform: uppercase; color: #94a3b8; margin-right: 8px;">Ad:</span>' .
                   '<a href="' . $targetUrl . '" target="_blank" rel="noopener sponsored" onclick="if(navigator.sendBeacon){navigator.sendBeacon(\'' . $clickUrl . '\');}" style="color: #f59e0b; text-decoration: none; font-size: 13px; font-weight: 500;">' . $title . $company . ' <i class="ti ti-external-link ml-1"></i></a>' .
                   '</div>';
        }

        return '';
    }

    /**
     * Get all ads for management
     */
    public static function getAll(): array {
        Database::ensureSchemaCompatibility();
        try {
            return Database::fetchAll("SELECT * FROM advertisements ORDER BY priority DESC, id DESC");
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * Get single ad by ID
     */
    public static function getById(int $id): ?array {
        try {
            $ad = Database::fetch("SELECT * FROM advertisements WHERE id = ?", [$id]);
            return $ad ?: null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Create new advertisement
     */
    public static function create(array $data): int {
        Database::ensureSchemaCompatibility();
        return Database::insert('advertisements', [
            'title' => trim($data['title'] ?? ''),
            'company_name' => trim($data['company_name'] ?? '') ?: null,
            'placement' => trim($data['placement'] ?? 'dashboard_top'),
            'image_url' => trim($data['image_url'] ?? '') ?: null,
            'target_url' => trim($data['target_url'] ?? '') ?: null,
            'ad_code' => !empty($data['ad_code']) ? trim($data['ad_code']) : null,
            'target_plans' => trim($data['target_plans'] ?? 'all') ?: 'all',
            'priority' => (int)($data['priority'] ?? 0),
            'start_date' => !empty($data['start_date']) ? $data['start_date'] : date('Y-m-d'),
            'end_date' => !empty($data['end_date']) ? $data['end_date'] : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'impressions_count' => 0,
            'clicks_count' => 0
        ]);
    }

    /**
     * Update existing advertisement
     */
    public static function update(int $id, array $data): bool {
        Database::ensureSchemaCompatibility();
        $fields = [
            'title' => trim($data['title'] ?? ''),
            'company_name' => trim($data['company_name'] ?? '') ?: null,
            'placement' => trim($data['placement'] ?? 'dashboard_top'),
            'image_url' => trim($data['image_url'] ?? '') ?: null,
            'target_url' => trim($data['target_url'] ?? '') ?: null,
            'ad_code' => !empty($data['ad_code']) ? trim($data['ad_code']) : null,
            'target_plans' => trim($data['target_plans'] ?? 'all') ?: 'all',
            'priority' => (int)($data['priority'] ?? 0),
            'start_date' => !empty($data['start_date']) ? $data['start_date'] : null,
            'end_date' => !empty($data['end_date']) ? $data['end_date'] : null,
            'is_active' => !empty($data['is_active']) ? 1 : 0
        ];
        return (bool)Database::update('advertisements', $fields, 'id = ?', [$id]);
    }

    /**
     * Toggle active status
     */
    public static function toggle(int $id): bool {
        $curr = Database::fetchColumn("SELECT is_active FROM advertisements WHERE id = ?", [$id]);
        $new = $curr ? 0 : 1;
        return (bool)Database::query("UPDATE advertisements SET is_active = ? WHERE id = ?", [$new, $id]);
    }

    /**
     * Delete an advertisement
     */
    public static function delete(int $id): bool {
        return (bool)Database::query("DELETE FROM advertisements WHERE id = ?", [$id]);
    }
}
