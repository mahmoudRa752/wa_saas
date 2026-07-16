<?php
/**
 * WA Manager — Offline Test Suite Execution Runner
 * File: tests/TestRunner.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/Unit/ConversationTest.php';
require_once __DIR__ . '/Integration/UsageServiceTest.php';

use Tests\Unit\ConversationTest;
use Tests\Integration\UsageServiceTest;

echo "===============================================\n";
echo "   Running Custom WA SaaS Offline Test Suite   \n";
echo "===============================================\n";

$tests = [
    [new ConversationTest(), 'testFromRowMapsFieldsCorrectly'],
    [new UsageServiceTest(), 'testGetMonthlyLimitAndUsageReturnsCorrectArray']
];

$passed = 0;
$failed = 0;

foreach ($tests as $t) {
    list($class, $method) = $t;
    $className = get_class($class);
    echo "• Running $className::$method()... ";
    try {
        $class->$method();
        echo "✓ Passed\n";
        $passed++;
    } catch (\Exception $e) {
        echo "✗ Failed\n";
        echo "  " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "-----------------------------------------------\n";
echo "Test Summary: $passed Passed, $failed Failed.\n";
echo "===============================================\n";

exit($failed > 0 ? 1 : 0);
