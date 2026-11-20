<?php
declare(strict_types=1);

/**
 * The back office: orders, products (photos, offers, options), categories,
 * settings. Every page checks the session; every form carries the CSRF token.
 * It edits the same rows as the Next.js back office.
 */
final class Admin
{
    public static function routes(Router $r): void
    {
        $r->get('/admin/connexion', [self::class, 'login']);
        $r->post('/admin/connexion', [self::class, 'login']);
        $r->post('/admin/deconnexion', [self::class, 'logout']);
        $r->get('/admin', [self::class, 'dashboard']);
        $r->get('/admin/commandes', [self::class, 'orders']);
        $r->get('/admin/commandes/{id}', [self::class, 'order']);
        $r->post('/admin/commandes/{id}', [self::class, 'orderAction']);
        $r->get('/admin/produits', [self::class, 'products']);
        $r->get('/admin/produits/nouveau', [self::class, 'productNew']);
        $r->post('/admin/produits/nouveau', [self::class, 'productCreate']);
        $r->get('/admin/produits/{id}', [self::class, 'product']);
        $r->post('/admin/produits/{id}', [self::class, 'productAction']);
        $r->get('/admin/categories', [self::class, 'categories']);
        $r->post('/admin/categories', [self::class, 'categoriesAction']);
        $r->get('/admin/parametres', [self::class, 'settings']);
        $r->post('/admin/parametres', [self::class, 'settingsSave']);
    }

    private static function page(string $template, array $data, string $title): void
    {
        View::render("admin/$template", $data + ['title' => $title, 'admin' => Auth::current()], 'admin/layout');
    }

    private static function flash(string $msg, bool $ok = true): void
    {
        Cart::session();
        $_SESSION['admin_flash'] = [$msg, $ok];
    }

    /** Every POST: signed in, and the form came from this site. */
    private static function guardPost(): array
    {
        $u = Auth::require();
        if (!Csrf::valid()) {
            self::flash('La page était trop ancienne : recommencez.', false);
            // Back to the same page: its path only, never a host from the request.
            $back = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/admin', PHP_URL_PATH);
            redirect(str_starts_with($back, '/admin') ? $back : '/admin');
        }
        return $u;
    }

    // ------------------------------------------------------------------ session

    public static function login(): void
    {
        if (Auth::current()) {
            redirect('/admin');
        }
        $error = null;
        if (is_post()) {
            if (!Tokens::hasSecret()) {
                $error = "Le serveur n'a pas sa clé app_secret (32 caractères ou plus) dans config.php.";
            } elseif (!Csrf::valid()) {
                $error = 'La page était trop ancienne : recommencez.';
            } elseif (!RateLimit::hit('login', client_ip())) {
                $error = 'Trop de tentatives. Réessayez dans 15 minutes.';
            } else {
                $u = Auth::verify(post_str('email', 190), post_str('password', 200));
                if ($u) {
                    Auth::login($u['id']);
                    redirect('/admin');
                }
                $error = 'E-mail ou mot de passe incorrect.';
            }
        }
        View::render('admin/login', ['error' => $error, 'title' => 'Connexion'], null);
    }

    public static function logout(): void
    {
        if (Csrf::valid()) {
            Auth::logout();
        }
        redirect('/admin/connexion');
    }

    // ---------------------------------------------------------------- dashboard

