/*
 * SHS Library Management System - ESP32 Controller Sketch (Dual-MCU)
 * ESP32 handles: WiFi, HTTP, MFRC522 RFID, LCD 20x4, LEDs, Buzzer
 * Arduino Uno handles: Barcode Scanner (SoftwareSerial), 4x3 Keypad
 * Communication: Serial UART at 9600 baud (ESP32 GPIO16 RX <- Uno TX, ESP32 GPIO17 TX -> Uno RX)
 *
 * Serial Protocol (ESP32 <-> Uno):
 *   "CMD:READY"         -> "RESP:READY"
 *   "CMD:READ_BARCODE"  -> "RESP:BARCODE:<data>" or "RESP:BARCODE:TIMEOUT"
 *   "CMD:READ_KEYPAD"   -> "RESP:KEY:<char>"
 *
 * Requires: WiFi.h, HTTPClient.h, MFRC522, LiquidCrystal, ArduinoJson libraries
 */

#include <WiFi.h>
#include <HTTPClient.h>
#include <SPI.h>
#include <MFRC522.h>
#include <LiquidCrystal.h>
#include <ArduinoJson.h>

// ==================== CONFIGURATION ====================
// Change these to match your network
const char* WIFI_SSID     = "library_system";
const char* WIFI_PASSWORD  = "3i2025G7";

// Your XAMPP server IP (e.g., 192.168.1.72)
const char* SERVER_HOST    = "192.168.1.36";
const int   SERVER_PORT    = 80;  // Use 443 if HTTPS with WiFiClientSecure
// ========================================================

// ==================== PIN DEFINITIONS (ESP32) ====================
// ESP32 pins — barcode scanner + keypad moved to Arduino Uno
// Uno communicates via Serial UART (GPIO 16 RX, GPIO 17 TX)

// MFRC522 RFID (SPI)
#define RFID_SS_PIN    5
#define RFID_RST_PIN   27
// SPI: MOSI=23, MISO=19, SCK=18 (default ESP32 SPI pins)

// LCD 20x4 (direct parallel, 4-bit mode)
#define LCD_RS  13
#define LCD_E   12
#define LCD_D4  14
#define LCD_D5  26
#define LCD_D6  25
#define LCD_D7  33

// Buzzer & LEDs
#define BUZZER_PIN    2
#define GREEN_LED_PIN 15
#define RED_LED_PIN   32

// Arduino Uno Serial Communication (UART2)
// Uno TX -> ESP32 GPIO 16 (RX2)
// Uno RX -> ESP32 GPIO 17 (TX2)
// Common GND required
#define UNO_RX         16
#define UNO_TX         17
#define UNO_BAUD       9600
// ================================================================

// ==================== GLOBAL OBJECTS ====================
LiquidCrystal lcd(LCD_RS, LCD_E, LCD_D4, LCD_D5, LCD_D6, LCD_D7);
MFRC522 mfrc522(RFID_SS_PIN, RFID_RST_PIN);

// Serial2 (UART2) on GPIO 16/17 for Arduino Uno communication
// Serial2 is globally defined by ESP32 Arduino framework

bool adminMode = false;
// ========================================================

// ==================== FUNCTION DECLARATIONS ====================
void showWelcomeMessage();
String getCardUID();
void beepBuzzer();
void accessDenied();
void accessGranted(String role);
char getKeypadFromUno();
String getBarcodeFromUno();
String sendUnoCommand(String cmd);
void handleValidUser(String rfidNumber);
void showTransactionResult(String result);
void scanRFIDForAdmin();
void scanBarcodeForAdmin();
void blinkText(String text, int row, int col, int blinks, int delayTime);
bool connectWiFi();
String httpGet(String path);
String httpPost(String path, String body, bool isJson);
String urlEncode(String str);
String truncStr(String str, int maxLen);
String lookupBook(String rfidNumber, String barcode, String action);
// ========================================================

