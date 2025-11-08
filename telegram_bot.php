<?php
/**
 * Professional Telegram Info Bot - Railway Optimized
 * Single File PHP Implementation
 */

// Error settings for production
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

// Get Railway environment variables
$bot_token = getenv('BOT_TOKEN');
$admin_id = getenv('ADMIN_ID');
$channel_id = getenv('CHANNEL_ID');
$channel_link = getenv('CHANNEL_LINK');

// Configuration with fallbacks
define('BOT_TOKEN', $bot_token ?: 'YOUR_BOT_TOKEN_HERE');
define('ADMIN_ID', $admin_id ? (int)$admin_id : 123456789);
define('CHANNEL_ID', $channel_id ?: '@your_channel');
define('CHANNEL_LINK', $channel_link ?: 'https://t.me/your_channel');
define('DB_FILE', 'users.db');

// Validate configuration
if (BOT_TOKEN === 'YOUR_BOT_TOKEN_HERE' || ADMIN_ID === 0) {
    http_response_code(500);
    header('Content-Type: application/json');
    die(json_encode(['error' => 'Please configure BOT_TOKEN and ADMIN_ID in Railway environment variables']));
}

// API Configuration
$API_CONFIG = [
    'phone' => [
        'url' => 'https://demon.taitanx.workers.dev/?mobile={query}',
        'name' => '📱 Number Info',
        'command' => '/phone',
        'example' => '9876543210',
        'pattern' => '/^[6-9]\d{9}$/',
        'credits' => 1
    ],
    'aadhaar' => [
        'url' => 'https://family-members-n5um.vercel.app/fetch?aadhaar={query}&key=paidchx',
        'name' => '🆔 Aadhaar Lookup',
        'command' => '/aadhaar',
        'example' => '123456789012',
        'pattern' => '/^\d{12}$/',
        'credits' => 1
    ],
    'vehicle' => [
        'url' => 'https://vehicleinfo-v2.zerovault.workers.dev/?vehicle_number={query}',
        'name' => '🚗 Vehicle Lookup',
        'command' => '/vehicle',
        'example' => 'KA04EQ4521',
        'pattern' => '/^[A-Z]{2}\d{1,2}[A-Z]{1,2}\d{1,4}$/i',
        'credits' => 1
    ],
    'ifsc' => [
        'url' => 'https://ifsc.razorpay.com/{query}',
        'name' => '🏦 IFSC Lookup',
        'command' => '/ifsc',
        'example' => 'SBIN0000001',
        'pattern' => '/^[A-Z]{4}0[A-Z0-9]{6}$/i',
        'credits' => 1
    ],
    'ip' => [
        'url' => 'https://ip-info.bjcoderx.workers.dev/?ip={query}',
        'name' => '🌐 IP Lookup',
        'command' => '/ip',
        'example' => '149.154.167.91',
        'pattern' => '/^(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)$/',
        'credits' => 1
    ],
    'pincode' => [
        'url' => 'https://api.postalpincode.in/pincode/{query}',
        'name' => '📮 Pincode Lookup',
        'command' => '/pincode',
        'example' => '110006',
        'pattern' => '/^\d{6}$/',
        'credits' => 1
    ]
];

// Database Class
class Database {
    private $db;
    
    public function __construct() {
        try {
            $this->db = new SQLite3(DB_FILE);
            $this->db->busyTimeout(5000);
            $this->createTables();
        } catch (Exception $e) {
            error_log("Database Error: " . $e->getMessage());
        }
    }
    
