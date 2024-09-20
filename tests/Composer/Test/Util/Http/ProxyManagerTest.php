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

use Composer\Util\Http\ProxyManager;
use Composer\Test\TestCase;

class ProxyManagerTest extends TestCase
{
    protected function setUp(): void
    {
        unset(
            $_SERVER['HTTP_PROXY'],
            $_SERVER['http_proxy'],
            $_SERVER['HTTPS_PROXY'],
            $_SERVER['https_proxy'],
            $_SERVER['NO_PROXY'],
            $_SERVER['no_proxy'],
            $_SERVER['CGI_HTTP_PROXY'],
            $_SERVER['cgi_http_proxy']
        );
        ProxyManager::reset();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        unset(
            $_SERVER['HTTP_PROXY'],
            $_SERVER['http_proxy'],
            $_SERVER['HTTPS_PROXY'],
            $_SERVER['https_proxy'],
            $_SERVER['NO_PROXY'],
            $_SERVER['no_proxy'],
            $_SERVER['CGI_HTTP_PROXY'],
            $_SERVER['cgi_http_proxy']
        );
        ProxyManager::reset();
    }

    public function testInstantiation(): void
    {
        $originalInstance = ProxyManager::getInstance();
        $sameInstance = ProxyManager::getInstance();
        self::assertTrue($originalInstance === $sameInstance);

        ProxyManager::reset();
        $newInstance = ProxyManager::getInstance();
        self::assertFalse($sameInstance === $newInstance);
    }

    /**
     * Used by Composer\Console\Application::hintCommonErrors
     */
    public function testThrowsOnBadProxyUrlWithPrefixedMessage(): void
    {
        $_SERVER['http_proxy'] = 'localhost';
        $proxyManager = ProxyManager::getInstance();

        self::expectException('Composer\Downloader\TransportException');
        self::expectExceptionMessage('Unable to use a proxy:');
        $proxyManager->getProxyForRequest('http://example.com');
    }

    /**
     * @dataProvider dataCaseOverrides
     *
     * @param array<string, string> $server
     * @param non-empty-string      $url
     */
    public function testLowercaseOverridesUppercase(array $server, string $url, string $expectedUrl): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest($url);
        self::assertSame($expectedUrl, $proxy->getStatus());
    }

    /**
     * @return list<array{0: array<string, string>, 1: string, 2: string}>
     */
    public static function dataCaseOverrides(): array
    {
        // server, url, expectedUrl
        return [
            [['HTTP_PROXY' => 'http://upper.com', 'http_proxy' => 'http://lower.com'], 'http://repo.org', 'http://lower.com:80'],
            [['CGI_HTTP_PROXY' => 'http://upper.com', 'cgi_http_proxy' => 'http://lower.com'], 'http://repo.org', 'http://lower.com:80'],
            [['HTTPS_PROXY' => 'http://upper.com', 'https_proxy' => 'http://lower.com'], 'https://repo.org', 'http://lower.com:80'],
            [['NO_PROXY' => 'upper.com', 'no_proxy' => 'lower.com', 'http_proxy' => 'http://proxy.com'], 'http://lower.com', 'excluded by no_proxy'],
        ];
    }

    /**
     * @dataProvider dataCGIProxy
     *
     * @param array<string, string> $server
     */
    public function testCGIProxyIsOnlyUsedWhenNoHttpProxy(array $server, string $expectedUrl): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest('http://repo.org');
        self::assertSame($expectedUrl, $proxy->getStatus());
    }

    /**
     * @return list<array{0: array<string, string>, 1: string}>
     */
    public static function dataCGIProxy(): array
    {
        // server, expectedUrl
        return [
            [['CGI_HTTP_PROXY' => 'http://cgi.com:80'], 'http://cgi.com:80'],
            [['http_proxy' => 'http://http.com:80', 'CGI_HTTP_PROXY' => 'http://cgi.com:80'], 'http://http.com:80'],
        ];
    }

    /**
     * @dataProvider dataSchemeSpecific
     *
     * @param array<string, string> $server
     * @param non-empty-string $url
     */
    public function testImplementsSchemeSpecificEnvs(array $server, string $url, string $status): void
    {
        $_SERVER = array_merge($_SERVER, $server);
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest($url);
        self::assertSame($status, $proxy->getStatus());
    }

    /**
     * @return list<array{0: array<string, string>, 1: string}>
     */
    public static function dataSchemeSpecific(): array
    {
        $http = ['http_proxy' => 'http://proxy.com'];
        $https = ['https_proxy' => 'http://otherproxy.com'];
        $both = array_merge($http, $https);

        // server, url, status
        return [
            [$both, 'http://repo.org', 'http://proxy.com:80'],
            [$both, 'https://repo.org', 'http://otherproxy.com:80'],
            [$http, 'https://repo.org', ''],
            [$https, 'http://repo.org', ''],
        ];
    }

    /**
     * @dataProvider dataNoProxy
     *
     * @param non-empty-string $url
     */
    public function testNoProxy(string $noproxy, string $url, string $status): void
    {
        $server = [
            'http_proxy' => 'http://proxy.com',
            'https_proxy' => 'http://proxy.com',
            'no_proxy' => $noproxy
        ];
        $_SERVER = array_merge($_SERVER, $server);
        $proxyManager = ProxyManager::getInstance();

        $proxy = $proxyManager->getProxyForRequest($url);
        self::assertSame($status, $proxy->getStatus());
    }

    /**
     * @return array<string, array<string>>
     */
    public static function dataNoProxy(): array
    {
        // noproxy, url, status
        return [
            'proxy-80' => ['repo.org:443', 'http://repo.org', 'http://proxy.com:80'],
            'no-proxy-443' => ['repo.org:443', 'https://repo.org', 'excluded by no_proxy'],
            'no-proxy-*' => ['*', 'http://repo.org', 'excluded by no_proxy'],
        ];
    }
}
