<?php
declare(strict_types=1);

/**
 * Mock SQL Account API endpoint.
 *
 * Pretend to be the SQL Account JSON API so ACCBOS can be tested end-to-end
 * without real credentials. Each call is logged to _state/log.jsonl and
 * viewable via inbox.php.
 *
 * Configure a company in ACCBOS with:
 *   api_base_url   = https://your-host/mock/sql_account/api.php
 *   api_access_key = MOCK_KEY
 *   api_secret_key = MOCK_SECRET
 *   api_region     = ap-southeast-1
 *   api_service    = mock-sql
 *
 * Failure simulation:
 *   customer code "FAIL_CUSTOMER"  -> 404 customer not found
 *   item code     "FAIL_ITEM"      -> 400 item not found
 *   reference_no  contains "FAIL"  -> 500 generic gateway error
 */

$path   = $_SERVER['PATH_INFO']      ?? '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body   = file_get_contents('php://input');
if ($body === false) {
    $body = '';
}
$decoded = json_decode($body, true);

$stateDir    = __DIR__ . '/_state';
$counterFile = $stateDir . '/counter.txt';
$logFile     = $stateDir . '/log.jsonl';

if (!is_dir($stateDir)) {
    @mkdir($stateDir, 0775, true);
}

/**
 * Read, increment and persist the document sequence number.
 */
function next_seq(string $file): int
{
    $fp = @fopen($file, 'c+');
    if (!$fp) {
        return (int)(microtime(true) * 1000);
    }
    flock($fp, LOCK_EX);
    $current = (int)stream_get_contents($fp);
    $next    = $current + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, (string)$next);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $next;
}

/**
 * Capture all interesting incoming headers for the inbox UI.
 *
 * @return array<string,string>
 */
function capture_headers(): array
{
    $out = [];
    if (function_exists('getallheaders')) {
        $all = getallheaders();
        if (is_array($all)) {
            foreach ($all as $k => $v) {
                $out[(string)$k] = (string)$v;
            }
        }
    }
    if (empty($out)) {
        foreach ($_SERVER as $k => $v) {
            if (strpos($k, 'HTTP_') === 0) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
                $out[$name] = (string)$v;
            }
        }
    }
    return $out;
}

// ---------- Routing -----------------------------------------------------------

$status   = 200;
$response = ['mock' => 'sql_account', 'method' => $method, 'path' => $path];
$normPath = '/' . trim($path, '/');

if ($method === 'POST' && $normPath === '/SalesOrder') {
    $errors = [];
    $custCode = $decoded['Customer']['Code'] ?? '';
    if ($custCode === '') {
        $errors[] = 'Customer.Code is required';
    }
    $detail = $decoded['Detail'] ?? null;
    if (!is_array($detail) || empty($detail)) {
        $errors[] = 'At least one Detail line is required';
    }

    if ($custCode === 'FAIL_CUSTOMER') {
        $status   = 404;
        $response = ['error' => true, 'code' => 'CUSTOMER_NOT_FOUND',
                     'message' => 'Customer ' . $custCode . ' not found in SQL Account'];
    } elseif (is_array($detail)) {
        foreach ($detail as $line) {
            if (($line['ItemCode'] ?? '') === 'FAIL_ITEM') {
                $status   = 400;
                $response = ['error' => true, 'code' => 'ITEM_NOT_FOUND',
                             'message' => 'Item FAIL_ITEM does not exist'];
                break;
            }
        }
    }

    if ($status === 200 && stripos((string)($decoded['DocRef'] ?? ''), 'FAIL') !== false) {
        $status   = 500;
        $response = ['error' => true, 'code' => 'GATEWAY_ERROR',
                     'message' => 'Simulated SQL Account gateway error'];
    }

    if ($status === 200 && !empty($errors)) {
        $status   = 400;
        $response = ['error' => true, 'code' => 'VALIDATION', 'message' => implode('; ', $errors)];
    }

    if ($status === 200) {
        $seq   = next_seq($counterFile);
        $docNo = sprintf('SO-MOCK-%05d', $seq);
        $total = 0.0;
        if (is_array($detail)) {
            foreach ($detail as $line) {
                $total += (float)($line['Amount'] ?? 0);
            }
        }
        $response = [
            'success'   => true,
            'DocNo'     => $docNo,
            'DocDate'   => $decoded['DocDate'] ?? gmdate('Y-m-d'),
            'Total'     => $total,
            'CreatedAt' => gmdate('c'),
            'Message'   => 'Sales order created in mock SQL Account',
        ];
    }
} elseif ($method === 'GET' && preg_match('#^/Customer/(.+)$#', $normPath, $m)) {
    $code = rawurldecode($m[1]);
    if ($code === 'FAIL_CUSTOMER') {
        $status   = 404;
        $response = ['error' => true, 'message' => 'Not found'];
    } else {
        $response = ['Code' => $code, 'Name' => 'Mock Customer ' . $code,
                     'Phone' => '0123456789', 'Email' => strtolower($code) . '@example.test'];
    }
} elseif ($method === 'GET' && preg_match('#^/Stock/(.+)$#', $normPath, $m)) {
    $code = rawurldecode($m[1]);
    if ($code === 'FAIL_ITEM') {
        $status   = 404;
        $response = ['error' => true, 'message' => 'Not found'];
    } else {
        $response = ['Code' => $code, 'Description' => 'Mock Item ' . $code,
                     'UnitPrice' => 99.90, 'UOM' => 'UNIT'];
    }
} else {
    $response = ['mock' => 'sql_account', 'method' => $method, 'path' => $normPath,
                 'note' => 'Unhandled route — returning generic OK'];
}

// ---------- Logging ----------------------------------------------------------

$entry = [
    'ts'              => gmdate('c'),
    'method'          => $method,
    'path'            => $normPath,
    'remote_addr'     => $_SERVER['REMOTE_ADDR'] ?? '',
    'headers'         => capture_headers(),
    'request_body'    => $decoded ?? $body,
    'response_status' => $status,
    'response_body'   => $response,
];
@file_put_contents($logFile, json_encode($entry, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);

// ---------- Respond ----------------------------------------------------------

http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
echo json_encode($response, JSON_UNESCAPED_SLASHES);
