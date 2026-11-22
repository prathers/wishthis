# Ønskeskyen Import Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Logged-in users paste an Ønskeskyen wishlist link, preview its wishes, and import the selected ones into a new wishthis wishlist.

**Architecture:** A database-free, translation-free class `wishthis\Import\Onskeskyen` parses the link, calls GoWish's GraphQL API through an injectable transport, and maps wishes to plain arrays. A new API module `src/api/import.php` uses it for preview (GET) and import (POST, one DB transaction). A new page `src/pages/import.php` with `src/assets/js/import.js` is the UI, linked from "My wishlists".

**Tech Stack:** PHP (must run on 8.2), PDO (MySQL and SQLite), Semantic UI + vanilla JS `fetch`, gettext via `translations/local/da.po`, PHPUnit 10.

**Spec:** `docs/superpowers/specs/2026-11-22-onskeskyen-import-design.md`

## Global Constraints

- Production runs **PHP 8.2** (cPanel `ea-php82`). Don't use 8.3+ features (typed class constants, `json_validate`, `#[\Override]`, etc.).
- Work stays in the prathers fork, on branch `feature/onskeskyen-import`. **No PR to wishthis/wishthis.**
- GoWish endpoint: `https://api.gowish.com/graphql`. `POST` with headers `Content-Type: application/json`, `Origin: https://onskeskyen.dk` and a browser-like `User-Agent`. **10-second** timeout.
- GraphQL fields (and no others; never request reservations): `wishlist(id) { id title wishes(input: {cursor, limit}) { data { id title description quantity url index deletedAt price currency photos } totalCount nextCursor } }`.
- Pagination: `limit: 100`, follow `nextCursor` until `0`/`null` or `totalCount` reached. Hard cap **500** wishes.
- Accepted hosts: `onskeskyen.dk`, `onskeskyen.no`, `gowish.com` (with or without `www.`), path `/<optional lang>/wishlists/<alphanumeric id>`, query string allowed.
- Imported URLs are **not** passed through `Wish::getAffiliateLink()`.
- Images are stored as the `img.gowish.com` URL (`photos[0]`), not copied.
- Title max **128** chars, URL max **255** chars (same as `src/api/wishes.php`). Accept URLs only if they start with `http://` or `https://`.
- `price` > 0 → `products.price` and `is_purchasable = true`. `price` 0 → no `products` row, `is_purchasable = false`. Currency is ignored.
- `quantity` > 1 → append a line `__('Quantity: %d')` (Danish: `Antal: %d`) to the description.
- `deletedAt` non-null → skip the wish.
- msgids in English in code. Danish in `translations/local/da.po`.
- Test fixtures are **synthetic**. Never commit the real family list or its ID.
- wishthis sorts wishes by `priority DESC, url ASC, title ASC` (`Wishlist.php:128`), so insertion order doesn't matter, and Ønskeskyen's order is not preserved.

## Review Focus

1. **A GoWish response that isn't JSON or has the wrong shape** (HTML error page, `data` missing, `wishes.data` not an array). Expect `OnskeskyenException::UNAVAILABLE`, never a PHP warning/fatal leaking into the JSON response. *Test: Task 2 `testMalformedResponsesThrowUnavailable`.*
2. **A `nextCursor` that never ends or repeats** (API bug). Expect the loop to stop at 500 wishes or when the cursor repeats. *Test: Task 2 `testPaginationStopsOnRepeatedCursorAndCap`.*
3. **Titles and notes containing HTML or quotes** (`<script>`, `"`, `&`). These must be sanitised exactly like hand-made wishes and render as text. *Manual check: Task 4 Step 5 uses a fixture-like title with `<b>&"`.*
4. **Non-http URLs in `url`** (`javascript:`, relative, empty). Expect them dropped to an empty URL. *Test: Task 1 `testMapWishUrlRules`.*
5. **A user who isn't logged in calling the API directly.** Expect a translated error and nothing created. *Manual check: Task 3 Step 4.*

---

### Task 0: Reachability check from production (gate)