    private function createTables() {
        $queries = [
            'CREATE TABLE IF NOT EXISTS users (
                user_id INTEGER PRIMARY KEY,
                username TEXT,
                first_name TEXT,
                last_name TEXT,
                credits INTEGER DEFAULT 20,
                total_searches INTEGER DEFAULT 0,
                is_banned INTEGER DEFAULT 0,
                ban_reason TEXT DEFAULT "",
                banned_by INTEGER DEFAULT NULL,
                ban_date TIMESTAMP DEFAULT NULL,
                joined_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                last_active TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            
            'CREATE TABLE IF NOT EXISTS search_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                service_type TEXT,
                query TEXT,
                timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )',
            
            'CREATE TABLE IF NOT EXISTS protected_numbers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone_number TEXT UNIQUE,
                protected_by INTEGER,
                protected_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                reason TEXT DEFAULT ""
            )'
        ];
        
        foreach ($queries as $query) {
            $this->db->exec($query);
        }
    }
    
    public function getUser($user_id) {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE user_id = :user_id');
        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        return $result->fetchArray(SQLITE3_ASSOC);
    }
    
    public function createUser($user_id, $username, $first_name, $last_name = '') {
        $user = $this->getUser($user_id);
        if (!$user) {
            $stmt = $this->db->prepare('INSERT INTO users (user_id, username, first_name, last_name) VALUES (:user_id, :username, :first_name, :last_name)');
            $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
            $stmt->bindValue(':username', $username, SQLITE3_TEXT);
            $stmt->bindValue(':first_name', $first_name, SQLITE3_TEXT);
            $stmt->bindValue(':last_name', $last_name, SQLITE3_TEXT);
            return $stmt->execute();
        }
        return true;
    }
    
    public function updateUserActivity($user_id) {
        $stmt = $this->db->prepare('UPDATE users SET last_active = CURRENT_TIMESTAMP WHERE user_id = :user_id');
        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }
    
    public function getCredits($user_id) {
        $user = $this->getUser($user_id);
        return $user ? $user['credits'] : 0;
    }
    
    public function isUserBanned($user_id) {
        $user = $this->getUser($user_id);
        return $user ? (bool)$user['is_banned'] : false;
    }
    
    public function banUser($user_id, $admin_id, $reason = 'No reason provided') {
        $stmt = $this->db->prepare('UPDATE users SET is_banned = 1, ban_reason = :reason, banned_by = :admin_id, ban_date = CURRENT_TIMESTAMP WHERE user_id = :user_id');
        $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
        $stmt->bindValue(':admin_id', $admin_id, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }
    
    public function unbanUser($user_id) {
        $stmt = $this->db->prepare('UPDATE users SET is_banned = 0, ban_reason = "", banned_by = NULL, ban_date = NULL WHERE user_id = :user_id');
        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }
    
    public function getBannedUsers() {
        $result = $this->db->query('SELECT * FROM users WHERE is_banned = 1 ORDER BY ban_date DESC');
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }
        return $users;
    }
    
    public function isNumberProtected($phone_number) {
        $stmt = $this->db->prepare('SELECT id FROM protected_numbers WHERE phone_number = :phone_number');
        $stmt->bindValue(':phone_number', $phone_number, SQLITE3_TEXT);
        $result = $stmt->execute();
        return $result->fetchArray() !== false;
    }
    
    public function protectNumber($phone_number, $admin_id, $reason = 'Admin protection') {
        $stmt = $this->db->prepare('INSERT OR IGNORE INTO protected_numbers (phone_number, protected_by, reason) VALUES (:phone_number, :admin_id, :reason)');
        $stmt->bindValue(':phone_number', $phone_number, SQLITE3_TEXT);
        $stmt->bindValue(':admin_id', $admin_id, SQLITE3_INTEGER);
        $stmt->bindValue(':reason', $reason, SQLITE3_TEXT);
        return $stmt->execute();
    }
    
    public function unprotectNumber($phone_number) {
        $stmt = $this->db->prepare('DELETE FROM protected_numbers WHERE phone_number = :phone_number');
        $stmt->bindValue(':phone_number', $phone_number, SQLITE3_TEXT);
        return $stmt->execute();
    }
    
    public function getProtectedNumbers() {
        $result = $this->db->query('SELECT * FROM protected_numbers ORDER BY protected_date DESC');
        $numbers = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $numbers[] = $row;
        }
        return $numbers;
    }
    
    public function getProtectedNumbersCount() {
        return $this->db->querySingle('SELECT COUNT(*) FROM protected_numbers');
    }
    
    public function deductCredits($user_id, $amount) {
        $stmt = $this->db->prepare('UPDATE users SET credits = credits - :amount WHERE user_id = :user_id AND credits >= :amount');
        $stmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        $result = $stmt->execute();
        return $this->db->changes() > 0;
    }
    
    public function addCredits($user_id, $amount) {
        $stmt = $this->db->prepare('UPDATE users SET credits = credits + :amount WHERE user_id = :user_id');
        $stmt->bindValue(':amount', $amount, SQLITE3_INTEGER);
        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        return $stmt->execute();
    }
    
    public function addSearchHistory($user_id, $service_type, $query) {
        $stmt = $this->db->prepare('INSERT INTO search_history (user_id, service_type, query) VALUES (:user_id, :service_type, :query)');
        $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        $stmt->bindValue(':service_type', $service_type, SQLITE3_TEXT);
        $stmt->bindValue(':query', $query, SQLITE3_TEXT);
        $stmt->execute();
        
        $stmt2 = $this->db->prepare('UPDATE users SET total_searches = total_searches + 1 WHERE user_id = :user_id');
        $stmt2->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
        return $stmt2->execute();
    }
    
    public function getAllUsers() {
        $result = $this->db->query('SELECT * FROM users ORDER BY joined_date DESC');
        $users = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $users[] = $row;
        }
        return $users;
    }
    
    public function getSearchStats() {
        $result = $this->db->query('SELECT service_type, COUNT(*) as count FROM search_history GROUP BY service_type ORDER BY count DESC');
        $stats = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $stats[] = $row;
        }
        return $stats;
    }
    
    public function getTotalUsers() {
        return $this->db->querySingle('SELECT COUNT(*) FROM users');
    }
    
    public function getBannedUsersCount() {
        return $this->db->querySingle('SELECT COUNT(*) FROM users WHERE is_banned = 1');
    }
    
    public function getTotalSearches() {
        return $this->db->querySingle('SELECT COUNT(*) FROM search_history');
    }
}

