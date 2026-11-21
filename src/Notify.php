<?php
declare(strict_types=1);

/**
 * SMS and e-mail, as the Next.js shop sends them (notify.ts, messages.ts):
 * every message is written to "Notification" first, so the back office shows
 * what was sent and what could not be (no gateway set). The SMS gateway is the
 * owner's own (Paramètres: its address with {to} {text} {sender} {key}); the
 * owner's new-order e-mail goes through Resend with the key saved in the back
 * office. Keys are read from the database, never written anywhere else.
 */
final class Notify
{
    private static function secrets(): array
    {
        try {
            $v = Db::value('SELECT value FROM "Setting" WHERE key = ?', ['secrets']);
            $d = is_string($v) ? json_decode($v, true) : null;
            return is_array($d) ? $d : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** One HTTPS call; returns [status code or 0, body]. */
    private static function http(string $method, string $url, ?string $body = null, array $headers = []): array
    {
        if (function_exists('curl_init')) {
            $c = curl_init($url);
            curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
            if ($body !== null) {
                curl_setopt($c, CURLOPT_POSTFIELDS, $body);
            }
            $out = curl_exec($c);
            $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
            $err = curl_error($c);
            curl_close($c);
            return [$code, $out === false ? $err : (string) $out];
        }
        $ctx = stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'content' => $body ?? '', 'timeout' => 8, 'ignore_errors' => true]]);
        $out = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+ (\d{3})#', $h, $m)) {
                $code = (int) $m[1];
            }
        }
        return [$code, (string) $out];
    }

    private static function log(string $channel, string $to, string $body, string $kind, ?string $orderId, string $status = 'QUEUED'): string
    {
        $id = new_id();
        Db::insert('Notification', ['id' => $id, 'orderId' => $orderId, 'channel' => $channel, 'to' => $to, 'kind' => $kind, 'body' => $body, 'status' => $status, 'createdAt' => Db::now()]);
        return $id;
    }

    private static function mark(string $id, string $status, ?string $error = null): void
    {
        Db::run('UPDATE "Notification" SET status = ?, error = ? WHERE id = ?', [$status, $error !== null ? mb_substr($error, 0, 300) : null, $id]);
    }

    public static function sms(string $to, string $text, string $kind, ?string $orderId = null): void
    {
        $n = self::log('SMS', $to, $text, $kind, $orderId);
        $s = Settings::get('notify');
        $tpl = (string) ($s['smsUrl'] ?? '');
        if ($tpl === '') {
            self::mark($n, 'NOT_CONFIGURED');
            return;
        }
        $key = (string) (self::secrets()['smsKey'] ?? '');
        $intl = '216' . $to;
        $fill = static fn (string $x, bool $enc) => strtr($x, [
            '{to}' => $enc ? rawurlencode($intl) : $intl,
            '{text}' => $enc ? rawurlencode($text) : substr((string) json_encode($text, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1),
            '{sender}' => $enc ? rawurlencode((string) $s['smsSender']) : (string) $s['smsSender'],
            '{key}' => $enc ? rawurlencode($key) : $key,
        ]);
        try {
            $url = $fill($tpl, true);
            [$code, $body] = ($s['smsMethod'] ?? 'GET') === 'GET'
                ? self::http('GET', $url)
                : self::http('POST', $url, $fill((string) ($s['smsBody'] ?: '{"to":"{to}","text":"{text}"}'), false), ['Content-Type: application/json']);
            $code >= 200 && $code < 300 ? self::mark($n, 'SENT') : self::mark($n, 'FAILED', $code ? "HTTP $code" : $body);
        } catch (Throwable $e) {
            self::mark($n, 'FAILED', $e->getMessage());
        }
    }

    public static function email(string $to, string $subject, string $text, string $kind, ?string $orderId = null): void
    {
        $n = self::log('EMAIL', $to, "$subject\n\n$text", $kind, $orderId);
        $key = (string) (self::secrets()['resendKey'] ?? '');
        if ($key === '') {
            self::mark($n, 'NOT_CONFIGURED');
            return;
        }
        $from = trim((string) Settings::get('notify.emailFrom', '')) ?: 'Mena <onboarding@resend.dev>';
        [$code, $body] = self::http('POST', 'https://api.resend.com/emails', (string) json_encode(['from' => $from, 'to' => [$to], 'subject' => $subject, 'text' => $text], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ["Authorization: Bearer $key", 'Content-Type: application/json']);
        if ($code >= 200 && $code < 300) {
            self::mark($n, 'SENT');
            return;
        }
        $msg = json_decode($body, true)['message'] ?? null;
        self::mark($n, 'FAILED', ($code ? "HTTP $code" : 'network') . (is_string($msg) ? " : $msg" : ''));
    }

    private static function first(string $name): string
    {
        return preg_split('/\s+/u', trim($name))[0] ?? '';
    }

    /** placedText */
    public static function placedText(array $o, string $shop, string $trackUrl): string
    {
        return $o['locale'] === 'ar'
            ? "$shop: شكرا " . self::first($o['name']) . "، طلبيتك {$o['ref']} (" . Money::format((int) $o['total'], 'ar') . ") وصلت. باش نكلموك نأكدوها. التتبع: $trackUrl"
            : "$shop : merci " . self::first($o['name']) . ", commande {$o['ref']} reçue (" . Money::format((int) $o['total'], 'fr') . "). Nous vous appelons pour la confirmer. Suivi : $trackUrl";
    }

    /** shippedText */
    public static function shippedText(array $o, string $shop, string $trackUrl, ?string $carrier, ?string $tracking): string
    {
        $ct = implode(' ', array_filter([$carrier, $tracking]));
        return $o['locale'] === 'ar'
            ? "$shop: طلبيتك {$o['ref']} خرجت" . ($ct !== '' ? " ($ct)" : '') . '. حضّر ' . Money::format((int) $o['total'], 'ar') . ". التتبع: $trackUrl"
            : "$shop : votre commande {$o['ref']} est en route" . ($ct !== '' ? " ($ct)" : '') . '. Montant à préparer : ' . Money::format((int) $o['total'], 'fr') . ". Suivi : $trackUrl";
    }

    /** The tracking link of an order, in the language it was placed in. */
    public static function trackUrl(array $o): string
    {
        return rtrim((string) config('site_url', ''), '/') . url('/suivi/t/' . Tokens::orderToken($o['accessSalt']), $o['locale'] === 'fr' ? 'fr' : 'ar');
    }

    /** After an order (once the customer has their answer): the customer's SMS, the owner's e-mail. */
    public static function orderPlaced(string $orderId): void
    {
        after_response(static function () use ($orderId) {
            $o = Db::one('SELECT id, ref, name, phone, governorate, address, total, "deliveryFee", locale, "accessSalt" FROM "Order" WHERE id = ?', [$orderId]);
            if (!$o) {
                return;
            }
            $s = Settings::all();
            if (!empty($s['notify']['smsOnPlaced'])) {
                self::sms($o['phone'], self::placedText($o, $s['shop']['name'], self::trackUrl($o)), 'order_placed', $o['id']);
            }
            $alertTo = trim((string) ($s['notify']['orderAlertTo'] ?: $s['shop']['email']));
            if ($alertTo === '') {
                return;
            }
            $site = rtrim((string) config('site_url', ''), '/');
            $lines = array_map(static fn ($l) => "{$l['qty']} × {$l['name']}" . ($l['variantName'] ? " ({$l['variantName']})" : '') . ($l['offerLabel'] ? " — {$l['offerLabel']}" : '') . ' — ' . Money::format((int) $l['lineTotal'], 'fr'), Db::all('SELECT qty, name, "variantName", "offerLabel", "lineTotal" FROM "OrderItem" WHERE "orderId" = ? ORDER BY id', [$o['id']]));
            self::email($alertTo, "Nouvelle commande {$o['ref']} — " . Money::format((int) $o['total'], 'fr'), implode("\n", [
                $o['name'] . ' · ' . Phone::format($o['phone']),
                Gov::label($o['governorate'], 'fr') . ' · ' . $o['address'],
                '',
                ...$lines,
                'Livraison : ' . Money::format((int) $o['deliveryFee'], 'fr'),
                'Total : ' . Money::format((int) $o['total'], 'fr'),
                '',
                "$site/admin/commandes/{$o['id']}",
            ]), 'order_alert', $o['id']);
        });
    }
}
