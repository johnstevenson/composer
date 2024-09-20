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

namespace Composer\Util\Http;

/**
 * @internal
 * @author John Stevenson <john-stevenson@blueyonder.co.uk>
 */
class ProxyItem
{
    /** @var non-empty-string */
    private $url;
    /** @var non-empty-string */
    private $safeUrl;
    /** @var ?non-empty-string */
    private $curlAuth;
    /** @var string */
    private $optionsProxy;
    /** @var ?non-empty-string */
    private $optionsAuth;

    /**
     * @param string $proxyUrl The value from the environment
     * @param string $envName The name of the environment variable
     * @throws \RuntimeException If the proxy url is invalid
     */
    public function __construct(string $proxyUrl, string $envName)
    {
        $syntaxError = sprintf('unsupported `%s` syntax', $envName);

        // parse_url replaces these characters with an underscore
        if (strpbrk($proxyUrl, "\r\n\t") !== false) {
            throw new \RuntimeException($syntaxError);
        }
        if (false === ($proxy = parse_url($proxyUrl))) {
            throw new \RuntimeException($syntaxError);
        }
        if (!isset($proxy['host'])) {
            throw new \RuntimeException('host missing in ' . $envName);
        }

        $scheme = isset($proxy['scheme']) ? strtolower($proxy['scheme']) : 'http';

        // Check scheme, allowing SOCKS5 via curl, which is checked when using php streams
        $allowedSchemes = ['http', 'https', 'socks5'];
        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new \RuntimeException(sprintf('unsupported scheme `%s` in %s', $scheme, $envName));
        }

        $safe = '';

        if (isset($proxy['user'])) {
            $safe = '***@';

            // Always include a password component (matches curl behaviour)
            $user = $proxy['user'];
            $pass = $proxy['pass'] ?? '';

            // CURLOPT_PROXYUSERPWD requires percent-encoded values
            $this->curlAuth = $user . ':' . $pass;

            // The unencoded values are base64-encoded for the auth header
            $auth = rawurldecode($user) . ':' . rawurldecode($pass);
            $this->optionsAuth = base64_encode($auth);
        }

        $host = $proxy['host'];
        $port = null;

        if (isset($proxy['port'])) {
            $port = $proxy['port'];
        } elseif ($scheme === 'http') {
            $port = 80;
        } elseif ($scheme === 'https') {
            $port = 443;
        }

        if ($port === null) {
            throw new \RuntimeException('port is missing in ' . $envName);
        }

        $this->url = sprintf('%s://%s:%d', $scheme, $host, $port);
        $this->safeUrl = sprintf('%s://%s%s:%d', $scheme, $safe, $host, $port);

        // http(s):// is not supported in proxy context options
        $this->optionsProxy = str_replace(['http://', 'https://'], ['tcp://', 'ssl://'], $this->url);
    }

    /**
     * Returns a RequestProxy instance for the scheme of the request url
     *
     * @param string $scheme The scheme of the request url
     */
    public function toRequestProxy(string $scheme): RequestProxy
    {
        $options = [
            'requestScheme' => $scheme,
            'proxy' => $this->optionsProxy,
        ];

        if ($this->optionsAuth !== null) {
            $options['auth'] = $this->optionsAuth;
        }

        return new RequestProxy($this->url, $this->curlAuth, $options, $this->safeUrl);
    }
}
