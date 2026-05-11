<?php
declare(strict_types=1);

/**
 * AWS Signature Version 4 signer for SQL Account API requests.
 *
 * SQL Account exposes an API gateway that is signed using AWS SigV4.
 * This signer constructs the canonical request, string to sign and the
 * Authorization header required by the gateway.
 *
 * Reference: docs.aws.amazon.com/general/latest/gr/sigv4-create-canonical-request.html
 *
 * NOTE: Replace region/service defaults from the company's connector setting
 *       once the official SQL Account documentation is finalised.
 */
final class SqlAccountSigner
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';

    public function __construct(
        private string $accessKey,
        private string $secretKey,
        private string $region  = 'ap-southeast-1',
        private string $service = 'execute-api'
    ) {
    }

    /**
     * Sign an HTTP request and return the headers that should be sent.
     *
     * @param string                  $method  HTTP verb (GET/POST/...)
     * @param string                  $url     Absolute URL of the request
     * @param array<string,string>    $headers Additional headers (e.g. Content-Type)
     * @param string                  $body    Raw request body
     *
     * @return array<string,string>
     */
    public function signRequest(string $method, string $url, array $headers, string $body): array
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new InvalidArgumentException('Invalid request URL: ' . $url);
        }

        $host  = $parts['host'];
        $path  = $parts['path'] ?? '/';
        $query = $parts['query'] ?? '';

        $now            = gmdate('Ymd\THis\Z');
        $shortDate      = substr($now, 0, 8);
        $credentialScope = $shortDate . '/' . $this->region . '/' . $this->service . '/aws4_request';
        $payloadHash    = hash('sha256', $body);

        $headers = array_change_key_case($headers, CASE_LOWER);
        $headers['host']                 = $host;
        $headers['x-amz-date']           = $now;
        $headers['x-amz-content-sha256'] = $payloadHash;

        ksort($headers);
        $canonicalHeaders = '';
        $signedHeaderList = [];
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name . ':' . trim((string)$value) . "\n";
            $signedHeaderList[] = $name;
        }
        $signedHeaders = implode(';', $signedHeaderList);

        $canonicalRequest = $method . "\n"
            . $this->canonicalUri($path) . "\n"
            . $this->canonicalQueryString($query) . "\n"
            . $canonicalHeaders . "\n"
            . $signedHeaders . "\n"
            . $payloadHash;

        $stringToSign = self::ALGORITHM . "\n"
            . $now . "\n"
            . $credentialScope . "\n"
            . hash('sha256', $canonicalRequest);

        $signingKey = $this->deriveSigningKey($shortDate);
        $signature  = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = self::ALGORITHM
            . ' Credential=' . $this->accessKey . '/' . $credentialScope
            . ', SignedHeaders=' . $signedHeaders
            . ', Signature=' . $signature;

        // Build header set to actually send. Skip 'host' as cURL controls that.
        $finalHeaders = [];
        foreach ($headers as $name => $value) {
            if ($name === 'host') {
                continue;
            }
            $finalHeaders[$this->prettyHeaderName($name)] = (string)$value;
        }
        $finalHeaders['Authorization'] = $authorization;

        return $finalHeaders;
    }

    private function canonicalUri(string $path): string
    {
        if ($path === '') {
            return '/';
        }
        $segments = explode('/', $path);
        $encoded = array_map(static function (string $segment): string {
            return rawurlencode(rawurldecode($segment));
        }, $segments);
        return implode('/', $encoded);
    }

    private function canonicalQueryString(string $query): string
    {
        if ($query === '') {
            return '';
        }
        $pairs = [];
        foreach (explode('&', $query) as $pair) {
            if ($pair === '') {
                continue;
            }
            [$k, $v] = array_pad(explode('=', $pair, 2), 2, '');
            $pairs[] = rawurlencode(rawurldecode($k)) . '=' . rawurlencode(rawurldecode($v));
        }
        sort($pairs);
        return implode('&', $pairs);
    }

    private function deriveSigningKey(string $shortDate): string
    {
        $kDate    = hash_hmac('sha256', $shortDate, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);
        return hash_hmac('sha256', 'aws4_request', $kService, true);
    }

    private function prettyHeaderName(string $lower): string
    {
        return implode('-', array_map('ucfirst', explode('-', $lower)));
    }
}
