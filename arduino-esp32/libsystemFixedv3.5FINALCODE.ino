#include <SPI.h>
#include <MFRC522.h>
#include <Keypad.h>
#include <LiquidCrystal.h>

LiquidCrystal lcd(13, 12, 11, 10, 9, 8);  // LCD pins: RS=13, E=12, D4=11, D5=10, D6=9, D7=8
#define SS_PIN 53
#define RST_PIN 49
MFRC522 mfrc522(SS_PIN, RST_PIN);  // RFID pins: SS=53, RST=49

const byte ROWS = 4;
const byte COLS = 3;
char keys[ROWS][COLS] = {
  {'1', '2', '3'},
  {'4', '5', '6'},
  {'7', '8', '9'},
  {'*', '0', '#'}
};
byte rowPins[ROWS] = {24, 26, 28, 30};  // Keypad row pins
byte colPins[COLS] = {32, 34, 36};      // Keypad column pins
Keypad keypad = Keypad(makeKeymap(keys), rowPins, colPins, ROWS, COLS);

#define BUZZER_PIN 48
#define GREEN_LED_PIN 6
#define RED_LED_PIN 7
const int triggerPin = 2;  // Trigger pin for MH-ET LIVE Scanner v3.0 on pin 2

bool adminMode = false;

void showWelcomeMessage();
String getCardUID();
void beepBuzzer();
void accessDenied();
void accessGranted(String role);
char getKeypadInput();
String getBarcode();
void handleValidUser(String rfidNumber);
void showTransactionResult(String response);
String getResponseFromNodeMCU();
void scanRFIDForAdmin();
void scanBarcodeForAdmin();
void blinkText(String text, int row, int col, int blinks, int delayTime);

void setup() {
  Serial1.begin(115200); // NodeMCU communication (pins 18/TX1, 19/RX1)
  Serial.begin(9600);    // Debugging via USB
  Serial3.begin(9600);   // MH-ET LIVE Scanner v3.0 (pins 14/TX3, 15/RX3)
  SPI.begin();
  mfrc522.PCD_Init();
  lcd.begin(20, 4);
  pinMode(BUZZER_PIN, OUTPUT);
  pinMode(GREEN_LED_PIN, OUTPUT);
  pinMode(RED_LED_PIN, OUTPUT);
  pinMode(triggerPin, OUTPUT);
  digitalWrite(GREEN_LED_PIN, LOW);
  digitalWrite(RED_LED_PIN, LOW);
  digitalWrite(triggerPin, HIGH); // Scanner OFF initially (active-low)

  while (Serial1.available()) Serial1.read();

  Serial.println("System Initialized - Scanner OFF until transaction");
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

      String checkCommand = "CHECK_RFID," + rfidNumber;
      Serial1.println(checkCommand);
      Serial.println("📡 Sent to NodeMCU: " + checkCommand);

      String response = getResponseFromNodeMCU();

      Serial.print("🔄 Raw NodeMCU Response: [");
      Serial.print(response);
      Serial.println("]");

      if (response == "VALID_ADMIN") {
        Serial.println("✅ Admin RFID matched");
        accessGranted("Admin");
        lcd.clear();
        lcd.setCursor(3, 0);
        lcd.print("Library System");
        lcd.setCursor(0, 1);
        lcd.print(" Press # for Admin ");
        lcd.setCursor(0, 2);
        lcd.print("Or any key to cont.");
        char key = getKeypadInput();
        if (key == '#') {
          adminMode = true;
          lcd.clear();
          lcd.setCursor(3, 0);
          lcd.print("Library System");
          lcd.setCursor(5, 1);
          lcd.print("Admin Mode");
          lcd.setCursor(0, 2);
          lcd.print("1:RFID  2:Barcode");
          char adminAction = getKeypadInput();
          if (adminAction == '1') {
            scanRFIDForAdmin();
          } else if (adminAction == '2') {
            scanBarcodeForAdmin();
          }
          adminMode = false;
        } else {
          handleValidUser(rfidNumber);
        }
      } else if (response == "VALID") {
        Serial.println("✅ User RFID matched");
        accessGranted("User");
        handleValidUser(rfidNumber);
      } else {
        Serial.println("❌ Invalid or no response: " + response);
        accessDenied();
      }
    }

    mfrc522.PICC_HaltA();
    showWelcomeMessage();
    delay(1000);
  }
}

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

