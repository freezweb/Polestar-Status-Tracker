<?php


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

    $pdo->exec('CREATE TABLE IF NOT EXISTS telematics_battery (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vin TEXT NOT NULL,
        timestamp DATETIME NOT NULL,
        battery_charge_level_percentage INTEGER,
        charging_status TEXT,
        estimated_charging_time_to_full_minutes INTEGER,
        estimated_distance_to_empty_km INTEGER,
        estimated_distance_to_empty_miles INTEGER,
        inserttimestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS telematics_health (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vin TEXT NOT NULL,
        timestamp DATETIME NOT NULL,
        days_to_service INTEGER,
        distance_to_service_km INTEGER,
        service_warning TEXT,
        brake_fluid_level_warning TEXT,
        engine_coolant_level_warning TEXT,
        oil_level_warning TEXT,
        inserttimestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS telematics_odometer (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vin TEXT NOT NULL,
        timestamp DATETIME NOT NULL,
        odometer_meters INTEGER,
        inserttimestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS consumer_car (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vin TEXT NOT NULL UNIQUE,
        registration_no TEXT,
        market TEXT,
        delivery_planned DATE,
        delivery_actual DATE,
        edition TEXT,
        model_year TEXT,
        doors INTEGER,
        fuel_type TEXT,
        original_market TEXT,
        model_name TEXT,
        model_code TEXT,
        interior TEXT,
        exterior TEXT,
        wheels TEXT,
        power_kw TEXT,
        power_hp TEXT,
        torque TEXT,
        battery_description TEXT,
        trunk_capacity TEXT,
        inserttimestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS location (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        vin TEXT NOT NULL,
        timestamp DATETIME NOT NULL,
        latitude REAL,
        longitude REAL,
        heading REAL,
        inserttimestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec('CREATE TABLE IF NOT EXISTS vehicle_order (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        internal_id TEXT,
        order_number TEXT,
        country_code TEXT,
        latest_date_to_lock TEXT,
        earliest_handover_date TEXT,
        first_name TEXT,
        last_name TEXT,
        email TEXT,
        phone TEXT,
        vin TEXT,
        registration_number TEXT,
        mileage INTEGER,
        total_with_discount REAL,
        vat REAL,
        inserttimestamp DATETIME DEFAULT CURRENT_TIMESTAMP
)');
}
