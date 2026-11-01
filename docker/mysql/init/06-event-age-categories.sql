CREATE TABLE IF NOT EXISTS event_age_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    category_name VARCHAR(80) NOT NULL,
    min_age SMALLINT UNSIGNED NOT NULL,
    max_age SMALLINT UNSIGNED DEFAULT NULL,
    CONSTRAINT chk_event_age_categories_range
        CHECK (max_age IS NULL OR max_age >= min_age),
    CONSTRAINT uq_event_age_categories_name
        UNIQUE (event_id, category_name),
    INDEX idx_event_age_categories_range (event_id, min_age, max_age),
    FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
