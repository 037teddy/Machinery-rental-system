USE machinery_rental_system;

ALTER TABLE bookings
    ADD COLUMN handed_over_at DATETIME NULL AFTER status,
    ADD COLUMN returned_at DATETIME NULL AFTER handed_over_at,
    ADD COLUMN condition_notes TEXT NULL AFTER returned_at;
