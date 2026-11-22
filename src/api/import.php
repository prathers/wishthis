<?php

/**
 * Import a wishlist from Ønskeskyen (GoWish).
 *
 * GET  ?url=<link>                       Preview: list title and wishes.
 * POST url, wishlist_name, wish_ids[]    Creates a new wishlist with the selected wishes.
 *
 * On failure `$response['error']` holds a translated message.
 */

namespace wishthis;

use wishthis\Import\Onskeskyen;
use wishthis\Import\OnskeskyenException;

global $database;

$user = User::getCurrent();

if (!$user->isLoggedIn()) {
    $response['error'] = __('You need to be logged in to import a wishlist.');

    return;
}

/**
 * Fetches the list behind `$url`, or sets `$response['error']` and returns null.
 */
$fetchList = function (string $url) use (&$response): ?array {
    $listId = Onskeskyen::parseListId($url);

    if (null === $listId) {
        $response['error'] = __('That doesn\'t look like an Ønskeskyen wishlist link.');

        return null;
    }

    try {
        return (new Onskeskyen())->fetch($listId);
    } catch (OnskeskyenException $exception) {
        if (OnskeskyenException::NOT_FOUND === $exception->getCode()) {
            $response['error'] = __('We couldn\'t find that list. Is it set to public on Ønskeskyen?');
        } else {
            \error_log('Ønskeskyen import: ' . $exception->getMessage());

            $response['error'] = __('Ønskeskyen didn\'t answer as expected. Try again later.');
        }

        return null;
    }
};

/** Adds the quantity to the description, since wishthis has no quantity field. */
$composeDescription = function (array $wish): string {
    $description = $wish['description'];

    if ($wish['quantity'] > 1) {
        $quantityLine = \sprintf(__('Quantity: %d'), $wish['quantity']);
        $description  = '' === $description ? $quantityLine : $description . "\n\n" . $quantityLine;
    }

    return $description;
};

switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
        $list = $fetchList((string) ($_GET['url'] ?? ''));

        if (null === $list) {
            break;
        }

        $response['data'] = [
            'title'  => $list['title'],
            'wishes' => \array_map(
                function (array $wish) use ($composeDescription): array {
                    return [
                        'id'          => $wish['id'],
                        'title'       => $wish['title'],
                        'description' => $composeDescription($wish),
                        'url'         => $wish['url'],
                        'image'       => $wish['image'],
                        'price'       => $wish['price'],
                    ];
                },
                $list['wishes']
            ),
        ];
        break;

    case 'POST':
        $selectedIds = \array_values(\array_unique(\array_map('strval', (array) ($_POST['wish_ids'] ?? []))));

        if (empty($selectedIds)) {
            $response['error'] = __('Choose at least one wish to import.');
            break;
        }

        /** Re-fetch: never trust wish data posted from the browser. */
        $list = $fetchList((string) ($_POST['url'] ?? ''));

        if (null === $list) {
            break;
        }

        $wishesById = [];

        foreach ($list['wishes'] as $wish) {
            $wishesById[$wish['id']] = $wish;
        }

        foreach ($selectedIds as $selectedId) {
            if (!isset($wishesById[$selectedId])) {
                $response['error'] = __('The list changed on Ønskeskyen. Please fetch it again.');
                break 2;
            }
        }

        $wishlistName = \trim((string) ($_POST['wishlist_name'] ?? ''));
        $wishlistName = '' === $wishlistName ? $list['title'] : $wishlistName;
        $wishlistName = \mb_substr(Sanitiser::getTitle($wishlistName), 0, 128);

        try {
            $database->pdo->beginTransaction();

            /** Wishlist: same as `src/api/wishlists.php`. */
            $userId       = Sanitiser::getNumber($user->getId());
            $wishlistHash = \sha1(\time() . $userId . $wishlistName);

            $database->query(
                'INSERT INTO `wishlists` (
                    `user`,
                    `name`,
                    `hash`
                ) VALUES (
                    :user_id,
                    :wishlist_name,
                    :wishlist_hash
                );',
                [
                    'user_id'       => $userId,
                    'wishlist_name' => $wishlistName,
                    'wishlist_hash' => $wishlistHash,
                ]
            );

            $wishlistId = $database->lastInsertId();

            /** Wishes: same sanitising as `src/api/wishes.php`, but no affiliate rewriting. */
            foreach ($selectedIds as $selectedId) {
                $wish        = $wishesById[$selectedId];
                $title       = \mb_substr(Sanitiser::sanitiseText($wish['title']), 0, 128);
                $description = Sanitiser::sanitiseText($composeDescription($wish));
                $hasPrice    = null !== $wish['price'];

                $database->query(
                    'INSERT INTO `wishes` (
                        `wishlist`,
                        `title`,
                        `description`,
                        `image`,
                        `url`,
                        `priority`,
                        `is_purchasable`
                    ) VALUES (
                        :wishlist_id,
                        :wish_title,
                        :wish_description,
                        :wish_image,
                        :wish_url,
                        :wish_priority,
                        :wish_is_purchasable
                    );',
                    [
                        'wishlist_id'         => $wishlistId,
                        'wish_title'          => '' === $title ? null : $title,
                        'wish_description'    => '' === $description ? null : $description,
                        'wish_image'          => '' === $wish['image'] ? null : $wish['image'],
                        'wish_url'            => '' === $wish['url'] ? null : $wish['url'],
                        'wish_priority'       => null,
                        'wish_is_purchasable' => $hasPrice,
                    ]
                );

                if ($hasPrice) {
                    $database->query(
                        'INSERT INTO `products` (
                            `wish`,
                            `price`
                        ) VALUES (
                            :wish_id,
                            :wish_price
                        );',
                        [
                            'wish_id'    => $database->lastInsertId(),
                            'wish_price' => $wish['price'],
                        ]
                    );
                }
            }

            $database->pdo->commit();

            $response['data'] = [
                'wishlistId' => $wishlistId,
            ];
        } catch (\Throwable $exception) {
            if ($database->pdo->inTransaction()) {
                $database->pdo->rollBack();
            }

            \error_log('Ønskeskyen import failed: ' . $exception->getMessage());

            $response['error'] = __('The import failed. Nothing was saved.');
        }
        break;
}
