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
use Composer\Util\Http\RequestProxy;
use Composer\Test\TestCase;

class RequestProxyTest extends TestCase
{
    public function testFactoryNone(): void
    {
        $proxy = RequestProxy::none();

        if (extension_loaded('curl')) {
            $curlOptions = [CURLOPT_PROXY => ''];
            self::assertSame($curlOptions, $proxy->getCurlOptions([]));
        }

        $contextOptions = ['http' => ['header' => ['User-Agent: foo']]];
        self::assertSame($contextOptions, $proxy->addContextOptions($contextOptions));
        self::assertSame('', $proxy->getStatus());
        self::assertFalse($proxy->isExcludedByNoProxy());
    }

    public function testFactoryNoProxy(): void
    {
        $proxy = RequestProxy::noProxy();

        if (extension_loaded('curl')) {
            $curlOptions = [CURLOPT_PROXY => ''];
            self::assertSame($curlOptions, $proxy->getCurlOptions([]));
        }

        $contextOptions = ['http' => ['header' => ['User-Agent: foo']]];
        self::assertSame($contextOptions, $proxy->addContextOptions($contextOptions));
        self::assertSame('excluded by no_proxy', $proxy->getStatus());
        self::assertTrue($proxy->isExcludedByNoProxy());
    }

    /**
     * @dataProvider dataSecure
     *
     * @param ?non-empty-string $url
     */
    public function testIsSecure(?string $url, bool $expected): void
    {
        $proxy = new RequestProxy($url, null, null, null);
        self::assertSame($expected, $proxy->isSecure());
    }

    /**
     * @return array<string, array{0: ?non-empty-string, 1: bool}>
     */
    public static function dataSecure(): array
    {
        // url, expected
        return [
            'basic' => ['http://proxy.com:80', false],
            'secure' => ['https://proxy.com:443', true],
            'socks5' => ['socks5://proxy.com:1068', false],
            'none' => [null, false],
        ];
    }

    public function testGetStatusThrowsOnBadFormatSpecifier(): void
    {
        $proxy = new RequestProxy('http://proxy.com:80', null, null, 'http://proxy.com:80');
        self::expectException('InvalidArgumentException');
        $proxy->getStatus('using proxy');
    }

    /**
     * @dataProvider dataStatus
     *
     * @param ?non-empty-string $url
     */
    public function testGetStatus(?string $url, ?string $format, string $expected): void
    {
        $proxy = new RequestProxy($url, null, null, $url);

        if ($format === null) {
            // try with and without optional param
            self::assertSame($expected, $proxy->getStatus());
            self::assertSame($expected, $proxy->getStatus($format));
        } else {
            self::assertSame($expected, $proxy->getStatus($format));
        }
    }

    /**
     * @return array<string, array{0: ?non-empty-string, 1: ?string, 2: string}>
     */
    public static function dataStatus(): array
    {
        $format = 'proxy (%s)';

        // url, format, expected
        return [
            'no-proxy' => [null, $format, ''],
            'null-format' => ['http://proxy.com:80', null, 'http://proxy.com:80'],
            'with-format' => ['http://proxy.com:80', $format, 'proxy (http://proxy.com:80)'],
        ];
    }

    /**
     * This test avoids HTTPS proxies so that it can be run on PHP < 7.3
     *
     * @requires extension curl
     */
    public function testGetCurlOptions(): void
    {
        $url = 'http://proxy.com:80';
        $proxy = $this->getRequestProxy($url);

        $expected = [
            CURLOPT_PROXY => 'http://proxy.com:80',
            CURLOPT_NOPROXY => '',
        ];

        self::assertSame($expected, $proxy->getCurlOptions([]));
        self::assertSame($url, $proxy->getStatus());
    }

    /**
     * This test avoids HTTPS proxies so that it can be run on PHP < 7.3
     *
     * @requires extension curl
     */
    public function testGetCurlOptionsWithAuth(): void
    {
        $url = 'http://user:p%40ss@proxy.com:80';
        $proxy = $this->getRequestProxy($url);

        $expected = [
            CURLOPT_PROXY => 'http://proxy.com:80',
            CURLOPT_NOPROXY => '',
            CURLOPT_PROXYAUTH => CURLAUTH_BASIC,
            CURLOPT_PROXYUSERPWD => 'user:p%40ss',
        ];

        self::assertSame($expected, $proxy->getCurlOptions([]));
        self::assertSame('http://***@proxy.com:80', $proxy->getStatus());
    }

