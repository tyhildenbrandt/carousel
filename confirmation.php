<?php
require_once 'config.php';

if (!isset($_SESSION['confirmation'])) {
    header('Location: index.php');
    exit;
}

$nickname = $_SESSION['confirmation']['nickname'];
$entryId = $_SESSION['confirmation']['entry_id'];

// Build the full shareable URL
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
// rtrim ensures we don't get double slashes if the user is in the root directory
$path = rtrim(dirname($_SERVER['PHP_SELF']), '/\\');
$shareUrl = "{$protocol}://{$host}{$path}/view_picks.php?id={$entryId}";


unset($_SESSION['confirmation']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submission Confirmed - Coaching Carousel Game</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            max-width: 600px;
            background: white;
            padding: 50px 40px;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            text-align: center;
        }
        .success-icon {
            font-size: 80px;
            margin-bottom: 20px;
        }
        h1 {
            color: #333;
            margin-bottom: 15px;
            font-size: 32px;
        }
        .message {
            color: #666;
            font-size: 18px;
            line-height: 1.6;
            margin-bottom: 30px;
        }
        
        /* New Share Box */
        .share-box {
            background: #f8f9fa;
            border: 2px solid #ddd;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: left;
        }
        .share-box h3 {
            color: #333;
            margin-bottom: 15px;
            text-align: center;
        }
        .share-url {
            width: 100%;
            padding: 10px;
            font-size: 14px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            margin-bottom: 10px;
        }
        .copy-btn {
            width: 100%;
            padding: 10px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: 600;
            font-size: 16px;
        }
        .copy-btn:hover {
            background: #218838;
        }
        /* End New Share Box */

        .info-box {
            background: #e8f4f8;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            text-align: left;
        }
        .info-box h3 {
            color: #333;
            margin-bottom: 10px;
        }
        .info-box ul {
            margin-left: 20px;
            color: #555;
        }
        .info-box li {
            margin: 8px 0;
        }
        .btn {
            display: inline-block;
            background: #667eea;
            color: white;
            padding: 15px 40px;
            border-radius: 6px;
            text-decoration: none;
            font-size: 18px;
            font-weight: 600;
            transition: background 0.3s;
        }
        .btn:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="success-icon">✅</div>
        <h1>Predictions Submitted!</h1>
        <p class="message">
            Thanks, <strong><?= htmlspecialchars($nickname) ?></strong>! Your predictions have been recorded.
        </p>
        
        <!-- New Share Box -->
        <div class="share-box">
            <h3>Share Your Picks!</h3>
            <p style="font-size: 14px; color: #555; margin-bottom: 10px;">
                Copy this link to share your predictions with friends:
            </p>
            <input type="text" id="shareUrl" class="share-url" value="<?= htmlspecialchars($shareUrl) ?>" readonly>
            <button id="copyBtn" class="copy-btn">Copy Link</button>
        </div>

        <div class="info-box">
            <h3>What Happens Next?</h3>
            <ul>
                <li>Your predictions are locked in and cannot be changed</li>
                <li>As coaching moves happen, scores will be calculated automatically</li>
                <li>Check the leaderboard to see how you stack up</li>
            </ul>
        </div>
        
        <a href="leaderboard.php" class="btn">View Leaderboard →</a>
    </div>

    <script>
        document.getElementById('copyBtn').addEventListener('click', function() {
            const urlInput = document.getElementById('shareUrl');
            urlInput.select();
            urlInput.setSelectionRange(0, 99999); // For mobile
            
            // Use execCommand as a fallback for iFrame compatibility
            try {
                document.execCommand('copy');
                this.textContent = 'Copied!';
                this.style.background = '#218838';
            } catch (err) {
                this.textContent = 'Copy Failed';
            }
            
            setTimeout(() => {
                this.textContent = 'Copy Link';
                this.style.background = '#28a745';
            }, 2000);
        });
    </script>
</body>
</html>
