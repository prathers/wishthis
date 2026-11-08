## Unreleased

### Added

-   Textfield for public wishlist url (#220)
-   SQLite support (#67)
-   PHP 8.4 support
-   Instance specific translations in `translations/local/` which are layered on top of the Transifex translations

### Changed

-   Updated translations from transifex
-   Only logged in users can fulfil a wish. Fulfilled wishes stay visible on the wishlist (blurred out) instead of disappearing, and the user who fulfilled a wish can un-fulfil it again. Requires a database migration (`wishes`.`fulfilled_by`).

### Deprecated

-   Nothing

### Removed

-   Nothing

### Fixed

-   An error when attempting to send an email (such as requesting new wishes) when the instance does not have MJML API keys configured (#215)
-   Outdated transifex project link (#231)
-   Apache RewriteRules not working (56bbc6d)

### Security

-   Changing the status of a wish via the API now requires a logged in user; only the wishlist owner can mark a wish as fulfilled
