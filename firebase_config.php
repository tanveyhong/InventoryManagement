<?php
// Firebase Configuration
require_once 'config.php';

class FirebaseConfig {
    const FIREBASE_CONFIG = [
        'apiKey' => 'AIzaSyCelemTDQegu399eOfW2gIoDjFQlW9Sv-A',
        'authDomain' => 'inventorymanagement-28b71.firebaseapp.com',
        'projectId' => 'inventorymanagement-28b71',
        'storageBucket' => 'inventorymanagement-28b71.firebasestorage.app',
        'messagingSenderId' => '773002212505',
        'appId' => '1:773002212505:web:adaabf306ab11d15093408',
        'measurementId' => 'G-8N27F9SHQ5'
    ];
    
    public static function getProjectId() {
        return self::FIREBASE_CONFIG['projectId'];
    }
    
    public static function getConfig() {
        return self::FIREBASE_CONFIG;
    }
    
    public static function getConfigJson() {
        return json_encode(self::FIREBASE_CONFIG);
    }
}
?>