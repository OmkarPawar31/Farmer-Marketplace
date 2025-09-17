<?php
require_once 'config/database.php';

$conn = getDB();

try {
    // Sample market data for the last 30 days
    $markets = [
        ['name' => 'Delhi Azadpur Mandi', 'state' => 'Delhi', 'district' => 'Delhi'],
        ['name' => 'Mumbai Crawford Market', 'state' => 'Maharashtra', 'district' => 'Mumbai'],
        ['name' => 'Kolkata Posta Bazar', 'state' => 'West Bengal', 'district' => 'Kolkata'],
        ['name' => 'Chennai Koyambedu Market', 'state' => 'Tamil Nadu', 'district' => 'Chennai'],
        ['name' => 'Bangalore KR Market', 'state' => 'Karnataka', 'district' => 'Bangalore']
    ];

    // Get crops
    $crops_stmt = $conn->query("SELECT id, name FROM crops WHERE is_active = 1 LIMIT 10");
    $crops = $crops_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Clear existing data for fresh start
    $conn->exec("DELETE FROM market_prices WHERE date >= DATE_SUB(NOW(), INTERVAL 30 DAY)");

    $insert_sql = "
        INSERT INTO market_prices (crop_id, market_name, state, district, min_price, max_price, modal_price, date)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";
    $stmt = $conn->prepare($insert_sql);

    $records_inserted = 0;

    // Generate data for last 30 days
    for ($day = 30; $day >= 0; $day--) {
        $date = date('Y-m-d', strtotime("-$day days"));
        
        foreach ($markets as $market) {
            foreach ($crops as $crop) {
                // Generate realistic price variations
                $base_price = rand(20, 80); // Base price between 20-80 rupees
                $variation = rand(-10, 15); // Price variation
                
                $min_price = max(10, $base_price + $variation - rand(5, 15));
                $max_price = $base_price + $variation + rand(5, 20);
                $modal_price = $base_price + $variation;
                
                // Add some seasonal trends
                if (in_array($crop['name'], ['Tomato', 'Onion', 'Potato'])) {
                    // Vegetables have more price volatility
                    $seasonal_factor = sin(($day / 30) * 2 * pi()) * 10;
                    $min_price += $seasonal_factor;
                    $max_price += $seasonal_factor;
                    $modal_price += $seasonal_factor;
                }
                
                $stmt->execute([
                    $crop['id'],
                    $market['name'],
                    $market['state'],
                    $market['district'],
                    round($min_price, 2),
                    round($max_price, 2),
                    round($modal_price, 2),
                    $date
                ]);
                
                $records_inserted++;
            }
        }
    }

    echo "Successfully inserted $records_inserted market price records.\n";
    echo "Data covers the last 30 days for " . count($crops) . " crops across " . count($markets) . " markets.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
