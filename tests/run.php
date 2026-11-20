<?php
declare(strict_types=1);

/**
 * The tests:   MENA_CONFIG=/path/to/config.local.php php tests/run.php
 *
 * 1. Rules (no database): money, phone numbers, offer names, prices. Offer
 *    names and prices are checked against answers recorded from the Next.js
 *    shop's own code (tests/fixtures/next-parity.json): both versions must
 *    price a basket the same way.
 * 2. An order placed for real, then cancelled (stock comes back) — only
 *    against a database on this computer: the tests refuse any other.
 */

$GLOBALS['MENA_TESTING'] = true;
// The tests read the database itself, never the pages' one-minute copy.
$GLOBALS['MENA_NO_CACHE'] = true;
require dirname(__DIR__) . '/src/bootstrap.php';

$failed = 0;
$passed = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed, $passed;
    if ($ok) {
        $passed++;
        return;
    }
    $failed++;
    echo "FAIL $name" . ($detail !== '' ? "\n     $detail" : '') . "\n";
}
function same(string $name, mixed $got, mixed $want): void
{
    check($name, $got === $want, 'got ' . json_encode($got, JSON_UNESCAPED_UNICODE) . ', want ' . json_encode($want, JSON_UNESCAPED_UNICODE));
}

// ------------------------------------------------------------------ 1. rules

same('money fr', Money::format(89900, 'fr'), '89,900 DT');
same('money ar', Money::format(8000, 'ar'), '8,000 د.ت');
same('money thousands', Money::format(2100000, 'fr'), '2100,000 DT');
same('money negative', Money::format(-54000, 'fr'), "\u{2212}54,000 DT");
same('parse comma', Money::parse('89,9'), 89900);
same('parse dot', Money::parse('89.900'), 89900);
same('parse spaces and DT', Money::parse('1 200 DT'), 1200000);
same('parse junk', Money::parse('abc'), null);

same('phone +216', Phone::normalize('+216 22 123 456'), '22123456');
same('phone 00216', Phone::normalize('0021622123456'), '22123456');
same('phone arabic digits', Phone::normalize('٢٢١٢٣٤٥٦'), '22123456');
same('phone leading 0', Phone::normalize('022123456'), '22123456');
same('phone fake', Phone::normalize('22222222'), null);
same('phone bad prefix', Phone::normalize('12345678'), null);
same('phone mobile', Phone::isMobile('22123456'), true);
same('phone landline', Phone::isMobile('71123456'), false);

$fx = json_decode((string) file_get_contents(__DIR__ . '/fixtures/next-parity.json'), true);
foreach ($fx['offerCases'] as $c) {
    $tag = "offer «{$c['label']}» q={$c['quantity']}";
    same("$tag units", OfferLabel::unitsIn($c['label']), $c['units']);
    same("$tag fits", OfferLabel::fits($c['label'], $c['quantity']), $c['fits']);
    same("$tag adds", OfferLabel::adds($c['label']), $c['adds']);
    same("$tag line fr", OfferLabel::line(['quantity' => $c['quantity'], 'label' => $c['label'], 'labelAr' => null], 'fr'), $c['lineFr']);
    same("$tag line ar", OfferLabel::line(['quantity' => $c['quantity'], 'label' => null, 'labelAr' => $c['label']], 'ar'), $c['lineAr']);
}

