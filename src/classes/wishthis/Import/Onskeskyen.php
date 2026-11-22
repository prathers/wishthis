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

    public const API_URL = 'https://api.gowish.com/graphql';

    public const PAGE_SIZE = 100;

    public const MAX_WISHES = 500;

    private const TIMEOUT_SECONDS = 10;

    private const USER_AGENT = 'Mozilla/5.0 (compatible; wishthis wishlist import)';

    /** Only the fields we import. Deliberately no reservations (other people's names). */
    private const QUERY = 'query getWishlistForImport($id: ID!, $cursor: Int, $limit: Int) {
  wishlist(id: $id) {
    id
    title
    wishes(input: {cursor: $cursor, limit: $limit}) {
      data { id title description quantity url index deletedAt price currency photos }
      totalCount
      nextCursor
    }
  }
}';

    /** @var \Closure(string): array{status: int, body: string|false} */
    private \Closure $transport;

    /**
     * @param ?\Closure $transport `function (string $jsonBody): array{status: int, body: string|false}`.
     *                             Defaults to a cURL POST to GoWish.
     */
    public function __construct(?\Closure $transport = null)
    {
        $this->transport = $transport ?? \Closure::fromCallable([self::class, 'curlTransport']);
    }

    /**
     * Fetches a public wishlist with its (mapped, non-deleted) wishes.
     *
     * @return array{title: string, wishes: list<array>}
     *
     * @throws OnskeskyenException
     */
    public function fetch(string $listId): array
    {
        $title       = '';
        $wishes      = [];
        $rawCount    = 0;
        $cursor      = 0;
        $seenCursors = [];

        while (true) {
            $seenCursors[] = $cursor;

            $list = $this->requestPage($listId, $cursor);

            $title = \is_string($list['title'] ?? null) ? $list['title'] : '';
            $page  = $list['wishes'];

            foreach ($page['data'] as $raw) {
                if (!\is_array($raw)) {
                    throw new OnskeskyenException('Unexpected wish entry', OnskeskyenException::UNAVAILABLE);
                }

                $rawCount++;
                $wish = self::mapWish($raw);

                if (null !== $wish) {
                    $wishes[] = $wish;
                }

                if (\count($wishes) >= self::MAX_WISHES) {
                    break 2;
                }
            }

            $next  = $page['nextCursor'] ?? null;
            $total = (int) ($page['totalCount'] ?? 0);

            if (
                   empty($next)
                || \in_array($next, $seenCursors, true)
                || $rawCount >= $total
                || empty($page['data'])
            ) {
                break;
            }

            $cursor = (int) $next;
        }

        return [
            'title'  => $title,
            'wishes' => $wishes,
        ];
    }

    /**
     * @return array{title?: mixed, wishes: array{data: array, nextCursor?: mixed, totalCount?: mixed}}
     *
     * @throws OnskeskyenException
     */
    private function requestPage(string $listId, int $cursor): array
    {
        $body = \json_encode(
            [
                'query'     => self::QUERY,
                'variables' => [
                    'id'     => $listId,
                    'cursor' => $cursor,
                    'limit'  => self::PAGE_SIZE,
                ],
            ]
        );

        $response = ($this->transport)((string) $body);

        if (200 !== ($response['status'] ?? 0) || !\is_string($response['body'] ?? null)) {
            throw new OnskeskyenException(
                'GoWish answered HTTP ' . ($response['status'] ?? 0),
                OnskeskyenException::UNAVAILABLE
            );
        }

        $json = \json_decode($response['body'], true);

        if (!\is_array($json)) {
            throw new OnskeskyenException('GoWish answered with invalid JSON', OnskeskyenException::UNAVAILABLE);
        }

        foreach ((array) ($json['errors'] ?? []) as $error) {
            $message = \is_array($error) ? (string) ($error['message'] ?? '') : '';
            $code    = \is_array($error) ? (int) ($error['extensions']['code'] ?? 0) : 0;

            if (
                   \in_array($code, [403, 404], true)
                || \preg_match('/not found|does not exist|forbidden|access|private|permission/i', $message)
            ) {
                throw new OnskeskyenException($message, OnskeskyenException::NOT_FOUND);
            }
        }

        if (!\is_array($json['data'] ?? null) || !\array_key_exists('wishlist', $json['data'])) {
            $message = isset($json['errors']) ? \json_encode($json['errors']) : 'missing data.wishlist';

            throw new OnskeskyenException('Unexpected GoWish response: ' . $message, OnskeskyenException::UNAVAILABLE);
        }

        $list = $json['data']['wishlist'];

        if (null === $list) {
            throw new OnskeskyenException('Wishlist not found', OnskeskyenException::NOT_FOUND);
        }

        if (!\is_array($list) || !\is_array($list['wishes'] ?? null) || !\is_array($list['wishes']['data'] ?? null)) {
            throw new OnskeskyenException('Unexpected GoWish response shape', OnskeskyenException::UNAVAILABLE);
        }

        return $list;
    }

    /**
     * @return array{status: int, body: string|false}
     */
    private static function curlTransport(string $jsonBody): array
    {
        $ch = \curl_init(self::API_URL);

        \curl_setopt_array(
            $ch,
            [
                \CURLOPT_POST           => true,
                \CURLOPT_POSTFIELDS     => $jsonBody,
                \CURLOPT_RETURNTRANSFER => true,
                \CURLOPT_TIMEOUT        => self::TIMEOUT_SECONDS,
                \CURLOPT_CONNECTTIMEOUT => self::TIMEOUT_SECONDS,
                \CURLOPT_USERAGENT      => self::USER_AGENT,
                \CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Origin: https://onskeskyen.dk',
                ],
            ]
        );

        $body   = \curl_exec($ch);
        $status = (int) \curl_getinfo($ch, \CURLINFO_HTTP_CODE);

        if (false === $body) {
            \error_log('Ønskeskyen import: cURL error: ' . \curl_error($ch));
        }

        return [
            'status' => $status,
            'body'   => \is_string($body) ? $body : false,
        ];
    }

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

        $url = self::safeUrl((string) ($raw['url'] ?? ''), '#^https?://#i');
        $url = \substr($url, 0, self::URL_MAX_LENGTH);

        $photos = $raw['photos'] ?? [];
        $image  = \is_array($photos) && isset($photos[0]) && \is_string($photos[0]) ? $photos[0] : '';
        $image  = self::safeUrl($image, '#^https://#i');

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

    /**
     * wishthis prints wish URLs into HTML attributes unescaped (e.g. `<img src>`),
     * so only accept URLs with the given scheme and without characters that could
     * break out of an attribute.
     */
    private static function safeUrl(string $url, string $schemePattern): string
    {
        $url = \trim($url);

        if (!\preg_match($schemePattern, $url) || \preg_match('/[\s"\'<>`\\\\]/', $url)) {
            return '';
        }

        return $url;
    }

    private static function cleanText(string $text): string
    {
        return \trim((string) \preg_replace('/\s+/u', ' ', $text));
    }
}