String getCardUID() {
  String uid = "";
  for (byte i = 0; i < mfrc522.uid.size; i++) {
    if (mfrc522.uid.uidByte[i] < 0x10) uid += "0";
    uid += String(mfrc522.uid.uidByte[i], HEX);
  }
  uid.toUpperCase();
  return uid;
}

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
  Serial.println("❌ ACCESS DENIED!");

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
  Serial.println("✅ ACCESS GRANTED: " + role);

  blinkText("  ACCESS GRANTED   ", 1, 0, 2, 300);

  digitalWrite(GREEN_LED_PIN, HIGH);
  tone(BUZZER_PIN, 1000, 200);
  delay(200);
  digitalWrite(GREEN_LED_PIN, LOW);
  noTone(BUZZER_PIN);

  delay(1000);
}

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
    action = getKeypadInput();
    Serial.print("Pressed Key: ");
    Serial.println(action);
  } while (action != '1' && action != '2' && action != '*' && action != '#');

  if (action == '*') {
    lcd.clear();
    lcd.setCursor(3, 0);
    lcd.print("Library System");
    lcd.setCursor(0, 1);
    lcd.print("    Cancelled     ");
    Serial.println("🔄 Transaction Cancelled");
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

  digitalWrite(triggerPin, LOW); // Scanner ON
  Serial.println("Scanner ON for transaction");

  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("  Scan Barcode Now  ");
  lcd.setCursor(0, 2);
  lcd.print(action == '1' ? "    Borrowing...   " : "    Returning...   ");
  blinkText("  Scan Barcode Now  ", 1, 0, 3, 300);

  Serial.println("Waiting for barcode...");
  String barcode = getBarcode();
  Serial.print("Barcode received: [");
  Serial.print(barcode);
  Serial.println("]");

  digitalWrite(triggerPin, HIGH); // Scanner OFF
  Serial.println("Scanner OFF after transaction");

  if (barcode.length() > 0) {
    lcd.clear();
    lcd.setCursor(3, 0);
    lcd.print("Library System");
    lcd.setCursor(0, 1);
    lcd.print("    Processing...   ");
    lcd.setCursor(0, 2);
    lcd.print("    Please wait    ");

    String transactionData = "TRANSACTION," + rfidNumber + "," +
                             (action == '1' ? "BORROW" : "RETURN") + "," +
                             barcode;
    Serial1.println(transactionData);
    Serial.println("📡 Sent: " + transactionData);

    String transactionResponse = getResponseFromNodeMCU();
    showTransactionResult(transactionResponse);
  } else {
    lcd.clear();
    lcd.setCursor(3, 0);
    lcd.print("Library System");
    lcd.setCursor(0, 1);
    lcd.print("  Invalid Barcode  ");
    Serial.println("❌ No barcode received");
    blinkText("  Invalid Barcode  ", 1, 0, 3, 300);
    delay(2000);
  }
}

