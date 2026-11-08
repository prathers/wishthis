<?php

/**
 * Gettext
 */

namespace wishthis;

function __(
    string $text,
    string|null $context = null,
    User|null $user = null
): string {
    if (null === $user) {
        $user = User::getCurrent();
    }

    if (null !== $user->translations) {
        $translation = $user->translations->find($context, $text);

        if ($translation) {
            $translationText = $translation->getTranslation();

            if (!empty($translationText)) {
                return \htmlentities($translationText);
            }
        }
    }

    return \htmlentities($text);
}

/**
 * Plurals are stored under the singular msgid with `msgstr[0]` (singular)
 * and `msgstr[1]` (plural), so look up the singular and pick the form.
 * Uses the `n != 1` rule, which covers languages with two plural forms.
 */
function _n(string $singular, string $plural, int $amount): string
{
    $isPlural = 1 !== $amount;
    $user     = User::getCurrent();

    if (null !== $user->translations) {
        $translation = $user->translations->find(null, $singular);

        if ($translation) {
            $translationText = $isPlural
                             ? ($translation->getPluralTranslations()[0] ?? '')
                             : $translation->getTranslation();

            if (!empty($translationText)) {
                return \htmlentities($translationText);
            }
        }
    }

    return \htmlentities($isPlural ? $plural : $singular);
}

function _x(string $text, string|null $context = null): string
{
    return __($text, $context);
}
