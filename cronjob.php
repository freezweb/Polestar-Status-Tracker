<?php

// 🔐 CONFIGURATION
$vin = '<your-vin-here>';
$refreshToken = '<your-refresh-token-here>';

$clientId = 'l3oopkc_10';
$tokenCacheFile = 'token.json';
$outputFile = 'polestar_data.json';

// Database connection
$db = new mysqli('localhost', '<dbuser>', '<dbpasspword>', '<databasename>');
if ($db->connect_error)
{
    die("Database connection failed: " . $db->connect_error);
}

// Create tables (if not exists)
$db->query("
CREATE TABLE IF NOT EXISTS telematics_battery (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vin VARCHAR(20) NOT NULL,
    timestamp DATETIME NOT NULL,
    battery_charge_level_percentage TINYINT,
    charging_status VARCHAR(50),
    estimated_charging_time_to_full_minutes INT,
    estimated_distance_to_empty_km INT,
    estimated_distance_to_empty_miles INT,
    `inserttimestamp` datetime NULL DEFAULT current_timestamp
);");

$db->query("
CREATE TABLE IF NOT EXISTS telematics_health (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vin VARCHAR(20) NOT NULL,
    timestamp DATETIME NOT NULL,
    days_to_service INT,
    distance_to_service_km INT,
    service_warning VARCHAR(50),
    brake_fluid_level_warning VARCHAR(50),
    engine_coolant_level_warning VARCHAR(50),
    oil_level_warning VARCHAR(50),
    `inserttimestamp` datetime NULL DEFAULT current_timestamp
);");

$db->query("
CREATE TABLE IF NOT EXISTS telematics_odometer (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vin VARCHAR(20) NOT NULL,
    timestamp DATETIME NOT NULL,
    odometer_meters INT,
    `inserttimestamp` datetime NULL DEFAULT current_timestamp
);");

// Table for vehicle details & order status
$db->query("
CREATE TABLE IF NOT EXISTS consumer_car (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vin VARCHAR(20) NOT NULL UNIQUE,
    registration_no VARCHAR(20),
    market VARCHAR(5),
    delivery_planned DATE,
    delivery_actual DATE,
    edition VARCHAR(50),
    model_year VARCHAR(10),
    doors TINYINT,
    fuel_type VARCHAR(50),
    original_market VARCHAR(5),
    model_name VARCHAR(50),
    model_code VARCHAR(10),
    interior TEXT,
    exterior VARCHAR(50),
    wheels VARCHAR(50),
    power_kw VARCHAR(20),
    power_hp VARCHAR(20),
    torque VARCHAR(20),
    battery_description TEXT,
    trunk_capacity VARCHAR(100),
    `inserttimestamp` datetime NULL DEFAULT current_timestamp
);");

// Table for GPS location data
$db->query("
CREATE TABLE IF NOT EXISTS location (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vin VARCHAR(20) NOT NULL,
    timestamp DATETIME NOT NULL,
    latitude DOUBLE,
    longitude DOUBLE,
    heading DOUBLE,
    `inserttimestamp` datetime NULL DEFAULT current_timestamp
);");

/**
 * Retrieves a new access token using refresh token or from cache
 * @param string $fallbackRefreshToken The refresh token to use as fallback
 * @param string $clientId The OAuth client ID
 * @param string $tokenCacheFile Path to token cache file
 * @return string Valid access token
 */
function getAccessToken($fallbackRefreshToken, $clientId, $tokenCacheFile): string
{
    $useRefreshToken = $fallbackRefreshToken;

    // If token cache exists, prefer the refresh token from there
    if (file_exists($tokenCacheFile))
    {
        $existing = json_decode(file_get_contents($tokenCacheFile), true);
        if (!empty($existing['refresh_token']))
        {
            $useRefreshToken = $existing['refresh_token'];
        }
    }

    echo "📡 Fetching new access token with refresh token...\n";

    // OAuth2 token request to Polestar
    $ch = curl_init('https://polestarid.eu.polestar.com/as/token.oauth2');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $useRefreshToken,
        'client_id' => $clientId,
        'scope' => 'openid profile email customer:attributes customer:attributes:write'
    ]));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // Error handling for token request
    if ($httpCode !== 200)
    {
        echo "❌ Error fetching token (HTTP $httpCode):\n$response\n";
        exit(1);
    }

    $data = json_decode($response, true);
    if (!isset($data['access_token']))
    {
        echo "❌ Access token not found in response.\n";
        print_r($data);
        exit(1);
    }

    // Save token to cache (including new refresh token if available)
    $data['expires_at'] = time() + ($data['expires_in'] ?? 3600) - 60; // 1 minute buffer
    file_put_contents($tokenCacheFile, json_encode($data, JSON_PRETTY_PRINT));

    echo "✅ Access token successfully retrieved\n";
    return $data['access_token'];
}

/**
 * Loads vehicle details and order information from Polestar API
 * @param string $accessToken Valid access token
 * @return array Vehicle data as associative array
 */
function fetchConsumerCar(string $accessToken): array
{
    // GraphQL query for vehicle details
    $query = [
        'operationName' => 'GetConsumerCarsV2',
        'variables' => ['locale' => 'de_DE'],
        'query' => <<<GRAPHQL
        query GetConsumerCarsV2(\$locale: String) {
        getConsumerCarsV2(locale: \$locale) {
            vin
            registrationNo
            market
            currentPlannedDeliveryDate
            deliveryDate
            edition
            modelYear
            computedModelYear
            numberOfDoors
            fuelType
            originalMarket
            content {
            model { code name }
            exterior { name }
            interior { name }
            wheels { name }
            specification {
                totalKw
                totalHp
                torque
                battery
                trunkCapacity { value }
            }
            }
        }
        }
        GRAPHQL
    ];

    $ch = curl_init('https://pc-api.polestar.com/eu-north-1/mystar-v2');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200)
    {
        echo "Error in vehicle data request ($httpCode):\n$response\n";
        exit(1);
    }

    $data = json_decode($response, true);
    return $data['data']['getConsumerCarsV2'][0] ?? [];
}


/**
 * Sends GraphQL query to Polestar for telemetry data
 * @param string $vin Vehicle identification number
 * @param string $accessToken Valid access token
 * @return array Telemetry data (battery, health, odometer)
 */
function fetchTelematicsGraphQL(string $vin, string $accessToken): array
{
    // GraphQL query for telemetry data
    $query = [
        'operationName' => 'CarTelematicsV2',
        'variables' => ['vins' => [$vin]],
        'query' => <<<GRAPHQL
        query CarTelematicsV2(\$vins: [String!]!) {
        carTelematicsV2(vins: \$vins) {
            battery {
            vin
            timestamp { seconds nanos }
            batteryChargeLevelPercentage
            chargingStatus
            estimatedChargingTimeToFullMinutes
            estimatedDistanceToEmptyKm
            estimatedDistanceToEmptyMiles
            }
            health {
            vin
            timestamp { seconds nanos }
            daysToService
            distanceToServiceKm
            serviceWarning
            brakeFluidLevelWarning
            engineCoolantLevelWarning
            oilLevelWarning
            }
            odometer {
            vin
            timestamp { seconds nanos }
            odometerMeters
            }
        }
        }
        GRAPHQL
    ];

    $ch = curl_init('https://pc-api.polestar.com/eu-north-1/mystar-v2');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200)
    {
        echo "Error in GraphQL request ($httpCode):\n$response\n";
        exit(1);
    }

    $data = json_decode($response, true);
    return $data['data']['carTelematicsV2'] ?? [];
}

