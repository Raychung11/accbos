<?php
declare(strict_types=1);

require_once __DIR__ . '/SqlAccountClient.php';

/**
 * Sales Order mapping and push for SQL Account.
 *
 * The internal payload shape is documented in the ACCBOS spec. This class
 * adapts it to the SQL Account API request body.
 *
 * TODO: Replace endpoint and field names according to official SQL Account
 *       Postman collection. The keys below follow the conventional shape
 *       used by SQL Account's SalesOrder resource.
 */
final class SqlAccountSalesOrder
{
    public const ENDPOINT = '/SalesOrder';

    /**
     * Convert the internal standard payload to the SQL Account payload.
     *
     * @param array<string,mixed> $internal
     * @return array<string,mixed>
     */
    public static function mapFromInternal(array $internal): array
    {
        $customer    = $internal['customer']     ?? [];
        $header      = $internal['sales_order']  ?? [];
        $items       = $internal['items']        ?? [];

        $detail = [];
        foreach ($items as $i => $line) {
            $detail[] = [
                'Seq'           => $i + 1,
                'ItemCode'      => (string)($line['item_code']    ?? ''),
                'Description'   => (string)($line['description']  ?? ''),
                'Qty'           => (float)($line['qty']           ?? 0),
                'UnitPrice'     => (float)($line['unit_price']    ?? 0),
                'Discount'      => (float)($line['discount']      ?? 0),
                'Tax'           => (string)($line['tax_code']     ?? ''),
                'Amount'        => (float)($line['amount']        ?? 0),
            ];
        }

        return [
            'DocDate'      => (string)($header['doc_date']      ?? date('Y-m-d')),
            'RequiredDate' => (string)($header['required_date'] ?? ''),
            'DocRef'       => (string)($header['reference_no']  ?? ''),
            'Remark'       => (string)($header['remark']        ?? ''),
            'Customer'     => [
                'Code'  => (string)($customer['code']  ?? ''),
                'Name'  => (string)($customer['name']  ?? ''),
                'Phone' => (string)($customer['phone'] ?? ''),
                'Email' => (string)($customer['email'] ?? ''),
            ],
            'Detail'       => $detail,
        ];
    }

    /**
     * Validate the internal payload before sending. Returns an array of error strings.
     *
     * @param array<string,mixed> $internal
     * @return string[]
     */
    public static function validateInternal(array $internal): array
    {
        $errors = [];

        $customer = $internal['customer']    ?? [];
        $header   = $internal['sales_order'] ?? [];
        $items    = $internal['items']       ?? [];

        if (empty($customer['code'])) {
            $errors[] = 'Customer code is required.';
        }
        if (empty($items) || !is_array($items)) {
            $errors[] = 'At least one item is required.';
        }
        if (empty($header['doc_date'])) {
            $errors[] = 'Document date is required.';
        }

        if (is_array($items)) {
            foreach ($items as $i => $line) {
                $row = $i + 1;
                $qty   = (float)($line['qty']        ?? 0);
                $price = (float)($line['unit_price'] ?? 0);
                if ($qty <= 0) {
                    $errors[] = "Line {$row}: qty must be > 0.";
                }
                if ($price < 0) {
                    $errors[] = "Line {$row}: unit price must be >= 0.";
                }
                if (empty($line['item_code'])) {
                    $errors[] = "Line {$row}: item code is required.";
                }
            }
        }

        return $errors;
    }

    /**
     * Push an internal SO payload to SQL Account.
     *
     * @param array<string,mixed> $internal
     * @return array{success:bool,http_status:?int,body:mixed,raw:string,error:?string,doc_no:?string}
     */
    public static function push(SqlAccountClient $client, array $internal): array
    {
        $errors = self::validateInternal($internal);
        if (!empty($errors)) {
            return [
                'success'     => false,
                'http_status' => null,
                'body'        => null,
                'raw'         => '',
                'error'       => implode(' ', $errors),
                'doc_no'      => null,
            ];
        }

        $payload = self::mapFromInternal($internal);
        return $client->post(self::ENDPOINT, $payload, 'sales_order_push');
    }
}
