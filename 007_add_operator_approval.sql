USE machinery_rental_system;

ALTER TABLE users
    ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'approved' AFTER role;