// Telegram Bot Class
class TelegramBot {
    private $token;
    private $admin_id;
    private $channel_id;
    private $channel_link;
    private $api_config;
    private $db;
    
    public function __construct() {
        $this->token = BOT_TOKEN;
        $this->admin_id = ADMIN_ID;
        $this->channel_id = CHANNEL_ID;
        $this->channel_link = CHANNEL_LINK;
        $this->api_config = $GLOBALS['API_CONFIG'];
        $this->db = new Database();
    }
    
    public function handleWebhook() {
        try {
            $input = file_get_contents('php://input');
            if (empty($input)) {
                // Health check response
                if ($_SERVER['REQUEST_METHOD'] === 'GET') {
                    $this->handleHealthCheck();
                }
                return;
            }
            
            $update = json_decode($input, true);
            if (!$update) {
                return;
            }
            
            $this->processUpdate($update);
        } catch (Exception $e) {
            error_log("Webhook Error: " . $e->getMessage());
        }
    }
    
    private function handleHealthCheck() {
        header('Content-Type: application/json');
        echo json_encode([
            'status' => 'ok', 
            'time' => date('Y-m-d H:i:s'),
            'service' => 'Telegram Info Bot',
            'version' => '1.0'
        ]);
        exit;
    }
    
    private function processUpdate($update) {
        if (isset($update['message'])) {
            $message = $update['message'];
            $chat_id = $message['chat']['id'];
            $user_id = $message['from']['id'];
            $username = $message['from']['username'] ?? 'user_' . $user_id;
            $first_name = $message['from']['first_name'] ?? 'User';
            $last_name = $message['from']['last_name'] ?? '';
            $text = $message['text'] ?? '';
            
            if (strpos($text, '/') === 0) {
                $this->handleCommand($chat_id, $user_id, $username, $first_name, $last_name, $text);
            } else {
                $this->handleMessage($chat_id, $user_id, $username, $first_name, $last_name, $text);
            }
        }
    }
    
