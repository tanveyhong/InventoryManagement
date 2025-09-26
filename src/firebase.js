// src/firebase.js
import { initializeApp } from "firebase/app";
import { getAuth } from "firebase/auth";
import { getFirestore, enableNetwork, disableNetwork } from "firebase/firestore";

const firebaseConfig = {
  apiKey: "AIzaSyCelemTDQegu399eOfW2gIoDjFQlW9Sv-A",
  authDomain: "inventorymanagement-28b71.firebaseapp.com",
  projectId: "inventorymanagement-28b71",
  storageBucket: "inventorymanagement-28b71.firebasestorage.app",
  messagingSenderId: "773002212505",
  appId: "1:773002212505:web:adaabf306ab11d15093408",
  measurementId: "G-8N27F9SHQ5"
};

// Initialize Firebase
const app = initializeApp(firebaseConfig);

// Initialize Auth
export const auth = getAuth(app);

// Initialize Firestore with offline persistence
export const db = getFirestore(app);

// Enable offline persistence (automatically enabled in v9+)
// Firestore automatically enables offline persistence by default in v9+
// Data will be cached locally and synced when connection is restored

// Optional: Functions to manually control network state
export const goOffline = () => {
  return disableNetwork(db);
};

export const goOnline = () => {
  return enableNetwork(db);
};

// Optional: Check if currently offline
export const isOffline = () => {
  return !navigator.onLine;
};

// Optional: Listen for network status changes
export const setupNetworkListener = (onOnline, onOffline) => {
  const handleOnline = () => {
    console.log('Network: Online');
    if (onOnline) onOnline();
  };
  
  const handleOffline = () => {
    console.log('Network: Offline');
    if (onOffline) onOffline();
  };
  
  window.addEventListener('online', handleOnline);
  window.addEventListener('offline', handleOffline);
  
  // Return cleanup function
  return () => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
  };
};