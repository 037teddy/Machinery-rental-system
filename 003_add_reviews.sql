USE machinery_rental_system;

CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    machinery_id INT NOT NULL,
    rating TINYINT UNSIGNED NOT NULL,
    comment TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY one_review_per_rental (user_id, machinery_id),
    CONSTRAINT reviews_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT reviews_machinery_fk FOREIGN KEY (machinery_id) REFERENCES machinery(id) ON DELETE CASCADE,
    CONSTRAINT reviews_rating_check CHECK (rating BETWEEN 1 AND 5)
);
