<?php

declare(strict_types=1);

namespace wishthis\Tests\Import;

require_once __DIR__ . '/../src/classes/wishthis/Import/OnskeskyenException.php';
require_once __DIR__ . '/../src/classes/wishthis/Import/Onskeskyen.php';

use PHPUnit\Framework\TestCase;
use wishthis\Import\Onskeskyen;
use wishthis\Import\OnskeskyenException;

final class OnskeskyenImportTest extends TestCase
{
    public function testParseListIdAcceptsKnownHosts(): void
    {
        foreach (
            [
                'https://onskeskyen.dk/da/wishlists/AbC123xyz',
                'https://www.onskeskyen.dk/wishlists/AbC123xyz?ref=share',
                'https://onskeskyen.no/no/wishlists/AbC123xyz',
                'https://gowish.com/en/wishlists/AbC123xyz/',
                '  https://onskeskyen.dk/da/wishlists/AbC123xyz  ',
            ] as $url
        ) {
            $this->assertSame('AbC123xyz', Onskeskyen::parseListId($url), $url);
        }
    }

    public function testParseListIdRejectsOthers(): void
    {
        foreach (
            [
                '',
                'onskeskyen',
                'https://evil.dk/da/wishlists/AbC123xyz',
                'https://onskeskyen.dk.evil.dk/wishlists/AbC123xyz',
                'https://onskeskyen.dk/da/profile/AbC123xyz',
                'https://onskeskyen.dk/da/wishlists/',
                'https://onskeskyen.dk/da/wishlists/abc-<x>',
            ] as $url
        ) {
            $this->assertNull(Onskeskyen::parseListId($url), $url);
        }
    }

    public function testMapWishBasic(): void
    {
        $wish = Onskeskyen::mapWish(
            [
                'id'          => 'w1',
                'title'       => "  Toy  car\n blue ",
                'description' => 'Size 7',
                'quantity'    => 2,
                'url'         => 'https://shop.example/p/1',
                'deletedAt'   => null,
                'price'       => 199.95,
                'currency'    => 'DKK',
                'photos'      => ['https://img.gowish.com/a.jpg', 'https://img.gowish.com/b.jpg'],
            ]
        );

        $this->assertSame(
            [
                'id'          => 'w1',
                'title'       => 'Toy car blue',
                'description' => 'Size 7',
                'url'         => 'https://shop.example/p/1',
                'image'       => 'https://img.gowish.com/a.jpg',
                'price'       => 199.95,
                'quantity'    => 2,
            ],
            $wish
        );
    }

    public function testMapWishFreeTextAndZeroPrice(): void
    {
        $wish = Onskeskyen::mapWish(
            [
                'id'          => 'w2',
                'title'       => 'Used school bag',
                'description' => null,
                'quantity'    => null,
                'url'         => null,
                'deletedAt'   => null,
                'price'       => 0,
                'photos'      => [],
            ]
        );

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

        $long = 'https://shop.example/' . \str_repeat('a', 300);
        $this->assertSame(255, \strlen(Onskeskyen::mapWish(['id' => 'w', 'title' => 't', 'url' => $long])['url']));
        $this->assertSame(128, \mb_strlen(Onskeskyen::mapWish(['id' => 'w', 'title' => \str_repeat('é', 200)])['title']));
    }

    public function testMapWishRejectsUrlsThatCouldBreakOutOfAttributes(): void
    {
        foreach (
            [
                'https://img.gowish.com/a.jpg" onerror="alert(1)',
                "https://img.gowish.com/a.jpg' x='",
                'https://img.gowish.com/<script>.jpg',
                'https://img.gowish.com/a b.jpg',
                'javascript:alert(1)',
                'http://img.gowish.com/plain-http.jpg',
            ] as $bad
        ) {
            $wish = Onskeskyen::mapWish(['id' => 'w', 'title' => 't', 'url' => $bad, 'photos' => [$bad]]);
            $this->assertSame('', $wish['image'], $bad);

            if (!\str_starts_with($bad, 'http://')) {
                $this->assertSame('', $wish['url'], $bad);
            }
        }
    }

