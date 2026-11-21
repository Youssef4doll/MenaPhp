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

The storefront is a copy of the Next.js shop's: the same stylesheet and
fonts, the same markup (checked element by element against the Next.js
shop's pages), and for everything interactive (offers, options, the order
form, the quick order sheet, the basket, the thank-you and tracking pages)
**the Next.js shop's own components**, compiled into `public/assets/islands`.
They call the same addresses as there (`/api/quote`, `/api/orders`,
`/api/cart`, `/api/events`, `/api/products/…`, `/api/suggest`, `/api/img/…`),
which this version answers the same way.

| In | Not yet (still in the Next.js shop) |
|---|---|
| The whole storefront: home, categories, shop, search with suggestions, product page, quick order, basket, thank-you, tracking, reviews and returns from the tracking page, information pages | Back office: product import (CSV, supplier pages), AI texts and translations |
| Promo codes, unfinished forms (for the back office's call list), statistics events, ad pixels and Tag Manager | Back office: the Next.js product editor and list (this version has a simpler one) |
| Customer SMS and the owner's new-order e-mail (same settings and keys as the Next.js shop) | Home banners editor, suppliers, expenses, ads tools |
| Back office: orders (confirm, ship, deliver, cancel, calls, blacklist), products (photos, offers, options), categories, settings | |

**Changing the interactive parts**: they come from the Next.js shop's code.
After a change there, rebuild them with Node.js on a computer (never on the
host): `node tools/islands/build.mjs ../Mena`, then commit
`public/assets/islands`.

## 1. Check a host before you pay

1. Upload `tools/check.php` to the host (any folder the site serves).
2. Open it in the browser: `https://your-site/check.php`.
3. Type the **host part** of your Neon address (for example
   `ep-cool-name-123456-pooler.eu-central-1.aws.neon.tech`), never the password.
4. Every line must say YES or —. A NO on "PostgreSQL driver" or "Neon
   reachable" means this host cannot run this version: choose another.

## 2a. On alwaysdata (tested: menashop.alwaysdata.net)

In alwaysdata's admin: **Advanced → SSH**, open the web terminal and sign in.

1. Download the shop (the repository is public, nothing to type but this):

   ```
   git clone https://github.com/Youssef4doll/MenaPhp.git ~/mena
   ```

2. Your settings, typed **in the terminal** (never in a chat):

   ```
   cp ~/mena/config.example.php ~/mena/config.php
   nano ~/mena/config.php
   ```

   Fill in `database_url` (Netlify → menatn → Environment variables →
   DATABASE_URL), `app_secret` (the same as APP_SECRET there) and `site_url`
   (`https://menashop.alwaysdata.net` for now). Save with **Ctrl+O, Enter**,
   leave with **Ctrl+X**.
3. Point the site at the shop: **Web → Sites → menashop.alwaysdata.net →
   edit (✎)**: Type **PHP**, Root directory **`/mena/public`**, PHP version
   **8.4**, then **Submit**.
4. Check, then remove the check page:

   ```
   cp ~/mena/tools/check.php ~/mena/public/check.php
   ```

   open `https://menashop.alwaysdata.net/check.php` (everything YES), then

   ```
   rm ~/mena/public/check.php ~/www/check.php
   ```
5. Open the shop and `/admin` (your usual back-office account).

**Updating later** (after a change on GitHub): `cd ~/mena && git pull`.

## 2b. Any other PHP host

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
5. Copy `tools/check.php` into `public/` and open it: everything should say
   YES. Then **delete** `public/check.php` from the host.
   On alwaysdata, section 2a does all of this.
6. Open the shop, then `/admin` and sign in with your usual back-office
   account.
7. Test: place one order on a product, confirm it in the back office, cancel it.

Folders the shop writes to: `storage/` (sessions, error log) and
`public/media/` (resized photos, made once on their first visit). Both must be
writable; `check.php` says so.

## Speed: a database far from the host

Each question to the database travels to it and back: from Europe to Neon in
Ohio (us-east-2), about 130 ms. So the shop:

- keeps what pages show (products, categories, settings) for a minute in
  `storage/cache`, and past that minute still shows the copy while reading it
  again **after** the page is sent: pages come back in about 1 ms;
- asks for a product, its photos, options and offers in one question;
- keeps the database connection open between visitors (`persistent` in
  config.php);
- never charges from a copy: an order reads prices, offers and stock again,
  and a total that changed is shown to the customer to confirm.

Measured on a test copy with 130 ms added to each question: a product page
went from 2.0 s to 0.4 s for the first visitor after a change, and about
0.002 s for everyone else.

If this PHP shop becomes the only one, moving the database next to the host
(a Neon project in Frankfurt, eu-central-1) makes the first visit faster too.
Not before: the Netlify shop's server is in Ohio, next to today's database.

## 3. Both versions at the same time

They share the database, so they can run side by side while you switch (for
example the Next.js shop on Netlify, this one on your domain). Edit products
and settings in one back office at a time; both show the same orders. A
change made in the Next.js back office shows on this shop after a minute. Only one
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
