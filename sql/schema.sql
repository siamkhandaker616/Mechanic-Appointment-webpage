-- Mayhem Mobility — Database Schema
-- CSE 391 Assignment 3

CREATE DATABASE IF NOT EXISTS mayhem_mobility
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE mayhem_mobility;

CREATE TABLE mechanics (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    bio TEXT,
    nickname VARCHAR(50) DEFAULT NULL,
    quote VARCHAR(255) DEFAULT NULL,
    theme VARCHAR(20) DEFAULT 'default',
    specialties TEXT,
    years_experience INT DEFAULT 0,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE mechanic_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mechanic_id INT NOT NULL,
    day_of_week TINYINT NOT NULL COMMENT '0=Sun, 1=Mon ... 6=Sat',
    slot_1 BOOLEAN DEFAULT TRUE COMMENT '10:00-12:00',
    slot_2 BOOLEAN DEFAULT TRUE COMMENT '12:00-14:00',
    slot_3 BOOLEAN DEFAULT TRUE COMMENT '14:00-16:00',
    slot_4 BOOLEAN DEFAULT TRUE COMMENT '16:00-18:00',
    UNIQUE KEY uq_mech_day (mechanic_id, day_of_week),
    FOREIGN KEY (mechanic_id) REFERENCES mechanics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE mechanic_overrides (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mechanic_id INT NOT NULL,
    override_date DATE NOT NULL,
    slot_1 BOOLEAN DEFAULT TRUE,
    slot_2 BOOLEAN DEFAULT TRUE,
    slot_3 BOOLEAN DEFAULT TRUE,
    slot_4 BOOLEAN DEFAULT TRUE,
    reason VARCHAR(255) DEFAULT NULL,
    UNIQUE KEY uq_mech_date (mechanic_id, override_date),
    FOREIGN KEY (mechanic_id) REFERENCES mechanics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    phone VARCHAR(20) NOT NULL UNIQUE,
    address TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE cars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    license_no VARCHAR(50) NOT NULL UNIQUE,
    engine_no VARCHAR(50) NOT NULL,
    model VARCHAR(100) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    car_id INT NOT NULL,
    mechanic_id INT NOT NULL,
    appointment_date DATE NOT NULL,
    slot_index TINYINT NOT NULL COMMENT '0=10:00, 1=12:00, 2=14:00, 3=16:00',
    status ENUM('scheduled','in_progress','completed','cancelled') DEFAULT 'scheduled',
    cancelled_at DATETIME DEFAULT NULL,
    admin_notes TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_car_date (car_id, appointment_date),
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    FOREIGN KEY (car_id) REFERENCES cars(id) ON DELETE CASCADE,
    FOREIGN KEY (mechanic_id) REFERENCES mechanics(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    appointment_id INT NOT NULL UNIQUE,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (appointment_id) REFERENCES appointments(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE sim_config (
    id INT PRIMARY KEY DEFAULT 1 CHECK (id = 1),
    use_simulated_time BOOLEAN DEFAULT FALSE,
    simulated_datetime DATETIME DEFAULT NULL
) ENGINE=InnoDB;

INSERT INTO sim_config (id, use_simulated_time, simulated_datetime)
VALUES (1, FALSE, NULL);
