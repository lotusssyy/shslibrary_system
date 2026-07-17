Local setup for XAMPP and ESP32 compatibility

1) Deploy to XAMPP (Windows)
- Copy the project folder into XAMPP's htdocs or configure an Apache VirtualHost pointing to the project root.
- Ensure PHP and MySQL are enabled in XAMPP control panel.
- Create a MySQL database named `library_system` (or change includes/db.php to another name).
- Import any SQL dump if available (phpMyAdmin).
- Start Apache and MySQL, visit: http://localhost/<project-folder>/

2) Composer / dependencies
- If vendor/ is missing, run: composer install (require Composer installed globally).

3) DB credentials
- includes/db.php will use Heroku JAWSDB_URL if present; otherwise it defaults to XAMPP: host=127.0.0.1, user=root, password="", database=library_system.

4) ESP32 compatibility (HTTP REST)
- ESP32 can use HTTP(S) requests to the app endpoints. Recommended endpoints:
  - GET /pages/available_books.php -> list available books (JSON)
  - POST /api/borrow.php {"book_id":..., "user_id":...} -> borrow
  - POST /api/return.php {"book_id":..., "user_id":...} -> return
- If these API endpoints do not exist, add simple PHP scripts that accept JSON input and respond with JSON.

5) Security / CORS
- For ESP32 on LAN, ensure CORS and authentication are handled (API token or simple key).

6) Next steps I can do:
- Create example API endpoints (borrow/return/available) and minimal JSON handlers for ESP32.
- Add a small example Arduino/ESP32 sketch showing HTTP requests.

If want those, say which APIs the ESP32 should call and whether to add example sketch.