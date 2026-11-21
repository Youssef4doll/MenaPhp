<?php
declare(strict_types=1);

/**
 * Turning a checkout into an order, and moving it through its statuses: the
 * same rules as src/lib/orders.ts in the Next.js shop, on the same tables.
 * Everything is derived again on the server: lines re-priced from the
 * catalog, the delivery fee read from the settings, stock re-read and taken
 * in the same transaction that writes the order. A total that differs from
 * the one the customer saw stops the order: they see the new total and
 * confirm again.
 */
final class Orders
{
    public const STATUS_LABELS = [
        'PENDING' => 'À confirmer', 'CONFIRMED' => 'Confirmée', 'SUPPLIER_ORDERED' => 'Commandée fournisseur', 'SHIPPED' => 'Expédiée',
        'DELIVERED' => 'Livrée', 'REFUSED' => 'Refusée à la livraison', 'RETURNED' => 'Retournée', 'CANCELLED' => 'Annulée',
    ];

    public const TRANSITIONS = [
        'PENDING' => ['CONFIRMED', 'CANCELLED'],
        'CONFIRMED' => ['SUPPLIER_ORDERED', 'SHIPPED', 'CANCELLED'],
        'SUPPLIER_ORDERED' => ['SHIPPED', 'CANCELLED'],
        'SHIPPED' => ['DELIVERED', 'REFUSED'],
        'DELIVERED' => ['RETURNED'],
        'REFUSED' => [],
        'RETURNED' => [],
        'CANCELLED' => [],
    ];

    public const FLAG_LABELS = [
        'blacklisted' => 'Liste noire', 'serial_refuser' => 'Refus répétés', 'past_refusals' => 'Refus passés',
        'past_delivered' => 'Déjà livré', 'duplicate' => 'Doublon possible', 'too_many_today' => "Trop de commandes aujourd'hui", 'landline' => 'Ligne fixe',
    ];

    /** Checkout flags (orderFlags): what the person calling should know. */
    public static function flags(array $h, int $maxPerDay, bool $mobile): array
    {
        $f = [];
        if ($h['blacklisted']) {
            $f[] = 'blacklisted';
        }
        if ($h['refused'] > 0) {
            $f[] = 'past_refusals:' . $h['refused'];
        }
        if ($h['refused'] >= 2 && $h['refused'] >= $h['delivered']) {
            $f[] = 'serial_refuser';
        }
        if ($h['delivered'] > 0) {
            $f[] = 'past_delivered:' . $h['delivered'];
        }
        if ($h['duplicateOpen']) {
            $f[] = 'duplicate';
        }
        if ($h['last24h'] >= $maxPerDay) {
            $f[] = 'too_many_today';
        }
        if (!$mobile) {
            $f[] = 'landline';
        }
        return $f;
    }

