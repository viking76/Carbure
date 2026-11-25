<?php
/**
 * patch-creditmutel.php
 *
 * Applies the FormNotFound patch for the creditmutuel woob module.
 * This script is called automatically after woob update if PATCH_CREDITMUT=TRUE.
 *
 * @author     Carbure App
 * @copyright  2026 Carbure App
 */

require_once __DIR__ . '/../src/lib/Autoload.php';

use Carbure\Lib\Woob;
use Carbure\Lib\Logger;

echo "Carbure: Applying creditmutuel FormNotFound patch...\n";

try {
    $result = Woob::patchCreditmutuelModule();
    
    if ($result) {
        echo "Carbure: Creditmutuel patch applied successfully\n";
        exit(0);
    } else {
        echo "Carbure: Creditmutuel patch not needed or already applied\n";
        exit(0);
    }
} catch (Exception $e) {
    echo "Carbure: Error applying creditmutuel patch: " . $e->getMessage() . "\n";
    Logger::error("Error applying creditmutuel patch: " . $e->getMessage());
    exit(1);
}