    private function handleCommand($chat_id, $user_id, $username, $first_name, $last_name, $text) {
        $command = explode(' ', $text)[0];
        
        switch ($command) {
            case '/start':
                $this->commandStart($chat_id, $user_id, $username, $first_name, $last_name);
                break;
            case '/help':
                $this->commandHelp($chat_id, $user_id);
                break;
            case '/credits':
                $this->commandCredits($chat_id, $user_id, $first_name);
                break;
            case '/phone':
                $this->sendMessage($chat_id, "📱 *Number Info Lookup*\n\nPlease send a 10-digit mobile number.\nExample: 9876543210", true);
                break;
            case '/aadhaar':
                $this->sendMessage($chat_id, "🆔 *Aadhaar Lookup*\n\nPlease send a 12-digit Aadhaar number.\nExample: 123456789012", true);
                break;
            case '/vehicle':
                $this->sendMessage($chat_id, "🚗 *Vehicle Lookup*\n\nPlease send a vehicle registration number.\nExample: KA04EQ4521", true);
                break;
            case '/ifsc':
                $this->sendMessage($chat_id, "🏦 *IFSC Lookup*\n\nPlease send an 11-character IFSC code.\nExample: SBIN0000001", true);
                break;
            case '/ip':
                $this->sendMessage($chat_id, "🌐 *IP Lookup*\n\nPlease send an IPv4 address.\nExample: 149.154.167.91", true);
                break;
            case '/pincode':
                $this->sendMessage($chat_id, "📮 *Pincode Lookup*\n\nPlease send a 6-digit pincode.\nExample: 110006", true);
                break;
            case '/admin':
                $this->commandAdmin($chat_id, $user_id);
                break;
            case '/stats':
                $this->commandStats($chat_id, $user_id);
                break;
            default:
                $this->sendMessage($chat_id, "❌ Unknown command. Use /help for available commands.");
                break;
        }
    }
    
    private function handleMessage($chat_id, $user_id, $username, $first_name, $last_name, $text) {
        if ($this->db->isUserBanned($user_id)) {
            return;
        }
        
        $this->db->updateUserActivity($user_id);
        
        if ($this->handleButtonClick($chat_id, $user_id, $first_name, $text)) {
            return;
        }
        
        // Admin commands processing
        if ($user_id == $this->admin_id) {
            if ($this->handleAdminCommands($chat_id, $user_id, $text)) {
                return;
            }
        }
        
        foreach ($this->api_config as $type => $config) {
            if (preg_match($config['pattern'], $text)) {
                $this->processLookup($chat_id, $user_id, $text, $type);
                return;
            }
        }
        
        $this->sendMessage($chat_id, "❌ Invalid input. Please use buttons or send a valid number.\n\nUse /help for guidance.");
    }
    
    private function handleButtonClick($chat_id, $user_id, $first_name, $text) {
        $buttons = [
            '📱 Number Info' => '/phone',
            '🆔 Aadhaar' => '/aadhaar',
            '🚗 Vehicle' => '/vehicle',
            '🏦 IFSC' => '/ifsc',
            '🌐 IP Lookup' => '/ip',
            '📮 Pincode' => '/pincode',
            '💎 My Credits' => '/credits',
            '🛒 Buy Credits' => 'buy_credits',
            'ℹ️ Help' => '/help',
            '👑 Admin Panel' => '/admin',
            '📊 Statistics' => '/stats'
        ];
        
        if (isset($buttons[$text])) {
            $action = $buttons[$text];
            
            if (strpos($action, '/') === 0) {
                $this->handleCommand($chat_id, $user_id, '', $first_name, '', $action);
            } elseif ($action === 'buy_credits') {
                $this->commandBuyCredits($chat_id, $user_id);
            }
            
            return true;
        }
        
        return false;
    }
    
