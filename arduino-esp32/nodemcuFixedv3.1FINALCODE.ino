#include <ESP8266WiFi.h>
#include <ESP8266HTTPClient.h>

const char* ssid = "library_system";       // Replace with your Wi-Fi SSID
const char* password = "3i2025G7";        // Replace with your Wi-Fi password
const String host = "shs-lib-uclm-fb2fda0817ed.herokuapp.com";
const int port = 80;                      // HTTP port

void setup() {
  Serial.begin(115200);
  delay(2000);

  WiFi.begin(ssid, password);
  Serial.print("Connecting to WiFi");

  int attempts = 0;
  while (WiFi.status() != WL_CONNECTED && attempts++ < 30) {
    delay(500);
    Serial.print(".");
  }

  if (WiFi.status() == WL_CONNECTED) {
    Serial.println("\nConnected: " + WiFi.localIP().toString());
    Serial.print("RSSI: ");
    Serial.println(WiFi.RSSI()); // Signal strength in dBm
  } else {
    Serial.println("\nWiFi Failed");
    while (true) delay(1000);
  }
}

void loop() {
  if (Serial.available()) {
    String command = Serial.readStringUntil('\n');
    command.trim();
    Serial.print("🔄 Received from Arduino: ");
    Serial.println(command);
    handleCommand(command);
    delay(2000); // Ensure network stability
  }
}

void handleCommand(String command) {
  if (command.startsWith("CHECK_RFID,")) {
    handleRFIDCheck(command.substring(11));
  } else if (command.startsWith("TRANSACTION,")) {
    handleTransaction(command.substring(12));
  } else if (command.startsWith("SCAN_RFID,")) {
    handleRFIDScan(command.substring(10));
  } else if (command.startsWith("SCAN_BARCODE,")) {
    handleBarcodeScan(command.substring(13));
  } else {
    Serial.println("ERROR: Unknown command");
    Serial.println("ERROR");
  }
}

void handleRFIDCheck(String rfid) {
  WiFiClient* client = new WiFiClient();
  if (!client) {
    Serial.println("❌ Failed to allocate WiFiClient");
    Serial.println("ERROR");
    return;
  }
  HTTPClient http;

  String path = "/check_user.php?rfid_number=" + urlEncode(rfid);
  Serial.print("📡 Sending GET request to: http://");
  Serial.print(host);
  Serial.println(path);

  int retries = 3;
  String response = "";
  int httpCode = -1;
  while (retries > 0 && httpCode <= 0) {
    if (client->connect(host, port)) {
      if (http.begin(*client, "http://" + host + path)) {
        http.setTimeout(30000); // 30-second timeout
        httpCode = http.GET();
        if (httpCode > 0) {
          if (httpCode == HTTP_CODE_OK) {
            response = http.getString();
            response.trim();
            Serial.print("✅ Response: ");
            Serial.println(response);
            Serial.println(response); // Send to Arduino
          } else {
            Serial.print("❌ HTTP Error Code: ");
            Serial.println(httpCode);
            Serial.println("HTTP_ERROR:" + String(httpCode));
          }
        } else {
          Serial.print("❌ Connection Failed: ");
          Serial.println(http.errorToString(httpCode));
          Serial.println("NETWORK_ERROR:" + http.errorToString(httpCode));
        }
        http.end();
      } else {
        Serial.println("❌ Failed to initialize HTTP connection");
        Serial.println("ERROR");
      }
      client->stop();
    } else {
      Serial.println("❌ Failed to connect to server");
      retries--;
      delay(2000); // Wait 2s before retry
      if (retries == 0) {
        Serial.println("❌ All retries failed");
        Serial.println("NETWORK_ERROR:Connection Failed");
      }
    }
  }
  delete client;
}

void handleTransaction(String data) {
  WiFiClient* client = new WiFiClient();
  if (!client) {
    Serial.println("❌ Failed to allocate WiFiClient");
    Serial.println("ERROR");
    return;
  }
  HTTPClient http;

  int firstComma = data.indexOf(',');
  int secondComma = data.indexOf(',', firstComma + 1);
  String rfid = data.substring(0, firstComma);
  String action = data.substring(firstComma + 1, secondComma);
  String barcode = data.substring(secondComma + 1);

  String path = "/process_rfid.php";
  String postData = "rfid_number=" + urlEncode(rfid) + "&action=" + urlEncode(action) + "&barcode=" + urlEncode(barcode);
  Serial.print("📡 Sending POST request to: http://");
  Serial.print(host);
  Serial.println(path);
  Serial.print("Data: ");
  Serial.println(postData);

  int retries = 3;
  String response = "";
  int httpCode = -1;
  while (retries > 0 && httpCode <= 0) {
    if (client->connect(host, port)) {
      if (http.begin(*client, "http://" + host + path)) {
        http.addHeader("Content-Type", "application/x-www-form-urlencoded");
        http.setTimeout(30000); // 30-second timeout
        httpCode = http.POST(postData);
        if (httpCode > 0) {
          if (httpCode == HTTP_CODE_OK) {
            response = http.getString();
            response.trim();
            Serial.print("✅ Response: ");
            Serial.println(response);
            Serial.println(response); // Send to Arduino
          } else {
            Serial.print("❌ HTTP Error Code: ");
            Serial.println(httpCode);
            Serial.println("HTTP_ERROR:" + String(httpCode));
          }
        } else {
          Serial.print("❌ Connection Failed: ");
          Serial.println(http.errorToString(httpCode));
          Serial.println("NETWORK_ERROR:" + http.errorToString(httpCode));
        }
        http.end();
      } else {
        Serial.println("❌ Failed to initialize HTTP connection");
        Serial.println("ERROR");
      }
      client->stop();
    } else {
      Serial.println("❌ Failed to connect to server");
      retries--;
      delay(2000); // Wait 2s before retry
      if (retries == 0) {
        Serial.println("❌ All retries failed");
        Serial.println("NETWORK_ERROR:Connection Failed");
      }
    }
  }
  delete client;
}