void showTransactionResult(String response) {
  lcd.clear();
  lcd.setCursor(3, 0);
  lcd.print("Library System");
  lcd.setCursor(0, 1);
  lcd.print("Transaction Status ");

  lcd.setCursor(0, 2);
  if (response == "BORROW_SUCCESS" || response == "RETURN_SUCCESS") {
    lcd.print("     SUCCESS!      ");
    digitalWrite(GREEN_LED_PIN, HIGH);
    tone(BUZZER_PIN, 1000, 200);
    delay(200);
    digitalWrite(GREEN_LED_PIN, LOW);
    Serial.println("✅ TRANSACTION SUCCESS!");
    blinkText("     SUCCESS!      ", 2, 0, 3, 300);
  } else if (response == "NO_BOOKS_AVAILABLE") {
    lcd.print(" No Books Available");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 400, 200);
    delay(200);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("⚠️ No Books Available!");
    blinkText(" No Books Available", 2, 0, 3, 300);
  } else if (response == "BOOK_ALREADY_RETURNED") {
    lcd.print(" Already Returned  ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 400, 200);
    delay(200);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("⚠️ Book Already Returned!");
    blinkText(" Already Returned  ", 2, 0, 3, 300);
  } else if (response == "BOOK_NOT_FOUND") {
    lcd.print("  Book Not Found   ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("❌ Book Not Found!");
    blinkText("  Book Not Found   ", 2, 0, 3, 300);
  } else if (response == "INVALID_ACTION") {
    lcd.print("  Invalid Action   ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("❌ Invalid Action!");
    blinkText("  Invalid Action   ", 2, 0, 3, 300);
  } else if (response == "MISSING_PARAMETERS" || response == "USER_NOT_FOUND") {
    lcd.print("  Invalid Request  ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("❌ Invalid Request: " + response);
    blinkText("  Invalid Request  ", 2, 0, 3, 300);
  } else if (response == "TRANSACTION_FAILED" || response == "UPDATE_FAILED") {
    lcd.print("Transaction Failed ");
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("❌ Transaction Failed: " + response);
    blinkText("Transaction Failed ", 2, 0, 3, 300);
  } else {
    lcd.print("   System Error    ");
    lcd.setCursor(0, 3);
    lcd.print(response.substring(0, 20));
    digitalWrite(RED_LED_PIN, HIGH);
    tone(BUZZER_PIN, 200, 400);
    delay(400);
    digitalWrite(RED_LED_PIN, LOW);
    Serial.println("❌ SYSTEM ERROR: " + response);
    blinkText("   System Error    ", 2, 0, 3, 300);
  }
  delay(3000);
  noTone(BUZZER_PIN);
}

String getResponseFromNodeMCU() {
  String response = "";
  String fullResponse = ""; // To log raw data
  unsigned long startTime = millis();
  bool validResponseFound = false;

  // Clear Serial1 buffer
  while (Serial1.available()) Serial1.read();

  // Read response with 30-second timeout
  while (millis() - startTime < 30000 && !validResponseFound) {
    if (Serial1.available()) {
      response = Serial1.readStringUntil('\n');
      response.trim();
      fullResponse += response + "\n"; // Accumulate raw response

      // Clean up response: Remove unwanted tags or prefixes
      response.replace("<br />", ""); // Remove HTML line breaks
      response.replace("✅ Response: ", ""); // Remove NodeMCU debug prefix
      if (response.startsWith("<b>Fatal error</b>") || response.startsWith("Fatal error")) {
        continue; // Skip error lines
      }

      // Check if response matches expected values
      if (response == "VALID" || response == "VALID_ADMIN" || response == "INVALID" ||
          response == "BORROW_SUCCESS" || response == "RETURN_SUCCESS" ||
          response == "NO_BOOKS_AVAILABLE" || response == "BOOK_ALREADY_RETURNED" ||
          response == "BOOK_NOT_FOUND" || response == "INVALID_ACTION" ||
          response == "MISSING_PARAMETERS" || response == "USER_NOT_FOUND" ||
          response == "TRANSACTION_FAILED" || response == "UPDATE_FAILED") {
        validResponseFound = true;
      }
      delay(100); // Brief delay to catch multi-line responses
    }
  }

  // Log full raw response for debugging
  Serial.print("🔍 Full Raw Response: [");
  Serial.print(fullResponse);
  Serial.println("]");
  Serial.print("🔍 Parsed Response: [");
  Serial.print(response);
  Serial.println("]");

  if (!validResponseFound) {
    Serial.println("❌ Timeout or invalid response from NodeMCU");
    return "ERROR";
  }

  return response;
}

char getKeypadInput() {
  char key;
  while (true) {
    key = keypad.getKey();
    if (key) {
      tone(BUZZER_PIN, 1000, 50);
      Serial.print("Key Pressed: ");
      Serial.println(key);
      return key;
    }
  }
}

String getBarcode() {
  String barcode = "";
  unsigned long startTime = millis();
  const unsigned long timeout = 15000;

  digitalWrite(triggerPin, LOW); // Ensure scanner is on
  Serial.println("Scanner ON in getBarcode()");

  while (millis() - startTime < timeout) {
    if (Serial3.available()) {
      while (Serial3.available()) {
        char c = Serial3.read();
        if (c == '\n') break;
        if (isPrintable(c)) barcode += c;
        delay(2);
      }
      if (barcode.length() > 0) {
        Serial.print("Barcode: ["); Serial.print(barcode); Serial.println("]");
        return barcode;
      }
    }
  }
  Serial.println("❌ No barcode received");
  return "";
}

boolean isPrintable(char c) {
  return c >= 32 && c <= 126;
}

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
      Serial1.println("SCAN_RFID," + rfidNumber);
      Serial.println("📡 Sent RFID: " + rfidNumber);
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

  digitalWrite(triggerPin, LOW); // Turn scanner ON
  Serial.println("Scanner ON for admin barcode scan");
  String barcode = getBarcode();
  digitalWrite(triggerPin, HIGH); // Turn scanner OFF
  Serial.println("Scanner OFF after admin scan");

  if (barcode.length() > 0) {
    Serial1.println("SCAN_BARCODE," + barcode);
    Serial.println("📡 Sent Barcode: " + barcode);
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