The test calls so far ran from a laptop. Before building anything, confirm wish.rather.dk can reach GoWish.

**Files:** none (nothing committed).

- [ ] **Step 1: Ask the user for the go-ahead.** This runs a read-only command on the production server over `ssh ratherdk@rather.dk -p 33 -i ~/.ssh/aze_rsa`.
- [ ] **Step 2: Run one cURL call from the server** with the exact headers and query from Global Constraints, `limit: 3`, against the example list ID the user gave in conversation. Don't write the ID into any file.
  Expected: HTTP 200 and JSON with `data.wishlist.wishes.totalCount` ≥ 1.
- [ ] **Step 3: Gate.** If it fails (timeout, 403, blocked outbound traffic), **stop** and report to the user. The design's fallback is a different feature.

---

### Task 1: URL parsing and wish mapping

**Files:**
- Create: `src/classes/wishthis/Import/Onskeskyen.php`
- Create: `src/classes/wishthis/Import/OnskeskyenException.php`
- Create: `tests/OnskeskyenImportTest.php`

**Interfaces:**
- Produces:
  - `final class OnskeskyenException extends \RuntimeException` with `public const NOT_FOUND = 1; public const UNAVAILABLE = 2;`
  - `Onskeskyen::parseListId(string $url): ?string`: the list ID, or `null`.
  - `Onskeskyen::mapWish(array $raw): ?array`: `null` if deleted; otherwise `['id' => string, 'title' => string, 'description' => string, 'url' => string, 'image' => string, 'price' => ?float, 'quantity' => int]`.

The class is namespace `wishthis\Import`, with no globals, no database and no `__()`. The test `require`s both files directly, because composer has no autoload for `wishthis\` and the app autoloader lives in `index.php`.

- [ ] **Step 1: Write the failing tests** in `tests/OnskeskyenImportTest.php` (namespace `wishthis\Tests\Import`, `require_once __DIR__ . '/../src/classes/wishthis/Import/OnskeskyenException.php'` and `.../Onskeskyen.php`):

```php
public function testParseListIdAcceptsKnownHosts(): void
{
    foreach ([
        'https://onskeskyen.dk/da/wishlists/AbC123xyz',
        'https://www.onskeskyen.dk/wishlists/AbC123xyz?ref=share',
        'https://onskeskyen.no/no/wishlists/AbC123xyz',
        'https://gowish.com/en/wishlists/AbC123xyz/',
        '  https://onskeskyen.dk/da/wishlists/AbC123xyz  ',
    ] as $url) {
        $this->assertSame('AbC123xyz', Onskeskyen::parseListId($url), $url);
    }
}

public function testParseListIdRejectsOthers(): void
{
    foreach ([
        '', 'onskeskyen', 'https://evil.dk/da/wishlists/AbC123xyz',
        'https://onskeskyen.dk.evil.dk/wishlists/AbC123xyz',
        'https://onskeskyen.dk/da/profile/AbC123xyz',
        'https://onskeskyen.dk/da/wishlists/', 'https://onskeskyen.dk/da/wishlists/abc-<x>',
    ] as $url) {
        $this->assertNull(Onskeskyen::parseListId($url), $url);
    }
}

public function testMapWishBasic(): void
{
    $wish = Onskeskyen::mapWish([
        'id' => 'w1', 'title' => "  Toy  car\n blue ", 'description' => 'Size 7',
        'quantity' => 2, 'url' => 'https://shop.example/p/1', 'deletedAt' => null,
        'price' => 199.95, 'currency' => 'DKK', 'photos' => ['https://img.gowish.com/a.jpg', 'https://img.gowish.com/b.jpg'],
    ]);
    $this->assertSame(['id' => 'w1', 'title' => 'Toy car blue', 'description' => 'Size 7',
        'url' => 'https://shop.example/p/1', 'image' => 'https://img.gowish.com/a.jpg',
        'price' => 199.95, 'quantity' => 2], $wish);
}

