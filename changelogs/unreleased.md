## Unreleased

### Added

-   Textfield for public wishlist url (#220)
-   SQLite support (#67)
-   PHP 8.4 support
-   Instance specific translations in `translations/local/` which are layered on top of the Transifex translations

### Changed

-   Emails are sent as simple HTML/plain text directly from the server; the MJML API is no longer needed. Optional `EMAIL_FROM` / `EMAIL_FROM_NAME` in `config.php`
-   Updated translations from transifex
-   Only logged in users can fulfil a wish. Fulfilled wishes stay visible on the wishlist (blurred out) instead of disappearing, and the user who fulfilled a wish can un-fulfil it again. Requires a database migration (`wishes`.`fulfilled_by`).

### Deprecated

-   Nothing

### Removed

-   Nothing

### Fixed

-   Profile page crashing after the preferences section (SQL syntax error), which made the account tab unusable
-   Password reset crashing for unknown email addresses and manipulated reset links
-   Password reset not possible when user registration is disabled
-   Links in emails using `http` behind a reverse proxy
-   An error when attempting to send an email (such as requesting new wishes) when the instance does not have MJML API keys configured (#215)
-   Outdated transifex project link (#231)
-   Apache RewriteRules not working (56bbc6d)

### Security

-   Changing the status of a wish via the API now requires a logged in user; only the wishlist owner can mark a wish as fulfilled
- Fixed: plural strings (e. g. "%d more subscribers needed") were never translated, because `_n()` looked up the plural English text instead of the singular msgid
- Fixed: the default wishlist name was double-encoded for languages with special characters (e. g. "Mine h&aring;b og dr&oslash;mme" in Danish)
