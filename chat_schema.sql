-- ============================================================
-- WA Manager — Live Chat SQL Schema
-- Database: wa_saas | Port: 3307
-- Run this in phpMyAdmin or MySQL CLI before using chat.php
-- ============================================================

-- جدول المحادثات
CREATE TABLE IF NOT EXISTS conversations (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    company_id       INT          NOT NULL,                        -- FK → companies.id
    user_id          INT          DEFAULT NULL,                    -- FK → users.id (الموظف المسؤول)
    contact_number   VARCHAR(50)  NOT NULL,                        -- رقم العميل (أرقام فقط)
    contact_name     VARCHAR(255) DEFAULT NULL,                    -- اسم العميل (اختياري)
    last_message_at  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_at       TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_conv (company_id, contact_number),           -- محادثة واحدة لكل رقم لكل شركة
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE SET NULL
);

-- جدول رسائل الشات
CREATE TABLE IF NOT EXISTS chat_messages (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    conversation_id INT          NOT NULL,                         -- FK → conversations.id
    user_id         INT          DEFAULT NULL,                     -- FK → users.id (الموظف عند direction=out)
    direction       ENUM('in','out') NOT NULL,                     -- in = وارد من العميل | out = صادر من الموظف
    body            TEXT         NOT NULL,                         -- نص الرسالة
    wa_message_id   VARCHAR(100) DEFAULT NULL,                     -- Message ID من Meta (لتجنب التكرار)
    sent_at         TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id)         REFERENCES users(id)         ON DELETE SET NULL
);

-- Index لتسريع جلب رسائل محادثة معينة
CREATE INDEX idx_chat_messages_conv ON chat_messages(conversation_id, sent_at);

-- Index لتسريع جلب محادثات شركة معينة
CREATE INDEX idx_conversations_company ON conversations(company_id, last_message_at);

-- جدول الملاحظات الداخلية للمحادثة (غير مرسلة إلى واتساب)
CREATE TABLE IF NOT EXISTS conversation_notes (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    company_id      INT NOT NULL,
    conversation_id  INT NOT NULL,
    body            TEXT NOT NULL,
    created_by      INT DEFAULT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY company_id (company_id),
    KEY conversation_id (conversation_id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);