void setup() {
  Serial.begin(115200);   // Debugging via USB
  Serial2.begin(UNO_BAUD, SERIAL_8N1, UNO_RX, UNO_TX); // Uno communication on UART2

  SPI.begin();
  mfrc522.PCD_Init();
  lcd.begin(20, 4);

  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, LOW);

  Serial.println("=================================");
  Serial.println("SHS Library System - ESP32 v5.0");
  Serial.println("      Dual-MCU Architecture");
  Serial.println("=================================");

  // Verify Uno is connected
  String ready = sendUnoCommand("CMD:READY");
  if (ready == "RESP:READY") {
    Serial.println("Arduino Uno connected");
    lcd.setCursor(0, 3);
    lcd.print("Uno: OK            ");
  } else {
    Serial.println("WARNING: Arduino Uno not responding");
    lcd.setCursor(0, 3);
    lcd.print("Uno: OFFLINE!      ");
    delay(2000);
  }

  // Connect to WiFi
  if (!connectWiFi()) {
    lcd.clear();
    lcd.setCursor(0, 0);
    lcd.print("WiFi FAILED!");
    lcd.setCursor(0, 1);
    lcd.print("Check credentials");
    lcd.setCursor(0, 2);
    lcd.print("and restart.");
    Serial.println("WiFi connection failed. Halting.");
    while (true) delay(1000);
  }

  Serial.println("System Initialized");
  showWelcomeMessage();
}

void loop() {
  if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
    String rfidNumber = getCardUID();
    beepBuzzer();

    if (!adminMode) {
      lcd.clear();
      lcd.setCursor(3, 0);
      lcd.print("Library System");
      lcd.setCursor(0, 1);
      lcd.print("   Checking RFID   ");
      lcd.setCursor(0, 2);
      lcd.print("    Please wait    ");
      delay(1000);

      // POST rfid_number to scan.php — returns JSON with user info
      String response = httpPost("/scan.php", "rfid_number=" + urlEncode(rfidNumber), false);
      Serial.println("Server response: " + response);

      // Parse JSON to determine if user is valid and what role
      String status = "";
      String role = "";
      StaticJsonDocument<512> doc;
      DeserializationError err = deserializeJson(doc, response);
      if (!err) {
        status = doc["status"] | "";
        if (status == "success") {
          role = doc["user"]["role"] | "";
        }
      }

      if (status == "success" && role == "librarian") {
        Serial.println("Admin RFID matched");
        accessGranted("Admin");
        lcd.clear();
        lcd.setCursor(3, 0);
        lcd.print("Library System");
        lcd.setCursor(0, 1);
        lcd.print(" Press # for Admin ");
        lcd.setCursor(0, 2);
        lcd.print("Or any key to cont.");
        char key = getKeypadFromUno();
        if (key == '#') {
          adminMode = true;
          lcd.clear();
          lcd.setCursor(3, 0);
          lcd.print("Library System");
          lcd.setCursor(5, 1);
          lcd.print("Admin Mode");
          lcd.setCursor(0, 2);
          lcd.print("1:RFID  2:Barcode");
          char adminAction = getKeypadFromUno();
          if (adminAction == '1') {
            scanRFIDForAdmin();
          } else if (adminAction == '2') {
            scanBarcodeForAdmin();
          }
          adminMode = false;
        } else {
          handleValidUser(rfidNumber);
        }
      } else if (status == "success") {
        Serial.println("User RFID matched");
        accessGranted("User");
        handleValidUser(rfidNumber);
      } else {
        // status could be "error" or JSON parsing failed
        String errorMsg = "";
        if (!err) {
          errorMsg = doc["error"] | "";
        }
        Serial.println("Invalid RFID: " + errorMsg);
        accessDenied();
      }
    }

    mfrc522.PICC_HaltA();
    showWelcomeMessage();
    delay(1000);
  }
}

