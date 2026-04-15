<?php
require "../../vendor/autoload.php";
require "../../config.php";

use QuickBooksOnline\API\Core\CoreConstants;
use QuickBooksOnline\API\Core\OAuth\OAuth2\OAuth2AccessToken;
use QuickBooksOnline\API\DataService\DataService;

$dataService = getDataService();

// Request body (QBO JSON). Omit read-only CustomField props (Type/Name) on create.
$payload = [
    "Line" => [
        [
            "Amount" => "500.00",
            "Description" => "From IntuitClient using 1000000006 and ?include=enhancedAllCustomFields",
            "DetailType" => "SalesItemLineDetail",
            "SalesItemLineDetail" => [
                "ItemRef" => [
                    "value" => "1",
                    "name" => "Line Haul",
                ],
            ],
        ],
    ],
    "CustomerRef" => [
        "value" => "2",
    ],
    "TxnDate" => "2026-04-01",
    "DueDate" => "2026-04-03",
    "DocNumber" => "999",
    "BillEmail" => [
        "Address" => "trejo@allwaystrack.com",
    ],
    "CustomField" => [
        [
            "DefinitionId" => "1000000006",
            "StringValue" => "9005",
        ],
    ],
];

try {
    $result = postInvoiceJson(
        $dataService,
        $payload,
        ['include' => 'enhancedAllCustomFields']
    );
    if (!empty($result['Invoice']['Id'])) {
        echo "Created Id={$result['Invoice']['Id']}\n\n";
    }
    $out = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    echo $out;
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

/**
 * POST JSON to {baseserviceURL}company/{realmId}/invoice?... (baseserviceURL is already .../v3/).
 *
 * @param array<string, string> $extraQuery e.g. ['include' => 'enhancedAllCustomFields']
 * @return array<string, mixed>
 */
function postInvoiceJson(DataService $dataService, array $invoicePayload, array $extraQuery = [])
{
    $ctx = $dataService->getServiceContext();
    $validator = $ctx->requestValidator;
    if (!$validator instanceof OAuth2AccessToken) {
        throw new RuntimeException('OAuth2 (OAuth2AccessToken) required.');
    }

    $token = $validator->getAccessToken();
    $base = $ctx->baseserviceURL;
    if ($base === null || $base === '') {
        $base = $validator->getBaseURL();
    }
    // baseserviceURL already ends with .../v3/ (see ServiceContext::getBaseURL); do not add /v3 again.
    $base = rtrim((string) $base, '/');
    $realm = (string) $ctx->realmId;

    $query = array_merge(
        $extraQuery,
        ['minorversion' => CoreConstants::DEFAULT_SDK_MINOR_VERSION]
    );
    $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);

    $url = $base . '/company/' . rawurlencode($realm) . '/invoice?' . $qs;

    $json = json_encode($invoicePayload, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('json_encode failed: ' . json_last_error_msg());
    }

    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('curl_init failed');
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/json',
            'Content-Type: application/json',
        ],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        throw new RuntimeException('cURL error: ' . $err);
    }

    $decoded = json_decode($body, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($decoded) ? json_encode($decoded, JSON_PRETTY_PRINT) : $body;
        throw new RuntimeException('HTTP ' . $code . ': ' . $msg);
    }

    return is_array($decoded) ? $decoded : [];
}
