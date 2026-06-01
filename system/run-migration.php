<?php
/**
 * Database Migration Runner
 * Runs SQL migrations from install/migrations/ directory
 */

// Initialize application
require_once __DIR__ . '/init.php';

$migrationFile = __DIR__ . '/install/migrations/2026-06-01-create-trial-system.sql';

if (!file_exists($migrationFile)) {
    die("Migration file not found: $migrationFile\n");
}

echo "[*] Running migration: " . basename($migrationFile) . "\n";

try {
    $sql = file_get_contents($migrationFile);
    
    // Split by ; but be careful with comments
    $statements = array_filter(array_map('trim', preg_split('/;(?=(?:[^\']*\'[^\']*\')*[^\']*$)/', $sql)));
    
    $count = 0;
    foreach ($statements as $statement) {
        // Skip comments and empty statements
        if (empty($statement) || strpos($statement, '--') === 0) {
            continue;
        }
        
        echo "[.] Executing: " . substr($statement, 0, 60) . "...\n";
        ORM::raw_execute($statement);
        $count++;
    }
    
    echo "[✓] Migration completed successfully! Executed $count statements.\n";
    
} catch (Exception $e) {
    echo "[✗] Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
