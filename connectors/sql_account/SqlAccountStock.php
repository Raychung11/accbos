<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlAccountClient.php';

/**
 * Stock / item sync skeleton for the SQL Account connector.
 *
 * TODO: Replace endpoint and field names according to official SQL Account
 *       Postman collection once Phase 3 begins.
 */
final class SqlAccountStock
{
    public const ENDPOINT_LIST   = '/Stock';
    public const ENDPOINT_LOOKUP = '/Stock/{code}';

    /**
     * @return array{success:bool,http_status:?int,body:mixed,raw:string,error:?string,doc_no:?string}
     */
    public static function find(SqlAccountClient $client, string $code): array
    {
        $endpoint = str_replace('{code}', rawurlencode($code), self::ENDPOINT_LOOKUP);
        return $client->get($endpoint, 'stock_find');
    }

    /**
     * @return array{success:bool,http_status:?int,body:mixed,raw:string,error:?string,doc_no:?string}
     */
    public static function listAll(SqlAccountClient $client): array
    {
        return $client->get(self::ENDPOINT_LIST, 'stock_list');
    }
}
