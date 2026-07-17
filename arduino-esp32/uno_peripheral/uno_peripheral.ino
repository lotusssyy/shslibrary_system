/*
 * Arduino Uno - Peripheral Handler for SHS Library System
 * Handles: Barcode Scanner (SoftwareSerial) + Keypad 4x3
 * Communicates with ESP32 via hardware Serial (9600 baud)
 *
 * Serial Protocol (ESP32 <-> Uno):
 *   ESP32 -> Uno: "CMD:READY"         -> Uno responds: "RESP:READY"
 *   ESP32 -> Uno: "CMD:READ_BARCODE"  -> Uno responds: "RESP:BARCODE:<data>" or "RESP:BARCODE:TIMEOUT"
 *   ESP32 -> Uno: "CMD:READ_KEYPAD"   -> Uno responds: "RESP:KEY:<char>"
 *
 * Wiring:
 *   Barcode Scanner RX -> Pin 2 (SoftwareSerial RX)
 *   Barcode Scanner TX -> Pin 3 (SoftwareSerial TX, not connected)
 *   Barcode Scanner Trigger -> Pin 12 (active-low, LOW=ON)
 *   Keypad Rows -> Pins 4, 5, 6, 7 (input with pull-up)
 *   Keypad Cols -> Pins 8, 9, 10 (output, driven LOW during scan)
 *   Uno TX (pin 1) -> ESP32 GPIO 16 (RX)
 *   Uno RX (pin 0) -> ESP32 GPIO 17 (TX)
 *   Common GND required
 *
 * Requires: Keypad library (install via Library Manager)
 */

#include <Keypad.h>
#include <SoftwareSerial.h>

// ==================== PIN DEFINITIONS ====================
// Barcode Scanner (SoftwareSerial)
#define BARCODE_RX      2   // Scanner TX -> Uno RX (SoftwareSerial)
#define BARCODE_TX      3   // Scanner RX (not connected, but required by library)
#define BARCODE_TRIGGER 12  // Active-low trigger (LOW = scanner ON)
#define BARCODE_BAUD    9600

// Keypad 4x3
#define KEYPAD_ROWS 4
#define KEYPAD_COLS 3

// Built-in LED (status indicator)
#define STATUS_LED 13

// ==================== GLOBAL OBJECTS ====================
SoftwareSerial barcodeSerial(BARCODE_RX, BARCODE_TX);

const byte rowPins[KEYPAD_ROWS] = {4, 5, 6, 7};
const byte colPins[KEYPAD_COLS] = {8, 9, 10};
char keys[KEYPAD_ROWS][KEYPAD_COLS] = {
  {'1', '2', '3'},
  {'4', '5', '6'},
  {'7', '8', '9'},
  {'*', '0', '#'}
};
Keypad keypad = Keypad(makeKeymap(keys), rowPins, colPins, KEYPAD_ROWS, KEYPAD_COLS);

// ==================== SETUP ====================
void setup() {
  Serial.begin(9600);           // Communication with ESP32
  barcodeSerial.begin(BARCODE_BAUD); // Barcode scanner

  pinMode(BARCODE_TRIGGER, OUTPUT);
  digitalWrite(BARCODE_TRIGGER, HIGH); // Scanner OFF initially
  pinMode(STATUS_LED, OUTPUT);
  digitalWrite(STATUS_LED, LOW);
}

// ==================== MAIN LOOP ====================
void loop() {
  if (Serial.available()) {
    String cmd = Serial.readStringUntil('\n');
    cmd.trim();

    if (cmd == "CMD:READY") {
      Serial.println("RESP:READY");

    } else if (cmd == "CMD:READ_BARCODE") {
      String barcode = readBarcode();
      Serial.println("RESP:BARCODE:" + barcode);

    } else if (cmd == "CMD:READ_KEYPAD") {
      char key = readKeypad();
      Serial.println("RESP:KEY:" + String(key));
    }
  }
}

// ==================== BARCODE SCANNER ====================
String readBarcode() {
  String barcode = "";
  unsigned long startTime = millis();
  const unsigned long timeout = 15000;

  digitalWrite(BARCODE_TRIGGER, LOW);  // Scanner ON
  digitalWrite(STATUS_LED, HIGH);

  while (millis() - startTime < timeout) {
    if (barcodeSerial.available()) {
      while (barcodeSerial.available()) {
        char c = barcodeSerial.read();
        if (c == '\n') break;
        if (c >= 32 && c <= 126) barcode += c;
        delay(2);
      }
      if (barcode.length() > 0) {
        break;
      }
    }
  }

  digitalWrite(BARCODE_TRIGGER, HIGH); // Scanner OFF
  digitalWrite(STATUS_LED, LOW);

  return barcode.length() > 0 ? barcode : "TIMEOUT";
}

// ==================== KEYPAD ====================
char readKeypad() {
  while (true) {
    char key = keypad.getKey();
    if (key) {
      return key;
    }
    // Any serial command received means ESP32 moved on — abort keypad wait
    if (Serial.available()) {
      String cmd = Serial.readStringUntil('\n');
      cmd.trim();
      if (cmd.length() > 0) {
        return '*';
      }
    }
  }
}
