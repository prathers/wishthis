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
}
