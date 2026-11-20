# Mena — PHP version

The Mena shop (cash on delivery, Tunisia) in plain PHP, for cheap PHP hosting
(IONOS, Hostinger "Premium", and most others). It uses **the same Neon
database** as the Next.js shop (`Youssef4doll/Mena`): same products, photos,
orders, customers, settings and back-office accounts. Nothing to copy.

- Plain PHP 8.2+, no framework, nothing to install (no Composer, no npm).
- French at `/`, Arabic at `/ar/…`: the same addresses as the Next.js shop, so
  links in posts, ads and Google keep working.
- Prices, offers, stock and delivery are computed on the server, with the same
  rules as the Next.js shop (`tests/fixtures/next-parity.json` holds its own
  answers; the PHP tests must match all of them).
- The same order tracking links and the same back-office login work in both
  versions, as long as `app_secret` is the same as `APP_SECRET`.

## What is in this version, and what is not (yet)

| In | Not yet (still in the Next.js shop) |
|---|---|
| Home (categories first), categories, all products, search | Promo codes |
| Product page: photos, offers (packs), options, description, details, video, questions | Unfinished-order follow-up (abandoned carts) |
| Order form with price, delivery and total in the same card | Analytics, ad pixels, ads tools |
| Basket, thank-you page, order tracking (link or reference + phone) | E-mail and SMS when an order is placed or shipped |
| Delivery & returns, contact, terms, privacy pages | Reviews, return requests |
| Back office: orders (confirm, ship, deliver, cancel, calls, blacklist) | AI texts, imports from supplier pages, CSV import/export |
| Back office: products (photos, offers, options), categories, settings | Home banners editor, suppliers, expenses |

Without e-mail alerts, **check the back office for new orders** (Commandes).

## 1. Check a host before you pay

1. Upload `public/check.php` to the host (any folder the site serves).
2. Open it in the browser: `https://your-site/check.php`.
3. Type the **host part** of your Neon address (for example
   `ep-cool-name-123456-pooler.eu-central-1.aws.neon.tech`), never the password.
4. Every line must say YES or —. A NO on "PostgreSQL driver" or "Neon
   reachable" means this host cannot run this version: choose another.

## 2. Put the shop on the host (once, about 20 minutes)

1. **PHP version**: in the host's panel choose PHP 8.2 or newer.
2. **Files**: send the whole project to the host (the host's Git deployment
   from this GitHub repository, or SFTP/File Manager with the files from
   GitHub → Code → Download ZIP).
3. **Web folder**: point the domain at the **`public`** folder
   (IONOS: Domains → your domain → Target directory). If the host cannot,
   point it at the project folder: the `.htaccess` there sends everything into
   `public/` and blocks the rest.
4. **Settings**: copy `config.example.php` to `config.php` (next to it, not in
   `public/`) and fill it in on the host, in its file editor:
   - `database_url`: the Neon **pooled** address (DATABASE_URL on Netlify);
   - `app_secret`: the **same value** as APP_SECRET on Netlify;
   - `site_url`: the shop's address, e.g. `https://menatn.com`.
   Never send these values in a chat or put them on GitHub.
5. Open `check.php` again: everything should say YES. Then **delete
   `check.php`** from the host.
6. Open the shop, then `/admin` and sign in with your usual back-office
   account.
7. Test: place one order on a product, confirm it in the back office, cancel it.

Folders the shop writes to: `storage/` (sessions, error log) and
`public/media/` (resized photos, made once on their first visit). Both must be
writable; `check.php` says so.

## 3. Both versions at the same time

They share the database, so they can run side by side while you switch (for
example the Next.js shop on Netlify, this one on your domain). Edit products
and settings in one back office at a time; both show the same orders. Only one
of them should be the address in your Instagram bio and your ads.

## For developers

```
cp config.example.php config.local.php      # a LOCAL test database only
MENA_CONFIG=$PWD/config.local.php php -S localhost:8080 -t public tools/dev-router.php
MENA_CONFIG=$PWD/config.local.php php tests/run.php
```

- The tables belong to the Next.js shop (`prisma/schema.prisma` there): this
  code never creates or changes them. Names are quoted (`"Product"`, `"nameAr"`),
  times are UTC, `"updatedAt"` is set on every write (`src/Db.php`).
- `tests/run.php` refuses to touch any database that is not on this computer.
- `tests/fixtures/next-parity.json` is made from the Next.js shop's own code
  (offer names, prices): when a rule changes there, make it again and change
  the PHP to match.
- Every form carries a CSRF token; every page escapes its output (`e()`);
  every query is prepared; uploaded photos are re-encoded before they are kept.
