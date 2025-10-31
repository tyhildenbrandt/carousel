<?php
require_once 'config.php';

$token = $_GET['token'] ?? '';
$error = '';

if (empty($token)) {
    $error = 'No token provided.';
} else {
    try {
        $db = getDB();
        
        // Find a matching, unexpired token
        $stmt = $db->prepare("
            SELECT entry_id, expires_at 
            FROM auth_tokens 
            WHERE token = ? AND expires_at > NOW()
        ");
        $stmt->execute([$token]);
        $auth_token = $stmt->fetch();
        
        if ($auth_token) {
            // --- SUCCESS ---
            $entry_id = $auth_token['entry_id'];

            // 1. Delete the token so it can't be used again
            $db->prepare("DELETE FROM auth_tokens WHERE token = ?")->execute([$token]);
            
            // 2. Load the user's entry data
            $entry_stmt = $db->prepare("SELECT email, nickname FROM entries WHERE id = ?");
            $entry_stmt->execute([$entry_id]);
            $entry = $entry_stmt->fetch();
            
            // 3. Load the user's wildcard picks
            $wc_stmt = $db->prepare("SELECT school FROM wildcard_picks WHERE entry_id = ?");
            $wc_stmt->execute([$entry_id]);
            $wildcards = $wc_stmt->fetchAll(PDO::FETCH_COLUMN);
            
            // 4. Load all data into the session
            $_SESSION['auth_entry_id'] = $entry_id; // Authorizes the overwrite
            $_SESSION['edit_entry_id'] = $entry_id; // Tells step2 to load old coach picks
            $_SESSION['email'] = $entry['email'];
            $_SESSION['nickname'] = $entry['nickname'];
            $_SESSION['wildcards'] = $wildcards;
            $_SESSION['load_success'] = 'Your picks have been loaded. Make your changes and re-submit!';
            
            // 5. Redirect to the main form page
            header('Location: create_entry.php');
            exit;

        } else {
            // --- FAILURE ---
            $error = 'This link is invalid or has expired. Please request a new one.';
            // Clean up any other expired tokens
            $db->query("DELETE FROM auth_tokens WHERE expires_at <= NOW()");
        }
    } catch (Exception $e) {
        // This will catch "table not found" or other fatal DB errors
        $error = "A fatal error occurred. Please contact support. " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Link Error</title>
    <style>
        body { font-family: Arial, sans-serif; display: flex; align-items: center; justify-content: center; min-height: 100vh; background: #f5f5f5; }
        .container { background: white; padding: 40px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); text-align: center; }
        .error { color: #c33; font-size: 18px; }
        .btn { display: inline-block; margin-top: 20px; background: #667eea; color: white; padding: 12px 20px; text-decoration: none; border-radius: 5px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="container">
        <div class="error"><?= htmlspecialchars($error) ?></div>
        <a href="index.php" class="btn">Back to Game</a>
    </div>
</body>
</html>