// ==================== WIFI ====================
bool connectWiFi() {
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);
  Serial.print("Connecting to WiFi");
  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts++ < 30) {
    delay(500);
    Serial.print(".");
  }
  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nConnected: " + WiFi.localIP().toString());
    Serial.print("RSSI: ");
    Serial.println(WiFi.RSSI());
    return true;
  }
  Serial.println("\nWiFi Failed");
  return false;
}

// ==================== HTTP HELPERS ====================
const int HTTP_TIMEOUT_MS  = 10000; // 10s per attempt (was 30s)
const int HTTP_MAX_RETRIES = 3;

String httpGet(String path) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected");
    return "";
  }

  String url = "http://" + String(SERVER_HOST) + ":" + String(SERVER_PORT) + path;
  for (int attempt = 1; attempt <= HTTP_MAX_RETRIES; attempt++) {
    HTTPClient http;
    http.begin(url);
    http.setTimeout(HTTP_TIMEOUT_MS);
    int httpCode = http.GET();
    String response = "";
    if (httpCode == HTTP_CODE_OK) {
      response = http.getString();
      response.trim();
      http.end();
      return response;
    }
    Serial.println("HTTP GET attempt " + String(attempt) + "/" + String(HTTP_MAX_RETRIES) + " failed: " + String(httpCode));
    http.end();
    if (attempt < HTTP_MAX_RETRIES) delay(500 * attempt); // backoff
  }
  return "";
}

String httpPost(String path, String body, bool isJson) {
  if (WiFi.status() != WL_CONNECTED) {
    Serial.println("WiFi not connected");
    return "";
  }

  String url = "http://" + String(SERVER_HOST) + ":" + String(SERVER_PORT) + path;
  for (int attempt = 1; attempt <= HTTP_MAX_RETRIES; attempt++) {
    HTTPClient http;
    http.begin(url);
    http.setTimeout(HTTP_TIMEOUT_MS);
    if (isJson) {
      http.addHeader("Content-Type", "application/json");
    } else {
      http.addHeader("Content-Type", "application/x-www-form-urlencoded");
    }
    int httpCode = http.POST(body);
    String response = "";
    if (httpCode == HTTP_CODE_OK || httpCode == HTTP_CODE_BAD_REQUEST || httpCode == HTTP_CODE_NOT_FOUND || httpCode == 500) {
      // Always read the body — even on 400/500, the server sends useful JSON errors
      response = http.getString();
      response.trim();
      http.end();
      return response;
    }
    Serial.println("HTTP POST attempt " + String(attempt) + "/" + String(HTTP_MAX_RETRIES) + " failed: " + String(httpCode));
    http.end();
    if (attempt < HTTP_MAX_RETRIES) delay(500 * attempt); // backoff
  }
  return "";
}