    private function handleAdminCommands($chat_id, $user_id, $text) {
        $parts = explode(' ', $text, 3);
        
        if (count($parts) >= 2 && is_numeric($parts[0])) {
            $target_user_id = (int)$parts[0];
            $amount = (int)$parts[1];
            
            if ($amount > 0) {
                $this->db->addCredits($target_user_id, $amount);
                $this->sendMessage($chat_id, "✅ Added $amount credits to user $target_user_id");
                return true;
            }
        }
        
        // Ban user: "ban user_id reason"
        if (strpos($text, 'ban ') === 0 && count($parts) >= 3) {
            $target_user_id = (int)$parts[1];
            $reason = $parts[2] ?? 'No reason provided';
            $this->db->banUser($target_user_id, $user_id, $reason);
            $this->sendMessage($chat_id, "✅ User $target_user_id banned. Reason: $reason");
            return true;
        }
        
        // Unban user: "unban user_id"
        if (strpos($text, 'unban ') === 0 && count($parts) >= 2) {
            $target_user_id = (int)$parts[1];
            $this->db->unbanUser($target_user_id);
            $this->sendMessage($chat_id, "✅ User $target_user_id unbanned");
            return true;
        }
        
        // Protect number: "protect number"
        if (strpos($text, 'protect ') === 0 && count($parts) >= 2) {
            $phone_number = $parts[1];
            if (preg_match('/^[6-9]\d{9}$/', $phone_number)) {
                $this->db->protectNumber($phone_number, $user_id);
                $this->sendMessage($chat_id, "✅ Number $phone_number protected");
                return true;
            }
        }
        
        return false;
    }
    
    private function commandStart($chat_id, $user_id, $username, $first_name, $last_name) {
        if (!empty($this->channel_id) && !$this->checkChannelMembership($user_id)) {
            $this->sendJoinChannelMessage($chat_id);
            return;
        }
        
        if ($this->db->isUserBanned($user_id)) {
            $user = $this->db->getUser($user_id);
            $ban_reason = $user['ban_reason'] ?? 'No reason provided';
            $ban_date = $user['ban_date'] ?? 'Unknown';
            
            $text = "🚫 *ACCOUNT BANNED*\n\n";
            $text .= "❌ Your account has been banned from using this bot.\n\n";
            $text .= "📋 Reason: $ban_reason\n";
            $text .= "📅 Banned on: $ban_date\n\n";
            $text .= "🔍 If you think this is a mistake, contact the administrator.";
            
            $this->sendMessage($chat_id, $text, true);
            return;
        }
        
        $this->db->createUser($user_id, $username, $first_name, $last_name);
        $this->db->updateUserActivity($user_id);
        
        $credits = $this->db->getCredits($user_id);
        
        $text = "✨ *Welcome $first_name!* ✨\n\n";
        $text .= "🤖 *Professional Multi-Info Bot*\n";
        $text .= "Your all-in-one information lookup solution\n\n";
        $text .= "💎 Available Credits: *$credits*\n";
        $text .= "Each search costs 1 credit\n\n";
        $text .= "🔍 *Available Lookups:*\n";
        $text .= "• 📱 Number Info - 10-digit mobile numbers\n";
        $text .= "• 🆔 Aadhaar Cards - 12-digit Aadhaar numbers\n";
        $text .= "• 🚗 Vehicle Info - Vehicle registration numbers\n";
        $text .= "• 🏦 Bank IFSC - 11-character IFSC codes\n";
        $text .= "• 🌐 IP Addresses - IPv4 address information\n";
        $text .= "• 📮 Pincode Info - 6-digit postal pincode details\n\n";
        $text .= "Choose an option below to get started!";
        
        $keyboard = $this->createMainKeyboard($user_id);
        $this->sendMessage($chat_id, $text, true, $keyboard);
    }
    