    public function testFetchPaginatesAndSkipsDeleted(): void
    {
        $sent      = [];
        $transport = $this->fakeTransport([$this->fixture('page1'), $this->fixture('page2')], $sent);
        $result    = (new Onskeskyen($transport))->fetch('List1');

        $this->assertSame('Test list', $result['title']);
        $this->assertCount(3, $result['wishes']);
        $this->assertSame(['a1', 'a2', 'a4'], \array_column($result['wishes'], 'id'));
        $this->assertCount(2, $sent);
        $this->assertSame(['id' => 'List1', 'cursor' => 0, 'limit' => 100], \json_decode($sent[0], true)['variables']);
        $this->assertSame(['id' => 'List1', 'cursor' => 3, 'limit' => 100], \json_decode($sent[1], true)['variables']);
        $this->assertStringNotContainsString('reservations', \json_decode($sent[0], true)['query']);
    }

    public function testFetchNotFound(): void
    {
        $this->expectExceptionCode(OnskeskyenException::NOT_FOUND);

        $sent = [];
        (new Onskeskyen($this->fakeTransport(['{"data":{"wishlist":null}}'], $sent)))->fetch('X');
    }

    public function testFetchNotFoundFromGraphqlError(): void
    {
        $this->expectExceptionCode(OnskeskyenException::NOT_FOUND);

        $sent = [];
        $body = '{"errors":[{"message":"Wishlist not found"}],"data":null}';
        (new Onskeskyen($this->fakeTransport([$body], $sent)))->fetch('X');
    }

    public function testFetchNotFoundFromGraphqlErrorCode(): void
    {
        foreach (
            [
                '{"errors":[{"message":"Wishlist with id \"{id}\" does not exists","path":["wishlist"],"extensions":{"code":404,"serviceName":"wishlists"}}],"data":null}',
                '{"errors":[{"message":"Nope","extensions":{"code":403}}],"data":null}',
            ] as $body
        ) {
            $sent = [];

            try {
                (new Onskeskyen($this->fakeTransport([$body], $sent)))->fetch('X');
                $this->fail('no exception for ' . $body);
            } catch (OnskeskyenException $e) {
                $this->assertSame(OnskeskyenException::NOT_FOUND, $e->getCode(), $body);
            }
        }
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
        $page = function (int $n, $nextCursor): string {
            return \json_encode(
                [
                    'data' => [
                        'wishlist' => [
                            'id'     => 'X',
                            'title'  => 't',
                            'wishes' => [
                                'totalCount' => 9999,
                                'nextCursor' => $nextCursor,
                                'data'       => \array_map(fn ($i) => ['id' => "w$n-$i", 'title' => 't'], \range(1, 100)),
                            ],
                        ],
                    ],
                ]
            );
        };

        /** Repeated cursor: stops after the second page. */
        $calls  = 0;
        $result = (new Onskeskyen(
            function () use (&$calls, $page) {
                return ['status' => 200, 'body' => $page(++$calls, 5)];
            }
        ))->fetch('X');
        $this->assertSame(2, $calls);
        $this->assertCount(200, $result['wishes']);

        /** Ever-increasing cursor: stops at the 500 wish cap. */
        $calls  = 0;
        $result = (new Onskeskyen(
            function () use (&$calls, $page) {
                ++$calls;

                return ['status' => 200, 'body' => $page($calls, $calls * 100)];
            }
        ))->fetch('X');
        $this->assertSame(5, $calls);
        $this->assertCount(500, $result['wishes']);
    }

    private function fixture(string $name): string
    {
        return (string) \file_get_contents(__DIR__ . '/fixtures/onskeskyen/' . $name . '.json');
    }

    private function fakeTransport(array $bodies, array &$sent): \Closure
    {
        return function (string $jsonBody) use (&$bodies, &$sent): array {
            $sent[] = $jsonBody;

            return ['status' => 200, 'body' => \array_shift($bodies) ?? false];
        };
    }
}
