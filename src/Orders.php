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
     * @param array $in name, phone, governorate, address, lines, expectedTotal, submitKey, locale, source, campaign
     * @return array ['ok' => true, 'ref', 'token'] or ['ok' => false, 'error', 'fields'?, 'quote'?]
     */
    public static function place(array $in, int $attempt = 0): array
    {
        $submitKey = (string) ($in['submitKey'] ?? '');
        if ($submitKey !== '' && ($done = self::bySubmitKey($submitKey))) {
            return ['ok' => true, 'ref' => $done['ref'], 'token' => Tokens::orderToken($done['accessSalt'])];
        }

        $phone = Phone::normalize((string) ($in['phone'] ?? ''));
        $name = trim((string) ($in['name'] ?? ''));
        $address = trim((string) ($in['address'] ?? ''));
        $gov = (string) ($in['governorate'] ?? '');
        $bad = [];
        if (mb_strlen($name) < 2) {
            $bad[] = 'name';
        }
        if (!$phone) {
            $bad[] = 'phone';
        }
        if (!Gov::valid($gov)) {
            $bad[] = 'governorate';
        }
        if (mb_strlen($address) < 5) {
            $bad[] = 'address';
        }
        if ($bad) {
            return ['ok' => false, 'error' => 'invalid', 'fields' => $bad];
        }

        $settings = Settings::all();
        $lines = $in['lines'] ?? [];
        $catalog = Catalog::forPricing(array_column($lines, 'productId'));
        $quote = Pricing::quote($lines, $catalog, $gov, $settings['delivery']);
        if (!$quote['lines']) {
            return ['ok' => false, 'error' => 'empty', 'quote' => $quote];
        }
        if ($quote['problems']) {
            return ['ok' => false, 'error' => 'cart_changed', 'quote' => $quote];
        }
        if ($quote['deliveryUnavailable'] || !$quote['delivery']) {
            return ['ok' => false, 'error' => 'delivery_unavailable', 'quote' => $quote];
        }
        if ($quote['total'] !== (int) ($in['expectedTotal'] ?? -1)) {
            return ['ok' => false, 'error' => 'price_changed', 'quote' => $quote];
        }

        $salt = Tokens::random();
        $token = Tokens::orderToken($salt);
        $deliveryQuote = Settings::rangeLabel($quote['delivery']['minDays'], $quote['delivery']['maxDays'], 'fr');
        $productIds = array_values(array_unique(array_column($quote['lines'], 'productId')));
        $now = Db::now();
        $tookStock = false;

        try {
            $ref = Db::tx(static function () use ($in, $phone, $name, $address, $gov, $quote, $settings, $salt, $token, $deliveryQuote, $productIds, $now, $submitKey, &$tookStock) {
                // Serializable, as the Next.js shop: two orders for the last unit cannot both pass.
                Db::run('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                $customer = Db::one('SELECT id, blacklisted FROM "Customer" WHERE phone = ?', [$phone]);
                if (!$customer) {
                    $customer = ['id' => new_id(), 'blacklisted' => false];
                    Db::insert('Customer', ['id' => $customer['id'], 'phone' => $phone, 'name' => $name, 'createdAt' => $now, 'updatedAt' => $now]);
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

                $orderId = new_id();
                $ref = Tokens::orderRef();
                Db::insert('Order', [
                    'id' => $orderId,
                    'ref' => $ref,
                    'customerId' => $customer['id'],
                    'name' => $name,
                    'phone' => $phone,
                    'governorate' => $gov,
                    'city' => '',
                    'address' => $address,
                    'subtotal' => $quote['subtotal'],
                    'discount' => $quote['discount'],
                    'deliveryFee' => $quote['deliveryFee'],
                    'total' => $quote['total'],
                    'costTotal' => $quote['costTotal'],
                    'deliveryQuote' => $deliveryQuote,
                    'flags' => Db::pgArray($flags),
                    'accessSalt' => $salt,
                    'accessHash' => Tokens::hash($token),
                    'submitKeyHash' => $submitKey !== '' ? Tokens::hash($submitKey) : null,
                    'source' => ($in['source'] ?? '') !== '' ? mb_substr((string) $in['source'], 0, 80) : 'direct',
                    'campaign' => ($in['campaign'] ?? '') !== '' ? mb_substr((string) $in['campaign'], 0, 120) : null,
                    'device' => $in['device'] ?? null,
                    'locale' => ($in['locale'] ?? 'fr') === 'ar' ? 'ar' : 'fr',
                    'ipHash' => $in['ipHash'] ?? null,
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
                return $ref;
            });
        } catch (StockGone) {
            $fresh = Pricing::quote($lines, Catalog::forPricing(array_column($lines, 'productId')), $gov, $settings['delivery']);
            return ['ok' => false, 'error' => 'cart_changed', 'quote' => $fresh];
        } catch (PDOException $e) {
            // A serialization conflict (two orders for the last unit), a ref collision, or the same form arriving twice at once: try again.
            if (in_array($e->getCode(), ['40001', '23505'], true) && $attempt < 2) {
                return self::place($in, $attempt + 1);
            }
            error_log('[order] placement failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'server'];
        }
        // Counted stock changed: pages show it again from the database.
        if ($tookStock) {
            Cache::flush();
        }
        return ['ok' => true, 'ref' => $ref, 'token' => $token];
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
