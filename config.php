<?php
// Start session *immediately*
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Database Configuration
// Fill in your SiteGround database credentials

define('DB_HOST', 'localhost'); // Usually 'localhost' on SiteGround
define('DB_NAME', 'mydbname');
define('DB_USER', 'myusername');
define('DB_PASS', 'mypassword');

// Application Settings
define('GAME_ACTIVE', true); // Set to false to close submissions

// Existing openings (these 8 schools)
$EXISTING_OPENINGS = [
    'LSU',
    'Florida', 
    'Penn State',
    'Virginia Tech',
    'Arkansas',
    'Oklahoma State',
    'UCLA',
    'Stanford'
];

// All P4 schools (excluding the 8 current openings: LSU, Florida, Penn State, Virginia Tech, Arkansas, Oklahoma State, UCLA, Stanford)
$ALL_P4_SCHOOLS = [
    // ACC (minus Virginia Tech, Stanford)
    'Boston College', 'California', 'Clemson', 'Duke', 'Florida State',
    'Georgia Tech', 'Louisville', 'Miami', 'NC State', 'North Carolina',
    'Pittsburgh', 'SMU', 'Syracuse', 'Virginia', 'Wake Forest',
    // SEC (minus LSU, Florida, Arkansas)
    'Alabama', 'Auburn', 'Georgia', 'Kentucky', 'Mississippi State',
    'Missouri', 'Oklahoma', 'Ole Miss', 'South Carolina', 'Tennessee',
    'Texas', 'Texas A&M', 'Vanderbilt',
    // Big Ten (minus Penn State, UCLA)
    'Illinois', 'Indiana', 'Iowa', 'Maryland', 'Michigan',
    'Michigan State', 'Minnesota', 'Nebraska', 'Northwestern', 'Ohio State',
    'Oregon', 'Purdue', 'Rutgers', 'USC', 'Washington', 'Wisconsin',
    // Big 12 (minus Oklahoma State)
    'Arizona', 'Arizona State', 'Baylor', 'BYU', 'Cincinnati',
    'Colorado', 'Houston', 'Iowa State', 'Kansas', 'Kansas State',
    'TCU', 'Texas Tech', 'UCF', 'Utah', 'West Virginia',
    // Independent
    'Notre Dame'
];

// Sort alphabetically
sort($ALL_P4_SCHOOLS);

// Logo settings
define('LOGO_DIR', 'logos/'); // Directory where logo PNG files are stored

// Function to get logo path for a school
function getSchoolLogo($schoolName) {
    // Convert school name to filename format
    // Examples: "Penn State" -> "Penn_State-light.png"
    //           "Texas A&M" -> "Texas_AM-light.png"
    
    $filename = str_replace(' ', '_', $schoolName); // Spaces to underscores
    $filename = str_replace('&', '', $filename);     // Remove ampersands
    $filename = $filename . '-light.png';            // Add suffix
    
    $logoPath = LOGO_DIR . $filename;
    
    // Check if file exists, return path or empty string
    if (file_exists($logoPath)) {
        return $logoPath;
    }
    
    return ''; // Return empty if logo doesn't exist
}

// Function to display logo img tag
function displayLogo($schoolName, $size = 40, $classes = '') {
    $logoPath = getSchoolLogo($schoolName);
    
    if (!empty($logoPath)) {
        $alt = htmlspecialchars($schoolName) . ' Logo';
        return '<img src="' . htmlspecialchars($logoPath) . '" 
                     alt="' . $alt . '" 
                     class="school-logo ' . htmlspecialchars($classes) . '" 
                     style="width: ' . (int)$size . 'px; height: ' . (int)$size . 'px; object-fit: contain;">';
    }
    
    return ''; // Return empty if no logo
}

// Database connection function
function getDB() {
    try {
        $pdo = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch(PDOException $e) {
        die("Database connection failed: " . $e->getMessage());
    }
}
?>
