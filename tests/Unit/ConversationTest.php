<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Core\Conversation\Conversation;

class ConversationTest extends TestCase {
    public function testFromRowMapsFieldsCorrectly() {
        $row = [
            'id' => 12,
            'company_id' => 3,
            'contact_number' => '123456789',
            'created_by' => 5,
            'assigned_to' => 2,
            'status' => 'pending',
            'is_pinned' => 1,
            'last_message_at' => '2026-07-13 12:00:00'
        ];
        
        $conv = Conversation::fromRow($row);
        
        $this->assertEquals(12, $conv->id);
        $this->assertEquals(3, $conv->companyId);
        $this->assertEquals('123456789', $conv->contactNumber);
        $this->assertEquals(5, $conv->createdBy);
        $this->assertEquals(2, $conv->assignedTo);
        $this->assertEquals('pending', $conv->status);
        $this->assertTrue($conv->isPinned);
        $this->assertEquals('2026-07-13 12:00:00', $conv->lastMessageAt);
    }
}
