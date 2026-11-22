<?php

/**
 * Import a wishlist from Ønskeskyen.
 *
 * The page script `src/assets/js/import.js` is loaded automatically.
 */

namespace wishthis;

$page = new Page(__FILE__, __('Import from Ønskeskyen'), 1);
$page->header();
$page->bodyStart();
$page->navigation();

/** Strings for import.js, decoded so the script can insert them as plain text. */
$strings = \array_map(
    fn (string $text): string => \html_entity_decode($text, \ENT_QUOTES | \ENT_HTML5, 'UTF-8'),
    [
        'wishesFound'    => __('%d wishes found'),
        'importWishes'   => __('Import %d wishes'),
        'noLink'         => __('no link'),
        'noWishes'       => __('This list has no wishes.'),
        'genericError'   => __('Ønskeskyen didn\'t answer as expected. Try again later.'),
    ]
);
?>

<main>
    <div class="ui container">
        <h1 class="ui header"><?= $page->title ?></h1>

        <?= $page->messages() ?>

        <script type="application/json" id="import-strings"><?= \json_encode($strings, \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT) ?></script>

        <div class="ui segment">
            <form class="ui form import-fetch">
                <div class="field">
                    <label for="import-url"><?= __('Link to your wishlist on Ønskeskyen') ?></label>

                    <div class="ui action input">
                        <input type="url"
                               id="import-url"
                               name="url"
                               required
                               placeholder="https://onskeskyen.dk/da/wishlists/…" />

                        <button class="ui primary button import-fetch-button" type="submit">
                            <i class="download icon"></i>
                            <?= __('Fetch') ?>
                        </button>
                    </div>
                </div>
            </form>

            <div class="ui error message import-error" hidden></div>
        </div>

        <div class="ui segment import-preview" hidden>
            <form class="ui form import-submit">
                <div class="field">
                    <label for="import-name"><?= __('Name of the new wishlist') ?></label>
                    <input type="text" id="import-name" name="wishlist_name" maxlength="128" />
                </div>

                <div class="field">
                    <p class="import-count"></p>

                    <div class="ui checkbox">
                        <input type="checkbox" id="import-select-all" checked />
                        <label for="import-select-all"><?= __('Select all') ?></label>
                    </div>
                </div>

                <div class="ui divided items import-wishes"></div>

                <div class="ui message import-empty" hidden></div>

                <button class="ui primary button import-submit-button" type="submit" disabled></button>
            </form>
        </div>
    </div>
</main>

<template id="import-wish-template">
    <div class="item">
        <div class="ui checkbox">
            <input type="checkbox" name="wish_ids[]" checked />
            <label></label>
        </div>

        <div class="ui tiny image">
            <img alt="" loading="lazy" />
        </div>

        <div class="middle aligned content">
            <div class="header import-wish-title"></div>
            <div class="meta">
                <span class="import-wish-price"></span>
                <span class="import-wish-host"></span>
            </div>
            <div class="description import-wish-description"></div>
        </div>
    </div>
</template>

<?php
$page->bodyEnd();
