<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>SHS Library - Barcode Scanner</title>
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f0f2f5;
            color: #333;
            min-height: 100vh;
        }
        .header {
            background: #003366;
            color: white;
            padding: 15px 20px;
            text-align: center;
        }
        .header h1 { font-size: 1.2rem; font-weight: 500; }
        .header p { font-size: 0.8rem; opacity: 0.8; margin-top: 4px; }
        .scanner-area {
            background: #000;
            margin: 10px;
            border-radius: 12px;
            overflow: hidden;
            position: relative;
            min-height: 250px;
        }
        #qr-reader { width: 100%; }
        #qr-reader video { border-radius: 12px; }
        .controls {
            padding: 10px 15px;
            display: flex;
            gap: 10px;
        }
        .btn {
            flex: 1;
            padding: 12px;
            border: none;
            border-radius: 8px;
            font-size: 0.95rem;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-start { background: #28a745; color: white; }
        .btn-start:hover { background: #218838; }
        .btn-stop { background: #dc3545; color: white; }
        .btn-stop:hover { background: #c82333; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .status-bar {
            margin: 0 15px;
            padding: 12px 15px;
            background: white;
            border-radius: 8px;
            border-left: 4px solid #003366;
            font-size: 0.9rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .status-bar.success { border-left-color: #28a745; background: #d4edda; }
        .status-bar.error { border-left-color: #dc3545; background: #f8d7da; }
        .status-bar.scanning { border-left-color: #ffc107; background: #fff3cd; }
        .last-scan {
            margin: 10px 15px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .last-scan .label { font-size: 0.75rem; color: #888; text-transform: uppercase; letter-spacing: 1px; }
        .last-scan .value { font-size: 1.8rem; font-weight: 700; color: #003366; margin-top: 5px; font-family: monospace; }
        .history {
            margin: 10px 15px 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .history-header {
            padding: 12px 15px;
            background: #f8f9fa;
            border-bottom: 1px solid #eee;
            font-weight: 500;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .history-count {
            background: #003366;
            color: white;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 0.75rem;
        }
        .queue-badge {
            margin: 10px 15px 0;
            padding: 10px 15px;
            background: #003366;
            color: white;
            border-radius: 8px;
            text-align: center;
            font-size: 0.9rem;
            font-weight: 500;
        }
        .queue-badge .count { font-size: 1.4rem; font-weight: 700; }
        .history-list { max-height: 300px; overflow-y: auto; }
        .history-item {
            padding: 10px 15px;
            border-bottom: 1px solid #f0f0f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.85rem;
        }
        .history-item:last-child { border-bottom: none; }
        .history-item .barcode { font-family: monospace; font-weight: 500; }
        .history-item .time { color: #888; font-size: 0.75rem; }
        .history-empty {
            padding: 20px;
            text-align: center;
            color: #888;
            font-style: italic;
            font-size: 0.85rem;
        }
        .clear-history {
            background: none;
            border: none;
            color: #dc3545;
            font-size: 0.8rem;
            cursor: pointer;
        }
        #qr-reader__scan_region { min-height: 200px; }
        #qr-reader__dashboard { display: none; }
        #qr-reader__status_span { display: none; }
    </style>
</head>
<body>
    <div class="header">
        <h1>SHS Library Barcode Scanner</h1>
        <p>Scan barcodes here. Each one is queued for the desktop to pick up.</p>
    </div>

    <div class="scanner-area">
        <div id="qr-reader"></div>
    </div>

    <div class="controls">
        <button class="btn btn-start" id="startBtn" onclick="startScanning()">Start Scanner</button>
        <button class="btn btn-stop" id="stopBtn" onclick="stopScanning()" disabled>Stop Scanner</button>
    </div>

    <div class="status-bar" id="statusBar">
        Press <strong>Start Scanner</strong> to begin scanning barcodes.
    </div>

    <div class="queue-badge" id="queueBadge" style="display:none;">
        Queued: <span class="count" id="queueCount">0</span> barcode(s) waiting on desktop
    </div>

    <div class="last-scan" id="lastScanBox" style="display:none;">
        <div class="label">Last Scanned Barcode</div>
        <div class="value" id="lastScanValue">-</div>
    </div>

    <div class="history">
        <div class="history-header">
            <span>Scanned Barcodes</span>
            <span>
                <span class="history-count" id="historyCount">0</span>
                <button class="clear-history" onclick="clearHistory()">Clear</button>
            </span>
        </div>
        <div class="history-list" id="historyList">
            <div class="history-empty" id="historyEmpty">No barcodes scanned yet.</div>
        </div>
    </div>

    <script>
        var html5QrCode = null;
        var isScanning = false;
        var scanHistory = [];
        var lastSentBarcode = '';
        var cooldown = false;
        var queueTotal = 0;

        function setStatus(message, type) {
            var bar = document.getElementById('statusBar');
            bar.innerHTML = message;
            bar.className = 'status-bar' + (type ? ' ' + type : '');
        }

        function startScanning() {
            if (isScanning) return;
            document.getElementById('startBtn').disabled = true;
            document.getElementById('stopBtn').disabled = false;
            setStatus('Starting camera...', 'scanning');

            html5QrCode = new Html5Qrcode('qr-reader');
            html5QrCode.start(
                { facingMode: 'environment' },
                {
                    fps: 5,
                    qrbox: { width: 250, height: 100 },
                    aspectRatio: 1.5
                },
                onScanSuccess,
                function onScanFailure(error) {}
            ).then(function() {
                isScanning = true;
                setStatus('Scanner active. Point camera at a barcode.', 'scanning');
            }).catch(function(err) {
                document.getElementById('startBtn').disabled = false;
                document.getElementById('stopBtn').disabled = true;
                setStatus('Camera error: ' + err, 'error');
            });
        }

        function stopScanning() {
            if (!isScanning || !html5QrCode) return;
            html5QrCode.stop().then(function() {
                isScanning = false;
                document.getElementById('startBtn').disabled = false;
                document.getElementById('stopBtn').disabled = true;
                setStatus('Scanner stopped. Press Start to resume.');
            }).catch(function(err) {
                console.error('Stop error:', err);
            });
        }

        function onScanSuccess(decodedText) {
            if (cooldown) return;
            if (decodedText === lastSentBarcode) return;

            cooldown = true;
            lastSentBarcode = decodedText;

            document.getElementById('lastScanBox').style.display = 'block';
            document.getElementById('lastScanValue').textContent = decodedText;
            setStatus('Sending barcode <strong>' + decodedText + '</strong>...', 'scanning');

            var formData = new FormData();
            formData.append('barcode', decodedText);

            fetch('../api/submit_barcode.php', {
                method: 'POST',
                body: formData
            })
            .then(function(response) { return response.json(); })
            .then(function(data) {
                if (data.status === 'ok') {
                    queueTotal++;
                    updateQueueBadge();
                    setStatus('Queued! (' + queueTotal + ' total). Scan the next barcode.', 'success');
                    addToHistory(decodedText, true);
                } else {
                    setStatus('Error: ' + (data.message || 'Unknown error'), 'error');
                    addToHistory(decodedText, false);
                }
                setTimeout(function() { cooldown = false; }, 3000);
            })
            .catch(function(err) {
                setStatus('Network error: ' + err.message, 'error');
                addToHistory(decodedText, false);
                setTimeout(function() { cooldown = false; }, 3000);
            });
        }

        function updateQueueBadge() {
            var badge = document.getElementById('queueBadge');
            var countEl = document.getElementById('queueCount');
            if (badge && countEl) {
                countEl.textContent = queueTotal;
                badge.style.display = queueTotal > 0 ? 'block' : 'none';
            }
        }

        function addToHistory(barcode, success) {
            var now = new Date();
            var timeStr = now.toLocaleTimeString();
            scanHistory.unshift({ barcode: barcode, time: timeStr, success: success });
            renderHistory();
        }

        function renderHistory() {
            var list = document.getElementById('historyList');
            var empty = document.getElementById('historyEmpty');
            var count = document.getElementById('historyCount');
            count.textContent = scanHistory.length;

            if (scanHistory.length === 0) {
                list.innerHTML = '';
                list.appendChild(empty);
                empty.style.display = 'block';
                return;
            }

            var html = '';
            scanHistory.forEach(function(item) {
                var icon = item.success ? '\u2705' : '\u274C';
                html += '<div class="history-item">' +
                    '<span class="barcode">' + icon + ' ' + item.barcode + '</span>' +
                    '<span class="time">' + item.time + '</span>' +
                    '</div>';
            });
            list.innerHTML = html;
        }

        function clearHistory() {
            scanHistory = [];
            queueTotal = 0;
            updateQueueBadge();
            renderHistory();
        }
    </script>
</body>
</html>
