USE machinery_rental_system;

ALTER TABLE bookings
    ADD COLUMN id_document_type ENUM('identification_card', 'passport') NOT NULL AFTER user_id,
    ADD COLUMN id_document_number VARCHAR(100) NOT NULL AFTER id_document_type,
    ADD COLUMN driver_included TINYINT(1) NOT NULL DEFAULT 1 AFTER id_document_number;
