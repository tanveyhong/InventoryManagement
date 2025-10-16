<?php
// Firebase Database Connection Handler
require_once 'config.php';
require_once 'firebase_config.php';
require_once 'firebase_rest_client.php';
require_once 'sql_db.php'; // Include SQL database class

class Database {
    private static $instance = null;
    private $restClient;
    
    private function __construct() {
        try {
            // Use REST client for Windows compatibility
            $this->restClient = new FirebaseRestClient();
            
        } catch (Exception $e) {
            $error_message = "Firebase connection failed: " . $e->getMessage();
            
            // Log error if logging is configured
            if (defined('LOG_ERRORS') && LOG_ERRORS && defined('ERROR_LOG_PATH')) {
                error_log($error_message, 3, ERROR_LOG_PATH);
            }
            
            // Show detailed error in development
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                die($error_message);
            } else {
                die("Database connection failed. Please try again later.");
            }
        }
    }
    
    // Singleton pattern to ensure single database connection
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Get the REST client
    public function getConnection() {
        return $this->restClient;
    }
    
    // Create a document in a collection
    public function create($collection, $data, $documentId = null) {
        try {
            return $this->restClient->createDocument($collection, $data, $documentId);
        } catch (Exception $e) {
            $this->handleError("Create operation failed: " . $e->getMessage(), $e);
            return false;
        }
    }
    
    // Read a single document
    public function read($collection, $documentId) {
        try {
            return $this->restClient->getDocument($collection, $documentId);
        } catch (Exception $e) {
            $this->handleError("Read operation failed: " . $e->getMessage(), $e);
            return false;
        }
    }
    
    // Read all documents from a collection
    public function readAll($collection, $conditions = [], $orderBy = null, $limit = null) {
        try {
            // REST client has limited query support for now
            $results = $this->restClient->queryCollection($collection, $limit);
            
            // Apply basic filtering if conditions are provided
            if (!empty($conditions) && !empty($results)) {
                $filtered = [];
                foreach ($results as $doc) {
                    $matches = true;
                    foreach ($conditions as $condition) {
                        if (count($condition) === 3) {
                            $field = $condition[0];
                            $operator = $condition[1];
                            $value = $condition[2];
                            
                            // Special handling for null checks
                            $fieldValue = $doc[$field] ?? null;
                            $fieldExists = isset($doc[$field]);
                            
                            switch ($operator) {
                                case '==':
                                    // If checking for null, treat missing field as null
                                    if ($value === null) {
                                        if ($fieldExists && $doc[$field] !== null) {
                                            $matches = false;
                                        }
                                    } else {
                                        if (!$fieldExists || $doc[$field] !== $value) {
                                            $matches = false;
                                        }
                                    }
                                    break;
                                case '!=':
                                    // If checking not null, missing field counts as null
                                    if ($value === null) {
                                        if (!$fieldExists || $doc[$field] === null) {
                                            $matches = false;
                                        }
                                    } else {
                                        if ($fieldExists && $doc[$field] === $value) {
                                            $matches = false;
                                        }
                                    }
                                    break;
                                case '>':
                                    if (!$fieldExists || $doc[$field] <= $value) $matches = false;
                                    break;
                                case '>=':
                                    if (!$fieldExists || $doc[$field] < $value) $matches = false;
                                    break;
                                case '<':
                                    if (!$fieldExists || $doc[$field] >= $value) $matches = false;
                                    break;
                                case '<=':
                                    if (!$fieldExists || $doc[$field] > $value) $matches = false;
                                    break;
                            }
                            
                            if (!$matches) break;
                        }
                    }
                    
                    if ($matches) {
                        $filtered[] = $doc;
                    }
                }
                $results = $filtered;
            }
            
            return $results;
        } catch (Exception $e) {
            $this->handleError("ReadAll operation failed: " . $e->getMessage(), $e);
            return false;
        }
    }
    
    // Update a document
    public function update($collection, $documentId, $data) {
        try {
            return $this->restClient->updateDocument($collection, $documentId, $data);
        } catch (Exception $e) {
            $this->handleError("Update operation failed: " . $e->getMessage(), $e);
            return false;
        }
    }
    
    // Delete a document
    public function delete($collection, $documentId) {
        try {
            return $this->restClient->deleteDocument($collection, $documentId);
        } catch (Exception $e) {
            $this->handleError("Delete operation failed: " . $e->getMessage(), $e);
            return false;
        }
    }
    
    // Legacy methods for backward compatibility
    
    // Emulate SQL-like queries (simplified)
    public function query($collection, $conditions = [], $params = []) {
        return $this->readAll($collection, $conditions);
    }
    
    // Fetch a single document (alias for read)
    public function fetch($collectionOrSql, $params = []) {
        // Support legacy SQL-style calls like: "SELECT ... FROM products WHERE p.id = ?", [$id]
        if (is_string($collectionOrSql) && preg_match('/\bFROM\b\s+([a-zA-Z0-9_]+)/i', $collectionOrSql, $m)) {
            $collection = $m[1];

            // Try to extract a simple WHERE field = ? pattern
            if (preg_match('/\bWHERE\b\s+([a-zA-Z0-9_.]+)\s*=\s*\?/i', $collectionOrSql, $m2)) {
                $field = $m2[1];
                // Remove any alias prefix (e.g. p.id -> id)
                if (strpos($field, '.') !== false) {
                    $field = substr($field, strpos($field, '.') + 1);
                }

                $value = is_array($params) ? ($params[0] ?? null) : $params;

                $results = $this->readAll($collection, [[$field, '==', $value]], null, 1);
                return !empty($results) ? $results[0] : null;
            }

            // Fallback: return first document from collection
            $results = $this->readAll($collection);
            return !empty($results) ? $results[0] : null;
        }

        // If caller passed (collection, documentId) directly
        return $this->read($collectionOrSql, $params);
    }
    
    // Fetch all documents (alias for readAll)
    public function fetchAll($collectionOrSql, $params = [], $orderBy = null, $limit = null) {
        // Support legacy SQL-style calls
        if (is_string($collectionOrSql) && preg_match('/\bFROM\b\s+([a-zA-Z0-9_]+)/i', $collectionOrSql, $m)) {
            $collection = $m[1];

            $conditions = [];

            // WHERE field = ? patterns (supports multiple occurrences, maps params in order)
            if (preg_match_all('/\bWHERE\b\s+(.+)/is', $collectionOrSql, $whereMatch)) {
                $whereClause = $whereMatch[1][0];

                // Find all "field = ?" occurrences
                if (preg_match_all('/([a-zA-Z0-9_.]+)\s*=\s*\?/i', $whereClause, $fieldMatches)) {
                    $fields = $fieldMatches[1];
                    $paramIndex = 0;
                    foreach ($fields as $f) {
                        $fieldName = $f;
                        if (strpos($fieldName, '.') !== false) {
                            $fieldName = substr($fieldName, strpos($fieldName, '.') + 1);
                        }
                        $value = is_array($params) ? ($params[$paramIndex] ?? null) : ($paramIndex === 0 ? $params : null);
                        $conditions[] = [$fieldName, '==', $value];
                        $paramIndex++;
                    }
                }
            }

            // Try to extract LIMIT n
            if (preg_match('/\bLIMIT\b\s+(\d+)/i', $collectionOrSql, $limm)) {
                $limit = (int)$limm[1];
            }

            // We ignore ORDER BY for now (Firestore query mapping is limited)
            return $this->readAll($collection, $conditions, $orderBy, $limit);
        }

        return $this->readAll($collectionOrSql, $params, $orderBy, $limit);
    }
    
    // Get the last inserted document ID (stored after create operation)
    private $lastInsertId = null;
    
    public function lastInsertId() {
        return $this->lastInsertId;
    }
    
    // Transaction methods (Firebase Firestore transactions)
    public function beginTransaction() {
        // Firestore uses different transaction patterns
        // This is a simplified version - you may need to adapt based on your use case
        return true;
    }
    
    public function commit() {
        // Firestore commits are automatic
        return true;
    }
    
    public function rollback() {
        // Firestore rollbacks are different - implement as needed
        return true;
    }
    
    // Row count (for Firebase, this would be document count)
    public function rowCount($collection) {
        try {
            $documents = $this->restClient->queryCollection($collection);
            return count($documents);
        } catch (Exception $e) {
            $this->handleError("Row count failed: " . $e->getMessage(), $e);
            return 0;
        }
    }
    
    // Check if collection exists
    public function tableExists($collectionName) {
        try {
            $documents = $this->restClient->queryCollection($collectionName, 1);
            return count($documents) > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    // Error handling
    private function handleError($message, $exception) {
        if (defined('LOG_ERRORS') && LOG_ERRORS && defined('ERROR_LOG_PATH')) {
            error_log($message, 3, ERROR_LOG_PATH);
        }
        
        if (defined('DEBUG_MODE') && DEBUG_MODE) {
            throw $exception;
        }
    }
}

// Global database instance
$db = Database::getInstance();

// Helper function to get database connection

// Test database connection
function testDatabaseConnection() {
    try {
        $db = Database::getInstance();
        return $db->getConnection() !== null;
    } catch (Exception $e) {
        return false;
    }
}

// Helper functions for Firebase operations

// Create a new document
function createDocument($collection, $data, $documentId = null) {
    return getDB()->create($collection, $data, $documentId);
}

// Read a document by ID
function readDocument($collection, $documentId) {
    return getDB()->read($collection, $documentId);
}

// Read all documents from a collection
function readAllDocuments($collection, $conditions = [], $orderBy = null, $limit = null) {
    return getDB()->readAll($collection, $conditions, $orderBy, $limit);
}

// Update a document
function updateDocument($collection, $documentId, $data) {
    return getDB()->update($collection, $documentId, $data);
}

// Delete a document
function deleteDocument($collection, $documentId) {
    return getDB()->delete($collection, $documentId);
}

// Firebase collections mapping (you can customize these based on your needs)
class Collections {
    const USERS = 'users';
    const PRODUCTS = 'products';
    const INVENTORY = 'inventory';
    const STORES = 'stores';
    const TRANSACTIONS = 'transactions';
    const ALERTS = 'alerts';
    const REPORTS = 'reports';
}

// Get SQL Database instance
function getSQLDB() {
    return SQLDatabase::getInstance();
}
?>