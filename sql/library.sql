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
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create the Books Table
CREATE TABLE books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    author VARCHAR(255) NOT NULL,
    genre VARCHAR(50),
    book_number VARCHAR(50) UNIQUE NOT NULL,
    available BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create the Transactions Table
CREATE TABLE transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    user_id INT NOT NULL,
    borrowed_date DATE NOT NULL,
    due_date DATE NOT NULL,
    returned BOOLEAN DEFAULT FALSE,
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

-- Insert a sample librarian account
INSERT INTO users (first_name, last_name, email, password, role)
VALUES ('Admin', 'User', 'admin@library.com', '$2y$10$K4JbIhJ50O8U6O1/c59zaOCWhBFbzBc1Tsh5FEzDZJj5Lo.L7Chz6', 'librarian');

-- Insert sample students
INSERT INTO users (first_name, last_name, email, password, role, student_id)
VALUES
('John', 'Doe', 'john.doe@student.com', '$2y$10$K4JbIhJ50O8U6O1/c59zaOCWhBFbzBc1Tsh5FEzDZJj5Lo.L7Chz6', 'student', '19074376'),
('Jane', 'Smith', 'jane.smith@student.com', '$2y$10$K4JbIhJ50O8U6O1/c59zaOCWhBFbzBc1Tsh5FEzDZJj5Lo.L7Chz6', 'student', '19074377');

-- Insert sample books
INSERT INTO books (title, author, genre, book_number, available)
VALUES
('The Great Gatsby', 'F. Scott Fitzgerald', 'Fiction', '001', TRUE),
('To Kill a Mockingbird', 'Harper Lee', 'Fiction', '002', TRUE),
('1984', 'George Orwell', 'Dystopian', '003', TRUE);

-- Insert sample transactions
INSERT INTO transactions (book_id, user_id, borrowed_date, due_date, returned)
VALUES
(1, 2, '2025-01-10', '2025-01-24', FALSE),
(2, 3, '2025-01-12', '2025-01-26', FALSE);

-- Insert sample notifications
INSERT INTO notifications (user_id, message, read_status)
VALUES
(2, 'Your book "The Great Gatsby" is due on January 24, 2025.', FALSE),
(3, 'Your book "To Kill a Mockingbird" is due on January 26, 2025.', FALSE);
