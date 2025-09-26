// Firebase client for web (CDN version)
// Include Firebase SDK via CDN in your HTML:
// <script src="https://www.gstatic.com/firebasejs/9.22.0/firebase-app-compat.js"></script>
// <script src="https://www.gstatic.com/firebasejs/9.22.0/firebase-auth-compat.js"></script>
// <script src="https://www.gstatic.com/firebasejs/9.22.0/firebase-firestore-compat.js"></script>

// Firebase configuration
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
firebase.initializeApp(firebaseConfig);

// Initialize services
const auth = firebase.auth();
const db = firebase.firestore();

// Enable offline persistence
db.enablePersistence({ synchronizeTabs: true })
  .then(() => {
    console.log('Firebase offline persistence enabled');
  })
  .catch((err) => {
    console.warn('Firebase offline persistence failed:', err);
  });

// Network state management
const goOffline = () => {
  return db.disableNetwork();
};

const goOnline = () => {
  return db.enableNetwork();
};

// Check if currently offline
const isOffline = () => {
  return !navigator.onLine;
};

// Listen for network status changes
const setupNetworkListener = (onOnline, onOffline) => {
  const handleOnline = () => {
    console.log('Network: Online');
    goOnline().then(() => {
      if (onOnline) onOnline();
    });
  };
  
  const handleOffline = () => {
    console.log('Network: Offline');
    goOffline().then(() => {
      if (onOffline) onOffline();
    });
  };
  
  window.addEventListener('online', handleOnline);
  window.addEventListener('offline', handleOffline);
  
  // Return cleanup function
  return () => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
  };
};

// Helper functions for common operations
const FirebaseHelper = {
  // Collections
  collections: {
    users: 'users',
    products: 'products',
    inventory: 'inventory',
    stores: 'stores',
    transactions: 'transactions',
    alerts: 'alerts',
    reports: 'reports'
  },
  
  // Create document
  create: async (collection, data, documentId = null) => {
    try {
      if (documentId) {
        await db.collection(collection).doc(documentId).set(data);
        return documentId;
      } else {
        const docRef = await db.collection(collection).add(data);
        return docRef.id;
      }
    } catch (error) {
      console.error('Error creating document:', error);
      throw error;
    }
  },
  
  // Read document
  read: async (collection, documentId) => {
    try {
      const doc = await db.collection(collection).doc(documentId).get();
      if (doc.exists) {
        return { id: doc.id, ...doc.data() };
      }
      return null;
    } catch (error) {
      console.error('Error reading document:', error);
      throw error;
    }
  },
  
  // Read all documents
  readAll: async (collection, conditions = [], orderBy = null, limit = null) => {
    try {
      let query = db.collection(collection);
      
      // Apply conditions
      conditions.forEach(condition => {
        if (condition.length === 3) {
          query = query.where(condition[0], condition[1], condition[2]);
        }
      });
      
      // Apply ordering
      if (orderBy) {
        query = query.orderBy(orderBy.field, orderBy.direction || 'asc');
      }
      
      // Apply limit
      if (limit) {
        query = query.limit(limit);
      }
      
      const snapshot = await query.get();
      const results = [];
      
      snapshot.forEach(doc => {
        results.push({ id: doc.id, ...doc.data() });
      });
      
      return results;
    } catch (error) {
      console.error('Error reading documents:', error);
      throw error;
    }
  },
  
  // Update document
  update: async (collection, documentId, data) => {
    try {
      await db.collection(collection).doc(documentId).update(data);
      return true;
    } catch (error) {
      console.error('Error updating document:', error);
      throw error;
    }
  },
  
  // Delete document
  delete: async (collection, documentId) => {
    try {
      await db.collection(collection).doc(documentId).delete();
      return true;
    } catch (error) {
      console.error('Error deleting document:', error);
      throw error;
    }
  },
  
  // Listen to real-time updates
  listen: (collection, callback, conditions = []) => {
    let query = db.collection(collection);
    
    // Apply conditions
    conditions.forEach(condition => {
      if (condition.length === 3) {
        query = query.where(condition[0], condition[1], condition[2]);
      }
    });
    
    return query.onSnapshot((snapshot) => {
      const results = [];
      snapshot.forEach(doc => {
        results.push({ id: doc.id, ...doc.data() });
      });
      callback(results);
    }, (error) => {
      console.error('Error listening to collection:', error);
    });
  }
};

// Make objects available globally
window.FirebaseHelper = FirebaseHelper;
window.firebaseAuth = auth;
window.firebaseDb = db;
window.goOffline = goOffline;
window.goOnline = goOnline;
window.isOffline = isOffline;
window.setupNetworkListener = setupNetworkListener;