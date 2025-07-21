<?php
// Konfigurationswerte
const API_URL = 'https://pc-api.polestar.com/eu-north-1/';
const DB_FILE = __DIR__ . '/polestar_data.sqlite';

// Authentifizierungstoken definieren (ersetzen!)
$authToken = 'Bearer DEIN_AUTH_TOKEN_HIER';

initDatabase();

$vehicles = getVehicles($authToken);

foreach ($vehicles as $vehicle)
{
    $vin = $vehicle['vin'] ?? null;
    $internalId = $vehicle['internalVehicleIdentifier'] ?? null;

    if ($vin && $internalId)
    {
        $order = getOrderByInternalId($authToken, $internalId);
        if ($order)
        {
            storeSnapshot('vehicle', $vin, $vehicle);
            storeSnapshot('order', $order['id'], $order);
        }
    }
}

function initDatabase(): void
{
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->exec('CREATE TABLE IF NOT EXISTS snapshots (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL,
        entity_id TEXT NOT NULL,
        data TEXT NOT NULL,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )');
}

function storeSnapshot(string $type, string $entityId, array $data): void
{
    $pdo = new PDO('sqlite:' . DB_FILE);
    $stmt = $pdo->prepare('INSERT INTO snapshots (type, entity_id, data) VALUES (?, ?, ?)');
    $stmt->execute([$type, $entityId, json_encode($data)]);
}

function getVehicles(string $authToken): array
{
    $payload = [
        'operationName' => 'GetConsumerCarsV2',
        'variables' => ['locale' => 'de_DE'],
        'query' => 'query GetConsumerCarsV2($locale: String) {
            getConsumerCarsV2(locale: $locale) {
                vin
                primaryDriver
                internalVehicleIdentifier
                registrationNo
                market
                currentPlannedDeliveryDate
                deliveryDate
                edition
                pno34
                hasPerformancePackage
                modelYear
                fuelType
                content {
                    model { name code }
                    motor { name }
                }
            }
        }'
    ];
    $response = postGraphQL('graphql', $payload, $authToken);
    return $response['data']['getConsumerCarsV2'] ?? [];
}

function getOrderByInternalId(string $authToken, string $internalId): ?array
{
    $payload = [
        'operationName' => 'GetOrderModel',
        'variables' => ['request' => ['id' => $internalId]],
        'query' => 'query GetOrderModel($request: QueryRequest!) {
            order: getOrderModel(getOrderModelRequest: $request) {
                id
                orderNumber
                countryCode
                delivery {
                    latestDateToLockOrder
                    customerEarliestPossibleHandoverDate
                }
                consumer {
                    firstName
                    lastName
                    email
                    phoneNumber
                }
                manufacturing {
                    vehicleIdentificationNumber
                    registrationNumber
                    mileage
                }
                priceBreakdown {
                    totalWithDiscount
                    vat
                }
            }
        }'
    ];
    $response = postGraphQL('order2delivery/', $payload, $authToken);
    return $response['data']['order'] ?? null;
}

function postGraphQL(string $endpoint, array $payload, string $authToken): array
{
    $ch = curl_init(API_URL . $endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: ' . $authToken
        ],
        CURLOPT_POSTFIELDS => json_encode($payload)
    ]);

    $response = curl_exec($ch);
    if (curl_errno($ch))
    {
        throw new RuntimeException('cURL error: ' . curl_error($ch));
    }
    curl_close($ch);

    $decoded = json_decode($response, true);
    if (!is_array($decoded))
    {
        throw new RuntimeException('Ungültige JSON-Antwort: ' . $response);
    }

    return $decoded;
}
