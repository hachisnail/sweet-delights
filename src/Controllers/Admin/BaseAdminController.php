<?php
namespace SweetDelights\Mayie\Controllers\Admin;

use Slim\Routing\RouteContext;
use Slim\Views\Twig;
use Psr\Http\Message\ServerRequestInterface as Request;
use \PDO; // <-- Use PHP's built-in database object

class BaseAdminController
{
    // --- DATABASE CONNECTION ---
    protected $db; // <-- The database connection

    public function __construct()
    {
        // --- DATABASE CONNECTION ---
        try {
            $dbHost = $_ENV['DB_HOST'];
            $dbName = $_ENV['DB_NAME'];
            $dbUser = $_ENV['DB_USER'];
            $dbPass = $_ENV['DB_PASS'];

            // Data Source Name (DSN)
            $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            $this->db = new PDO($dsn, $dbUser, $dbPass, $options);
        } catch (\PDOException $e) {
            // In a real app, you'd have a proper error page
            die('Database Connection Failed: ' . $e->getMessage());
        }
    }

    // --- NEW DATABASE-DRIVEN DATA HELPERS ---
    
    /**
     * Hydrates a given array of products with their sizes and calculated stock.
     */
    protected function hydrateProductsWithSizes(array $products): array
    {
        if (empty($products)) {
            return [];
        }

        // 1. Get all product IDs from the provided array
        $productIds = array_column($products, 'id');
        
        // 2. Fetch all sizes for *only* these products in one query
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $sizeStmt = $this->db->prepare("SELECT * FROM product_sizes WHERE product_id IN ($placeholders)");
        $sizeStmt->execute($productIds);
        $allSizes = $sizeStmt->fetchAll();

        // 3. Map sizes to their products
        $sizesByProductId = [];
        foreach ($allSizes as $size) {
            $sizesByProductId[$size['product_id']][] = $size;
        }

        // 4. "Hydrate" the product objects
        foreach ($products as &$product) {
            $product['sizes'] = $sizesByProductId[$product['id']] ?? [];
            
            // Calculate total stock from sizes
            if (!empty($product['sizes'])) {
                // If there are sizes, stock is the sum of size stocks
                $product['stock'] = array_sum(array_column($product['sizes'], 'stock'));
            } else {
                // This product has no sizes, its stock is 0 (or should be handled differently)
                // For safety, we check if it's a "Default" size stock
                $product['stock'] = $product['sizes'][0]['stock'] ?? 0;
            }
        }

        return $products;
    }

    protected function getProducts(): array {
        // 1. Fetch all products
        $stmt = $this->db->query("SELECT * FROM products");
        $products = $stmt->fetchAll();
        
        // 2. Hydrate them with their size/stock info
        return $this->hydrateProductsWithSizes($products);
    }

    protected function getCategories(): array {
        $stmt = $this->db->query("SELECT * FROM categories ORDER BY name ASC");
        return $stmt->fetchAll();
    }

    protected function getOrders(): array {
        // 1. Fetch all orders
        $stmt = $this->db->query("SELECT * FROM orders ORDER BY date DESC");
        $orders = $stmt->fetchAll();
        
        if (empty($orders)) {
            return [];
        }
        
        // 2. Fetch all order items
        $itemStmt = $this->db->query("SELECT * FROM order_items");
        $allItems = $itemStmt->fetchAll();

        // 3. Map items to their orders
        $itemsByOrderId = [];
        foreach ($allItems as $item) {
            $item['price'] = (float)$item['price'];
            $item['quantity'] = (int)$item['quantity'];
            $itemsByOrderId[$item['order_id']][] = $item;
        }

        // 4. Hydrate the order objects
        foreach ($orders as &$order) {
            $order['address'] = json_decode($order['address'], true) ?? [];
            $order['items'] = $itemsByOrderId[$order['id']] ?? [];
        }

        return $orders;
    }

    protected function getUsers(): array { 
        $stmt = $this->db->query("SELECT * FROM users");
        
        // Decode JSON strings back into arrays
        return array_map(function($user) {
            $user['address'] = json_decode($user['address'], true) ?? [];
            $user['cart'] = json_decode($user['cart'], true) ?? [];
            $user['favourites'] = json_decode($user['favourites'], true) ?? [];
            $user['is_verified'] = (bool)$user['is_verified'];
            $user['is_active'] = (bool)$user['is_active'];
            return $user;
        }, $stmt->fetchAll());
    }
    