void handleRFIDScan(String rfid) {
  WiFiClient* client = new WiFiClient();
  if (!client) {
    Serial.println("❌ Failed to allocate WiFiClient");
    Serial.println("ERROR");
    return;
  }
  HTTPClient http;

  String path = "/scan.php";
  String postData = "rfid_number=" + urlEncode(rfid);
  Serial.print("📡 Sending POST request to: http://");
  Serial.print(host);
  Serial.println(path);
  Serial.print("Data: ");
  Serial.println(postData);

  int retries = 3;
  String response = "";
  int httpCode = -1;
  while (retries > 0 && httpCode <= 0) {
    if (client->connect(host, port)) {
      if (http.begin(*client, "http://" + host + path)) {
        http.addHeader("Content-Type", "application/x-www-form-urlencoded");
        http.setTimeout(30000); // 30-second timeout
        httpCode = http.POST(postData);
        if (httpCode > 0) {
          if (httpCode == HTTP_CODE_OK) {
            response = http.getString();
            response.trim();
            Serial.print("✅ Response: ");
            Serial.println(response);
            Serial.println(response); // Send to Arduino
          } else {
            Serial.print("❌ HTTP Error Code: ");
            Serial.println(httpCode);
            Serial.println("HTTP_ERROR:" + String(httpCode));
          }
        } else {
          Serial.print("❌ Connection Failed: ");
          Serial.println(http.errorToString(httpCode));
          Serial.println("NETWORK_ERROR:" + http.errorToString(httpCode));
        }
        http.end();
      } else {
        Serial.println("❌ Failed to initialize HTTP connection");
        Serial.println("ERROR");
      }
      client->stop();
    } else {
      Serial.println("❌ Failed to connect to server");
      retries--;
      delay(2000); // Wait 2s before retry
      if (retries == 0) {
        Serial.println("❌ All retries failed");
        Serial.println("NETWORK_ERROR:Connection Failed");
      }
    }
  }
  delete client;
}

void handleBarcodeScan(String barcode) {
  WiFiClient* client = new WiFiClient();
  if (!client) {
    Serial.println("❌ Failed to allocate WiFiClient");
    Serial.println("ERROR");
    return;
  }
  HTTPClient http;

  String path = "/scan.php";
  String postData = "barcode=" + urlEncode(barcode);
  Serial.print("📡 Sending POST request to: http://");
  Serial.print(host);
  Serial.println(path);
  Serial.print("Data: ");
  Serial.println(postData);

  int retries = 3;
  String response = "";
  int httpCode = -1;
  while (retries > 0 && httpCode <= 0) {
    if (client->connect(host, port)) {
      if (http.begin(*client, "http://" + host + path)) {
        http.addHeader("Content-Type", "application/x-www-form-urlencoded");
        http.setTimeout(30000); // 30-second timeout
        httpCode = http.POST(postData);
        if (httpCode > 0) {
          if (httpCode == HTTP_CODE_OK) {
            response = http.getString();
            response.trim();
            Serial.print("✅ Response: ");
            Serial.println(response);
            Serial.println(response); // Send to Arduino
          } else {
            Serial.print("❌ HTTP Error Code: ");
            Serial.println(httpCode);
            Serial.println("HTTP_ERROR:" + String(httpCode));
          }
        } else {
          Serial.print("❌ Connection Failed: ");
          Serial.println(http.errorToString(httpCode));
          Serial.println("NETWORK_ERROR:" + http.errorToString(httpCode));
        }
        http.end();
      } else {
        Serial.println("❌ Failed to initialize HTTP connection");
        Serial.println("ERROR");
      }
      client->stop();
    } else {
      Serial.println("❌ Failed to connect to server");
      retries--;
      delay(2000); // Wait 2s before retry
      if (retries == 0) {
        Serial.println("❌ All retries failed");
        Serial.println("NETWORK_ERROR:Connection Failed");
      }
    }
  }
  delete client;
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