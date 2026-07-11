-- Seed test RFID and barcode for local testing
USE library_management;

-- Set RFID for John Doe
UPDATE users SET rfid_number = 'RFID123456' WHERE email = 'john.doe@student.com';

-- Ensure The Great Gatsby has a barcode and correct quantities
UPDATE books SET barcode = 'GATSBY001', total_quantity = 1, available = 1 WHERE book_number = '001';

-- Optional: show changes (for manual verification)
SELECT id, first_name, last_name, email, rfid_number FROM users WHERE email = 'john.doe@student.com';
SELECT id, title, book_number, barcode, available, total_quantity FROM books WHERE book_number = '001';