    private function commandHelp($chat_id, $user_id) {
        if ($this->db->isUserBanned($user_id)) {
            return;
        }
        
        $text = "📚 *Professional Help Guide* 📚\n\n";
        $text .= "🔍 *Available Lookup Services:*\n\n";
        $text .= "📱 *Number Info* - 1 credit\n";
        $text .= "• Format: 10-digit number\n";
        $text .= "• Example: 9876543210\n\n";
        $text .= "🆔 *Aadhaar Lookup* - 1 credit\n";
        $text .= "• Format: 12-digit number\n";
        $text .= "• Example: 123456789012\n\n";
        $text .= "🚗 *Vehicle Information* - 1 credit\n";
        $text .= "• Format: Vehicle registration\n";
        $text .= "• Example: KA04EQ4521\n\n";
        $text .= "🏦 *IFSC Bank Details* - 1 credit\n";
        $text .= "• Format: 11-character code\n";
        $text .= "• Example: SBIN0000001\n\n";
        $text .= "🌐 *IP Address Information* - 1 credit\n";
        $text .= "• Format: IPv4 address\n";
        $text .= "• Example: 149.154.167.91\n\n";
        $text .= "📮 *Pincode Information* - 1 credit\n";
        $text .= "• Format: 6-digit pincode\n";
        $text .= "• Example: 110006\n\n";
        $text .= "💎 *Credits System:*\n";
        $text .= "• Each search costs 1 credit\n";
        $text .= "• Check credits with /credits\n";
        $text .= "• Buy more credits with \"🛒 Buy Credits\"\n\n";
        $text .= "⚡ *Quick Tips:*\n";
        $text .= "• Send numbers directly without commands\n";
        $text .= "• Use buttons for easy navigation\n";
        $text .= "• All data returned in JSON format";
        
        $keyboard = $this->createMainKeyboard($user_id);
        $this->sendMessage($chat_id, $text, true, $keyboard);
    }
    
    private function commandCredits($chat_id, $user_id, $first_name) {
        if ($this->db->isUserBanned($user_id)) {
            return;
        }
        
        $credits = $this->db->getCredits($user_id);
        
        $text = "💎 *Your Credits*\n\n";
        $text .= "🆔 User: $first_name\n";
        $text .= "💳 Available Credits: *$credits*\n";
        $text .= "🔍 Cost per search: 1 credit\n\n";
        
        if ($credits < 5) {
            $text .= "⚠️ *Low Balance!* Please buy more credits.\n\n";
        }
        
        $text .= "🛒 *Need more credits?*\n";
        $text .= "Click '🛒 Buy Credits' button below";
        
        $keyboard = $this->createMainKeyboard($user_id);
        $this->sendMessage($chat_id, $text, true, $keyboard);
    }
    
    private function commandBuyCredits($chat_id, $user_id) {
        if ($this->db->isUserBanned($user_id)) {
            return;
        }
        
        $text = "🛒 *Buy Credits*\n\n";
        $text .= "💎 *Credit Packages Available:*\n\n";
        $text .= "💰 *100 Credits* - ₹500\n";
        $text .= "💰 *500 Credits* - ₹2000\n";
        $text .= "💰 *1000 Credits* - ₹3500\n";
        $text .= "💰 *5000 Credits* - ₹15000\n\n";
        $text .= "📞 *To Purchase:*\n";
        $text .= "Contact admin with your:\n";
        $text .= "• User ID: `$user_id`\n";
        $text .= "• Desired package\n\n";
        $text .= "✅ Credits will be added instantly after verification!";
        
        $keyboard = $this->createMainKeyboard($user_id);
        $this->sendMessage($chat_id, $text, true, $keyboard);
    }
    
