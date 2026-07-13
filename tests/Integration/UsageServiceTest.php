<?php
namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Core\Services\UsageService;
use Tests\MockMysqli;
use Tests\MockMysqliStmt;
use Tests\MockMysqliResult;

class UsageServiceTest extends TestCase {
    public function testGetMonthlyLimitAndUsageReturnsCorrectArray() {
        $db = new MockMysqli();
        
        $stmt1 = new MockMysqliStmt();
        $res1 = new MockMysqliResult();
        $res1->setData([['monthly_limit' => 1000]]);
        $stmt1->setResult($res1);
        
        $stmt2 = new MockMysqliStmt();
        $res2 = new MockMysqliResult();
        $res2->setData([['total' => 250]]);
        $stmt2->setResult($res2);
        
        $db->setPreparedStmts([$stmt1, $stmt2]);
               
        $service = new UsageService($db);
        $usage = $service->getMonthlyLimitAndUsage(3);
        
        $this->assertEquals(1000, $usage['limit']);
        $this->assertEquals(250, $usage['used']);
    }
}