    public static function dashboard(): void
    {
        Auth::require();
        $midnight = (new DateTimeImmutable('today', new DateTimeZone('Africa/Tunis')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $today = Db::one('SELECT count(*) AS n, coalesce(sum(total), 0) AS total FROM "Order" WHERE "createdAt" >= ? AND status <> \'CANCELLED\'', [$midnight]);
        $byStatus = array_column(Db::all('SELECT status, count(*) AS n FROM "Order" WHERE status IN (\'PENDING\',\'CONFIRMED\',\'SUPPLIER_ORDERED\',\'SHIPPED\') GROUP BY status'), 'n', 'status');
        $toConfirm = Db::all('SELECT id, ref, name, phone, total, governorate, flags, "createdAt", "confirmAttempts" FROM "Order" WHERE status = \'PENDING\' ORDER BY "createdAt" ASC LIMIT 8');
        $lowStock = Db::all('SELECT p.id, p.name, p."stockMode", p."stockQty" FROM "Product" p WHERE p.active = true AND ((p."stockMode" = \'TRACKED\' AND p."stockQty" <= 5 AND NOT EXISTS (SELECT 1 FROM "ProductVariant" v WHERE v."productId" = p.id)) OR (p."stockMode" = \'SUPPLIER\' AND p.available = false)) ORDER BY p.name LIMIT 8');
        self::page('dashboard', compact('today', 'byStatus', 'toConfirm', 'lowStock'), 'Tableau de bord');
    }

    // ------------------------------------------------------------------- orders

    public static function orders(): void
    {
        Auth::require();
        $status = get_str('statut', 20);
        $q = get_str('q', 60);
        $where = ['true'];
        $params = [];
        if (array_key_exists($status, Orders::STATUS_LABELS)) {
            $where[] = 'status = ?';
            $params[] = $status;
        }
        if ($q !== '') {
            $phone = Phone::normalize($q);
            $where[] = '(ref ILIKE ? OR name ILIKE ? OR phone = ?)';
            array_push($params, '%' . addcslashes(strtoupper($q), '%_\\') . '%', '%' . addcslashes($q, '%_\\') . '%', $phone ?? $q);
        }
        $pageNo = max(1, (int) get_str('page'));
        $w = implode(' AND ', $where);
        $total = (int) Db::value("SELECT count(*) FROM \"Order\" WHERE $w", $params);
        $rows = Db::all("SELECT id, ref, name, phone, governorate, total, status, flags, \"createdAt\", \"confirmAttempts\" FROM \"Order\" WHERE $w ORDER BY \"createdAt\" DESC LIMIT 50 OFFSET " . (($pageNo - 1) * 50), $params);
        self::page('orders', ['rows' => $rows, 'status' => $status, 'q' => $q, 'pageNo' => $pageNo, 'pages' => max(1, (int) ceil($total / 50)), 'total' => $total], 'Commandes');
    }

    public static function order(string $id): void
    {
        Auth::require();
        $o = Db::one('SELECT * FROM "Order" WHERE id = ?', [$id]);
        if (!$o) {
            Shop::notFound();
            return;
        }
        $o = Orders::withLines($o);
        $events = Db::all('SELECT * FROM "OrderEvent" WHERE "orderId" = ? ORDER BY "createdAt"', [$id]);
        $calls = Db::all('SELECT * FROM "OrderCall" WHERE "orderId" = ? ORDER BY "createdAt"', [$id]);
        $customer = Db::one('SELECT id, blacklisted, notes FROM "Customer" WHERE id = ?', [$o['customerId']]);
        $history = Db::all('SELECT id, ref, status, total, "createdAt" FROM "Order" WHERE "customerId" = ? AND id <> ? ORDER BY "createdAt" DESC LIMIT 10', [$o['customerId'], $id]);
        self::page('order', compact('o', 'events', 'calls', 'customer', 'history'), 'Commande ' . $o['ref']);
    }

    public static function orderAction(string $id): void
    {
        $u = self::guardPost();
        $by = (string) ($u['email'] ?? 'admin');
        $do = post_str('do', 20);
        if ($do === 'status') {
            $res = Orders::transition($id, post_str('to', 20), $by, ['note' => post_str('note', 500), 'carrier' => post_str('carrier', 80), 'trackingNumber' => post_str('trackingNumber', 80)]);
            self::flash($res === 'ok' ? 'Statut changé.' : ($res === 'conflict' ? "Quelqu'un a changé cette commande en même temps : vérifiez." : 'Ce changement de statut n\'est pas possible.'), $res === 'ok');
        } elseif ($do === 'call') {
            $outcome = post_str('outcome', 30);
            if (in_array($outcome, ['NO_ANSWER', 'CALL_BACK', 'WRONG_NUMBER'], true)) {
                Db::tx(static function () use ($id, $outcome, $by) {
                    Db::insert('OrderCall', ['id' => new_id(), 'orderId' => $id, 'outcome' => $outcome, 'note' => post_str('note', 500) ?: null, 'by' => $by, 'createdAt' => Db::now()]);
                    Db::run('UPDATE "Order" SET "confirmAttempts" = "confirmAttempts" + 1, "updatedAt" = ? WHERE id = ?', [Db::now(), $id]);
                });
                self::flash('Appel noté.');
            }
        } elseif ($do === 'shipping') {
            Db::update('Order', ['carrier' => post_str('carrier', 80) ?: null, 'trackingNumber' => post_str('trackingNumber', 80) ?: null, 'updatedAt' => Db::now()], 'id = ?', [$id]);
            self::flash('Livraison enregistrée.');
        } elseif ($do === 'blacklist') {
            $cid = Db::value('SELECT "customerId" FROM "Order" WHERE id = ?', [$id]);
            if ($cid) {
                Db::update('Customer', ['blacklisted' => post_str('on', 1) === '1', 'updatedAt' => Db::now()], 'id = ?', [$cid]);
                self::flash(post_str('on', 1) === '1' ? 'Numéro mis en liste noire : ses prochaines commandes seront signalées.' : 'Numéro retiré de la liste noire.');
            }
        }
        redirect('/admin/commandes/' . rawurlencode($id));
    }

    // ----------------------------------------------------------------- products

    public static function products(): void
    {
        Auth::require();
        $q = get_str('q', 60);
        $where = 'true';
        $params = [];
        if ($q !== '') {
            $where = '(p.name ILIKE ? OR p."nameAr" ILIKE ? OR p.slug ILIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $params = [$like, $like, $like];
        }
        $pageNo = max(1, (int) get_str('page'));
        $total = (int) Db::value("SELECT count(*) FROM \"Product\" p WHERE $where", $params);
        $rows = Db::all("SELECT p.id, p.slug, p.name, p.price, p.active, p.featured, p.\"stockMode\", p.\"stockQty\", p.available, p.\"updatedAt\", c.name AS category,
            (SELECT pi.\"mediaId\" FROM \"ProductImage\" pi WHERE pi.\"productId\" = p.id AND pi.kind = 'GALLERY' ORDER BY pi.sort LIMIT 1) AS \"imageId\"
            FROM \"Product\" p LEFT JOIN \"Category\" c ON c.id = p.\"categoryId\" WHERE $where ORDER BY p.\"updatedAt\" DESC LIMIT 50 OFFSET " . (($pageNo - 1) * 50), $params);
        self::page('products', ['rows' => $rows, 'q' => $q, 'pageNo' => $pageNo, 'pages' => max(1, (int) ceil($total / 50)), 'total' => $total], 'Produits');
    }

    public static function productNew(): void
    {
        Auth::require();
        self::page('product-new', ['categories' => Db::all('SELECT id, name FROM "Category" ORDER BY sort, name')], 'Nouveau produit');
    }

    public static function productCreate(): void
    {
        self::guardPost();
        $name = post_str('name', 200);
        $price = Money::parse(post_str('price', 20));
        if ($name === '' || $price === null || $price <= 0) {
            self::flash('Il faut un nom et un prix (ex. 49,900).', false);
            redirect('/admin/produits/nouveau');
        }
        $id = new_id();
        $now = Db::now();
        Db::insert('Product', [
            'id' => $id,
            'slug' => self::freeSlug(slugify($name), null),
            'name' => $name,
            'nameAr' => post_str('nameAr', 200) ?: null,
            'price' => $price,
            'categoryId' => post_str('categoryId', 40) ?: null,
            'active' => false,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);
        self::flash('Produit créé, encore caché : ajoutez les photos puis cochez « En ligne ».');
        redirect('/admin/produits/' . $id);
    }

    /** An address no other product has: "nom", then "nom-2", "nom-3"… */
    private static function freeSlug(string $slug, ?string $selfId): string
    {
        $base = $slug;
        for ($i = 2; $i < 200; $i++) {
            $taken = Db::value('SELECT id FROM "Product" WHERE slug = ? AND id <> ?', [$slug, $selfId ?? '']);
            if (!$taken) {
                return $slug;
            }
            $slug = "$base-$i";
        }
        return $base . '-' . substr(new_id(), 1, 5);
    }

    public static function product(string $id): void
    {
        Auth::require();
        $p = Db::one('SELECT * FROM "Product" WHERE id = ?', [$id]);
        if (!$p) {
            Shop::notFound();
            return;
        }
        $images = Db::all('SELECT id, "mediaId", kind, lang, sort, alt FROM "ProductImage" WHERE "productId" = ? ORDER BY kind, sort, id', [$id]);
        $offers = Db::all('SELECT * FROM "ProductOffer" WHERE "productId" = ? ORDER BY sort, quantity, id', [$id]);
        $variants = Db::all('SELECT * FROM "ProductVariant" WHERE "productId" = ? ORDER BY sort, id', [$id]);
        $categories = Db::all('SELECT id, name FROM "Category" ORDER BY sort, name');
        $supplier = $p['supplierId'] ? Db::value('SELECT name FROM "Supplier" WHERE id = ?', [$p['supplierId']]) : null;
        self::page('product', compact('p', 'images', 'offers', 'variants', 'categories', 'supplier'), $p['name']);
    }

    public static function productAction(string $id): void
    {
        self::guardPost();
        if (!Db::value('SELECT 1 FROM "Product" WHERE id = ?', [$id])) {
            redirect('/admin/produits');
        }
        $now = Db::now();
        $touch = static fn () => Db::run('UPDATE "Product" SET "updatedAt" = ? WHERE id = ?', [$now, $id]);
        $do = post_str('do', 20);
        $back = '/admin/produits/' . rawurlencode($id);

        if ($do === 'save') {
            $price = Money::parse(post_str('price', 20));
            $compare = post_str('compareAt', 20) === '' ? null : Money::parse(post_str('compareAt', 20));
            $cost = post_str('cost', 20) === '' ? null : Money::parse(post_str('cost', 20));
            $name = post_str('name', 200);
            if ($name === '' || $price === null || $price <= 0) {
                self::flash('Il faut un nom et un prix valides.', false);
                redirect($back);
            }
            $slug = slugify(post_str('slug', 160) ?: $name);
            $old = Db::one('SELECT slug, "oldSlugs" FROM "Product" WHERE id = ?', [$id]);
            $oldSlugs = Db::parseArray($old['oldSlugs'] ?? null);
            $slug = self::freeSlug($slug, $id);
            // The former address keeps working: old links and Google results are sent to the new one.
            if ($old && $old['slug'] !== $slug && !in_array($old['slug'], $oldSlugs, true)) {
                $oldSlugs[] = $old['slug'];
            }
            $mode = post_str('stockMode', 10) === 'TRACKED' ? 'TRACKED' : 'SUPPLIER';
            $video = post_str('videoUrl', 500);
            Db::update('Product', [
                'name' => $name, 'nameAr' => post_str('nameAr', 200) ?: null, 'slug' => $slug, 'oldSlugs' => Db::pgArray(array_values(array_diff($oldSlugs, [$slug]))),
                'summary' => post_str('summary', 400), 'summaryAr' => post_str('summaryAr', 400) ?: null,
                'description' => post_str('description', 20000), 'descriptionAr' => post_str('descriptionAr', 20000) ?: null,
                'specs' => post_str('specs', 5000), 'specsAr' => post_str('specsAr', 5000) ?: null,
                'videoUrl' => preg_match('#^https://#', $video) ? $video : null,
                'keywords' => post_str('keywords', 500),
                'price' => $price, 'compareAt' => $compare !== null && $compare > $price ? $compare : null, 'cost' => $cost,
                'supplierUrl' => post_str('supplierUrl', 500) ?: null, 'supplierRef' => post_str('supplierRef', 120) ?: null,
                'categoryId' => post_str('categoryId', 40) ?: null,
                'stockMode' => $mode, 'stockQty' => max(0, (int) post_str('stockQty', 7)), 'available' => isset($_POST['available']),
                'active' => isset($_POST['active']), 'featured' => isset($_POST['featured']),
                'updatedAt' => $now,
            ], 'id = ?', [$id]);
            self::flash('Enregistré.' . ($compare !== null && $compare <= $price ? ' Le prix barré a été retiré : il doit être plus haut que le prix.' : ''));
        } elseif ($do === 'photos') {
            $kind = post_str('kind', 10) === 'DETAIL' ? 'DETAIL' : 'GALLERY';
            $lang = in_array(post_str('lang', 2), ['fr', 'ar'], true) ? post_str('lang', 2) : null;
            $files = $_FILES['photos'] ?? null;
            $added = 0;
            $refused = 0;
            if ($files && is_array($files['tmp_name'])) {
                $sort = (int) Db::value('SELECT coalesce(max(sort), -1) FROM "ProductImage" WHERE "productId" = ? AND kind = ?', [$id, $kind]);
                foreach ($files['tmp_name'] as $i => $tmp) {
                    if (($files['error'][$i] ?? 1) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp) || $files['size'][$i] > 15 * 1024 * 1024) {
                        $refused++;
                        continue;
                    }
                    $mediaId = Media::store((string) file_get_contents($tmp));
                    if (!$mediaId) {
                        $refused++;
                        continue;
                    }
                    try {
                        Db::insert('ProductImage', ['id' => new_id(), 'productId' => $id, 'mediaId' => $mediaId, 'sort' => ++$sort, 'kind' => $kind, 'lang' => $kind === 'DETAIL' ? $lang : null, 'source' => 'file:' . mb_substr(basename((string) $files['name'][$i]), 0, 120) . ':' . (int) $files['size'][$i]]);
                        $added++;
                    } catch (PDOException $e) {
                        // The same file twice: already there.
                        $refused++;
                    }
                }
            }
            $touch();
            self::flash("$added photo(s) ajoutée(s)." . ($refused ? " $refused refusée(s) (pas une image, trop lourde, ou déjà là)." : ''), $added > 0);
        } elseif ($do === 'photoDelete') {
            Db::run('DELETE FROM "ProductImage" WHERE id = ? AND "productId" = ?', [post_str('imageId', 40), $id]);
            $touch();
            self::flash('Photo retirée.');
        } elseif ($do === 'photoMove') {
            $img = Db::one('SELECT id, sort, kind FROM "ProductImage" WHERE id = ? AND "productId" = ?', [post_str('imageId', 40), $id]);
            if ($img) {
                $all = Db::all('SELECT id FROM "ProductImage" WHERE "productId" = ? AND kind = ? ORDER BY sort, id', [$id, $img['kind']]);
                $ids = array_column($all, 'id');
                $at = array_search($img['id'], $ids, true);
                $to = post_str('dir', 4) === 'up' ? $at - 1 : $at + 1;
                if ($at !== false && isset($ids[$to])) {
                    [$ids[$at], $ids[$to]] = [$ids[$to], $ids[$at]];
                    Db::tx(static function () use ($ids) {
                        foreach ($ids as $n => $iid) {
                            Db::run('UPDATE "ProductImage" SET sort = ? WHERE id = ?', [$n, $iid]);
                        }
                    });
                    $touch();
                }
            }
        } elseif ($do === 'offerAdd' || $do === 'offerSave') {
            $o = [
                'label' => post_str('label', 120), 'labelAr' => post_str('labelAr', 120) ?: null,
                'quantity' => max(0, (int) post_str('quantity', 3)), 'price' => Money::parse(post_str('price', 20)),
                'freeDelivery' => isset($_POST['freeDelivery']), 'active' => isset($_POST['active']) || $do === 'offerAdd',
                'sort' => (int) post_str('sort', 4),
            ];
            $ends = post_str('endsAt', 10);
            $o['endsAt'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ends) ? (new DateTimeImmutable("$ends 23:59:59", new DateTimeZone('Africa/Tunis')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') : null;
            $problem = OfferLabel::problem($o);
            if ($o['quantity'] < 1 || $o['quantity'] > 50 || $o['price'] === null || $o['price'] <= 0) {
                self::flash('Une offre a une quantité (1 à 50) et un prix total.', false);
            } elseif ($problem) {
                self::flash("Offre refusée : $problem.", false);
            } elseif ($do === 'offerAdd') {
                Db::insert('ProductOffer', $o + ['id' => new_id(), 'productId' => $id, 'createdAt' => $now]);
                $touch();
                self::flash('Offre ajoutée.');
            } else {
                Db::update('ProductOffer', $o, 'id = ? AND "productId" = ?', [post_str('offerId', 40), $id]);
                $touch();
                self::flash('Offre enregistrée.');
            }
        } elseif ($do === 'offerDelete') {
            Db::run('DELETE FROM "ProductOffer" WHERE id = ? AND "productId" = ?', [post_str('offerId', 40), $id]);
            $touch();
            self::flash('Offre supprimée.');
        } elseif ($do === 'variantAdd' || $do === 'variantSave') {
            $color = post_str('colorHex', 9);
            $vp = post_str('price', 20);
            $v = [
                'name' => post_str('name', 120), 'nameAr' => post_str('nameAr', 120) ?: null,
                'colorHex' => preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : null,
                'price' => $vp === '' ? null : Money::parse($vp),
                'stockQty' => max(0, (int) post_str('stockQty', 7)), 'available' => isset($_POST['available']) || $do === 'variantAdd',
                'sort' => (int) post_str('sort', 4),
            ];
            if ($v['name'] === '') {
                self::flash("Une option a un nom (ex. « Noir », « M »).", false);
            } elseif ($do === 'variantAdd') {
                Db::insert('ProductVariant', $v + ['id' => new_id(), 'productId' => $id]);
                $touch();
                self::flash('Option ajoutée.');
            } else {
                Db::update('ProductVariant', $v, 'id = ? AND "productId" = ?', [post_str('variantId', 40), $id]);
                $touch();
                self::flash('Option enregistrée.');
            }
        } elseif ($do === 'variantDelete') {
            Db::run('DELETE FROM "ProductVariant" WHERE id = ? AND "productId" = ?', [post_str('variantId', 40), $id]);
            $touch();
            self::flash('Option supprimée.');
        } elseif ($do === 'delete') {
            if ((int) Db::value('SELECT count(*) FROM "OrderItem" WHERE "productId" = ?', [$id]) > 0) {
                Db::update('Product', ['active' => false, 'updatedAt' => $now], 'id = ?', [$id]);
                self::flash('Ce produit a des commandes : il est caché au lieu d\'être supprimé.');
            } else {
                Db::run('DELETE FROM "Product" WHERE id = ?', [$id]);
                self::flash('Produit supprimé.');
                redirect('/admin/produits');
            }
        }
        redirect($back);
    }

    // --------------------------------------------------------------- categories

    public static function categories(): void
    {
        Auth::require();
        $rows = Db::all('SELECT c.*, (SELECT count(*) FROM "Product" p WHERE p."categoryId" = c.id) AS n FROM "Category" c ORDER BY c.sort, c.name');
        self::page('categories', ['rows' => $rows], 'Catégories');
    }

    public static function categoriesAction(): void
    {
        self::guardPost();
        $do = post_str('do', 10);
        $name = post_str('name', 120);
        if ($do === 'add' && $name !== '') {
            $slug = slugify(post_str('slug', 120) ?: $name);
            if (Db::value('SELECT 1 FROM "Category" WHERE slug = ?', [$slug])) {
                $slug .= '-' . substr(new_id(), 1, 4);
            }
            Db::insert('Category', ['id' => new_id(), 'slug' => $slug, 'name' => $name, 'nameAr' => post_str('nameAr', 120) ?: null, 'sort' => (int) post_str('sort', 4), 'active' => true, 'createdAt' => Db::now()]);
            self::flash('Catégorie ajoutée.');
        } elseif ($do === 'save' && $name !== '') {
            Db::update('Category', ['name' => $name, 'nameAr' => post_str('nameAr', 120) ?: null, 'headline' => post_str('headline', 160) ?: null, 'headlineAr' => post_str('headlineAr', 160) ?: null, 'sort' => (int) post_str('sort', 4), 'active' => isset($_POST['active'])], 'id = ?', [post_str('id', 40)]);
            self::flash('Catégorie enregistrée.');
        }
        redirect('/admin/categories');
    }

    // ----------------------------------------------------------------- settings

    public static function settings(): void
    {
        Auth::require();
        self::page('settings', ['s' => Settings::all()], 'Paramètres');
    }

    public static function settingsSave(): void
    {
        self::guardPost();
        $fee = Money::parse(post_str('fee', 20));
        $threshold = post_str('freeThreshold', 20) === '' ? null : Money::parse(post_str('freeThreshold', 20));
        if ($fee === null || $fee > 100000) {
            self::flash('Frais de livraison illisibles (ex. 8,000).', false);
            redirect('/admin/parametres');
        }
        $zones = [];
        foreach (array_keys(Gov::LIST) as $i => $g) {
            $min = max(0, min(30, (int) ($_POST['zmin'][$i] ?? 2)));
            $max = max($min, min(30, (int) ($_POST['zmax'][$i] ?? 3)));
            $zones[$g] = ['minDays' => $min, 'maxDays' => $max, 'enabled' => isset($_POST['zon'][$i])];
        }
        $wa = Phone::normalize(post_str('whatsapp', 20));
        Settings::save([
            'shop' => [
                'name' => post_str('name', 60) ?: 'Mena', 'tagline' => post_str('tagline', 140), 'taglineAr' => post_str('taglineAr', 140),
                'phone' => post_str('phone', 20), 'email' => post_str('email', 120), 'address' => post_str('address', 200),
                'legalName' => post_str('legalName', 120), 'legalId' => post_str('legalId', 60),
            ],
            'hero' => ['title' => post_str('heroTitle', 80), 'titleAr' => post_str('heroTitleAr', 80)],
            'contact' => ['whatsapp' => $wa ?? ''],
            'delivery' => ['fee' => $fee, 'freeThreshold' => $threshold, 'openBeforePaying' => isset($_POST['openBeforePaying']), 'zones' => $zones],
            'returns' => ['days' => max(10, min(60, (int) post_str('returnDays', 2)))],
        ]);
        self::flash('Paramètres enregistrés.' . (post_str('whatsapp', 20) !== '' && !$wa ? ' Le numéro WhatsApp n\'était pas un numéro tunisien : retiré.' : ''));
        redirect('/admin/parametres');
    }
}
