<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

/*
 * The excluded-SKU list becomes sku+name pairs (JSON), so the settings page
 * can show WHAT is filtered rather than bare numbers. Same 24 services &
 * labor items, now carrying the names from the owner's price list. The SKU
 * set is unchanged, so no receipt re-pull is needed.
 */
return new class extends Migration
{
    private const ITEMS = [
        ['sku' => '11898', 'name' => 'BATTERY CHARGE'],
        ['sku' => '10943', 'name' => 'BRAKE MASTER REPAIR XRM'],
        ['sku' => '11370', 'name' => 'Cylinder Block services'],
        ['sku' => '11567', 'name' => 'ECU DIAGNOSTIC RESET'],
        ['sku' => '11084', 'name' => 'ECU RESET DIAGNOSTIC TOOL'],
        ['sku' => '19181', 'name' => 'FWZ DIAGNOSTIC SCANNING'],
        ['sku' => '19219', 'name' => 'FWZ EGR CLEANING'],
        ['sku' => '19105', 'name' => 'FWZ GENERAL AIRCON CLEANING PACKAGE'],
        ['sku' => '19220', 'name' => 'FWZ INTAKE MANIFOLD'],
        ['sku' => '19143', 'name' => 'FWZ LABOR'],
        ['sku' => '19160', 'name' => 'FWZ OIL COMPRESSOR REFRIGERANT'],
        ['sku' => '19106', 'name' => 'FWZ REFRIGERANT REFIL'],
        ['sku' => '17137', 'name' => 'LABOR'],
        ['sku' => '11083', 'name' => 'LABOR CHARGE 100'],
        ['sku' => '15822', 'name' => 'LABOR CHARGE PACKAGE MDL & PIAA'],
        ['sku' => '15823', 'name' => 'LABOR CHARGE PACKAGE MDL ONLY'],
        ['sku' => '15824', 'name' => 'LABOR CHARGE PACKAGE PIAA HORN ONLY'],
        ['sku' => '12065', 'name' => 'Machine charge'],
        ['sku' => '14429', 'name' => 'REMAPING PACKAGES CVT & Fi CLEANING'],
        ['sku' => '14558', 'name' => 'TRANSPORTATION SERVICES'],
        ['sku' => '10812', 'name' => 'WHEEL ALIGNMENT'],
        ['sku' => '17907', 'name' => 'WS DIAGNOSTIC TOOL JDIAG M100'],
        ['sku' => '17885', 'name' => 'WS FEELER GUAGE'],
        ['sku' => '17883', 'name' => 'WS TORX KEY/REPAIR TOOL'],
    ];

    public function up(): void
    {
        Setting::write('sales_excluded_skus', (string) json_encode(self::ITEMS));
    }

    public function down(): void
    {
        Setting::write(
            'sales_excluded_skus',
            implode(',', array_column(self::ITEMS, 'sku')),
        );
    }
};