    /** The order made from this form already, if the same form was sent twice. */
    public static function bySubmitKey(string $key): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_-]{16,64}$/', $key)) {
            return null;
        }
        return Db::one('SELECT ref, "accessSalt" FROM "Order" WHERE "submitKeyHash" = ?', [Tokens::hash($key)]);
    }

    /**
     * placeOrderSchema: the order form's fields, checked as the Next.js shop checks them. Returns
     * [clean input, list of bad fields]. Where the visit came from is cut, never a reason to refuse.
     */
    public static function validate(array $in): array
    {
        $bad = [];
        $str = static function (string $k, int $max, bool $required = false, int $min = 0) use ($in, &$bad): ?string {
            $v = $in[$k] ?? null;
            if ($v === null) {
                if ($required) {
                    $bad[] = $k;
                }
                return null;
            }
            if (!is_string($v)) {
                $bad[] = $k;
                return null;
            }
            $v = trim($v);
            if (mb_strlen($v) > $max || mb_strlen($v) < $min) {
                $bad[] = $k;
            }
            return $v;
        };
        $cut = static fn ($v, int $max) => is_string($v) ? mb_substr(trim($v), 0, $max) : null;
        $lines = $in['lines'] ?? null;
        $clean = [];
        if (!is_array($lines) || !$lines || count($lines) > 30) {
            $bad[] = 'lines';
        } else {
            foreach ($lines as $l) {
                $ok = is_array($l) && is_string($l['productId'] ?? null) && $l['productId'] !== '' && strlen($l['productId']) <= 40
                    && (!isset($l['variantId']) || (is_string($l['variantId']) && strlen($l['variantId']) <= 40))
                    && (!isset($l['offerId']) || (is_string($l['offerId']) && strlen($l['offerId']) <= 40))
                    && is_int($l['qty'] ?? null) && $l['qty'] >= 1 && $l['qty'] <= 10;
                if (!$ok) {
                    $bad[] = 'lines';
                    break;
                }
                $clean[] = ['productId' => $l['productId'], 'variantId' => $l['variantId'] ?? null, 'offerId' => $l['offerId'] ?? null, 'qty' => $l['qty']];
            }
        }
        $out = [
            'lines' => $clean,
            'phone' => $str('phone', 30, true),
            'phoneAlt' => $str('phoneAlt', 30),
            'name' => $str('name', 80, true, 2),
            'email' => $str('email', 120),
            'governorate' => $str('governorate', 40, true),
            'city' => $str('city', 80),
            'address' => $str('address', 300, true, 4),
            'note' => $str('note', 300),
            'promoCode' => $str('promoCode', 40),
            'website' => $str('website', 200),
        ];
        if ($out['email'] !== null && $out['email'] !== '' && !filter_var($out['email'], FILTER_VALIDATE_EMAIL)) {
            $bad[] = 'email';
        }
        $payment = $in['payment'] ?? null;
        if (!in_array($payment, ['COD', 'CARD'], true)) {
            $bad[] = 'payment';
        }
        $out['payment'] = $payment;
        $total = $in['expectedTotal'] ?? null;
        if (!is_int($total) || $total < 0) {
            $bad[] = 'expectedTotal';
        }
        $out['expectedTotal'] = $total;
        foreach (['cartToken' => 64, 'visitorId' => 64, 'sessionId' => 64] as $k => $max) {
            $v = $in[$k] ?? null;
            if ($v !== null && (!is_string($v) || strlen($v) > $max)) {
                $bad[] = $k;
            }
            $out[$k] = is_string($v) ? $v : null;
        }
        $restored = $in['restoredCartId'] ?? null;
        if ($restored !== null && (!is_string($restored) || !preg_match('/^[a-z0-9]{20,40}$/', $restored))) {
            $bad[] = 'restoredCartId';
        }
        $out['restoredCartId'] = is_string($restored) ? $restored : null;
        $key = $in['submitKey'] ?? null;
        if ($key !== null && (!is_string($key) || !preg_match('/^[A-Za-z0-9-]{16,64}$/', $key))) {
            $bad[] = 'submitKey';
        }
        $out['submitKey'] = is_string($key) ? $key : null;
        $a = is_array($in['attribution'] ?? null) ? $in['attribution'] : [];
        $out['attribution'] = ['source' => $cut($a['source'] ?? null, 60), 'medium' => $cut($a['medium'] ?? null, 60), 'campaign' => $cut($a['campaign'] ?? null, 120), 'content' => $cut($a['content'] ?? null, 120), 'landing' => $cut($a['landing'] ?? null, 200)];
        $ad = is_array($in['ad'] ?? null) ? $in['ad'] : [];
        $clickId = static fn ($v, int $max) => is_string($v) && mb_strlen(trim($v)) <= $max ? trim($v) : null;
        $out['ad'] = array_filter(['fbp' => $clickId($ad['fbp'] ?? null, 120), 'fbc' => $clickId($ad['fbc'] ?? null, 300), 'ttp' => $clickId($ad['ttp'] ?? null, 120), 'ttclid' => $clickId($ad['ttclid'] ?? null, 300), 'gaClientId' => $clickId($ad['gaClientId'] ?? null, 60)], static fn ($v) => $v !== null);
        $out['consentAds'] = ($in['consentAds'] ?? false) === true;
        $out['locale'] = in_array($in['locale'] ?? 'ar', ['fr', 'ar'], true) ? ($in['locale'] ?? 'ar') : null;
        if ($out['locale'] === null) {
            $bad[] = 'locale';
        }
        $origin = $in['origin'] ?? null;
        if ($origin !== null && !in_array($origin, ['product', 'sheet', 'quick', 'cart', 'admin'], true)) {
            $bad[] = 'origin';
        }
        // "Typed in the back office" is said by the back office only.
        $out['origin'] = $origin === 'admin' ? null : $origin;
        return [$out, array_values(array_unique($bad))];
    }

    /**
     * placeOrder: turns a checked form into an order. $ctx: ua, ip, ipHash, device.
     * @return array ['ok' => true, 'ref', 'token'] or ['ok' => false, 'error', 'fields'?, 'quote'?]
     */
    public static function place(array $in, array $ctx = [], int $attempt = 0): array
    {
        $submitKey = (string) ($in['submitKey'] ?? '');
        if ($submitKey !== '' && ($done = self::bySubmitKey($submitKey))) {
            return ['ok' => true, 'ref' => $done['ref'], 'token' => Tokens::orderToken($done['accessSalt'])];
        }
        $phone = Phone::normalize((string) ($in['phone'] ?? ''));
        $phoneAlt = !empty($in['phoneAlt']) ? Phone::normalize((string) $in['phoneAlt']) : null;
        $gov = (string) ($in['governorate'] ?? '');
        $bad = [];
        if (!$phone) {
            $bad[] = 'phone';
        }
        if (!empty($in['phoneAlt']) && !$phoneAlt) {
            $bad[] = 'phoneAlt';
        }
        if (!Gov::valid($gov)) {
            $bad[] = 'governorate';
        }
        if ($bad) {
            return ['ok' => false, 'error' => 'invalid', 'fields' => $bad];
        }

        $settings = Settings::all();
        $lines = $in['lines'];
        $promoCode = ($in['promoCode'] ?? '') !== '' ? $in['promoCode'] : null;
        $quote = Pricing::quoteCart($lines, $gov, $promoCode, $phone);
        $pq = Pricing::publicQuote($quote);
        if (!$quote['lines']) {
            return ['ok' => false, 'error' => 'empty', 'quote' => $pq];
        }
        if ($quote['problems']) {
            return ['ok' => false, 'error' => 'cart_changed', 'quote' => $pq];
        }
        if ($quote['deliveryUnavailable'] || !$quote['delivery']) {
            return ['ok' => false, 'error' => 'delivery_unavailable', 'quote' => $pq];
        }
        if ($promoCode !== null && $quote['promo'] && !$quote['promo']['result']['ok']) {
            return ['ok' => false, 'error' => 'promo_invalid', 'quote' => $pq];
        }
        if ($quote['total'] !== (int) ($in['expectedTotal'] ?? -1)) {
            return ['ok' => false, 'error' => 'price_changed', 'quote' => $pq];
        }
        // The order form offers cash on delivery only (as the Next.js shop's CARD_IN_FORM = false).
        if (($in['payment'] ?? 'COD') === 'CARD') {
            return ['ok' => false, 'error' => 'card_unavailable', 'quote' => $pq];
        }

        $salt = Tokens::random();
        $token = Tokens::orderToken($salt);
        $deliveryQuote = Settings::rangeLabel($quote['delivery']['minDays'], $quote['delivery']['maxDays'], 'fr');
        $productIds = array_values(array_unique(array_column($quote['lines'], 'productId')));
        $now = Db::now();
        $tookStock = false;
        $a = $in['attribution'] ?? [];

        try {
            $order = Db::tx(static function () use ($in, $ctx, $phone, $phoneAlt, $gov, $quote, $settings, $salt, $token, $deliveryQuote, $productIds, $now, $submitKey, $a, &$tookStock) {
                // Serializable, as the Next.js shop: two orders for the last unit cannot both pass.
                Db::run('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                $customer = Db::one('SELECT id, blacklisted FROM "Customer" WHERE phone = ?', [$phone]);
                if (!$customer) {
                    $customer = ['id' => new_id(), 'blacklisted' => false];
                    Db::insert('Customer', ['id' => $customer['id'], 'phone' => $phone, 'name' => $in['name'], 'email' => ($in['email'] ?? '') !== '' ? $in['email'] : null, 'createdAt' => $now, 'updatedAt' => $now]);
                }
                $delivered = (int) Db::value('SELECT count(*) FROM "Order" WHERE "customerId" = ? AND status = \'DELIVERED\'', [$customer['id']]);
                $refused = (int) Db::value('SELECT count(*) FROM "Order" WHERE "customerId" = ? AND status = \'REFUSED\'', [$customer['id']]);
                $last24h = (int) Db::value('SELECT count(*) FROM "Order" WHERE "customerId" = ? AND "createdAt" >= ?', [$customer['id'], Db::now(-86400)]);
                $dup = (int) Db::value(
                    'SELECT count(*) FROM "OrderItem" i JOIN "Order" o ON o.id = i."orderId" WHERE o."customerId" = ? AND o."createdAt" >= ? AND o.status IN (\'PENDING\',\'CONFIRMED\',\'SUPPLIER_ORDERED\',\'SHIPPED\') AND i."productId" IN (' . Db::marks($productIds) . ')',
                    [$customer['id'], Db::now(-2 * 86400), ...$productIds]
                );
                $flags = self::flags(
                    ['blacklisted' => Db::bool($customer['blacklisted']), 'delivered' => $delivered, 'refused' => $refused, 'last24h' => $last24h, 'duplicateOpen' => $dup > 0],
                    (int) $settings['checkout']['maxOrdersPerPhonePerDay'],
                    Phone::isMobile($phone)
                );

                // Counted stock leaves the shelf with the order, or the order does not happen.
                foreach ($quote['lines'] as $l) {
                    $mode = Db::value('SELECT "stockMode" FROM "Product" WHERE id = ?', [$l['productId']]);
                    if ($mode !== 'TRACKED') {
                        continue;
                    }
                    $n = $l['variantId'] !== null
                        ? Db::run('UPDATE "ProductVariant" SET "stockQty" = "stockQty" - ? WHERE id = ? AND "stockQty" >= ?', [$l['qty'], $l['variantId'], $l['qty']])->rowCount()
                        : Db::run('UPDATE "Product" SET "stockQty" = "stockQty" - ?, "updatedAt" = ? WHERE id = ? AND "stockQty" >= ?', [$l['qty'], $now, $l['productId'], $l['qty']])->rowCount();
                    if ($n === 0) {
                        throw new StockGone();
                    }
                    $tookStock = true;
                }

                $promoCodeId = null;
                if ($quote['promo'] && $quote['promo']['result']['ok']) {
                    $promo = Db::one('SELECT id, "maxUses" FROM "PromoCode" WHERE code = ?', [$quote['promo']['code']]);
                    if ($promo) {
                        $n = $promo['maxUses'] !== null
                            ? Db::run('UPDATE "PromoCode" SET "usedCount" = "usedCount" + 1 WHERE id = ? AND "usedCount" < ?', [$promo['id'], (int) $promo['maxUses']])->rowCount()
                            : Db::run('UPDATE "PromoCode" SET "usedCount" = "usedCount" + 1 WHERE id = ?', [$promo['id']])->rowCount();
                        if ($n === 0) {
                            throw new StockGone();
                        }
                        $promoCodeId = $promo['id'];
                    }
                }

                $orderId = new_id();
                $ref = Tokens::orderRef();
                $consent = !empty($in['consentAds']);
                Db::insert('Order', [
                    'id' => $orderId,
                    'ref' => $ref,
                    'customerId' => $customer['id'],
                    'name' => $in['name'],
                    'phone' => $phone,
                    'phoneAlt' => $phoneAlt,
                    'email' => ($in['email'] ?? '') !== '' ? $in['email'] : null,
                    'governorate' => $gov,
                    'city' => $in['city'] ?? '',
                    'address' => $in['address'],
                    'note' => ($in['note'] ?? '') !== '' ? $in['note'] : null,
                    'subtotal' => $quote['subtotal'],
                    'discount' => $quote['discount'],
                    'deliveryFee' => $quote['deliveryFee'],
                    'total' => $quote['total'],
                    'costTotal' => $quote['costTotal'],
                    'promoCodeId' => $promoCodeId,
                    'promoCodeText' => $promoCodeId ? $quote['promo']['code'] : null,
                    'paymentMethod' => 'COD',
                    'paymentStatus' => 'UNPAID',
                    'deliveryQuote' => $deliveryQuote,
                    'flags' => Db::pgArray($flags),
                    'accessSalt' => $salt,
                    'accessHash' => Tokens::hash($token),
                    'submitKeyHash' => $submitKey !== '' ? Tokens::hash($submitKey) : null,
                    'source' => ($a['source'] ?? '') ?: 'direct',
                    'medium' => ($a['medium'] ?? '') ?: null,
                    'campaign' => ($a['campaign'] ?? '') ?: null,
                    'content' => ($a['content'] ?? '') ?: null,
                    'landing' => ($a['landing'] ?? '') ?: null,
                    'device' => $ctx['device'] ?? null,
                    'visitorId' => ($in['visitorId'] ?? '') ?: null,
                    'sessionId' => ($in['sessionId'] ?? '') ?: null,
                    'locale' => $in['locale'] ?? 'ar',
                    'ipHash' => $ctx['ipHash'] ?? null,
                    'consentAds' => $consent ? 'true' : 'false',
                    'adContext' => $consent ? json_encode(($in['ad'] ?? []) + array_filter(['ua' => $ctx['ua'] ?? null, 'ip' => $ctx['ip'] ?? null]), JSON_UNESCAPED_SLASHES) : null,
                    'createdAt' => $now,
                    'updatedAt' => $now,
                ]);
                foreach ($quote['lines'] as $l) {
                    Db::insert('OrderItem', [
                        'id' => new_id(), 'orderId' => $orderId, 'productId' => $l['productId'], 'variantId' => $l['variantId'],
                        'name' => $l['name'], 'variantName' => $l['variantName'], 'unitPrice' => $l['unitPrice'], 'unitCost' => $l['unitCost'],
                        'qty' => $l['qty'], 'lineTotal' => $l['lineTotal'], 'offerId' => $l['offerId'], 'offerLabel' => $l['offerLabel'], 'offerLabelAr' => $l['offerLabelAr'],
                    ]);
                }
                Db::insert('OrderEvent', ['id' => new_id(), 'orderId' => $orderId, 'from' => null, 'to' => 'PENDING', 'by' => 'client', 'createdAt' => $now]);
                if (!empty($in['restoredCartId'])) {
                    Db::run('UPDATE "Cart" SET status = \'CONVERTED\', "orderId" = ?, "updatedAt" = ? WHERE id = ? AND status = \'ACTIVE\'', [$orderId, $now, $in['restoredCartId']]);
                }
                if (!empty($in['cartToken'])) {
                    Db::run('UPDATE "Cart" SET status = \'CONVERTED\', "orderId" = ?, phone = ?, "updatedAt" = ? WHERE "tokenHash" = ?', [$orderId, $phone, $now, Tokens::hash((string) $in['cartToken'])]);
                }
                return ['id' => $orderId, 'ref' => $ref];
            });
        } catch (StockGone) {
            return ['ok' => false, 'error' => 'cart_changed', 'quote' => Pricing::publicQuote(Pricing::quoteCart($lines, $gov, $promoCode, $phone))];
        } catch (PDOException $e) {
            // A serialization conflict (two orders for the last unit), a ref collision, or the same form arriving twice at once: try again.
            if (in_array($e->getCode(), ['40001', '23505'], true) && $attempt < 2) {
                return self::place($in, $ctx, $attempt + 1);
            }
            error_log('[order] placement failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'server'];
        }
        Cache::flush();

        // Forms left with the same number in another browser: no reminder after an order.
        try {
            Db::run('UPDATE "Cart" SET status = \'CONVERTED\', "orderId" = ?, "updatedAt" = ? WHERE status = \'ACTIVE\' AND phone = ? AND "updatedAt" >= ?', [$order['id'], Db::now(), $phone, Db::now(-30 * 86400)]);
        } catch (Throwable $e) {
            error_log('[order] closing open forms failed: ' . $e->getMessage());
        }
        // The shop's own statistics: the order, where it came from.
        Events::server('order_placed', ['id' => $order['id'], 'total' => $quote['total'], 'visitorId' => ($in['visitorId'] ?? '') ?: null, 'sessionId' => ($in['sessionId'] ?? '') ?: null, 'source' => ($a['source'] ?? '') ?: 'direct', 'medium' => ($a['medium'] ?? '') ?: null, 'campaign' => ($a['campaign'] ?? '') ?: null, 'device' => $ctx['device'] ?? null, 'governorate' => $gov], [
            'items' => array_sum(array_column($quote['lines'], 'qty')),
            'lines' => count($quote['lines']),
            'payment' => 'COD',
            'promo' => (bool) $quote['discount'],
            'origin' => $in['origin'] ?? 'product',
            'restored' => !empty($in['restoredCartId']),
        ]);
        Notify::orderPlaced($order['id']);
        return ['ok' => true, 'ref' => $order['ref'], 'token' => $token];
    }

    /** The order a tracking-link token opens, with its lines and history. */
    public static function byToken(string $token): ?array
    {
        if (!Tokens::looksLikeToken($token)) {
            return null;
        }
        $o = Db::one('SELECT * FROM "Order" WHERE "accessHash" = ?', [Tokens::hash($token)]);
        return $o ? self::withLines($o) : null;
    }

    /** The order from its reference and the full phone number: both are needed. */
    public static function byRefAndPhone(string $ref, string $phone): ?array
    {
        $p = Phone::normalize($phone);
        $ref = strtoupper(trim($ref));
        if (!$p || !preg_match('/^MN-[A-Z0-9]{6}$/', $ref)) {
            return null;
        }
        $o = Db::one('SELECT * FROM "Order" WHERE ref = ? AND phone = ?', [$ref, $p]);
        return $o ? self::withLines($o) : null;
    }

    public static function withLines(array $o): array
    {
        $o['items'] = Db::all('SELECT * FROM "OrderItem" WHERE "orderId" = ? ORDER BY id', [$o['id']]);
        $o['flags'] = Db::parseArray($o['flags'] ?? null);
        return $o;
    }

    /**
     * Moves an order to a new status, if that move is allowed from where it is
     * now. "Where status = what we read" makes two admins clicking at once harmless.
     * Cancelled, refused and returned orders put counted stock back.
     */
    public static function transition(string $orderId, string $to, string $by, array $extra = []): string
    {
        $o = Db::one('SELECT id, status FROM "Order" WHERE id = ?', [$orderId]);
        if (!$o) {
            return 'not_found';
        }
        if (!in_array($to, self::TRANSITIONS[$o['status']] ?? [], true)) {
            return 'not_allowed';
        }
        $now = Db::now();
        $restock = in_array($to, ['CANCELLED', 'REFUSED', 'RETURNED'], true);
        $ok = Db::tx(static function () use ($o, $to, $by, $extra, $now, $restock) {
            $set = ['status' => $to, 'updatedAt' => $now];
            $stamp = ['CONFIRMED' => 'confirmedAt', 'SHIPPED' => 'shippedAt', 'DELIVERED' => 'deliveredAt', 'REFUSED' => 'refusedAt', 'CANCELLED' => 'cancelledAt'][$to] ?? null;
            if ($stamp) {
                $set[$stamp] = $now;
            }
            if ($to === 'SHIPPED') {
                if (($extra['carrier'] ?? '') !== '') {
                    $set['carrier'] = mb_substr($extra['carrier'], 0, 80);
                }
                if (($extra['trackingNumber'] ?? '') !== '') {
                    $set['trackingNumber'] = mb_substr($extra['trackingNumber'], 0, 80);
                }
            }
            if (Db::update('Order', $set, 'id = ? AND status = ?', [$o['id'], $o['status']]) === 0) {
                return false;
            }
            Db::insert('OrderEvent', ['id' => new_id(), 'orderId' => $o['id'], 'from' => $o['status'], 'to' => $to, 'by' => $by, 'note' => ($extra['note'] ?? '') !== '' ? mb_substr($extra['note'], 0, 500) : null, 'createdAt' => $now]);
            if ($restock) {
                foreach (Db::all('SELECT "productId", "variantId", qty FROM "OrderItem" WHERE "orderId" = ?', [$o['id']]) as $i) {
                    if (!$i['productId'] || Db::value('SELECT "stockMode" FROM "Product" WHERE id = ?', [$i['productId']]) !== 'TRACKED') {
                        continue;
                    }
                    if ($i['variantId']) {
                        Db::run('UPDATE "ProductVariant" SET "stockQty" = "stockQty" + ? WHERE id = ?', [(int) $i['qty'], $i['variantId']]);
                    } else {
                        Db::run('UPDATE "Product" SET "stockQty" = "stockQty" + ?, "updatedAt" = ? WHERE id = ?', [(int) $i['qty'], $now, $i['productId']]);
                    }
                }
            }
            return true;
        });
        if ($ok && $restock) {
            Cache::flush();
        }
        return $ok ? 'ok' : 'conflict';
    }
}

final class StockGone extends RuntimeException
{
}
