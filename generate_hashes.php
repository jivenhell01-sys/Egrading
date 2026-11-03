<?php
require_once 'includes/file_integrity.php';

// Only allow this script to run from command line
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line.');
}

$checker = new FileIntegrityChecker();
try {
    $checker->generateHashes();
    echo "File hashes have been successfully generated in file_hashes.json\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
