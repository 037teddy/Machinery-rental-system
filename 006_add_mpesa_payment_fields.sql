USE machinery_rental_system;

ALTER TABLE payments
    ADD COLUMN phone_number VARCHAR(20) NULL AFTER method,
    ADD COLUMN checkout_request_id VARCHAR(150) NULL AFTER phone_number,
    ADD COLUMN external_reference VARCHAR(150) NULL AFTER checkout_request_id,
    ADD COLUMN transaction_reference VARCHAR(150) NULL AFTER external_reference,
    ADD COLUMN failure_reason VARCHAR(255) NULL AFTER transaction_reference;

CREATE UNIQUE INDEX payments_checkout_request_unique ON payments (checkout_request_id);
CREATE UNIQUE INDEX payments_external_reference_unique ON payments (external_reference);