    /**
     * @requires PHP >= 7.3.0
     * @requires extension curl >= 7.52.0
     */
    public function testGetCurlOptionsHttpsProxy(): void
    {
        // for PHPStan on PHP < 7.3
        $caInfo = 10246; // CURLOPT_PROXY_CAINFO

        $url = 'https://proxy.com:443';
        $proxy = $this->getRequestProxy($url);

        $expected = [
            CURLOPT_PROXY => 'https://proxy.com:443',
            CURLOPT_NOPROXY => '',
            $caInfo => '/certs/bundle.pem',
        ];

        $sslOptions = ['cafile' => '/certs/bundle.pem'];
        self::assertSame($expected, $proxy->getCurlOptions($sslOptions));
        self::assertSame($url, $proxy->getStatus());
    }

    /**
     * @requires PHP >= 7.3.0
     * @requires extension curl >= 7.52.0
     */
    public function testGetCurlOptionsHttpsProxyWithAuth(): void
    {
        // for PHPStan on PHP < 7.3
        $caPath = 10247; // CURLOPT_PROXY_CAPATH

        $url = 'https://user:p%40ss@proxy.com:443';
        $proxy = $this->getRequestProxy($url);

        $expected = [CURLOPT_PROXY => 'https://proxy.com:443',
            CURLOPT_NOPROXY => '',
            CURLOPT_PROXYAUTH => CURLAUTH_BASIC,
            CURLOPT_PROXYUSERPWD => 'user:p%40ss',
            $caPath => '/certs',
        ];

        $sslOptions = ['capath' => '/certs'];
        self::assertSame($expected, $proxy->getCurlOptions($sslOptions));
        self::assertSame('https://***@proxy.com:443', $proxy->getStatus());
    }

    public function testAddContextOptionsThrowsOnSocks5Proxy(): void
    {
        $proxy = $this->getRequestProxy('socks5://proxy.com:1080', 'http');
        self::expectException('Composer\Downloader\TransportException');
        self::expectExceptionMessage('Unable to use a proxy:');

        $contextOptions = ['http' => ['header' => []]];
        $proxy->addContextOptions($contextOptions);
    }

    /**
     * @requires extension openssl
     */
    public function testAddContextOptionsThrowsOnHttpsToHttpsProxy(): void
    {
        $proxy = $this->getRequestProxy('https://proxy.com');
        self::expectException('Composer\Downloader\TransportException');
        self::expectExceptionMessage('Unable to use a proxy:');

        $contextOptions = ['http' => ['header' => []]];
        $proxy->addContextOptions($contextOptions);
    }

    public function testAddContextOptions(): void
    {
        $url = 'http://proxy.com:80';
        $proxy = $this->getRequestProxy($url);

        $contextOptions = ['http' => [
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => ['User-Agent: foo'],
        ]];

        $expected = ['http' => [
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => ['User-Agent: foo'],
            'proxy' => 'tcp://proxy.com:80',
            'request_fulluri' => true,
        ]];

        $contextOptions = $proxy->addContextOptions($contextOptions);
        self::assertSame($expected, $contextOptions);
        self::assertSame($url, $proxy->getStatus());
    }

    public function testAddContextOptionsWithAuth(): void
    {
        $url = 'http://user:p%40ss@proxy.com:80';
        $proxy = $this->getRequestProxy($url);

        $contextOptions = ['http' => [
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => ['User-Agent: foo'],
        ]];

        $expected = ['http' => [
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => ['User-Agent: foo', 'Proxy-Authorization: Basic dXNlcjpwQHNz'],
            'proxy' => 'tcp://proxy.com:80',
            'request_fulluri' => true,
        ]];

        $contextOptions = $proxy->addContextOptions($contextOptions);
        self::assertSame($expected, $contextOptions);
        self::assertSame('http://***@proxy.com:80', $proxy->getStatus());
    }

    public function testAddContextOptionsHttps(): void
    {
        $url = 'https://proxy.com:443';
        $proxy = $this->getRequestProxy($url, 'http');

        $contextOptions = ['http' => [
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => ['User-Agent: foo'],
        ]];

        $expected = ['http' => [
            'method' => 'GET',
            'max_redirects' => 20,
            'follow_location' => 1,
            'header' => ['User-Agent: foo'],
            'proxy' => 'ssl://proxy.com:443',
            'request_fulluri' => true,
        ]];

        $contextOptions = $proxy->addContextOptions($contextOptions);
        self::assertSame($expected, $contextOptions);
        self::assertSame($url, $proxy->getStatus());
    }

    private function getRequestProxy(string $proxyUrl, ?string $requestScheme = null): RequestProxy
    {
        $scheme = $requestScheme ?? (string) parse_url($proxyUrl, PHP_URL_SCHEME);
        $envName = $scheme . '-proxy';
        $proxyItem = new ProxyItem($proxyUrl, $envName);

        return $proxyItem->toRequestProxy($scheme);
    }
}
