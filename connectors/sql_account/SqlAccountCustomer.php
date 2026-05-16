<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlAccountClient.php';

/**
 * Customer sync skeleton for the SQL Account connector.
 *
 * TODO: Replace endpoint and field names according to official SQL Account
 *       Postman collection once Phase 3 begins.
 */
final class SqlAccountCustomer
{
    public const ENDPOINT_LIST   = '/Customer';
    public const ENDPOINT_CREATE = '/Customer';
    public const ENDPOINT_LOOKUP = '/Customer/{code}';

    /**
     * @param array<string,mixed> $internal
     * @return array<string,mixed>
     */
    public static function mapFromInternal(array $internal): array
    {
        return [
            'Code'    => (string)($internal['code']    ?? ''),
            'Name'    => (string)($internal['name']    ?? ''),
            'Phone'   => (string)($internal['phone']   ?? ''),
            'Email'   => (string)($internal['email']   ?? ''),
            'Address' => (string)($internal['address'] ?? ''),
        ];
    }

    /**
     * Look up a single customer by code.
     *
     * @return array{success:bool,http_status:?int,body:mixed,raw:string,error:?string,doc_no:?string}
     */
    public static function find(SqlAccountClient $client, string $code): array
    {
        $endpoint = str_replace('{code}', rawurlencode($code), self::ENDPOINT_LOOKUP);
        return $client->get($endpoint, 'customer_find');
    }

    /**
     * Create a customer.
     *
     * @param array<string,mixed> $internal
     * @return array{success:bool,http_status:?int,body:mixed,raw:string,error:?string,doc_no:?string}
     */
    public static function create(SqlAccountClient $client, array $internal): array
    {
        return $client->post(self::ENDPOINT_CREATE, self::mapFromInternal($internal), 'customer_create');
    }
}
