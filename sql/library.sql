-- Create the database
CREATE DATABASE IF NOT EXISTS library_management;
USE library_management;

-- Create the Users Table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('student', 'librarian') DEFAULT 'student',
    student_id VARCHAR(20) UNIQUE,
    rfid_number VARCHAR(100) UNIQUE,
    course VARCHAR(100),
    year_level TINYINT UNSIGNED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- RFID assignment history. A student may have one active card, while old cards remain recorded as revoked.
CREATE TABLE rfid_card_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    rfid_number VARCHAR(100) NOT NULL,
    status ENUM('active', 'revoked') NOT NULL DEFAULT 'active',
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at TIMESTAMP NULL DEFAULT NULL,
    assigned_by INT NULL,
    revoked_by INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (revoked_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_rfid_history_user_status (user_id, status),
    INDEX idx_rfid_history_number (rfid_number)
);

-- Create the Books Table
CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    genre VARCHAR(50),
    book_number VARCHAR(50) UNIQUE NOT NULL,
    barcode VARCHAR(255) UNIQUE,
    available INT DEFAULT 1,
    total_quantity INT DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create the Transactions Table
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    user_id INT NOT NULL,
    action ENUM('BORROW', 'RETURN') NOT NULL,
    borrowed_date DATETIME,
    due_date DATE NOT NULL,
    returned_date DATETIME NULL,
    FOREIGN KEY (book_id) REFERENCES books(id),
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Create the Notifications Table
CREATE TABLE notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    message TEXT NOT NULL,
    read_status BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);

-- Exit Verification Log
CREATE TABLE exit_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    verified_by INT NOT NULL,
    status ENUM('verified', 'flagged') NOT NULL,
    notes TEXT,
    verified_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(id),
    FOREIGN KEY (verified_by) REFERENCES users(id)
);

-- Insert a sample librarian account
INSERT INTO users (first_name, last_name, email, password, role)
VALUES ('Admin', 'User', 'admin@library.com', '$2y$10$K4JbIhJ50O8U6O1/c59zaOCWhBFbzBc1Tsh5FEzDZJj5Lo.L7Chz6', 'librarian');

-- Insert sample students
INSERT INTO users (first_name, last_name, email, password, role, student_id)
VALUES
('John', 'Doe', 'john.doe@student.com', '$2y$10$K4JbIhJ50O8U6O1/c59zaOCWhBFbzBc1Tsh5FEzDZJj5Lo.L7Chz6', 'student', '19074376'),
('Jane', 'Smith', 'jane.smith@student.com', '$2y$10$K4JbIhJ50O8U6O1/c59zaOCWhBFbzBc1Tsh5FEzDZJj5Lo.L7Chz6', 'student', '19074377');

-- Insert sample books
INSERT INTO books (title, author, genre, book_number, barcode, available, total_quantity)
VALUES
('The Great Gatsby', 'F. Scott Fitzgerald', 'Fiction', 'FIC-2026-0001', '9780743273565', 2, 2),
('To Kill a Mockingbird', 'Harper Lee', 'Fiction', 'FIC-2026-0002', '9780061120084', 2, 2),
('1984', 'George Orwell', 'Fiction', 'FIC-2026-0003', '9780451524935', 1, 1);

-- Insert sample transactions
INSERT INTO transactions (book_id, user_id, action, borrowed_date, due_date, returned_date)
VALUES
(1, 2, 'BORROW', '2026-07-10 10:00:00', '2026-07-24', NULL),
(2, 3, 'BORROW', '2026-07-12 14:30:00', '2026-07-26', NULL);

-- Insert sample notifications
INSERT INTO notifications (user_id, message, read_status)
VALUES
(2, 'Your book "The Great Gatsby" is due on January 24, 2025.', FALSE),
(3, 'Your book "To Kill a Mockingbird" is due on January 26, 2025.', FALSE);

-- System constants (defined in includes/db.php):
-- BORROW_LIMIT = 5  (max books a student can borrow at once)
-- FINE_PER_DAY = 5.00  (₱5 per day for overdue books)

-- Messages table for two-way student-librarian messaging
CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