foreach ($fx['pricing'] as $i => $c) {
    $catalog = [];
    foreach ($fx['catalogs'][$c['catalog']] as $p) {
        $catalog[$p['id']] = $p;
    }
    $q = Pricing::quote($c['lines'], $catalog, $c['governorate'], ['fee' => $c['fee'], 'freeThreshold' => $c['threshold'], 'zones' => $fx['zones']]);
    $e = $c['expect'];
    $tag = "pricing #$i ({$c['catalog']})";
    foreach (['subtotal', 'deliveryFee', 'total', 'deliveryUnavailable', 'delivery', 'freeDeliveryRemaining', 'costTotal'] as $k) {
        same("$tag $k", $q[$k], $e[$k]);
    }
    same("$tag lines", array_map(static fn ($l) => ['productId' => $l['productId'], 'variantId' => $l['variantId'], 'offerId' => $l['offerId'], 'qty' => $l['qty'], 'unitPrice' => $l['unitPrice'], 'lineTotal' => $l['lineTotal'], 'offerLabel' => $l['offerLabel'], 'offerLabelAr' => $l['offerLabelAr'], 'freeDelivery' => $l['freeDelivery']], $q['lines']), $e['lines']);
    same("$tag problems", array_map(static fn ($p) => ['productId' => $p['productId'], 'variantId' => $p['variantId'], 'kind' => $p['kind'], 'available' => $p['available'] ?? null], $q['problems']), array_map(static fn ($p) => $p + ['available' => null], $e['problems']));
}

// Every word with a value, in both languages, without a single PHP warning (an Arabic letter right after
// "$name" in a string becomes part of the name: the text then loses its value).
set_error_handler(static function (int $no, string $msg) { throw new ErrorException($msg, 0, $no); });
foreach (['fr', 'ar'] as $l) {
    $GLOBALS['MENA_LANG'] = $l;
    $made = 0;
    $walk = static function ($node, string $path) use (&$walk, &$made, $l) {
        if (is_array($node)) {
            foreach ($node as $k => $v) {
                $walk($v, $path === '' ? (string) $k : "$path.$k");
            }
            return;
        }
        if (!$node instanceof Closure) {
            return;
        }
        $args = array_map(static fn ($p) => ($p->getType()?->getName() === 'int') ? 3 : 'VALUE', (new ReflectionFunction($node))->getParameters());
        try {
            $text = (string) $node(...$args);
            check("text $l $path shows its values", !in_array('VALUE', $args, true) || str_contains($text, 'VALUE'), $text);
            $made++;
        } catch (Throwable $e) {
            check("text $l $path", false, $e->getMessage());
        }
    };
    $walk(Lang::dict($l), '');
    check("texts $l found", $made > 20, "$made");
}
restore_error_handler();

$GLOBALS['MENA_LANG'] = 'fr';
same('range fr', Settings::rangeLabel(1, 2, 'fr'), '1 à 2 jours ouvrés');
same('range ar', Settings::rangeLabel(2, 2, 'ar'), 'خلال 2 أيام عمل');
same('pg array', Db::parseArray(Db::pgArray(['a', 'b c', 'd"e'])), ['a', 'b c', 'd"e']);
same('pg array empty', Db::parseArray('{}'), []);

// The same tokens as the Next.js shop (same secret, same formats).
if (Tokens::hasSecret()) {
    same('token length', strlen(Tokens::orderToken('salt')), 32);
    check('ref format', (bool) preg_match('/^MN-[23456789A-HJ-NP-Z]{6}$/', Tokens::orderRef()));
}

// ------------------------------------------------- 2. an order, for real

