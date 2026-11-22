---
project: wishthis (prathers fork, wish.rather.dk)
date: 2026-11-22
status: draft – awaiting approval (user decisions incorporated)
---

# Import a wishlist from Ønskeskyen – design

## Intent and why

Family members are moving their wishlists from Ønskeskyen (onskeskyen.dk, the
Danish brand of GoWish) to our wishthis instance. They should be able to do it
**themselves**: paste the link to their Ønskeskyen list and get the same list in
wishthis, complete and recognisable, without retyping anything.

It is mainly a **one-time move per list**, for a handful of people. It has to
work reliably now. It does not need to keep working for years or stay in sync.

## Goals

- A logged-in user pastes an Ønskeskyen wishlist link, e.g.
  `https://onskeskyen.dk/da/wishlists/Xb9vpdulcGnMQWWu`.
- They see a preview of the wishes (title, image, price, shop link) and can
  untick any they don't want.
- "Import" creates a **new** wishlist in their account (name prefilled with the
  Ønskeskyen list title, editable) containing the selected wishes.
- Afterwards they land on the new wishlist.
- All user-facing text is translatable, with Danish wording in
  `translations/local/da.po`.

## Non-goals

- Ongoing sync or re-import that updates an earlier import.
- Importing reservations / "who bought what", followers, collaborators,
  cover photo, event date.
- Importing into an *existing* wishlist (can be added later).
- Private lists or anything that requires logging in to Ønskeskyen.
- Other wishlist services.
- An upstream PR to wishthis/wishthis. This lives only in the prathers fork.

## Anti-goals (would be a failure even if it "works")

- Silently losing wishes: the preview must show every wish GoWish returns, and
  the import must either create all selected wishes or none (one transaction).
- Confusing English or technical errors for family members. Every failure mode
  gets a plain, translated message.
- Slow imports: no per-wish fetching of shop pages during import.

## Constraints and what we know about the source

- The Ønskeskyen HTML page contains only metadata (title, wish count). The
  wishes are loaded client-side from GoWish's **undocumented** GraphQL API at
  `https://api.gowish.com/graphql`.
- Two test calls on 2026-11-22 **without login**, made from the developer's
  laptop (not the production server), returned all 19/19 wishes of the
  example list. The field set comes from the site's own `WishItem` fragment,
  minus personal data (reservations, reservedBy):

  ```graphql
  query($id: ID!) {
    wishlist(id: $id) {
      id
      title
      wishes(input: {cursor: 0, limit: 100}) {
        data { id title description quantity url index deletedAt price currency photos }
        totalCount
        nextCursor
      }
    }
  }
  ```

  Request: `POST`, `Content-Type: application/json`,
  body `{"query": "...", "variables": {"id": "<listId>"}}`.
  The test also sent `Origin: https://onskeskyen.dk` and a browser-like
  User-Agent. Whether those are required is unknown; send both to be safe.
- Field meanings, as observed:
  - `title`: wish title (may contain double spaces, so trim/collapse).
  - `price`: number; `0` means "no price set".
  - `currency`: e.g. `DKK`.
  - `photos`: array of image URLs (`img.gowish.com/...`); use the first.
  - `url`: the plain shop URL, e.g.
    `https://nordicdrop.dk/products/vandflaske-350ml-blush`. Empty for
    free-text wishes (2 of 19 in the example), which then get no URL.
  - `description`: the user's note on the wish (none in the example list, but
    the field exists and is returned).
  - `quantity`: integer (all `1` in the example).
  - `index`: sort position. Returned already in display order (highest first).
  - `deletedAt`: non-null means the wish is deleted. Skip those.
- **Not yet verified from the production server.** Hosting can block outbound
  calls, and GoWish may treat server IPs differently. The plan's first step is
  therefore a reachability check from wish.rather.dk (one cURL call with the
  query above). If it fails, stop and revisit. A paste/upload fallback would
  be a different feature.
- Because the API is private, it can change or start blocking us without
  notice. All GoWish-specific code must live in **one class**, so a breakage
  is fixed in one place and never takes down the rest of wishthis.

## Approaches considered

1. **Server-side fetch in PHP (chosen).** wishthis calls the GoWish API from
   the server, maps the result and shows a preview. GoWish-specific logic is
   isolated in one class, and there are no browser CORS issues.
2. **Browser-side fetch in JavaScript.** Most likely blocked by CORS on GoWish's
   API, and it spreads the fragile part across the front end. Rejected.
3. **No API: family paste or upload their wishes.** Robust against GoWish
   changes, but Ønskeskyen has no export, so people would retype or paste
   messy text, which defeats the purpose. Kept only as a fallback idea if
   GoWish starts blocking server calls.

The user picked approach 1.

## Design

### Pieces

| Piece | Location | Responsibility |
|---|---|---|
| `Onskeskyen` class | `src/classes/wishthis/Import/Onskeskyen.php` (namespace `wishthis\Import`) | Parse list ID from a URL; call GoWish (with pagination); map raw wishes to plain arrays `{title, url, image, price}`; throw typed exceptions on failure. No database access. |
| Import API | `src/api/import.php` (same structure as `src/api/wishlists.php`) | `GET ?url=` returns the preview JSON; `POST` creates the wishlist and wishes in one DB transaction and returns the new wishlist ID. Requires a logged-in user. |
| Import page | `src/pages/import.php` + `src/assets/js/import.js` | Form with URL field, then preview list with checkboxes and an editable list name, then the Import button. Semantic UI like the other pages. |
| Entry point | `src/pages/wishlists.php` | Button "Import from Ønskeskyen" linking to the import page. |
| Translations | `translations/local/da.po` | Danish strings for all new msgids (English msgids in code, as elsewhere). |
| Changelog | `changelogs/unreleased.md` under "Added" | One line. |
| Tests | `tests/OnskeskyenImportTest.php` + synthetic fixture JSON | Offline unit tests of URL parsing and mapping. |