public function testMapWishFreeTextAndZeroPrice(): void
{
    $wish = Onskeskyen::mapWish(['id' => 'w2', 'title' => 'Used school bag', 'description' => null,
        'quantity' => null, 'url' => null, 'deletedAt' => null, 'price' => 0, 'photos' => []]);
    $this->assertSame('', $wish['url']);
    $this->assertSame('', $wish['image']);
    $this->assertSame('', $wish['description']);
    $this->assertNull($wish['price']);
    $this->assertSame(1, $wish['quantity']);
}

public function testMapWishSkipsDeleted(): void
{
    $this->assertNull(Onskeskyen::mapWish(['id' => 'w3', 'title' => 'x', 'deletedAt' => '2026-01-01T00:00:00Z']));
}

public function testMapWishUrlRules(): void
{
    foreach (['javascript:alert(1)', '/relative', 'ftp://x', ''] as $bad) {
        $this->assertSame('', Onskeskyen::mapWish(['id' => 'w', 'title' => 't', 'url' => $bad])['url'], $bad);
    }
    $long = 'https://shop.example/' . str_repeat('a', 300);
    $this->assertSame(255, strlen(Onskeskyen::mapWish(['id' => 'w', 'title' => 't', 'url' => $long])['url']));
    $this->assertSame(128, mb_strlen(Onskeskyen::mapWish(['id' => 'w', 'title' => str_repeat('é', 200)])['title']));
}
```

- [ ] **Step 2: Run them and check they fail.**
  Run: `vendor/bin/phpunit tests/OnskeskyenImportTest.php --testdox`
  Expected: FAIL (the class file doesn't exist).

- [ ] **Step 3: Implement the exception and the two static methods.** `parseListId`: trim, `parse_url`, host lowercased with a leading `www.` stripped, which must be in the allowed set. Path regex `#^/(?:[a-z]{2}/)?wishlists/([A-Za-z0-9]+)/?$#`. `mapWish`: collapse whitespace with `preg_replace('/\s+/u', ' ', …)` then trim. Title cut with `mb_substr(…, 0, 128)`. URL kept only if it matches `#^https?://#i`, then `substr(…, 0, 255)`. Missing keys are treated as empty/null. Quantity `max(1, (int) …)`. Price `> 0 ? (float) : null`.

- [ ] **Step 4: Run them and check they pass.**
  Run: `vendor/bin/phpunit tests/OnskeskyenImportTest.php --testdox`
  Expected: all PASS. Also run `php -l` on both class files.

- [ ] **Step 5: Commit.**
```bash
git add src/classes/wishthis/Import tests/OnskeskyenImportTest.php
git commit -m "feat(import): parse Ønskeskyen links and map wishes"
```

---

### Task 2: Fetching from GoWish

**Files:**
- Modify: `src/classes/wishthis/Import/Onskeskyen.php`
- Create: `tests/fixtures/onskeskyen/page1.json`, `tests/fixtures/onskeskyen/page2.json` (synthetic: list title "Test list", 3 wishes on page 1 with `nextCursor: 3`, `totalCount: 4`; 1 wish on page 2 with `nextCursor: 0`; one of the 4 has `deletedAt` set)
- Modify: `tests/OnskeskyenImportTest.php`

**Interfaces:**
- Consumes: `mapWish`, `OnskeskyenException` (Task 1).
- Produces:
  - `public function __construct(?\Closure $transport = null)`. `$transport` is `function (string $jsonBody): array{status: int, body: string|false}`. The default is a cURL `POST` per Global Constraints.
  - `public function fetch(string $listId): array{title: string, wishes: list<array>}`: wishes are already mapped, with deleted ones removed.
  - Throws `OnskeskyenException(NOT_FOUND)` when `data.wishlist` is `null`, or `errors[*].message` contains "not found" / "forbidden" / "access" (case-insensitive). Throws `OnskeskyenException(UNAVAILABLE)` on transport failure, non-200, invalid JSON or the wrong shape.

- [ ] **Step 1: Write the failing tests.** A helper `fakeTransport(array $bodies, array &$sent): \Closure` returns the queued bodies in order, with status 200, and records each request body.

