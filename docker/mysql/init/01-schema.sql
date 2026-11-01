-- Tworzy tabelę na odczyty tagów z ChronoTrack
CREATE TABLE IF NOT EXISTS tag_observations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sequence_number INT NOT NULL,
    tag_code VARCHAR(20) NOT NULL,
    timer_code VARCHAR(10) NOT NULL,
    event_code VARCHAR(10) NOT NULL,
    observation_time DECIMAL(15,2) NOT NULL,
    reader_id VARCHAR(20) NOT NULL,
    port_number INT NOT NULL,
    rssi INT NOT NULL,
    reader_sequence INT NOT NULL,
    transaction_type CHAR(1) NOT NULL,
    connection_id VARCHAR(64) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tag (tag_code),
    INDEX idx_time (observation_time),
    INDEX idx_reader (reader_id),
    UNIQUE INDEX idx_unique_obs
        (sequence_number, tag_code, reader_id, observation_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX idx_tag_obs_event_tag_time
  ON tag_observations(event_code, tag_code, observation_time);