### URL parsing

Accept `onskeskyen.dk`, `onskeskyen.no` and `gowish.com`, with or without a
language segment (`/da/`) and query string, matching
`/wishlists/<id>` where `<id>` is alphanumeric. Anything else gives the
"That doesn't look like an Ønskeskyen wishlist link" error.

### Fetching

- cURL `POST` to the API with a 10-second timeout.
- Paginate: start at `cursor: 0, limit: 100`, then follow `nextCursor` until it
  is `0`/`null` or all `totalCount` wishes are collected. Hard cap of 500
  wishes as a safety stop.
- `data.wishlist === null`, or GraphQL `errors` meaning not-found/forbidden,
  gives "We couldn't find that list. Is it set to public on Ønskeskyen?"
- Network error, non-200 or an unexpected shape gives "Ønskeskyen didn't answer
  as expected. Try again later." Log the details server-side.

### Mapping to wishthis

| GoWish | wishthis |
|---|---|
| `title` (trimmed, whitespace collapsed) | `wishes.title` |
| `url` | `wishes.url` (empty if missing or not `http(s)://`) |
| `description` | `wishes.description` |
| `quantity` > 1 | appended to the description as a translated line, e.g. "Antal: 2" (wishthis has no quantity field) |
| `deletedAt` set | wish skipped entirely |
| returned order | not preserved: wishthis sorts by priority, url, title (`Wishlist.php:128`) |
| `photos[0]` | `wishes.image` (empty if none) |
| `price` > 0 | `products.price` for the wish (same `REPLACE INTO products` as `src/api/wishes.php`) and `is_purchasable` set; `0` means no price |
| `currency` | not stored. wishthis has no per-wish currency, so prices are taken as-is. |
| list `title` | default name of the new wishlist |

Wishes are inserted directly (same columns and sanitising as
`src/api/wishes.php`, but **without** affiliate-link rewriting), not via the embed/fetch-info path, so the import is
fast. Wishlist creation follows `src/api/wishlists.php` (user, name, hash).

### Flow

```
Mine ønskelister → [Importér fra Ønskeskyen]
  ┌───────────────────────────────────────────────┐
  │ Link til din ønskeliste på Ønskeskyen          │
  │ [ https://onskeskyen.dk/da/wishlists/…   ] [Hent] │
  └───────────────────────────────────────────────┘
  ↓ preview
  Navn på ny ønskeliste: [ Eas ønsker            ]
  19 ønsker fundet                       [x] Vælg alle
  [x] [img] Børneflaske - 350ML - Blush   199 kr.  nordicdrop.dk
  [x] [img] Denne skoletaske fra Beckmann …    –   (intet link)
  …
                              [Importér 19 ønsker]
  ↓
  redirect to the new wishlist + success toast
```

### Error and edge cases

- Not logged in: the page requires login like the other account pages.
- Empty list: preview says the list has no wishes, and the Import button is disabled.
- Importing the same list twice: allowed and creates a second list. No dedupe.
- Nothing ticked: Import button disabled.
- DB failure mid-import: roll back the transaction and show a generic error.
- The POST re-fetches from GoWish server-side using the URL plus the list of
  selected GoWish wish IDs. It does not trust wish data posted from the
  browser.

### Testing

- Unit tests (PHPUnit, offline) for URL parsing (valid and invalid variants),
  URL validation, title cleanup, quantity → description, skipping deleted
  wishes, and price `0` handling, using a **synthetic** fixture shaped like the real
  response. Don't commit the real family list.
- Reachability check from the production server before UI work (see
  Constraints).
- Manual end-to-end test on a local instance with the example link, checking
  that wishes, images, prices and links appear on the new list.

## Decisions made by Claude (open to correction)

- Import always creates a new wishlist, named after the Ønskeskyen list.
- Currency is ignored. Prices are stored as numbers.
- No dedupe when importing the same list twice.
- Entry point is a button on the "My wishlists" page.
- Notes and quantity are imported. Reservations are deliberately not fetched
  (they contain other people's names).
- If a selected wish has disappeared from the Ønskeskyen list between preview
  and import, the whole import is refused with "The list changed, please fetch
  it again", rather than importing a partial list.

## Decided by the user

- **Images are linked, not copied:** store the `img.gowish.com` URL, the same
  way wishthis already links to shop images. Accepted risk: photos can
  disappear if GoWish removes them, e.g. after a family member deletes their
  Ønskeskyen account.
- **Links are imported untouched:** do *not* run imported URLs through
  `Wish::getAffiliateLink()` or any other affiliate rewriting.

## Open questions

- None.

## Left to the builder

- Exact Semantic UI markup and JS structure, following existing pages.
- How the import page is registered/routed (follow existing page conventions).
- Exception class names and log format.