```php
public function testFetchPaginatesAndSkipsDeleted(): void
{
    $sent = [];
    $result = (new Onskeskyen($this->fakeTransport([$this->fixture('page1'), $this->fixture('page2')], $sent)))->fetch('List1');
    $this->assertSame('Test list', $result['title']);
    $this->assertCount(3, $result['wishes']);
    $this->assertCount(2, $sent);
    $this->assertSame(['id' => 'List1', 'cursor' => 3, 'limit' => 100], json_decode($sent[1], true)['variables']);
    $this->assertStringNotContainsString('reservations', json_decode($sent[0], true)['query']);
}

public function testFetchNotFound(): void
{
    $this->expectExceptionCode(OnskeskyenException::NOT_FOUND);
    $sent = [];
    (new Onskeskyen($this->fakeTransport(['{"data":{"wishlist":null}}'], $sent)))->fetch('X');
}

public function testMalformedResponsesThrowUnavailable(): void
{
    foreach (['<html>502</html>', '{}', '{"data":{"wishlist":{"title":"t","wishes":{"data":"nope"}}}}'] as $body) {
        $sent = [];
        try {
            (new Onskeskyen($this->fakeTransport([$body], $sent)))->fetch('X');
            $this->fail('no exception for ' . $body);
        } catch (OnskeskyenException $e) {
            $this->assertSame(OnskeskyenException::UNAVAILABLE, $e->getCode(), $body);
        }
    }
    $this->expectExceptionCode(OnskeskyenException::UNAVAILABLE);
    (new Onskeskyen(fn () => ['status' => 500, 'body' => '']))->fetch('X');
}

public function testPaginationStopsOnRepeatedCursorAndCap(): void
{
    $page = fn (int $n) => json_encode(['data' => ['wishlist' => ['id' => 'X', 'title' => 't', 'wishes' => [
        'totalCount' => 9999, 'nextCursor' => 5,
        'data' => array_map(fn ($i) => ['id' => "w$n-$i", 'title' => 't'], range(1, 100)),
    ]]]]);
    $calls = 0;
    $result = (new Onskeskyen(function () use (&$calls, $page) { return ['status' => 200, 'body' => $page(++$calls)]; }))->fetch('X');
    $this->assertSame(2, $calls); // cursor 5 repeats after the 2nd page
    $this->assertLessThanOrEqual(500, count($result['wishes']));
}
```

- [ ] **Step 2: Run them and check they fail.** Run: `vendor/bin/phpunit tests/OnskeskyenImportTest.php --testdox`. Expected: the four new tests FAIL.
- [ ] **Step 3: Implement `__construct`, `fetch` and the default cURL transport.** The query is a class constant string using variables `$id`, `$cursor` and `$limit` (`wishes(input: {cursor: $cursor, limit: $limit})`, declared as `Int`). Stop when the cursor is `0`/`null`, already seen, all wishes are collected, or 500 is reached (truncate to 500). Wrap `json_decode` and shape checks, so no warnings escape.
- [ ] **Step 4: Run them and check they pass.** Run: `vendor/bin/phpunit tests/OnskeskyenImportTest.php --testdox`. Expected: all PASS.
- [ ] **Step 5: Commit.**
```bash
git add src/classes/wishthis/Import/Onskeskyen.php tests/OnskeskyenImportTest.php tests/fixtures/onskeskyen
git commit -m "feat(import): fetch Ønskeskyen wishlists from the GoWish API"
```

---

### Task 3: Import API module

**Files:**
- Create: `src/api/import.php`