    private function commandAdmin($chat_id, $user_id) {
        if ($user_id != $this->admin_id) {
            $this->sendMessage($chat_id, "❌ Access denied.");
            return;
        }
        
        $total_users = $this->db->getTotalUsers();
        $banned_users = $this->db->getBannedUsersCount();
        $protected_numbers = $this->db->getProtectedNumbersCount();
        $total_searches = $this->db->getTotalSearches();
        
        $text = "👑 *Admin Panel*\n\n";
        $text .= "📊 *Statistics:*\n";
        $text .= "• Total Users: $total_users\n";
        $text .= "• Banned Users: $banned_users\n";
        $text .= "• Protected Numbers: $protected_numbers\n";
        $text .= "• Total Searches: $total_searches\n\n";
        $text .= "🛠 *Quick Commands:*\n";
        $text .= "• Add credits: `123456789 50`\n";
        $text .= "• Ban user: `ban 123456789 reason`\n";
        $text .= "• Unban user: `unban 123456789`\n";
        $text .= "• Protect number: `protect 9876543210`\n\n";
        $text .= "Use buttons below for detailed options:";
        
        $keyboard = $this->createAdminKeyboard();
        $this->sendMessage($chat_id, $text, true, $keyboard);
    }
    
    private function commandStats($chat_id, $user_id) {
        $user = $this->db->getUser($user_id);
        if (!$user) {
            $this->sendMessage($chat_id, "❌ User not found.");
            return;
        }
        
        $credits = $user['credits'];
        $total_searches = $user['total_searches'];
        $joined_date = $user['joined_date'];
        $last_active = $user['last_active'];
        
        $text = "📊 *Your Statistics*\n\n";
        $text .= "🆔 User: " . $user['first_name'] . "\n";
        $text .= "💎 Credits: $credits\n";
        $text .= "🔍 Total Searches: $total_searches\n";
        $text .= "📅 Joined: " . substr($joined_date, 0, 10) . "\n";
        $text .= "🕒 Last Active: " . substr($last_active, 0, 16) . "\n\n";
        
        if ($user_id == $this->admin_id) {
            $total_users = $this->db->getTotalUsers();
            $total_all_searches = $this->db->getTotalSearches();
            $text .= "👑 *Admin Stats:*\n";
            $text .= "• Total Users: $total_users\n";
            $text .= "• Total Searches: $total_all_searches\n";
        }
        
        $keyboard = $this->createMainKeyboard($user_id);
        $this->sendMessage($chat_id, $text, true, $keyboard);
    }
    
    private function processLookup($chat_id, $user_id, $query, $type) {
        if ($type === 'phone' && $this->db->isNumberProtected($query)) {
            $this->sendMessage($chat_id, "🛡️ *Protected Number*\n\nThis number is protected and cannot be looked up.", true);
            return;
        }
        
        $credits = $this->db->getCredits($user_id);
        $required_credits = $this->api_config[$type]['credits'];
        
        if ($credits < $required_credits) {
            $text = "❌ *Insufficient Credits*\n\n";
            $text .= "💎 Your Credits: $credits\n";
            $text .= "🔍 Required: $required_credits\n\n";
            $text .= "Please buy more credits to continue.";
            
            $this->sendMessage($chat_id, $text, true);
            return;
        }
        
        if (!$this->db->deductCredits($user_id, $required_credits)) {
            $this->sendMessage($chat_id, "❌ Failed to deduct credits. Please try again.");
            return;
        }
        
        $this->db->addSearchHistory($user_id, $type, $query);
        
        $this->sendMessage($chat_id, "🔍 Processing your request...\nPlease wait...");
        
        $url = str_replace('{query}', urlencode($query), $this->api_config[$type]['url']);
        $response = $this->makeApiCall($url);
        
        if ($response === false) {
            $this->sendMessage($chat_id, "❌ API Error: Failed to fetch data. Credits have been refunded.");
            $this->db->addCredits($user_id, $required_credits);
            return;
        }
        
        $service_name = $this->api_config[$type]['name'];
        $remaining_credits = $this->db->getCredits($user_id);
        
        $text = "✅ *$service_name Result*\n\n";
        $text .= "📊 *Query:* `$query`\n";
        $text .= "💎 *Credits Used:* $required_credits\n";
        $text .= "💰 *Remaining Credits:* $remaining_credits\n\n";
        $text .= "📄 *Response:*\n```json\n" . $this->formatJson($response) . "\n```";
        
        $this->sendMessage($chat_id, $text, true);
    }
    
