-- Pivot: одна задача протокола → N карточек (по одной у каждого ответственного).
CREATE TABLE IF NOT EXISTS protocol_decision_cards (
    decision_id INT NOT NULL,
    card_id INT UNSIGNED NOT NULL,
    user_name VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (decision_id, card_id),
    INDEX idx_pdc_user (user_name),
    INDEX idx_pdc_card (card_id),
    INDEX idx_pdc_decision (decision_id),
    CONSTRAINT fk_pdc_decision FOREIGN KEY (decision_id) REFERENCES protocol_decisions(id) ON DELETE CASCADE,
    CONSTRAINT fk_pdc_card FOREIGN KEY (card_id) REFERENCES tasks_cards(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
