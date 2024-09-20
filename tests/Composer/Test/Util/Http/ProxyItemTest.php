<?php declare(strict_types=1);

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Util\Http;

use Composer\Util\Http\ProxyItem;
use Composer\Test\TestCase;

class ProxyItemTest extends TestCase
{
    /**
     * @dataProvider dataMalformed
     */
    public function testThrowsOnMalformedUrl(string $url): void
    {
        self::expectException('RuntimeException');
        $proxyItem = new ProxyItem($url, 'http_proxy');
    }

    /**
     * @return array<string, array<string>>
     */
    public static function dataMalformed(): array
    {
        return [
            'ws-r' => ["http://user\rname@localhost:80"],
            'ws-n' => ["http://user\nname@localhost:80"],
            'ws-t' => ["http://user\tname@localhost:80"],
            'no-host' => ['localhost'],
            'bad-scheme' => ['ssh://proxy.com'],
            'no-port' => ['socks5://proxy.com'],
        ];
    }

    /**
     * @dataProvider dataUrlFormatting
     */
    public function testUrlFormatting(string $url, string $expected): void
    {
        $proxyItem = new ProxyItem($url, 'http_proxy');
        $proxy = $proxyItem->toRequestProxy('http');

        self::assertSame($expected, $proxy->getStatus());
    }

    /**
     * @return array<string, array<string>>
     */
    public static function dataUrlFormatting(): array
    {
        // url, expected
        return [
            'none' => ['http://proxy.com:8888', 'http://proxy.com:8888'],
            'lowercases-scheme' => ['HTTP://proxy.com:8888', 'http://proxy.com:8888'],
            'adds-http-scheme' => ['proxy.com:80', 'http://proxy.com:80'],
            'adds-http-port' => ['http://proxy.com', 'http://proxy.com:80'],
            'adds-https-port' => ['https://proxy.com', 'https://proxy.com:443'],
            'removes-user' => ['http://user@proxy.com:6180', 'http://***@proxy.com:6180'],
            'removes-user-pass' => ['http://user:p%40ss@proxy.com:6180', 'http://***@proxy.com:6180'],
        ];
    }

    /**
     * @dataProvider dataContextProxyFormatting
     */
    public function testContextProxyFormatting(string $url, string $contextProxy): void
    {
        $proxyItem = new ProxyItem($url, 'http');

        $ref = new \ReflectionProperty($proxyItem, 'optionsProxy');
        $ref->setAccessible(true);
        $optionsProxy = $ref->getValue($proxyItem);

        self::assertSame($contextProxy, $optionsProxy);
    }

    /**
     * @return list<array<string>>
     */
    public static function dataContextProxyFormatting(): array
    {
        // url, contextProxy
        return [
            ['http://proxy.com', 'tcp://proxy.com:80'],
            ['https://proxy.com', 'ssl://proxy.com:443'],
        ];
    }

    public function testAuthFormatting(): void
    {
        $userinfo = 'user:p%40ss';
        $url = sprintf('http://%s@proxy.com', $userinfo);
        $proxyItem = new ProxyItem($url, 'http');

        // curlAuth is percent-encoded userinfo
        $ref = new \ReflectionProperty($proxyItem, 'curlAuth');
        $ref->setAccessible(true);
        $curlAuth = $ref->getValue($proxyItem);
        self::assertSame($userinfo, $curlAuth);

        // proxy authorization is unencoded userinfo, base64 encoded
        $auth = base64_encode('user:p@ss');
        $ref = new \ReflectionProperty($proxyItem, 'optionsAuth');
        $ref->setAccessible(true);
        $optionsAuth = $ref->getValue($proxyItem);

        self::assertSame($auth, $optionsAuth);
    }

    /**
     * @dataProvider dataEmptyUserInfo
     */
    public function testEmptyUserInfo(string $userinfo): void
    {
        $url = sprintf('http://%s@proxy.com', $userinfo);
        $proxyItem = new ProxyItem($url, 'http');

        $ref = new \ReflectionProperty($proxyItem, 'curlAuth');
        $ref->setAccessible(true);
        $curlAuth = $ref->getValue($proxyItem);
        self::assertSame(':', $curlAuth);
    }

    /**
     * @return list<array<string>>
     */
    public static function dataEmptyUserInfo(): array
    {
        // userinfo
        return [
            [''],
            [':'],
        ];
    }
}
