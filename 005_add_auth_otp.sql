USE machinery_rental_system;

ALTER TABLE users
    ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER role,
    ADD COLUMN otp_hash VARCHAR(255) NULL AFTER email_verified,
    ADD COLUMN otp_expires DATETIME NULL AFTER otp_hash;
