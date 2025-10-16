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
        // For now, we'll use the Firebase Web API without authentication
        // In production, you should implement proper authentication
        
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => [
                    'Content-Type: application/json',
                ],
                'content' => $data ? json_encode($data) : null,
                'ignore_errors' => true
            ]
        ]);
        
        $response = @file_get_contents($url, false, $context);
        
        if ($response === false) {
            throw new Exception("Failed to make request to Firebase: " . error_get_last()['message']);
        }
        
        $decoded = json_decode($response, true);
        
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
    
    public function queryCollection($collection, $limit = null) {
        $url = $this->baseUrl . '/' . $collection;
        
        if ($limit) {
            $url .= '?pageSize=' . $limit;
        }
        
        try {
            $response = $this->makeRequest('GET', $url);
            $documents = [];
            
            if (isset($response['documents'])) {
                foreach ($response['documents'] as $doc) {
                    $documents[] = $this->convertFromFirestoreFormat($doc);
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