/**
 * Fetches GPS location data from Polestar API
 * @param string $vin Vehicle identification number  
 * @param string $accessToken Valid access token
 * @return array Location data (latitude, longitude, heading, timestamp)
 */
function fetchLocationGraphQL(string $vin, string $accessToken): array
{
    // GraphQL query for GPS location
    $query = [
        'operationName' => 'Location',
        'variables' => ['vins' => [$vin]],
        'query' => <<<GRAPHQL
query Location(\$vins: [String!]!) {
  location(vins: \$vins) {
    vin
    heading
    latitude
    longitude
    timestamp { seconds nanos }
  }
}
GRAPHQL
    ];

    $ch = curl_init('https://pc-api.polestar.com/eu-north-1/mystar-v2');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        "Authorization: Bearer $accessToken",
        "Content-Type: application/json"
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($query));

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200)
    {
        echo "Error in location data request ($httpCode):\n$response\n";
        return [];
    }

    $data = json_decode($response, true);
    print_r($data); // Debug output
    return $data['data']['location'][0] ?? [];
}

/**
 * Converts Polestar timestamp object to MySQL DATETIME format
 * @param array $ts Timestamp object with 'seconds' and 'nanos' keys
 * @return string Formatted datetime string (Y-m-d H:i:s)
 */
function formatTimestamp($ts): string
{
    $seconds = (int)($ts['seconds'] ?? 0);
    $nanos = (int)($ts['nanos'] ?? 0);
    $total = $seconds + ($nanos / 1_000_000_000);
    return date('Y-m-d H:i:s', (int)$total);
}

// Main execution starts here
echo "🚗 Polestar Status Tracker - Starting data collection...\n";

// Get access token
$accessToken = getAccessToken($refreshToken, $clientId, $tokenCacheFile);
echo "✅ Access token valid\n";

// Fetch telemetry data
echo "📊 Fetching telemetry data...\n";
$telematics = fetchTelematicsGraphQL($vin, $accessToken);

