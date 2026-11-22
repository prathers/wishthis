<?php

/**
 * Reads public wishlists from Ønskeskyen (onskeskyen.dk, a GoWish brand).
 *
 * GoWish has no public API. This uses the undocumented GraphQL endpoint the
 * website itself uses, so everything GoWish-specific lives in this class.
 * No database access and no translations here.
 */

namespace wishthis\Import;

final class Onskeskyen
{
    private const HOSTS = ['onskeskyen.dk', 'onskeskyen.no', 'gowish.com'];

    private const TITLE_MAX_LENGTH = 128;

    private const URL_MAX_LENGTH = 255;

    /**
     * Returns the wishlist ID from an Ønskeskyen/GoWish wishlist link, or null.
     */
    public static function parseListId(string $url): ?string
    {
        $parts = \parse_url(\trim($url));

        if (!\is_array($parts) || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }

        $scheme = \strtolower($parts['scheme'] ?? '');

        if ('http' !== $scheme && 'https' !== $scheme) {
            return null;
        }

        $host = \strtolower($parts['host']);

        if (\str_starts_with($host, 'www.')) {
            $host = \substr($host, 4);
        }

        if (!\in_array($host, self::HOSTS, true)) {
            return null;
        }

        if (!\preg_match('#^/(?:[a-z]{2}/)?wishlists/([A-Za-z0-9]+)/?$#', $parts['path'], $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * Maps one GoWish wish to a plain array, or null if it is deleted.
     *
     * @return array{id: string, title: string, description: string, url: string, image: string, price: ?float, quantity: int}|null
     */
    public static function mapWish(array $raw): ?array
    {
        if (!empty($raw['deletedAt'])) {
            return null;
        }

        $title = self::cleanText((string) ($raw['title'] ?? ''));
        $title = \mb_substr($title, 0, self::TITLE_MAX_LENGTH);

        $url = \trim((string) ($raw['url'] ?? ''));
        $url = \preg_match('#^https?://#i', $url) ? \substr($url, 0, self::URL_MAX_LENGTH) : '';

        $photos = $raw['photos'] ?? [];
        $image  = \is_array($photos) && isset($photos[0]) && \is_string($photos[0]) ? $photos[0] : '';

        $price = (float) ($raw['price'] ?? 0);

        return [
            'id'          => (string) ($raw['id'] ?? ''),
            'title'       => $title,
            'description' => \trim((string) ($raw['description'] ?? '')),
            'url'         => $url,
            'image'       => $image,
            'price'       => $price > 0 ? $price : null,
            'quantity'    => \max(1, (int) ($raw['quantity'] ?? 1)),
        ];
    }

    private static function cleanText(string $text): string
    {
        return \trim((string) \preg_replace('/\s+/u', ' ', $text));
    }
}
