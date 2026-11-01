CREATE TABLE IF NOT EXISTS chip (
    chip_number VARCHAR(255) NOT NULL,
    participant_id INT NOT NULL,
    PRIMARY KEY (participant_id),
    FOREIGN KEY (participant_id) REFERENCES participants(id),
    INDEX idx_chip_number (chip_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;