**Interfaces:**
- Consumes: `Onskeskyen::parseListId`, `->fetch`, `OnskeskyenException` (Tasks 1–2). Globals `$database`, `$response`. `User::getCurrent()`, `Sanitiser::getTitle`/`getText`.
- Produces (called by Task 4's JS, at `/index.php?page=api&module=import`):
  - `GET ?url=<link>` → `response.data = {title: string, wishes: [{id, title, description, url, image, price}]}`, where `description` already includes the quantity line.
  - `POST url, wishlist_name, wish_ids[]` → `response.data = {wishlistId: int}`.
  - Errors: `response.error = <translated message>`, with no `data`. (`API::do()` always sets `success: true`, so the JS checks `error`.)

Error messages (exact msgids):
- not logged in: `You need to be logged in to import a wishlist.`
- invalid link: `That doesn't look like an Ønskeskyen wishlist link.`
- NOT_FOUND: `We couldn't find that list. Is it set to public on Ønskeskyen?`
- UNAVAILABLE: `Ønskeskyen didn't answer as expected. Try again later.` (also `error_log` the exception message)
- a selected ID is missing on re-fetch: `The list changed on Ønskeskyen. Please fetch it again.`
- no IDs selected: `Choose at least one wish to import.`
- DB failure: `The import failed. Nothing was saved.`

- [ ] **Step 1: Implement the module.** It starts with a login check (`$user->isLoggedIn()`). Use a `switch ($_SERVER['REQUEST_METHOD'])` like `src/api/wishlists.php`. A local closure `$compose = fn (array $w): string` appends `"\n\n" . sprintf(__('Quantity: %d'), $w['quantity'])` when quantity > 1, or just the line if the description is empty.
  POST:
  - Re-fetch, then check every posted ID exists. Otherwise return the "list changed" error.
  - Inside `$database->pdo->beginTransaction()`, insert the wishlist exactly like `src/api/wishlists.php:21-40` (name falls back to the fetched title if empty, through `Sanitiser::getTitle`).
  - Then insert each selected wish into `wishes` (`wishlist`, `title` via `Sanitiser::getTitle`, `description` via `Sanitiser::getText` or `null`, `image` or `null`, `url` or `null`, without affiliate rewriting, `priority` `null`, `is_purchasable` = price > 0). Add a `products` row when price > 0, as in `src/api/wishes.php:283-300`.
  - `commit()`. On any `\Throwable`, `rollBack()`, `error_log` it, and return the DB-failure error.
- [ ] **Step 2: Lint.** Run: `php -l src/api/import.php`. Expected: `No syntax errors detected`.
- [ ] **Step 3: Manual check of the preview in DDEV** (`ddev start`; log in as a test user). In the browser console, run `fetch('/index.php?page=api&module=import&url=' + encodeURIComponent('<example link>')).then(r => r.json())`.
  Expected: `data.title` is the list title, and `data.wishes.length` equals the list's wish count. A garbage URL gives `error` with the invalid-link text.
- [ ] **Step 4: Manual check while logged out.** Repeat Step 3 in a private window. Expected: `error` = the not-logged-in text, and no `data`.
- [ ] **Step 5: Commit.**
```bash
git add src/api/import.php
git commit -m "feat(import): API for previewing and importing Ønskeskyen lists"
```

---

### Task 4: Import page, entry point, translations, changelog

**Files:**
- Create: `src/pages/import.php`
- Create: `src/assets/js/import.js`
- Modify: `src/classes/wishthis/Page.php` (add `public const PAGE_IMPORT = '/index.php?page=import';` next to `PAGE_WISHLISTS`, line ~47)
- Modify: `src/pages/wishlists.php` (button below the `<h1>`, line ~25)
- Modify: `translations/local/da.po`
- Modify: `changelogs/unreleased.md` ("Added")

**Interfaces:**
- Consumes: the Task 3 API contract.
- Produces: page `?page=import`. After a successful import, redirect to `Page::PAGE_WISHLISTS . '&id=' + wishlistId` (`wishlists.js` selects the list by `id`).

- [ ] **Step 1: Build the page.** Structure it like `src/pages/wishlists-saved.php`: `new Page(__FILE__, __('Import from Ønskeskyen'), 1)` (power 1 requires login), `header/bodyStart/navigation`, `$page->scripts['import'] = 'src/assets/js/import.js'` set before `header()`, as `wishlists.php:16` does.
  It has three parts:
  - a form with a URL field (label `Link to your wishlist on Ønskeskyen`) and a button `Fetch`;
  - a hidden preview with a field `Name of the new wishlist`, the line `%d wishes found`, a "select all" checkbox `Select all`, and one row per wish (checkbox ticked, image, title, price, link host or `no link`);
  - a button `Import %d wishes`, disabled when 0 are ticked.
  Errors appear in a Semantic UI `ui error message`. An empty list shows `This list has no wishes.` with the button disabled.
- [ ] **Step 2: Write `import.js`.** It wires the fetch → render preview → POST (`FormData` with `url`, `wishlist_name`, `wish_ids[]`) → redirect flow. Insert all GoWish text with `textContent`, never `innerHTML`. Only set `img.src` for `https://` URLs. Show a Semantic `loading` state on buttons while requests run. Pass translated strings from PHP to JS the same way existing pages do (check `src/assets/js/inline.js.php`), or via `data-` attributes on the page.
- [ ] **Step 3: Add the entry point** in `src/pages/wishlists.php`: `<a class="ui basic button" href="<?= Page::PAGE_IMPORT ?>"><i class="download icon"></i> <?= __('Import from Ønskeskyen') ?></a>`.
- [ ] **Step 4: Add the translations and changelog.**
  - Add every new msgid from Tasks 3–4 to `translations/local/da.po` (follow the existing format; `%d` preserved). Danish:
    - "Import from Ønskeskyen" → "Importér fra Ønskeskyen"
    - "Link to your wishlist on Ønskeskyen" → "Link til din ønskeliste på Ønskeskyen"
    - "Fetch" → "Hent"
    - "Name of the new wishlist" → "Navn på ny ønskeliste"
    - "%d wishes found" → "%d ønsker fundet"
    - "Select all" → "Vælg alle"
    - "no link" → "intet link"
    - "Import %d wishes" → "Importér %d ønsker"
    - "This list has no wishes." → "Listen har ingen ønsker."
    - "Quantity: %d" → "Antal: %d"
    - "You need to be logged in to import a wishlist." → "Du skal være logget ind for at importere en ønskeliste."
    - "That doesn't look like an Ønskeskyen wishlist link." → "Det ligner ikke et link til en ønskeliste på Ønskeskyen."
    - "We couldn't find that list. Is it set to public on Ønskeskyen?" → "Vi kunne ikke finde listen. Er den sat til offentlig på Ønskeskyen?"
    - "Ønskeskyen didn't answer as expected. Try again later." → "Ønskeskyen svarede ikke som forventet. Prøv igen senere."
    - "The list changed on Ønskeskyen. Please fetch it again." → "Listen er ændret på Ønskeskyen. Hent den venligst igen."
    - "Choose at least one wish to import." → "Vælg mindst ét ønske at importere."
    - "The import failed. Nothing was saved." → "Importen mislykkedes. Intet blev gemt."
  - Changelog line: `-   Import a wishlist from Ønskeskyen (onskeskyen.dk / GoWish) into a new wishlist`.
- [ ] **Step 5: End-to-end manual test in DDEV**, with the user's locale set to Danish:
  1. "Mine ønskelister" shows "Importér fra Ønskeskyen".
  2. Paste the example link → the preview shows all wishes with images, prices and hosts. The two free-text wishes show "intet link".
  3. Untick one, rename the list, then "Importér N ønsker" → you land on the new list, with N wishes, working shop links, images, and prices on priced wishes.
  4. Check in the DB that no imported Amazon-style URL got an affiliate tag. Temporarily edit one row's title to `<b>&"` and confirm it renders as text.
  5. Paste `https://example.com/x` → the Danish invalid-link error.
  6. `php -l` all new/changed PHP files, then `vendor/bin/phpunit tests/OnskeskyenImportTest.php --testdox` passes.
- [ ] **Step 6: Commit.**
```bash
git add src/pages/import.php src/assets/js/import.js src/classes/wishthis/Page.php src/pages/wishlists.php translations/local/da.po changelogs/unreleased.md
git commit -m "feat(import): import page for Ønskeskyen wishlists"
```
