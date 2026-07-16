<?php
/**
 * WA Manager — Lightweight Offline Test Harness
 * File: tests/TestCase.php
 */
namespace PHPUnit\Framework;

class TestCase {
    protected function assertEquals($expected, $actual, $message = '') {
        if ($expected !== $actual) {
            throw new \Exception("Assertion failed: Expected " . var_export($expected, true) . ", got " . var_export($actual, true) . ". " . $message);
        }
    }
    
    protected function assertTrue($condition, $message = '') {
        if ($condition !== true) {
            throw new \Exception("Assertion failed: Expected true, got " . var_export($condition, true) . ". " . $message);
        }
    }
}

namespace Tests;

class MockMysqli extends \mysqli {
    private $preparedStmts = [];
    public function __construct() {}
    public function prepare(string $query): \mysqli_stmt|false {
        $stmt = array_shift($this->preparedStmts);
        return $stmt ?: new MockMysqliStmt();
    }
    public function setPreparedStmts($stmts) {
        $this->preparedStmts = $stmts;
    }
}

class MockMysqliStmt extends \mysqli_stmt {
    private $result;
    public function __construct() {}
    public function bind_param(string $types, mixed &...$vars): bool {
        return true;
    }
    public function execute(?array $params = null): bool {
        return true;
    }
    public function get_result(): \mysqli_result|false {
        return $this->result ?: new MockMysqliResult();
    }
    public function setResult($res) {
        $this->result = $res;
    }
    public function close(): bool {
        return true;
    }
}

class MockMysqliResult extends \mysqli_result {
    private $data = [];
    public function __construct() {}
    public function fetch_assoc(): array|null|false {
        return array_shift($this->data) ?: null;
    }
    public function setData($data) {
        $this->data = $data;
    }
}
