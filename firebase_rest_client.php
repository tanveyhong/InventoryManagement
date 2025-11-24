<?php
// Simple Firebase REST Client for Windows compatibility
require_once 'firebase_config.php';

class FirebaseRestClient {
    private $projectId;
    private $apiKey;
    private $baseUrl;
    
    public function __construct() {
        $config = FirebaseConfig::getConfig();
        $this->projectId = $config['projectId'];
        $this->apiKey = $config['apiKey'];
        $this->baseUrl = "https://firestore.googleapis.com/v1/projects/{$this->projectId}/databases/(default)/documents";
    }
    
    private function makeRequest($method, $url, $data = null) {
        // Try cURL first (more reliable on Windows), fallback to file_get_contents
        if (function_exists('curl_init')) {
            return $this->makeRequestCurl($method, $url, $data);
        } else {
            return $this->makeRequestFileGetContents($method, $url, $data);
        }
    }
    
    private function makeRequestCurl($method, $url, $data = null) {
        $ch = curl_init();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
            ],
        ]);
        
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception("Network error: Unable to connect to Firebase. " . $error);
        }
        
        $decoded = json_decode($response, true);
        
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Firebase: " . json_last_error_msg());
        }
        
        // Check for error response
        if (isset($decoded['error'])) {
            throw new Exception("Firebase API Error: " . $decoded['error']['message']);
        }
        
        return $decoded;
    }
    
    private function makeRequestFileGetContents($method, $url, $data = null) {
        // For now, we'll use the Firebase Web API without authentication
        // In production, you should implement proper authentication
        
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => [
                    'Content-Type: application/json',
                ],
                'content' => $data ? json_encode($data) : null,
                'ignore_errors' => true,
                'timeout' => 5 // Add timeout to fail faster
            ]
        ]);
        
        // Suppress warnings and capture errors
        error_reporting(E_ERROR | E_PARSE);
        $response = @file_get_contents($url, false, $context);
        error_reporting(E_ALL);
        
        if ($response === false) {
            $error = error_get_last();
            $errorMsg = $error ? $error['message'] : 'Unknown error';
            
            // Check if it's a network connectivity issue
            if (strpos($errorMsg, 'getaddrinfo') !== false || 
                strpos($errorMsg, 'No such host') !== false ||
                strpos($errorMsg, 'Connection refused') !== false) {
                throw new Exception("Network error: Unable to connect to Firebase. Please check your internet connection.");
            }
            
            throw new Exception("Failed to make request to Firebase: " . $errorMsg);
        }
        
        $decoded = json_decode($response, true);
        
        if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception("Invalid JSON response from Firebase: " . json_last_error_msg());
        }
        
        // Check for error response
        if (isset($decoded['error'])) {
            throw new Exception("Firebase API Error: " . $decoded['error']['message']);
        }
        
        return $decoded;
    }
    
    public function createDocument($collection, $data, $documentId = null) {
        $url = $this->baseUrl . '/' . $collection;
        
        if ($documentId) {
            $url .= '?documentId=' . urlencode($documentId);
        }
        
        // Convert data to Firestore format
        $firestoreData = $this->convertToFirestoreFormat($data);
        
        try {
            $response = $this->makeRequest('POST', $url, ['fields' => $firestoreData]);
            return $this->extractDocumentId($response);
        } catch (Exception $e) {
            error_log("Firebase create error: " . $e->getMessage());
            return false;
        }
    }
    
    public function getDocument($collection, $documentId) {
        if (is_array($documentId) || is_object($documentId)) {
            error_log("Firebase getDocument called with invalid documentId (array/object) for collection: {$collection}");
            return null;
        }

        $url = $this->baseUrl . '/' . $collection . '/' . urlencode((string)$documentId);
        
        try {
            $response = $this->makeRequest('GET', $url);
            return $this->convertFromFirestoreFormat($response);
        } catch (Exception $e) {
            error_log("Firebase get error: " . $e->getMessage());
            return null;
        }
    }
    
    public function updateDocument($collection, $documentId, $data) {
        if (is_array($documentId) || is_object($documentId)) {
            error_log("Firebase updateDocument called with invalid documentId (array/object) for collection: {$collection}");
            return false;
        }

        $url = $this->baseUrl . '/' . $collection . '/' . urlencode((string)$documentId);
        
        // Convert data to Firestore format
        $firestoreData = $this->convertToFirestoreFormat($data);
        
        // Build update mask to only update specified fields (not replace entire document)
        $fieldNames = array_keys($data);
        $updateMask = [];
        foreach ($fieldNames as $fieldName) {
            $updateMask[] = 'updateMask.fieldPaths=' . urlencode($fieldName);
        }
        
        // Add update mask to URL to ensure PATCH only updates specified fields
        if (!empty($updateMask)) {
            $url .= '?' . implode('&', $updateMask);
        }
        
        try {
            $response = $this->makeRequest('PATCH', $url, ['fields' => $firestoreData]);
            return true;
        } catch (Exception $e) {
            error_log("Firebase update error: " . $e->getMessage());
            return false;
        }
    }
    
    public function deleteDocument($collection, $documentId) {
        if (is_array($documentId) || is_object($documentId)) {
            error_log("Firebase deleteDocument called with invalid documentId (array/object) for collection: {$collection}");
            return false;
        }

        $url = $this->baseUrl . '/' . $collection . '/' . urlencode((string)$documentId);
        
        try {
            $this->makeRequest('DELETE', $url);
            return true;
        } catch (Exception $e) {
            error_log("Firebase delete error: " . $e->getMessage());
            return false;
        }
    }
    
    public function queryCollection($collection, $limit = null, $orderBy = null) {
        $url = $this->baseUrl . '/' . $collection;
        
        // IMPORTANT: Set a default limit to prevent reading ALL documents
        // Firebase charges per document read, so unlimited queries are expensive!
        if ($limit === null) {
            $limit = 100; // Safe default limit
        }
        
        $params = [];
        if ($limit) {
            $params[] = 'pageSize=' . $limit;
        }
        
        if ($orderBy) {
            $params[] = 'orderBy=' . urlencode($orderBy);
        }
        
        if (!empty($params)) {
            $url .= '?' . implode('&', $params);
        }
        
        try {
            $response = $this->makeRequest('GET', $url);
            $documents = [];
            
            if (isset($response['documents'])) {
                foreach ($response['documents'] as $doc) {
                    $converted = $this->convertFromFirestoreFormat($doc);
                    if ($converted && isset($converted['id'])) {
                        // Use document ID as array key to preserve it
                        $documentId = $converted['id'];
                        $documents[$documentId] = $converted;
                    }
                }
            }
            
            return $documents;
        } catch (Exception $e) {
            error_log("Firebase query error: " . $e->getMessage());
            return [];
        }
    }
    
    private function convertToFirestoreFormat($data) {
        $formatted = [];
        
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                // Convert arrays to JSON string for storage
                $formatted[$key] = ['stringValue' => json_encode($value)];
            } elseif (is_string($value)) {
                $formatted[$key] = ['stringValue' => $value];
            } elseif (is_int($value)) {
                $formatted[$key] = ['integerValue' => (string)$value];
            } elseif (is_float($value)) {
                $formatted[$key] = ['doubleValue' => $value];
            } elseif (is_bool($value)) {
                $formatted[$key] = ['booleanValue' => $value];
            } elseif (is_null($value)) {
                $formatted[$key] = ['nullValue' => null];
            } else {
                $formatted[$key] = ['stringValue' => (string)$value];
            }
        }
        
        return $formatted;
    }
    
    private function convertFromFirestoreFormat($document) {
        if (!isset($document['fields'])) {
            return null;
        }
        
        $data = ['id' => $this->extractDocumentId($document)];
        
        foreach ($document['fields'] as $key => $field) {
            if (isset($field['stringValue'])) {
                $data[$key] = $field['stringValue'];
            } elseif (isset($field['integerValue'])) {
                $data[$key] = (int)$field['integerValue'];
            } elseif (isset($field['doubleValue'])) {
                $data[$key] = $field['doubleValue'];
            } elseif (isset($field['booleanValue'])) {
                $data[$key] = $field['booleanValue'];
            } elseif (isset($field['nullValue'])) {
                $data[$key] = null;
            }
        }
        
        return $data;
    }
    
    private function extractDocumentId($document) {
        if (isset($document['name'])) {
            $parts = explode('/', $document['name']);
            return end($parts);
        }
        return null;
    }
}
?>