    private function formatJson($json) {
        $decoded = json_decode($json, true);
        if ($decoded === null) {
            return substr($json, 0, 3000) . (strlen($json) > 3000 ? "\n... (truncated)" : "");
        }
        $formatted = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        return substr($formatted, 0, 3000) . (strlen($formatted) > 3000 ? "\n... (truncated)" : "");
    }
    
    private function makeApiCall($url) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'TelegramBot/1.0',
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error || $http_code !== 200) {
            error_log("API Call Failed: $url | HTTP: $http_code | Error: $error");
            return false;
        }
        
        return $response;
    }
    
    private function checkChannelMembership($user_id) {
        if (empty($this->channel_id) || $this->channel_id == '@your_channel') {
            return true;
        }
        
        $url = "https://api.telegram.org/bot" . $this->token . "/getChatMember?chat_id=" . $this->channel_id . "&user_id=" . $user_id;
        $response = $this->makeApiCall($url);
        
        if ($response === false) {
            return true;
        }
        
        $data = json_decode($response, true);
        $status = $data['result']['status'] ?? '';
        
        return in_array($status, ['member', 'administrator', 'creator']);
    }
    
    private function sendJoinChannelMessage($chat_id) {
        $text = "👋 *Welcome!*\n\n";
        $text .= "To use this bot, you must be a member of our official channel.\n\n";
        $text .= "Please join the channel below and then press /start again.";
        
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => 'Join Our Channel', 'url' => $this->channel_link]
                ],
                [
                    ['text' => '✅ I Have Joined', 'callback_data' => 'joined_channel']
                ]
            ]
        ];
        
        $this->sendMessage($chat_id, $text, true, $keyboard);
    }
    
    private function createMainKeyboard($user_id) {
        $keyboard = [
            'keyboard' => [
                [
                    ['text' => '📱 Number Info'],
                    ['text' => '🆔 Aadhaar'],
                    ['text' => '🚗 Vehicle']
                ],
                [
                    ['text' => '🏦 IFSC'],
                    ['text' => '🌐 IP Lookup'],
                    ['text' => '📮 Pincode']
                ],
                [
                    ['text' => '💎 My Credits'],
                    ['text' => '🛒 Buy Credits'],
                    ['text' => 'ℹ️ Help']
                ]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
        
        if ($user_id == $this->admin_id) {
            $keyboard['keyboard'][] = [['text' => '👑 Admin Panel']];
        }
        
        return $keyboard;
    }
    
    private function createAdminKeyboard() {
        return [
            'keyboard' => [
                [['text' => '📊 User Statistics'], ['text' => '👥 All Users']],
                [['text' => '➕ Add Credits'], ['text' => '🔨 Ban User']],
                [['text' => '🔓 Unban User'], ['text' => '🛡️ Protect Number']],
                [['text' => '📈 Search Stats'], ['text' => '🏠 Main Menu']]
            ],
            'resize_keyboard' => true,
            'one_time_keyboard' => false
        ];
    }
    
    private function sendMessage($chat_id, $text, $markdown = false, $keyboard = null) {
        $url = "https://api.telegram.org/bot" . $this->token . "/sendMessage";
        
        $data = [
            'chat_id' => $chat_id,
            'text' => $text
        ];
        
        if ($markdown) {
            $data['parse_mode'] = 'Markdown';
        }
        
        if ($keyboard) {
            $data['reply_markup'] = json_encode($keyboard);
        }
        
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10
        ]);
        
        $result = curl_exec($ch);
        curl_close($ch);
        
        return $result;
    }
}

// Main execution with health check
try {
    $bot = new TelegramBot();
    $bot->handleWebhook();
} catch (Exception $e) {
    error_log("Main Execution Error: " . $e->getMessage());
    http_response_code(500);
}
?>