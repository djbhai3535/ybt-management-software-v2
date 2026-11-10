<?php
/**
 * YBT Management Software — Flash Messages
 */

class Flash {
    public static function set(string $type, string $message): void {
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function success(string $message): void {
        self::set('success', $message);
    }

    public static function error(string $message): void {
        self::set('error', $message);
    }

    public static function warning(string $message): void {
        self::set('warning', $message);
    }

    public static function info(string $message): void {
        self::set('info', $message);
    }

    public static function get(?string $type = null): array {
        $messages = $_SESSION['_flash'] ?? [];
        if ($type === null) {
            unset($_SESSION['_flash']);
            return $messages;
        }
        $filtered = [];
        $remaining = [];
        foreach ($messages as $m) {
            if (($m['type'] ?? '') === $type) {
                $filtered[] = $m;
            } else {
                $remaining[] = $m;
            }
        }
        $_SESSION['_flash'] = $remaining;
        return $filtered;
    }

    public static function getMessage(?string $type = null): ?string {
        $items = self::get($type);
        if (empty($items)) return null;
        return $items[0]['message'] ?? null;
    }

    public static function has(): bool {
        return !empty($_SESSION['_flash']);
    }

    /**
     * Render flash messages as HTML
     */
    public static function render(): string {
        $messages = self::get();
        if (empty($messages)) return '';

        $html = '<div class="flash-container">';
        foreach ($messages as $msg) {
            $type = htmlspecialchars($msg['type'], ENT_QUOTES);
            $text = htmlspecialchars($msg['message'], ENT_QUOTES);
            $icon = match($type) {
                'success' => 'circle-check',
                'error'   => 'alert-circle',
                'warning' => 'alert-triangle',
                default   => 'info-circle',
            };
            $html .= "<div class=\"flash flash-{$type}\" role=\"alert\">";
            $html .= "<i class=\"ti ti-{$icon}\"></i>";
            $html .= "<span>{$text}</span>";
            $html .= '<button type="button" class="flash-close" aria-label="Close">&times;</button>';
            $html .= '</div>';
        }
        $html .= '</div>';
        return $html;
    }
}
