<?php

/**
 * Errors while importing a wishlist from Ønskeskyen / GoWish.
 */

namespace wishthis\Import;

final class OnskeskyenException extends \RuntimeException
{
    /** The list does not exist or is not public. */
    public const NOT_FOUND = 1;

    /** GoWish could not be reached or answered with something unexpected. */
    public const UNAVAILABLE = 2;
}
