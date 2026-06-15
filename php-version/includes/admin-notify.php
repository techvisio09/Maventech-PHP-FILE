<?php
/**
 * Admin notification helpers.
 *
 * One short helper to push a notification row into the `admin_notifications`
 * table — anywhere a hook fires (new order, new lead, etc.) just calls
 * `admin_notify(type, title, body, link)`.
 *
 * The admin PWA polls /admin.php?ajax=notif_poll every ~30 seconds (or
 * when the tab gains focus) and surfaces new rows as system toasts +
 * an in-app bell badge.
 */

if (!function_exists('admin_notify')) {

    function admin_notify(string $type, string $title, string $body = '', string $link = '/admin.php'): void
    {
        try {
            $pdo = db();
            // Guard: dedupe within the last 30 seconds for the same {type, title, link}
            // so a checkout that fires twice doesn't double-buzz the bell.
            $dup = $pdo->prepare(
                "SELECT id FROM admin_notifications
                  WHERE type=? AND title=? AND COALESCE(link,'')=COALESCE(?, '')
                    AND created_at >= NOW() - INTERVAL 30 SECOND
                  LIMIT 1"
            );
            $dup->execute([$type, $title, $link]);
            if ($dup->fetchColumn()) return;

            $stmt = $pdo->prepare(
                "INSERT INTO admin_notifications (type, title, body, link)
                 VALUES (?, ?, ?, ?)"
            );
            $stmt->execute([
                substr($type,  0, 40),
                substr($title, 0, 180),
                substr($body,  0, 400),
                substr($link,  0, 255),
            ]);
        } catch (Throwable $e) {
            // Notifications are decorative — never let a logging failure
            // bubble up to the checkout / lead-capture / review flow.
            @error_log('[admin_notify] ' . $e->getMessage());
        }
    }
}
