<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Zone;
use App\Models\Store;
use App\Models\Address;
use App\Helpers\CommonHelper;
use Illuminate\Support\Facades\DB;

class DiagnoseDeliveryIssue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'diagnose:delivery {--address-id= : Address ID to diagnose} {--latitude= : Test latitude} {--longitude= : Test longitude} {--channel=quick : Delivery channel (quick or ecommerce)}';

    /**
     * The description of the console command.
     *
     * @var string
     */
    protected $description = 'Safely diagnose delivery address validation issues - READ ONLY';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('🔍 DELIVERY DIAGNOSIS TOOL - READ ONLY MODE');
        $this->info('==============================================');
        $this->newLine();

        // Get input parameters
        $addressId = $this->option('address-id');
        $latitude = $this->option('latitude');
        $longitude = $this->option('longitude');
        $channel = $this->option('channel') ?? 'quick';

        // Validate channel
        if (!in_array($channel, ['quick', 'ecommerce'])) {
            $this->error('❌ Invalid channel. Use "quick" or "ecommerce"');
            return 1;
        }

        // Get coordinates from address ID or direct input
        if ($addressId) {
            $address = Address::find($addressId);
            if (!$address) {
                $this->error("❌ Address ID {$addressId} not found");
                return 1;
            }
            $latitude = $address->latitude;
            $longitude = $address->longitude;
            $addressName = $address->address ?? 'N/A';
        } else {
            if (!$latitude || !$longitude) {
                $this->error('❌ Provide either --address-id or both --latitude and --longitude');
                return 1;
            }
            $addressName = "Lat: {$latitude}, Lng: {$longitude}";
        }

        $this->info("📍 Testing Address: {$addressName}");
        $this->info("📡 Channel: {$channel}");
        $this->newLine();

        // Step 1: Check active zones
        $this->line('STEP 1: Checking Active Zones');
        $this->line('------------------------------');
        $activeZones = Zone::where('status', 1)->get();
        $this->info("✓ Total active zones: {$activeZones->count()}");

        if ($activeZones->isEmpty()) {
            $this->warn('⚠️  No active zones found in system');
            return 1;
        }

        // Step 2: Check zones by channel
        $this->newLine();
        $this->line('STEP 2: Zones Serving Channel "' . $channel . '"');
        $this->line('--------------------------------------------');
        $channelZones = Zone::where('status', 1)->serving($channel)->get();
        $this->info("✓ Zones serving '{$channel}': {$channelZones->count()}");

        if ($channelZones->isEmpty()) {
            $this->error("❌ NO ZONES SERVE CHANNEL '{$channel}'");
            $this->info("Available zones and their channels:");
            foreach ($activeZones as $zone) {
                $this->line("   - Zone #{$zone->id} ({$zone->name}): sales_channel = '{$zone->sales_channel}'");
            }
            return 1;
        }

        // Step 3: Check polygon matches
        $this->newLine();
        $this->line('STEP 3: Testing Point-in-Polygon Match');
        $this->line('---------------------------------------');
        $point = ['lat' => $latitude, 'lng' => $longitude];
        $matchedZones = [];

        foreach ($channelZones as $zone) {
            $polygon = $zone->polygonFor($channel);
            $hasPolygon = is_array($polygon) && !empty($polygon);
            
            $status = "  Zone #{$zone->id} ({$zone->name}):";
            if (!$hasPolygon) {
                $this->warn("$status NO POLYGON BOUNDARY");
            } else {
                $isInPolygon = CommonHelper::isPointInPolygon($point, $polygon);
                if ($isInPolygon) {
                    $this->info("$status ✓ MATCHED (point in polygon)");
                    $matchedZones[] = $zone;
                } else {
                    $this->line("$status ✗ No match (point outside polygon)");
                }
            }
        }

        if (empty($matchedZones)) {
            $this->error("❌ POINT DOES NOT MATCH ANY POLYGON");
            $this->info("This means the coordinates fall outside all zone boundaries for '{$channel}' channel.");
            return 1;
        }

        $this->info("✓ Matched zones: " . count($matchedZones));
        $this->newLine();

        // Step 4: Check stores in matched zones
        $this->line('STEP 4: Checking Active Stores in Matched Zones');
        $this->line('------------------------------------------------');
        $matchedZoneIds = array_map(fn($z) => $z->id, $matchedZones);
        $activeStores = Store::whereIn('zone_id', $matchedZoneIds)
            ->where('status', 1)
            ->get();

        $this->info("✓ Active stores in matched zones: {$activeStores->count()}");

        if ($activeStores->isEmpty()) {
            $this->error("❌ NO ACTIVE STORES IN MATCHED ZONES!");
            $this->info("This is likely the root cause. Stores exist in zones but are inactive.");
            
            $inactiveStores = Store::whereIn('zone_id', $matchedZoneIds)->get();
            if ($inactiveStores->isNotEmpty()) {
                $this->line("\nInactive stores in these zones:");
                foreach ($inactiveStores as $store) {
                    $this->line("   - Store #{$store->id} ({$store->name}): status = {$store->status}");
                }
            }
            return 1;
        }

        $this->info("Stores:");
        foreach ($activeStores as $store) {
            $this->line("   - Store #{$store->id}: {$store->name} (Zone #{$store->zone_id})");
        }

        // Step 5: Simulate getDeliverableCity
        $this->newLine();
        $this->line('STEP 5: Simulating getDeliverableCity()');
        $this->line('---------------------------------------');
        $resolvedZone = CommonHelper::getDeliverableCity($latitude, $longitude, $channel);
        
        if ($resolvedZone) {
            $this->info("✓ Zone resolved: #{$resolvedZone->id} ({$resolvedZone->name})");
        } else {
            $this->error("❌ getDeliverableCity() returned NULL");
            $this->info("This means the address validation will fail.");
            return 1;
        }

        // Step 6: Test delivery charge calculation
        $this->newLine();
        $this->line('STEP 6: Testing Delivery Charge Calculation');
        $this->line('-------------------------------------------');
        
        if ($activeStores->isNotEmpty()) {
            $testStore = $activeStores->first();
            $testSubtotal = 100; // Dummy amount
            
            $this->line("Testing with Store #{$testStore->id} ({$testStore->name})");
            $this->line("Store location: Lat {$testStore->latitude}, Lng {$testStore->longitude}");
            
            $delivery = CommonHelper::getDeliveryCharge(
                $latitude,
                $longitude,
                $testStore->latitude,
                $testStore->longitude,
                $testStore->zone_id,
                $testSubtotal,
                $channel
            );

            if ($delivery['error']) {
                $this->error("❌ Delivery charge calculation failed");
                $this->line("Error: " . ($delivery['message'] ?? 'Unknown error'));
                
                if (strpos($delivery['message'] ?? '', 'distance') !== false) {
                    $this->warn("⚠️  Distance API issue - check API credentials and connectivity");
                }
            } else {
                $this->info("✓ Delivery charge calculated successfully");
                $this->line("Charge: " . $delivery['charge'] . " | Distance: " . $delivery['distance']);
            }
        }

        $this->newLine();
        $this->info('✅ DIAGNOSIS COMPLETE');
        $this->newLine();
        
        return 0;
    }
}