    protected function getConfig(): array {
        $config = [];
        $stmt = $this->db->query("SELECT * FROM settings");
        foreach ($stmt->fetchAll() as $row) {
            $config[$row['setting_key']] = is_numeric($row['setting_value']) ? (float)$row['setting_value'] : $row['setting_value'];
        }
        return $config;
    }

    /**
     * Fetches the most recent log entries from the database.
     *
     * @param array $filters Filters for 'actor', 'action', and 'target'
     * @param int $page The current page number
     * @param int $perPage The number of items per page
     */
    protected function getLogs(array $filters = [], int $page = 1, int $perPage = 20): array 
    {
        // Extract filters
        $actorName = $filters['actor'] ?? null;
        $actionType = $filters['action'] ?? null;
        $targetType = $filters['target'] ?? null;

        $sql = "
            SELECT 
                a.*, 
                u.first_name, 
                u.last_name, 
                u.email 
            FROM audit_log a
            LEFT JOIN users u ON a.actor_id = u.id
        ";

        $where = [];
        $params = [];

        if ($actorName) {
            // Filter by actor name (first, last, or email)
            $where[] = "(CONCAT(u.first_name, ' ', u.last_name) LIKE ? OR u.email LIKE ?)";
            $params[] = "%{$actorName}%";
            $params[] = "%{$actorName}%";
        }

        if ($actionType) {
            $where[] = "a.action_type = ?";
            $params[] = $actionType;
        }

        if ($targetType) {
            $where[] = "a.target_type = ?";
            $params[] = $targetType;
        }

        if (!empty($where)) {
            $sql .= " WHERE " . implode(" AND ", $where);
        }

        // --- NEW: Perform a COUNT query first ---
        $countSql = str_replace("SELECT 
                a.*, 
                u.first_name, 
                u.last_name, 
                u.email 
            FROM audit_log a
            LEFT JOIN users u ON a.actor_id = u.id", "SELECT COUNT(a.id) FROM audit_log a LEFT JOIN users u ON a.actor_id = u.id", $sql);
        
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($params);
        $totalLogs = (int)$countStmt->fetchColumn();
        // --- END COUNT QUERY ---

        // --- NEW: Add pagination to the main query ---
        $offset = ($page - 1) * $perPage;
        // We can safely inject $perPage and $offset because they are cast to int
        $sql .= " ORDER BY a.timestamp DESC LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

        // Prepare and execute the query
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $logs = $stmt->fetchAll();

        // Now, we process the results to make them easy to display
        $processedLogs = array_map(function($log) {
            
            // 1. Decode the JSON 'details' field
            $log['details'] = json_decode($log['details'], true) ?? [];

            // 2. Create a friendly "actor_name"
            if (!empty($log['first_name'])) {
                $log['actor_name'] = $log['first_name'] . ' ' . $log['last_name'];
            } else if (!empty($log['email'])) {
                $log['actor_name'] = $log['email'];
            } else if (!empty($log['actor_id'])) {
                $log['actor_name'] = 'User (ID: ' . $log['actor_id'] . ')';
            } else {
                $log['actor_name'] = 'System/Unknown';
            }

            // 3. Clean up the raw user fields we don't need
            unset($log['first_name'], $log['last_name'], $log['email']);

            return $log;
        }, $logs);

        // --- NEW: Return logs and total count ---
        return [
            'logs' => $processedLogs,
            'total' => $totalLogs
        ];
    }

    /**
     * Logs a specific action to the database audit trail.
     *
     * @param int|null $actorId The ID of the user performing the action (e.g., logged-in admin)
     * @param string $actionType The type of action (e.g., 'create', 'update', 'delete')
     * @param string $targetType The type of entity being modified (e.g., 'user', 'product')
     * @param int|null $targetId The ID of the entity being modified
     * @param array|null $details Extra context or data (e.g., changed fields)
     */
    protected function logAction(?int $actorId, string $actionType, string $targetType, ?int $targetId, ?array $details = null)
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO audit_log 
                    (actor_id, action_type, target_type, target_id, details)
                VALUES 
                    (:actor_id, :action_type, :target_type, :target_id, :details)
            ");

            $stmt->execute([
                ':actor_id'    => $actorId,
                ':action_type' => $actionType,
                ':target_type' => $targetType,
                ':target_id'   => $targetId,
                ':details'     => $details ? json_encode($details) : null
            ]);
        } catch (\PDOException $e) {
            // IMPORTANT: A logging failure should *not* crash the main application.
            // We log this error to the server's error log instead of dying.
            error_log('Failed to write to audit log: ' . $e->getMessage());
        }
    }

        /**
     * Helper wrapper for logging entity before/after changes in a structured format.
     *
     * @param int|null $actorId
     * @param string $actionType ('create', 'update', 'delete')
     * @param string $entityType e.g., 'category', 'product', 'user'
     * @param int|null $entityId
     * @param array|null $before Previous state (before change)
     * @param array|null $after  New state (after change)
     * @param array $meta        Extra contextual info (IP, route, etc.)
     */
    protected function logEntityChange(?int $actorId, string $actionType, string $entityType, ?int $entityId, ?array $before, ?array $after, array $meta = [])
    {
        $this->logAction(
            $actorId,
            $actionType,
            $entityType,
            $entityId,
            [
                'before' => $before,
                'after'  => $after,
                'meta'   => $meta
            ]
        );
    }


    /**
     * Gets a single log entry by its ID, with actor details.
     */
    protected function getLogById(int $id): ?array
    {
        $stmt = $this->db->prepare("
            SELECT 
                a.*, 
                u.first_name, 
                u.last_name, 
                u.email 
            FROM audit_log a
            LEFT JOIN users u ON a.actor_id = u.id
            WHERE a.id = ?
        ");
        $stmt->execute([$id]);
        $log = $stmt->fetch();

        if (!$log) {
            return null;
        }

        // Process the log just like in getLogs()
        $log['details'] = json_decode($log['details'], true) ?? [];

        if (!empty($log['first_name'])) {
            $log['actor_name'] = $log['first_name'] . ' ' . $log['last_name'];
        } else if (!empty($log['email'])) {
            $log['actor_name'] = $log['email'];
        } else if (!empty($log['actor_id'])) {
            $log['actor_name'] = 'User (ID: ' . $log['actor_id'] . ')';
        } else {
            $log['actor_name'] = 'System/Unknown';
        }

        unset($log['first_name'], $log['last_name'], $log['email']);

        return $log;
    }

    /**
     * Update product co-purchase associations.
     * 
     * Called when an order is successfully placed (after order_items insertion).
     * 
     * @param array $purchasedSkus  Array of product SKUs from the same order.
     * @return void
     */
    protected function updateProductAssociations(array $purchasedSkus): void
    {
        if (count($purchasedSkus) < 2) return;

        sort($purchasedSkus); // avoid duplicate reversed pairs

        $insertStmt = $this->db->prepare("
            INSERT INTO product_associations (product_sku_1, product_sku_2, support_count)
            VALUES (:sku1, :sku2, 1)
            ON DUPLICATE KEY UPDATE
                support_count = support_count + 1,
                last_purchased_at = CURRENT_TIMESTAMP
        ");

        foreach ($purchasedSkus as $i => $sku1) {
            for ($j = $i + 1; $j < count($purchasedSkus); $j++) {
                $sku2 = $purchasedSkus[$j];
                $insertStmt->execute([':sku1' => $sku1, ':sku2' => $sku2]);
            }
        }
    }


    // --- USER-SPECIFIC HELPERS (The missing methods) ---

    /**
     * Finds a single user by their ID.
     */
    protected function findUserById(int $id): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();

        if ($user) {
            // Decode JSON fields
            $user['address'] = json_decode($user['address'], true) ?? [];
            $user['cart'] = json_decode($user['cart'], true) ?? [];
            $user['favourites'] = json_decode($user['favourites'], true) ?? [];
        }
        
        return $user ?: null;
    }

    /**
     * Finds a single user by their email.
     */
    protected function findUserByEmail(string $email): ?array {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user) {
            // Decode JSON fields
            $user['address'] = json_decode($user['address'], true) ?? [];
            $user['cart'] = json_decode($user['cart'], true) ?? [];
            $user['favourites'] = json_decode($user['favourites'], true) ?? [];
        }
        
        return $user ?: null;
    }

    /**
     * Gets a single order and its associated items.
     */
    protected function getOrderById(int $id): ?array
    {
        // 1. Fetch the specific order
        $stmt = $this->db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$id]);
        $order = $stmt->fetch();

        if (!$order) {
            return null;
        }

        // 2. Fetch items for *this* order
        $itemStmt = $this->db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $itemStmt->execute([$id]);
        $items = $itemStmt->fetchAll();

        // 3. Hydrate the order
        $order['address'] = json_decode($order['address'], true) ?? [];
        $order['items'] = [];
        foreach ($items as $item) {
            // Cast types for safety
            $item['price'] = (float)$item['price'];
            $item['quantity'] = (int)$item['quantity'];
            $order['items'][] = $item;
        }

        return $order;
    }

    /**
     * Creates a new user in the database.
     */
    protected function createNewUser(array $userData): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO users 
                (first_name, last_name, email, password_hash, contact_number, address, role, is_verified, is_active, verification_token, password_reset_token, password_reset_expires, cart, favourites)
            VALUES 
                (:first_name, :last_name, :email, :password_hash, :contact_number, :address, :role, :is_verified, :is_active, :verification_token, :pr_token, :pr_expires, :cart, :favourites)
        ");
        
        $stmt->execute([
            ':first_name' => $userData['first_name'],
            ':last_name' => $userData['last_name'],
            ':email' => $userData['email'],
            ':password_hash' => $userData['password_hash'],
            ':contact_number' => $userData['contact_number'] ?? null,
            ':address' => json_encode($userData['address'] ?? []),
            ':role' => $userData['role'] ?? 'customer',
            ':is_verified' => (int)($userData['is_verified'] ?? 0),
            ':is_active' => (int)($userData['is_active'] ?? 1),
            ':verification_token' => $userData['verification_token'] ?? null,
            ':pr_token' => $userData['password_reset_token'] ?? null,
            ':pr_expires' => $userData['password_reset_expires'] ?? null,
            ':cart' => json_encode($userData['cart'] ?? []),
            ':favourites' => json_encode($userData['favourites'] ?? [])
        ]);

        return (int)$this->db->lastInsertId();
    }

    /**
     * Updates an existing user in the database.
     * Dynamically builds the UPDATE query based on the $data keys.
     */
    protected function updateUser(int $id, array $data)
    {
        if (empty($data)) {
            return;
        }

        $sql = "UPDATE users SET ";
        $params = [];
        
        foreach ($data as $key => $value) {
            // Whitelist columns to prevent arbitrary updates
            if (in_array($key, ['first_name', 'last_name', 'email', 'password_hash', 'contact_number', 'address', 'role', 'is_verified', 'is_active', 'verification_token', 'password_reset_token', 'password_reset_expires', 'cart', 'favourites'])) {
                $sql .= "`$key` = ?, ";
                // JSON-encode arrays if needed
                if (in_array($key, ['address', 'cart', 'favourites']) && is_array($value)) {
                    $params[] = json_encode($value);
                } else {
                    $params[] = $value;
                }
            }
        }
        
        $sql = rtrim($sql, ', '); // Remove trailing comma
        $sql .= " WHERE id = ?";
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Deletes a user from the database.
     */
    protected function deleteUser(int $id)
    {
        $stmt = $this->db->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
    }


    /**
     * Updates a single JSON key for a user (e.g., 'cart' or 'favourites').
     */
    protected function saveUserKey(int $userId, string $key, array $data)
    {
        // Whitelist keys to prevent SQL injection
        if (!in_array($key, ['cart', 'favourites', 'address'])) {
            return;
        }

        $jsonPayload = json_encode($data);
        $stmt = $this->db->prepare("UPDATE users SET `$key` = ? WHERE id = ?");
        $stmt->execute([$jsonPayload, $userId]);
    }

    /**
     * Saves a password reset token for a user.
     */
    protected function saveUserResetToken(int $userId, ?string $token, ?int $expires)
    {
        $stmt = $this->db->prepare("UPDATE users SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
        $stmt->execute([$token, $expires, $userId]);
    }

    /**
     * Verifies a user's email based on their token.
     */
    protected function verifyUserEmail(string $token): bool
    {
        $stmt = $this->db->prepare("UPDATE users SET is_verified = 1, verification_token = NULL WHERE verification_token = ?");
        $stmt->execute([$token]);
        
        // return true if a row was affected, false otherwise
        return $stmt->rowCount() > 0;
    }

    // --- (Standard Methods Below) ---

    protected function viewFromRequest(Request $request): Twig
    {
        return Twig::fromRequest($request);
    }
    
    protected function breadcrumbs(Request $request, array $trail): array
    {
        $routeParser = RouteContext::fromRequest($request)->getRouteParser();
        $breadcrumbs = [];
        foreach ($trail as $crumb) {
            if (isset($crumb['url']) && $crumb['url'] && !str_starts_with($crumb['url'], '/')) {
                $crumb['url'] = $routeParser->urlFor($crumb['url']);
            }
            $breadcrumbs[] = $crumb;
        }
        return $breadcrumbs;
    }

    /**
     * Saves an indexed array (like users, products) to a file.
     * @deprecated This method is for file-based storage. Use DB queries instead.
     */
    protected function saveData(string $filePath, array $data)
    {
        // This is now legacy. We leave it here so old controllers don't break,
        // but we should not use it in new DB-driven code.
        $indexedData = array_values($data);
        $phpCode = '<?php' . PHP_EOL . 'return ' . var_export($indexedData, true) . ';';
        file_put_contents($filePath, $phpCode, LOCK_EX);
    }

    /**
     * Saves an associative array (like config) to a file.
     * @deprecated This method is for file-based storage. Use DB queries instead.
     */
    protected function saveConfigData(string $filePath, array $data)
    {
        // This is now legacy.
        $phpCode = '<?php' . PHP_EOL . 'return ' . var_export($data, true) . ';';
        file_put_contents($filePath, $phpCode, LOCK_EX);
    }


    protected function _createSlugAbbreviation(string $slug): string
    {
        if (empty($slug)) {
            return 'uncat';
        }
        if (strpos($slug, '-') !== false) {
            $parts = explode('-', $slug);
            $abbr = '';
            foreach ($parts as $part) {
                if (!empty($part)) {
                    $abbr .= $part[0]; 
                }
            }
            return empty($abbr) ? 'uncat' : $abbr;
        } else {
            return substr($slug, 0, 3);
        }
    }

    protected function _createNameAbbreviation(string $name): string
    {
        if (empty($name)) {
            return 'PROD';
        }
        $cleanName = preg_replace('/[^a-zA-Z\s]/', '', $name);
        if (strpos(trim($cleanName), ' ') !== false) {
            $parts = explode(' ', $cleanName);
            $abbr = '';
            foreach ($parts as $part) {
                if (!empty($part)) {
                    $abbr .= $part[0];
                }
            }
            return empty($abbr) ? 'PROD' : strtoupper($abbr);
        } else {
            return strtoupper(substr($cleanName, 0, 3));
        }
    }

    protected function generateSku(string $name, string $id, int $categoryId, array $allCategories): string
    {
        $category = null;
        $parentCategory = null;
        $categoryId = (int)$categoryId;

        if ($categoryId === 0) {
             $categoryAbbr = 'uncat';
             $parentAbbr = 'uncat';
        } else {
            foreach ($allCategories as $cat) {
                if ($cat['id'] === $categoryId) {
                    $category = $cat;
                    break;
                }
            }
            if ($category && $category['parent_id'] !== null) {
                foreach ($allCategories as $cat) {
                    if ($cat['id'] === $category['parent_id']) {
                        $parentCategory = $cat;
                        break;
                    }
                }
            }
            $categoryAbbr = 'uncat';
            $parentAbbr = 'uncat';
            if ($category) {
                $categoryAbbr = $this->_createSlugAbbreviation($category['slug']);
                if ($parentCategory) {
                    $parentAbbr = $this->_createSlugAbbreviation($parentCategory['slug']);
                } else {
                    $parentAbbr = $categoryAbbr;
                }
            }
        }
        
        $productCode = $this->_createNameAbbreviation($name);
        $sku = sprintf('%s-%s-%s-%s', $parentAbbr, $categoryAbbr, $productCode, $id);
        $sku = preg_replace('/[^a-zA-Z0-9]+/', '-', $sku);
        return strtolower(trim($sku, '-'));
    }
}