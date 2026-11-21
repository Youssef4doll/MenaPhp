<?php
declare(strict_types=1);

/**
 * The shop's own statistics, in the Next.js shop's "AnalyticsEvent" table:
 * what the pages send (/api/events, the Next.js shop's events route) and what
 * the server records about orders (recordOrderEvent).
 */
final class Events
{
    /** CLIENT_EVENTS: the names a page may send. */
    public const CLIENT = ['page_view', 'product_viewed', 'quick_buy_opened', 'add_to_cart', 'remove_from_cart', 'cart_viewed', 'video_played', 'gallery_zoomed', 'category_viewed', 'checkout_started', 'checkout_phone_entered', 'checkout_failed', 'search', 'search_suggestion_clicked', 'whatsapp_clicked', 'phone_clicked', 'messenger_clicked', 'delivery_estimate_checked', 'gallery_swiped', 'tracking_viewed', 'sticky_clicked', 'details_link_clicked', 'details_seen', 'details_end', 'order_sheet_opened', 'list_item_clicked', 'consent_granted', 'consent_denied', 'not_found'];

    /** deviceFromUA */
    public static function device(?string $ua): string
    {
        $s = strtolower((string) $ua);
        if (preg_match('/ipad|tablet|kindle|silk|(android(?!.*mobile))/', $s)) {
            return 'tablet';
        }
        if (preg_match('/mobi|iphone|ipod|android|blackberry|opera mini|iemobile/', $s)) {
            return 'mobile';
        }
        return 'desktop';
    }

    /** isBot: crawlers and link previewers are not visitors. */
    public static function isBot(?string $ua): bool
    {
        return !$ua || (bool) preg_match('/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|facebookexternalhit|whatsapp|telegram|curl|wget|python|go-http|axios|node-fetch|playwright/i', $ua);
    }

    /** isInternal: a browser signed in to the back office, or a device the owner excluded. */
    public static function isInternal(): bool
    {
        if (($_COOKIE['mena_internal'] ?? '') === '1') {
            return true;
        }
        return isset($_COOKIE[Auth::COOKIE]) && Auth::current() !== null;
    }

    /** The events of one page batch, checked as the Next.js shop checks them. Returns how many were kept. */
    public static function intake(array $d, ?string $ua): int
    {
        $id = '/^[A-Za-z0-9-]{8,64}$/';
        if (!is_string($d['visitorId'] ?? null) || !preg_match($id, $d['visitorId']) || !is_string($d['sessionId'] ?? null) || !preg_match($id, $d['sessionId'])) {
            throw new InvalidArgumentException('ids');
        }
        $events = $d['events'] ?? null;
        if (!is_array($events) || !$events || count($events) > 25) {
            throw new InvalidArgumentException('events');
        }
        foreach (['source' => 60, 'medium' => 60] as $k => $max) {
            if (isset($d[$k]) && (!is_string($d[$k]) || mb_strlen($d[$k]) > $max)) {
                throw new InvalidArgumentException($k);
            }
        }
        if (isset($d['campaign']) && (!is_string($d['campaign']) || mb_strlen($d['campaign']) > 120)) {
            throw new InvalidArgumentException('campaign');
        }
        $content = is_string($d['content'] ?? null) ? mb_substr($d['content'], 0, 120) : null;
        $device = self::device($ua);
        $now = time();
        $rows = [];
        foreach ($events as $e) {
            if (!is_array($e) || !in_array($e['name'] ?? null, self::CLIENT, true)) {
                throw new InvalidArgumentException('name');
            }
            if ((isset($e['path']) && (!is_string($e['path']) || mb_strlen($e['path']) > 200))
                || (isset($e['productId']) && (!is_string($e['productId']) || strlen($e['productId']) > 40))
                || (isset($e['value']) && (!is_int($e['value']) || $e['value'] < 0 || $e['value'] > 100000000))
                || (isset($e['props']) && !is_array($e['props']))) {
                throw new InvalidArgumentException('event');
            }
            $props = isset($e['props']) ? json_encode($e['props'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
            if ($props !== null && strlen($props) > 1024) {
                continue;
            }
            $at = isset($e['at']) && is_string($e['at']) ? strtotime($e['at']) : false;
            $at = $at === false || abs($at - $now) > 86400 ? $now : $at;
            $rows[] = [
                'id' => new_id(), 'name' => $e['name'], 'visitorId' => $d['visitorId'], 'sessionId' => $d['sessionId'],
                'path' => $e['path'] ?? null, 'productId' => $e['productId'] ?? null, 'source' => ($d['source'] ?? null) ?? 'direct',
                'medium' => $d['medium'] ?? null, 'campaign' => $d['campaign'] ?? null, 'content' => $content, 'device' => $device,
                'value' => $e['value'] ?? null, 'props' => $props, 'createdAt' => gmdate('Y-m-d H:i:s', $at),
            ];
        }
        foreach ($rows as $r) {
            Db::insert('AnalyticsEvent', $r);
        }
        return count($rows);
    }

    /** recordOrderEvent: an order's step, with where the order came from. Never fails the order. */
    public static function server(string $name, array $order, array $props = []): void
    {
        try {
            Db::insert('AnalyticsEvent', [
                'id' => new_id(), 'name' => $name, 'visitorId' => $order['visitorId'] ?? 'server', 'sessionId' => $order['sessionId'] ?? 'server',
                'orderId' => $order['id'], 'value' => $order['total'], 'source' => $order['source'] ?? null, 'medium' => $order['medium'] ?? null,
                'campaign' => $order['campaign'] ?? null, 'device' => $order['device'] ?? null,
                'props' => json_encode($props + ['governorate' => $order['governorate'], 'server' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'createdAt' => Db::now(),
            ]);
        } catch (Throwable $e) {
            error_log("[events] $name not recorded: " . $e->getMessage());
        }
    }
}
