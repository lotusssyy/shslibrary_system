/*
ESP32 example: POST JSON to api/rfid.php to BORROW a book
Usage: replace WIFI_SSID, WIFI_PASSWORD and SERVER_HOST
*/

#include <WiFi.h>
#include <HTTPClient.h>

const char* ssid = "WIFI_SSID";
const char* password = "WIFI_PASSWORD";
const char* server = "http://192.168.1.100"; // replace with PC running XAMPP (LAN IP)

void setup() {
  Serial.begin(115200);
  WiFi.begin(ssid, password);
  Serial.print("Connecting to WiFi");
  while (WiFi.status() != WL_CONNECTED) {
    delay(500);
    Serial.print('.');
  }
  Serial.println("\nConnected");

  // Example: borrow a book by RFID and barcode
  String rfid = "RFID123456"; // set to a test RFID in DB
  String barcode = "978-0143126560"; // example barcode
  String action = "BORROW"; // or RETURN

  if (postRfidAction(rfid, action, barcode)) {
    Serial.println("API call successful");
  } else {
    Serial.println("API call failed");
  }
}

void loop() {
  // nothing
}

bool postRfidAction(const String &rfid, const String &action, const String &barcode) {
  if (WiFi.status() != WL_CONNECTED) return false;
  HTTPClient http;
  String url = String(server) + "/api/rfid.php";
  http.begin(url);
  http.addHeader("Content-Type", "application/json");
  String payload = "{\"rfid_number\":\"" + rfid + "\",\"action\":\"" + action + "\",\"barcode\":\"" + barcode + "\"}";
  int httpCode = http.POST(payload);
  if (httpCode > 0) {
    String resp = http.getString();
    Serial.printf("HTTP %d\n", httpCode);
    Serial.println(resp);
    http.end();
    return (httpCode >= 200 && httpCode < 300);
  } else {
    Serial.printf("HTTP failed: %s\n", http.errorToString(httpCode).c_str());
    http.end();
    return false;
  }
}