$url = (string) config('database_url', '');
$host = (string) (parse_url($url, PHP_URL_HOST) ?? '');
if ($url === '') {
    echo "skip  database tests: no database_url\n";
} elseif (!Db::isLocal($host)) {
    // Never against the real shop's database.
    echo "REFUSED database tests: database_url is not on this computer ($host). Use a local test database.\n";
    $failed++;
} else {
    Settings::forget();
    $p = Db::one('SELECT id, price FROM "Product" p WHERE active = true AND "stockMode" = \'SUPPLIER\' AND available = true AND NOT EXISTS (SELECT 1 FROM "ProductVariant" v WHERE v."productId" = p.id) ORDER BY "createdAt" LIMIT 1');
    if (!$p) {
        echo "skip  order test: no product in the test database\n";
    } else {
        $fee = (int) Settings::get('delivery.fee');
        $line = [['productId' => $p['id'], 'variantId' => null, 'offerId' => null, 'qty' => 2]];
        $wrong = Orders::place(['name' => 'Php Test', 'phone' => '29 111 222', 'governorate' => 'Sousse', 'address' => 'Sousse, rue des tests', 'lines' => $line, 'expectedTotal' => 1]);
        same('order: a total the customer did not see is refused', $wrong['error'] ?? null, 'price_changed');
        $expected = $wrong['quote']['total'];
        same('order: total = 2 × price + delivery', $expected, 2 * (int) $p['price'] + ($wrong['quote']['deliveryFee']));
        $key = Tokens::random(18);
        $ok = Orders::place(['name' => 'Php Test', 'phone' => '29 111 222', 'governorate' => 'Sousse', 'address' => 'Sousse, rue des tests', 'lines' => $line, 'expectedTotal' => $expected, 'submitKey' => $key]);
        check('order placed', $ok['ok'] ?? false, json_encode($ok));
        $again = Orders::place(['name' => 'Php Test', 'phone' => '29 111 222', 'governorate' => 'Sousse', 'address' => 'Sousse, rue des tests', 'lines' => $line, 'expectedTotal' => $expected, 'submitKey' => $key]);
        same('order: the same form twice makes one order', $again['ref'] ?? null, $ok['ref'] ?? 'x');
        $o = Orders::byToken($ok['token']);
        same('order: found from its tracking token', $o['ref'] ?? null, $ok['ref']);
        same('order: found from ref + phone', Orders::byRefAndPhone($ok['ref'], '+216 29 111 222')['ref'] ?? null, $ok['ref']);
        same('order: not found with another phone', Orders::byRefAndPhone($ok['ref'], '29 111 223'), null);
        same('order: one line of 2', [count($o['items']), (int) $o['items'][0]['qty']], [1, 2]);
        same('order: history starts at PENDING', Db::value('SELECT "to" FROM "OrderEvent" WHERE "orderId" = ?', [$o['id']]), 'PENDING');
        same('order: cannot jump to DELIVERED', Orders::transition($o['id'], 'DELIVERED', 'test'), 'not_allowed');
        same('order: confirmed', Orders::transition($o['id'], 'CONFIRMED', 'test'), 'ok');
        same('order: cancelled', Orders::transition($o['id'], 'CANCELLED', 'test'), 'ok');
        same('order: flags are a list', is_array(Orders::withLines(Db::one('SELECT * FROM "Order" WHERE id = ?', [$o['id']]))['flags']), true);

        // Counted stock: taken with the order, given back on cancel.
        $t = Db::one('SELECT id, price, "stockQty" FROM "Product" p WHERE active = true AND "stockMode" = \'TRACKED\' AND "stockQty" >= 2 AND NOT EXISTS (SELECT 1 FROM "ProductVariant" v WHERE v."productId" = p.id) LIMIT 1');
        if ($t) {
            $q = Pricing::quote([['productId' => $t['id'], 'qty' => 1]], Catalog::forPricing([$t['id']]), 'Sfax', Settings::get('delivery'));
            $r = Orders::place(['name' => 'Php Stock', 'phone' => '29 111 333', 'governorate' => 'Sfax', 'address' => 'Sfax, rue des tests', 'lines' => [['productId' => $t['id'], 'qty' => 1]], 'expectedTotal' => $q['total']]);
            same('stock: taken by the order', (int) Db::value('SELECT "stockQty" FROM "Product" WHERE id = ?', [$t['id']]), (int) $t['stockQty'] - 1);
            $oid = Db::value('SELECT id FROM "Order" WHERE ref = ?', [$r['ref']]);
            Orders::transition($oid, 'CANCELLED', 'test');
            same('stock: back after cancel', (int) Db::value('SELECT "stockQty" FROM "Product" WHERE id = ?', [$t['id']]), (int) $t['stockQty']);
        }
        same('order: invalid fields named', Orders::place(['name' => '', 'phone' => '123', 'governorate' => 'Paris', 'address' => '', 'lines' => $line, 'expectedTotal' => 0])['fields'] ?? null, ['name', 'phone', 'governorate', 'address']);
    }
}

echo ($failed === 0 ? 'OK' : 'FAILED') . " — $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