// ==================== RFID ====================
String getCardUID() {
  String uid = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(mfrc522.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  return uid;
}

// ==================== BUZZER & LEDS ====================
void beepBuzzer() {
  tone(BUZZER_PIN, 1000, 200);
  delay(200);
  noTone(BUZZER_PIN);
}

void accessDenied() {
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("   ACCESS DENIED   ");
  Serial.println("ACCESS DENIED!");

  blinkText("   ACCESS DENIED   ", 1, 0, 3, 300);

  digitalWrite(RED_LED_PIN, HIGH);
  tone(BUZZER_PIN, 400, 200);
  delay(200);
  tone(BUZZER_PIN, 200, 400);
  delay(400);
  digitalWrite(RED_LED_PIN, LOW);
  noTone(BUZZER_PIN);

  delay(2000);
}

void accessGranted(String role) {
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("  ACCESS GRANTED   ");
  lcd.setCursor(0, 2);
  lcd.print("Role: "); lcd.print(role);
  Serial.println("ACCESS GRANTED: " + role);

  blinkText("  ACCESS GRANTED   ", 1, 0, 2, 300);

  digitalWrite(GREEN_LED_PIN, HIGH);
  tone(BUZZER_PIN, 1000, 200);
  delay(200);
  digitalWrite(GREEN_LED_PIN, LOW);
  noTone(BUZZER_PIN);

  delay(1000);
}

// ==================== LCD ====================
void showWelcomeMessage() {
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("   Welcome User!   ");
  lcd.setCursor(0, 2);
  lcd.print("   Scan your ID    ");
  lcd.setCursor(0, 3);
  lcd.print("   to proceed...   ");
}

void blinkText(String text, int row, int col, int blinks, int delayTime) {
  for (int i = 0; i < blinks; i++) {
    lcd.setCursor(col, row);
    lcd.print(text);
    delay(delayTime);
    lcd.setCursor(col, row);
    lcd.print("                    ");
    delay(delayTime);
  }
  lcd.setCursor(col, row);
  lcd.print(text);
}

// ==================== UNO COMMUNICATION ====================
String sendUnoCommand(String cmd) {
  // Drain any stale data from previous exchanges
  while (Serial2.available()) Serial2.read();

  Serial2.println(cmd);
  unsigned long startTime = millis();
  const unsigned long timeout = 20000; // 20s timeout (barcode read can take time)
  String response = "";

  while (millis() - startTime < timeout) {
    if (Serial2.available()) {
      response = Serial2.readStringUntil('\n');
      response.trim();
      if (response.length() > 0) {
        return response;
      }
    }
  }
  return "TIMEOUT";
}

// ==================== KEYPAD (via Uno) ====================
char getKeypadFromUno() {
  Serial.println("Requesting keypad input from Uno...");
  String resp = sendUnoCommand("CMD:READ_KEYPAD");

  if (resp.startsWith("RESP:KEY:")) {
    char key = resp.charAt(9);
    tone(BUZZER_PIN, 1000, 50);
    Serial.print("Key Pressed: ");
    Serial.println(key);
    return key;
  }
  Serial.println("Keypad timeout/error from Uno");
  return '*'; // Default cancel on error
}

// ==================== BARCODE (via Uno) ====================
String getBarcodeFromUno() {
  Serial.println("Requesting barcode scan from Uno...");
  String resp = sendUnoCommand("CMD:READ_BARCODE");

  if (resp.startsWith("RESP:BARCODE:")) {
    String barcode = resp.substring(13); // Length of "RESP:BARCODE:"
    if (barcode == "TIMEOUT" || barcode.length() == 0) {
      Serial.println("No barcode received from Uno");
      return "";
    }
    Serial.print("Barcode: ["); Serial.print(barcode); Serial.println("]");
    return barcode;
  }
  Serial.println("Barcode timeout/error from Uno");
  return "";
}

// ==================== TRANSACTION HANDLING ====================
void handleValidUser(String rfidNumber) {
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("    Choose Action   ");
  lcd.setCursor(0, 2);
  lcd.print("1:Borrow   2:Return ");
  lcd.setCursor(0, 3);
  lcd.print("*:Cancel    #:Help  ");

  char action;
  do {
    action = getKeypadFromUno();
    Serial.print("Pressed Key: ");
    Serial.println(action);
  } while (action != '1' && action != '2' && action != '*' && action != '#');

  if (action == '*') {
    lcd.clear();
    lcd.setCursor(3, 0);
    lcd.print("Library System");
    lcd.setCursor(0, 1);
    lcd.print("    Cancelled     ");
    Serial.println("Transaction Cancelled");
    delay(2000);
    return;
  } else if (action == '#') {
    lcd.clear();
    lcd.setCursor(3, 0);
    lcd.print("Library System");
    lcd.setCursor(0, 1);
    lcd.print(" Help Information: ");
    lcd.setCursor(0, 2);
    lcd.print("1: Borrow a book  ");
    lcd.setCursor(0, 3);
    lcd.print("2: Return a book  ");
    delay(3000);
    return;
  }

  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("  Scan Barcode Now  ");
  lcd.setCursor(0, 2);
  lcd.print(action == '1' ? "    Borrowing...   " : "    Returning...   ");
  blinkText("  Scan Barcode Now  ", 1, 0, 3, 300);

  Serial.println("Waiting for barcode from Uno...");
  String barcode = getBarcodeFromUno();
  Serial.print("Barcode received: [");
  Serial.print(barcode);
  Serial.println("]");

  if (barcode.length() == 0) {
    lcd.clear();
    lcd.setCursor(3, 0);
    lcd.print("Library System");
    lcd.setCursor(0, 1);
    lcd.print("  Invalid Barcode  ");
    Serial.println("No barcode received");
    blinkText("  Invalid Barcode  ", 1, 0, 3, 300);
    delay(2000);
    return;
  }

  // --- PHASE 1: Book lookup + safety checks ---
  String actionStr = (action == '1') ? "BORROW" : "RETURN";
  String lookupResp = lookupBook(rfidNumber, barcode, actionStr);
  Serial.println("Lookup response: " + lookupResp);

  String lookupStatus = "";
  String bookTitle = "";
  String bookAuthor = "";
  bool blocked = false;
  String blockReason = "";
  bool canReturn = false;
  bool isOverdue = false;
  int daysOverdue = 0;
  float fineAmount = 0;

  StaticJsonDocument<512> lookupDoc;
  DeserializationError lookupErr = deserializeJson(lookupDoc, lookupResp);
  if (!lookupErr) {
    lookupStatus = lookupDoc["status"] | "";
    bookTitle = lookupDoc["title"] | "Unknown";
    bookAuthor = lookupDoc["author"] | "Unknown";
    blocked = lookupDoc["blocked"] | false;
    blockReason = lookupDoc["block_reason"] | "";
    canReturn = lookupDoc["can_return"] | false;
    isOverdue = lookupDoc["is_overdue"] | false;
    daysOverdue = lookupDoc["days_overdue"] | 0;
    fineAmount = lookupDoc["fine_amount"] | 0.0f;
  }

  if (lookupStatus != "ok") {
    String errMsg = "";
    if (!lookupErr) {
      errMsg = lookupDoc["message"] | "";
    }
    if (errMsg == "BOOK_NOT_FOUND") {
      showTransactionResult("BOOK_NOT_FOUND");
    } else if (errMsg == "USER_NOT_FOUND") {
      showTransactionResult("USER_NOT_FOUND");
    } else {
      showTransactionResult("LOOKUP_FAILED");
    }
    return;
  }

  // --- PHASE 2: Block checks ---
  if (action == '1' && blocked) {
    if (blockReason == "OVERDUE_BLOCK") {
      showTransactionResult("OVERDUE_BLOCK");
    } else if (blockReason == "BORROW_LIMIT_REACHED") {
      showTransactionResult("BORROW_LIMIT_REACHED");
    } else {
      showTransactionResult(blockReason);
    }
    return;
  }

  if (action == '2' && !canReturn) {
    showTransactionResult("NO_BORROW_RECORD");
    return;
  }

  // --- PHASE 3: LCD Confirmation ---
  String confirmTitle = truncStr(bookTitle, 18);
  String confirmAuthor = truncStr(bookAuthor, 18);

  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  if (action == '1') {
    lcd.print("Borrow:");
  } else {
    lcd.print("Return:");
  }
  lcd.setCursor(action == '1' ? 8 : 7, 1);
  lcd.print(confirmTitle);
  lcd.setCursor(0, 2);
  lcd.print("by ");
  lcd.print(confirmAuthor);
  lcd.setCursor(0, 3);
  lcd.print("#Confirm     *Cancel");

  Serial.println("Confirm: " + actionStr + " - " + bookTitle + " by " + bookAuthor);

  char confirmKey;
  do {
    confirmKey = getKeypadFromUno();
  } while (confirmKey != '#' && confirmKey != '*');

  if (confirmKey == '*') {
    lcd.clear();
    lcd.setCursor(3, 0);
    lcd.print("Library System");
    lcd.setCursor(0, 1);
    lcd.print("    Cancelled     ");
    Serial.println("Transaction Cancelled by user");
    delay(2000);
    return;
  }

  // --- PHASE 4: Execute transaction ---
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("    Processing...   ");
  lcd.setCursor(0, 2);
  lcd.print("    Please wait    ");

  StaticJsonDocument<256> txDoc;
  txDoc["rfid_number"] = rfidNumber;
  txDoc["action"] = actionStr;
  txDoc["barcode"] = barcode;
  String jsonBody;
  serializeJson(txDoc, jsonBody);

  Serial.println("Sending transaction: " + jsonBody);
  String txResponse = httpPost("/api/rfid.php", jsonBody, true);
  Serial.println("Transaction response: " + txResponse);

  String result = "";
  StaticJsonDocument<256> respDoc;
  DeserializationError err = deserializeJson(respDoc, txResponse);
  if (!err) {
    if (respDoc.containsKey("result")) {
      result = respDoc["result"] | "";
    } else if (respDoc.containsKey("message")) {
      result = respDoc["message"] | "";
    }
  }
  showTransactionResult(result);
}

void showTransactionResult(String result) {
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("Transaction Status ");

  lcd.setCursor(0, 2);
  if (result == "BORROW_SUCCESS" || result == "RETURN_SUCCESS") {
    lcd.print("     SUCCESS!      ");
    digitalWrite(GREEN_LED_PIN, HIGH);
    tone(BUZZER_PIN, 1000, 200);
    delay(200);
    digitalWrite(GREEN_LED_PIN, LOW);
    Serial.println("TRANSACTION SUCCESS!");
    blinkText("     SUCCESS!      ", 2, 0, 3, 300);
  } else if (result == "NO_BOOKS_AVAILABLE") {
    lcd.print(" No Books Available");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 400, 200);
    delay(200);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("No Books Available!");
    blinkText(" No Books Available", 2, 0, 3, 300);
  } else if (result == "BOOK_ALREADY_RETURNED") {
    lcd.print(" Already Returned  ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 400, 200);
    delay(200);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("Book Already Returned!");
    blinkText(" Already Returned  ", 2, 0, 3, 300);
  } else if (result == "BOOK_NOT_FOUND") {
    lcd.print("  Book Not Found   ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("Book Not Found!");
    blinkText("  Book Not Found   ", 2, 0, 3, 300);
  } else if (result == "INVALID_ACTION") {
    lcd.print("  Invalid Action   ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("Invalid Action!");
    blinkText("  Invalid Action   ", 2, 0, 3, 300);
  } else if (result == "MISSING_PARAMETERS" || result == "USER_NOT_FOUND") {
    lcd.print("  Invalid Request  ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("Invalid Request: " + result);
    blinkText("  Invalid Request  ", 2, 0, 3, 300);
  } else if (result == "TRANSACTION_FAILED" || result == "UPDATE_FAILED" || result == "NO_BORROW_RECORD") {
    lcd.print("Transaction Failed ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("Transaction Failed: " + result);
    blinkText("Transaction Failed ", 2, 0, 3, 300);
  } else if (result == "BORROW_LIMIT_REACHED") {
    lcd.print("Borrow Limit Hit! ");
    lcd.setCursor(0, 3);
    lcd.print("Return books first");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 400, 200);
    delay(200);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("BORROW LIMIT REACHED");
    blinkText("Borrow Limit Hit! ", 2, 0, 3, 300);
  } else if (result == "OVERDUE_BLOCK") {
    lcd.print("OVERDUE BOOKS!     ");
    lcd.setCursor(0, 3);
    lcd.print("Return them first!");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 400, 200);
    delay(200);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("OVERDUE BLOCK");
    blinkText("OVERDUE BOOKS!     ", 2, 0, 3, 300);
  } else if (result == "LOOKUP_FAILED") {
    lcd.print(" Lookup Failed     ");
    lcd.setCursor(0, 3);
    lcd.print("Try again...");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("LOOKUP FAILED");
    blinkText(" Lookup Failed     ", 2, 0, 3, 300);
  } else {
    lcd.print("   System Error    ");
    lcd.setCursor(0, 3);
    lcd.print(result.substring(0, 20));
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("SYSTEM ERROR: " + result);
    blinkText("   System Error    ", 2, 0, 3, 300);
  }
  delay(3000);
  noTone(BUZZER_PIN);
}

// ==================== ADMIN FUNCTIONS ====================
void scanRFIDForAdmin() {
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("   Scan RFID Now   ");
  blinkText("   Scan RFID Now   ", 1, 0, 3, 300);
  delay(1000);

  unsigned long startTime = millis();
  while (millis() - startTime < 20000) {
    if (mfrc522.PICC_IsNewCardPresent() && mfrc522.PICC_ReadCardSerial()) {
      String rfidNumber = getCardUID();
      Serial.println("Admin scanned RFID: " + rfidNumber);

      // POST to scan.php so the desktop dashboard can pick it up
      httpPost("/scan.php", "rfid_number=" + urlEncode(rfidNumber), false);

      lcd.clear();
      lcd.setCursor(3, 0);
      lcd.print("Library System");
      lcd.setCursor(0, 1);
      lcd.print("    RFID Scanned   ");
      delay(2000);
      mfrc522.PICC_HaltA();
      return;
    }
  }
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print(" No RFID Detected  ");
  blinkText(" No RFID Detected  ", 1, 0, 3, 300);
  delay(2000);
}

void scanBarcodeForAdmin() {
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print(" Scan Barcode Now  ");
  blinkText(" Scan Barcode Now  ", 1, 0, 3, 300);

  Serial.println("Admin barcode scan via Uno...");
  String barcode = getBarcodeFromUno();

  if (barcode.length() > 0) {
    Serial.println("Admin scanned barcode: " + barcode);

    // POST to scan.php so the desktop dashboard can pick it up
    httpPost("/scan.php", "barcode=" + urlEncode(barcode), false);

    lcd.clear();
    lcd.setCursor(3, 0);
    lcd.print("Library System");
    lcd.setCursor(0, 1);
    lcd.print("  Barcode Scanned  ");
    delay(2000);
  } else {
    lcd.clear();
    lcd.setCursor(3, 0);
    lcd.print("Library System");
    lcd.setCursor(0, 1);
    lcd.print("No Barcode Detected");
    blinkText("No Barcode Detected", 1, 0, 3, 300);
    delay(2000);
  }
}

// ==================== UTILITY ====================
String truncStr(String str, int maxLen) {
  if (str.length() <= maxLen) return str;
  return str.substring(0, maxLen - 3) + "...";
}

String lookupBook(String rfidNumber, String barcode, String action) {
  StaticJsonDocument<256> lookupDoc;
  lookupDoc["rfid_number"] = rfidNumber;
  lookupDoc["barcode"] = barcode;
  lookupDoc["action"] = action;
  String body;
  serializeJson(lookupDoc, body);
  return httpPost("/api/book_lookup.php", body, true);
}

String urlEncode(String str) {
  String encodedString = "";
  char c;
  char code0;
  char code1;

  for (int i = 0; i < str.length(); i++) {
    c = str.charAt(i);
    if (c == ' ') {
      encodedString += '+';
    } else if (isAlphaNumeric(c)) {
      encodedString += c;
    } else {
      code1 = (c & 0xf) + '0';
      if ((c & 0xf) > 9) {
        code1 = (c & 0xf) - 10 + 'A';
      }
      c = (c >> 4) & 0xf;
      code0 = c + '0';
      if (c > 9) {
        code0 = c - 10 + 'A';
      }
      encodedString += '%';
      encodedString += code0;
      encodedString += code1;
    }
  }
  return encodedString;
}