// Save raw JSON data
file_put_contents($outputFile, json_encode($telematics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "💾 Raw data saved to $outputFile\n";

// Database insert: battery data
echo "🔋 Inserting battery data...\n";
foreach ($telematics['battery'] ?? [] as $entry)
{
    $stmt = $db->prepare("
        INSERT INTO telematics_battery (
            vin, timestamp, battery_charge_level_percentage, charging_status,
            estimated_charging_time_to_full_minutes, estimated_distance_to_empty_km, estimated_distance_to_empty_miles
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $ts = formatTimestamp($entry['timestamp']);
    $stmt->bind_param(
        'ssisiii',
        $entry['vin'],
        $ts,
        $entry['batteryChargeLevelPercentage'],
        $entry['chargingStatus'],
        $entry['estimatedChargingTimeToFullMinutes'],
        $entry['estimatedDistanceToEmptyKm'],
        $entry['estimatedDistanceToEmptyMiles']
    );
    $stmt->execute();
}

// Database insert: health data
echo "🔧 Inserting health/service data...\n";
foreach ($telematics['health'] ?? [] as $entry)
{
    $stmt = $db->prepare("
        INSERT INTO telematics_health (
            vin, timestamp, days_to_service, distance_to_service_km, service_warning,
            brake_fluid_level_warning, engine_coolant_level_warning, oil_level_warning
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $ts = formatTimestamp($entry['timestamp']);
    $stmt->bind_param(
        'ssisssss',
        $entry['vin'],
        $ts,
        $entry['daysToService'],
        $entry['distanceToServiceKm'],
        $entry['serviceWarning'],
        $entry['brakeFluidLevelWarning'],
        $entry['engineCoolantLevelWarning'],
        $entry['oilLevelWarning']
    );
    $stmt->execute();
}

// Database insert: odometer data
echo "📏 Inserting odometer data...\n";
foreach ($telematics['odometer'] ?? [] as $entry)
{
    $stmt = $db->prepare("
        INSERT INTO telematics_odometer (
            vin, timestamp, odometer_meters
        ) VALUES (?, ?, ?)
    ");
    $ts = formatTimestamp($entry['timestamp']);
    $stmt->bind_param(
        'ssi',
        $entry['vin'],
        $ts,
        $entry['odometerMeters']
    );
    $stmt->execute();
}

// Fetch and insert vehicle details
echo "🚙 Fetching vehicle details...\n";
$car = fetchConsumerCar($accessToken);

// Insert vehicle data (replace on duplicate VIN)
$stmt = $db->prepare("
    INSERT INTO consumer_car (
        vin, registration_no, market, delivery_planned, delivery_actual,
        edition, model_year, doors, fuel_type, original_market,
        model_name, model_code, interior, exterior, wheels,
        power_kw, power_hp, torque, battery_description, trunk_capacity
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        registration_no = VALUES(registration_no),
        delivery_planned = VALUES(delivery_planned),
        delivery_actual = VALUES(delivery_actual)
");

$stmt->bind_param(
    'sssssssissssssssssss',
    $car['vin'],
    $car['registrationNo'],
    $car['market'],
    $car['currentPlannedDeliveryDate'],
    $car['deliveryDate'],
    $car['edition'],
    $car['modelYear'],
    $car['numberOfDoors'],
    $car['fuelType'],
    $car['originalMarket'],
    $car['content']['model']['name'],
    $car['content']['model']['code'],
    $car['content']['interior']['name'],
    $car['content']['exterior']['name'],
    $car['content']['wheels']['name'],
    $car['content']['specification']['totalKw'],
    $car['content']['specification']['totalHp'],
    $car['content']['specification']['torque'],
    $car['content']['specification']['battery'],
    $car['content']['specification']['trunkCapacity']['value']
);
$stmt->execute();

echo "🚙 Vehicle data saved (VIN: {$car['vin']})\n";

// Fetch and insert GPS location data
echo "📍 Fetching location data...\n";
$location = fetchLocationGraphQL($vin, $accessToken);

if (!empty($location))
{
    $stmt = $db->prepare("
        INSERT INTO location (
            vin, timestamp, latitude, longitude, heading
        ) VALUES (?, ?, ?, ?, ?)
    ");
    $ts = formatTimestamp($location['timestamp']);
    $stmt->bind_param(
        'ssddd',
        $location['vin'],
        $ts,
        $location['latitude'],
        $location['longitude'],
        $location['heading']
    );
    $stmt->execute();

    echo "📍 Location data saved (" . round($location['latitude'], 5) . ", " . round($location['longitude'], 5) . ")\n";
}
else 
{
    echo "⚠️  No location data available\n";
}

echo "✅ Data successfully inserted into database\n";
echo "🏁 Polestar Status Tracker completed successfully!\n";
