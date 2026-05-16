<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlAccountSigner.php';
require_once __DIR__ . '/../../includes/functions.php';

/**
 * Thin HTTP client for the SQL Account API.
 *
 * Responsibilities:
 *  - Compose the absolute URL from base + endpoint
 *  - Sign the request via SqlAccountSigner (AWS SigV4 style)
 *  - Send JSON over cURL with timeouts
 *  - Persist a row in api_logs for every call (success or failure)
 *  - Return a structured result array
 */
final class SqlAccountClient
{
    private SqlAccountSigner $signer;
    private string $baseUrl;
    private ?int $companyId;
    private string $module = 'sql_account';

    /**
     * @param array{
     *   company_id?: int|null,
     *   api_base_url: string,
     *   api_access_key: string,
     *   api_secret_key: string,
     *   api_region?: string,
     *   api_service?: string
     * } $config
     */
    public function __construct(array $config)
    {
        $this->baseUrl   = rtrim((string)($config['api_base_url'] ?? ''), '/');
        $this->companyId = isset($config['company_id']) ? (int)$config['company_id'] : null;

        if ($this->baseUrl === '') {
            throw new InvalidArgumentException('SQL Account connector requires api_base_url.');
        }
        if (empty($config['api_access_key']) || empty($config['api_secret_key'])) {
            throw new InvalidArgumentException('SQL Account connector requires access and secret keys.');
        }

        $this->signer = new SqlAccountSigner(
            (string)$config['api_access_key'],
            (string)$config['api_secret_key'],
            (string)($config['api_region']  ?? 'ap-southeast-1'),
            (string)($config['api_service'] ?? 'execute-api')
        );
    }

    /**
     * POST a JSON payload to the SQL Account API.
     *
     * @param array<string,mixed> $payload
     * @return array{success:bool,http_status:?int,body:mixed,raw:string,error:?string,doc_no:?string}
     */
    public function post(string $endpoint, array $payload, string $action = 'post'): array
    {
        return $this->request('POST', $endpoint, $payload, $action);
    }

    /**
     * GET a resource from the SQL Account API.
     *
     * @return array{success:bool,http_status:?int,body:mixed,raw:string,error:?string,doc_no:?string}
     */
    public function get(string $endpoint, string $action = 'get'): array
    {
        return $this->request('GET', $endpoint, null, $action);
    }

    /**
     * Internal request driver.
     *
     * @param array<string,mixed>|null $payload
     * @return array{success:bool,http_status:?int,body:mixed,raw:string,error:?string,doc_no:?string}
     */
    private function request(string $method, string $endpoint, ?array $payload, string $action): array
    {
        $url  = $this->baseUrl . '/' . ltrim($endpoint, '/');
        $body = $payload === null ? '' : (string)json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];

        try {
            $signed = $this->signer->signRequest($method, $url, $headers, $body);
        } catch (Throwable $e) {
            $this->logCall($action, $endpoint, $payload, null, null, 'failed', $e->getMessage());
            return [
                'success'     => false,
                'http_status' => null,
                'body'        => null,
                'raw'         => '',
                'error'       => 'Signing failure: ' . $e->getMessage(),
                'doc_no'      => null,
            ];
        }

        $curlHeaders = [];
        foreach ($signed as $name => $value) {
            $curlHeaders[] = $name . ': ' . $value;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $curlHeaders,
            CURLOPT_CONNECTTIMEOUT => HTTP_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => HTTP_TIMEOUT,
            CURLOPT_FAILONERROR    => false,
        ]);
        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $rawResponse = curl_exec($ch);
        $httpStatus  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlErrno   = curl_errno($ch);
        $curlError   = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            $err = 'cURL error #' . $curlErrno . ': ' . $curlError;
            $this->logCall($action, $endpoint, $payload, null, $httpStatus ?: null, 'failed', $err);
            return [
                'success'     => false,
                'http_status' => $httpStatus ?: null,
                'body'        => null,
                'raw'         => '',
                'error'       => $err,
                'doc_no'      => null,
            ];
        }

        $raw     = (string)$rawResponse;
        $decoded = json_decode($raw, true);
        $success = $httpStatus >= 200 && $httpStatus < 300;
        $docNo   = null;
        if (is_array($decoded)) {
            foreach (['DocNo', 'doc_no', 'document_no', 'docno', 'DocumentNo'] as $k) {
                if (!empty($decoded[$k])) {
                    $docNo = (string)$decoded[$k];
                    break;
                }
            }
            if ($docNo === null && isset($decoded['data']) && is_array($decoded['data'])) {
                foreach (['DocNo', 'doc_no', 'document_no'] as $k) {
                    if (!empty($decoded['data'][$k])) {
                        $docNo = (string)$decoded['data'][$k];
                        break;
                    }
                }
            }
        }

        $errorMessage = null;
        if (!$success) {
            $errorMessage = is_array($decoded) && isset($decoded['message'])
                ? (string)$decoded['message']
                : 'HTTP ' . $httpStatus;
        }

        $this->logCall(
            $action,
            $endpoint,
            $payload,
            $raw,
            $httpStatus,
            $success ? 'success' : 'failed',
            $errorMessage
        );

        return [
            'success'     => $success,
            'http_status' => $httpStatus,
            'body'        => $decoded,
            'raw'         => $raw,
            'error'       => $errorMessage,
            'doc_no'      => $docNo,
        ];
    }

    /**
     * @param array<string,mixed>|null $payload
     */
    private function logCall(
        string $action,
        string $endpoint,
        ?array $payload,
        ?string $rawResponse,
        ?int $httpStatus,
        string $status,
        ?string $errorMessage
    ): void {
        log_api_call([
            'company_id'       => $this->companyId,
            'module'           => $this->module,
            'action'           => $action,
            'endpoint'         => $endpoint,
            'request_payload'  => $payload === null ? null : json_encode($payload, JSON_UNESCAPED_SLASHES),
            'response_payload' => $rawResponse,
            'http_status'      => $httpStatus,
            'status'           => $status,
            'error_message'    => $errorMessage,
        ]);
    }
}
