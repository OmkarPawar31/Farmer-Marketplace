/**
 * Real-time Auction WebSocket Client
 * Handles live bidding, countdown timers, and auction notifications
 */

class AuctionWebSocketClient {
    constructor(serverUrl = 'ws://localhost:8080') {
        this.serverUrl = serverUrl;
        this.socket = null;
        this.isConnected = false;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 2000;
        this.heartbeatInterval = null;
        this.currentAuctions = new Set();
        this.eventHandlers = {};
        this.userId = null;
        this.sessionId = null;
        
        // Bind methods
        this.connect = this.connect.bind(this);
        this.onOpen = this.onOpen.bind(this);
        this.onMessage = this.onMessage.bind(this);
        this.onClose = this.onClose.bind(this);
        this.onError = this.onError.bind(this);
    }
    
    // Event handling
    on(eventType, handler) {
        if (!this.eventHandlers[eventType]) {
            this.eventHandlers[eventType] = [];
        }
        this.eventHandlers[eventType].push(handler);
    }
    
    off(eventType, handler) {
        if (this.eventHandlers[eventType]) {
            const index = this.eventHandlers[eventType].indexOf(handler);
            if (index > -1) {
                this.eventHandlers[eventType].splice(index, 1);
            }
        }
    }
    
    emit(eventType, data) {
        if (this.eventHandlers[eventType]) {
            this.eventHandlers[eventType].forEach(handler => {
                try {
                    handler(data);
                } catch (error) {
                    console.error('Error in event handler:', error);
                }
            });
        }
    }
    
    // Connection management
    connect(userId, sessionId) {
        if (this.socket && this.socket.readyState === WebSocket.OPEN) {
            console.log('Already connected to auction server');
            return Promise.resolve();
        }
        
        this.userId = userId;
        this.sessionId = sessionId;
        
        return new Promise((resolve, reject) => {
            try {
                this.socket = new WebSocket(this.serverUrl);
                
                this.socket.onopen = (event) => {
                    this.onOpen(event);
                    resolve();
                };
                
                this.socket.onmessage = this.onMessage;
                this.socket.onclose = this.onClose;
                this.socket.onerror = (error) => {
                    this.onError(error);
                    reject(error);
                };
                
            } catch (error) {
                reject(error);
            }
        });
    }
    
    disconnect() {
        if (this.socket) {
            this.socket.close();
        }
        
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
    }
    
    onOpen(event) {
        console.log('Connected to auction server');
        this.isConnected = true;
        this.reconnectAttempts = 0;
        
        // Authenticate
        this.authenticate();
        
        // Start heartbeat
        this.startHeartbeat();
        
        this.emit('connected', { event });
    }
    
    onMessage(event) {
        try {
            const data = JSON.parse(event.data);
            
            if (data.success === false) {
                console.error('Server error:', data.message);
                this.emit('error', { message: data.message, data });
                return;
            }
            
            switch (data.type) {
                case 'authenticated':
                    this.handleAuthenticated(data);
                    break;
                    
                case 'auction_status':
                    this.handleAuctionStatus(data);
                    break;
                    
                case 'bid_update':
                    this.handleBidUpdate(data);
                    break;
                    
                case 'bid_placed':
                    this.handleBidPlaced(data);
                    break;
                    
                case 'countdown_update':
                    this.handleCountdownUpdate(data);
                    break;
                    
                case 'auction_warning':
                    this.handleAuctionWarning(data);
                    break;
                    
                case 'auction_ended':
                    this.handleAuctionEnded(data);
                    break;
                    
                case 'user_joined':
                    this.handleUserJoined(data);
                    break;
                    
                case 'user_left':
                    this.handleUserLeft(data);
                    break;
                    
                case 'heartbeat_ack':
                    // Heartbeat acknowledged
                    break;
                    
                case 'error':
                    this.emit('error', data);
                    break;
                    
                default:
                    console.log('Unknown message type:', data.type, data);
            }
            
        } catch (error) {
            console.error('Error parsing message:', error, event.data);
        }
    }
    
    onClose(event) {
        console.log('Disconnected from auction server');
        this.isConnected = false;
        
        if (this.heartbeatInterval) {
            clearInterval(this.heartbeatInterval);
            this.heartbeatInterval = null;
        }
        
        this.emit('disconnected', { event });
        
        // Attempt to reconnect if not closed intentionally
        if (event.code !== 1000 && this.reconnectAttempts < this.maxReconnectAttempts) {
            this.attemptReconnect();
        }
    }
    
    onError(error) {
        console.error('WebSocket error:', error);
        this.emit('error', { error });
    }
    
    attemptReconnect() {
        this.reconnectAttempts++;
        
        console.log(`Attempting to reconnect... (${this.reconnectAttempts}/${this.maxReconnectAttempts})`);
        
        setTimeout(() => {
            if (this.userId && this.sessionId) {
                this.connect(this.userId, this.sessionId).catch(error => {
                    console.error('Reconnection failed:', error);
                    
                    if (this.reconnectAttempts >= this.maxReconnectAttempts) {
                        this.emit('reconnection_failed');
                    }
                });
            }
        }, this.reconnectDelay * this.reconnectAttempts);
    }
    
    // Authentication
    authenticate() {
        if (!this.userId || !this.sessionId) {
            console.error('User ID and Session ID required for authentication');
            return;
        }
        
        this.send({
            type: 'authenticate',
            user_id: this.userId,
            session_id: this.sessionId
        });
    }
    
    handleAuthenticated(data) {
        console.log('Authenticated successfully');
        this.emit('authenticated', data);
    }
    
    // Heartbeat management
    startHeartbeat() {
        this.heartbeatInterval = setInterval(() => {
            if (this.isConnected) {
                this.send({
                    type: 'heartbeat',
                    timestamp: Date.now()
                });
            }
        }, 30000); // Send heartbeat every 30 seconds
    }
    
    // Auction management
    joinAuction(auctionId) {
        if (!this.isConnected) {
            console.error('Not connected to server');
            return false;
        }
        
        this.send({
            type: 'join_auction',
            auction_id: auctionId
        });
        
        this.currentAuctions.add(auctionId);
        return true;
    }
    
    leaveAuction(auctionId) {
        if (!this.isConnected) {
            return false;
        }
        
        this.send({
            type: 'leave_auction',
            auction_id: auctionId
        });
        
        this.currentAuctions.delete(auctionId);
        return true;
    }
    
    placeBid(auctionId, bidAmount, quantity = 1) {
        if (!this.isConnected) {
            console.error('Not connected to server');
            return false;
        }
        
        this.send({
            type: 'place_bid',
            auction_id: auctionId,
            bid_amount: bidAmount,
            quantity: quantity
        });
        
        return true;
    }
    
    getAuctionStatus(auctionId) {
        if (!this.isConnected) {
            return false;
        }
        
        this.send({
            type: 'get_auction_status',
            auction_id: auctionId
        });
        
        return true;
    }
    
    // Message handlers
    handleAuctionStatus(data) {
        this.emit('auction_status', data.data);
    }
    
    handleBidUpdate(data) {
        this.emit('bid_update', {
            auctionId: data.auction_id,
            bidAmount: data.bid_amount,
            bidderId: data.bidder_id,
            timestamp: data.timestamp,
            autoBid: data.auto_bid || false
        });
    }
    
    handleBidPlaced(data) {
        this.emit('bid_placed', data.data);
    }
    
    handleCountdownUpdate(data) {
        this.emit('countdown_update', {
            auctionId: data.auction_id,
            secondsRemaining: data.seconds_remaining
        });
    }
    
    handleAuctionWarning(data) {
        this.emit('auction_warning', {
            auctionId: data.auction_id,
            message: data.message,
            secondsRemaining: data.seconds_remaining
        });
    }
    
    handleAuctionEnded(data) {
        this.emit('auction_ended', {
            auctionId: data.auction_id,
            winnerId: data.winner_id,
            finalPrice: data.final_price,
            totalBids: data.total_bids,
            status: data.status,
            message: data.message
        });
        
        // Remove from current auctions
        this.currentAuctions.delete(data.auction_id);
    }
    
    handleUserJoined(data) {
        this.emit('user_joined', {
            userId: data.user_id,
            participantCount: data.participant_count
        });
    }
    
    handleUserLeft(data) {
        this.emit('user_left', {
            userId: data.user_id,
            participantCount: data.participant_count
        });
    }
    
    // Utility methods
    send(message) {
        if (this.socket && this.socket.readyState === WebSocket.OPEN) {
            this.socket.send(JSON.stringify(message));
            return true;
        }
        return false;
    }
    
    isConnectedToServer() {
        return this.isConnected && this.socket && this.socket.readyState === WebSocket.OPEN;
    }
    
    getCurrentAuctions() {
        return Array.from(this.currentAuctions);
    }
}

// Utility functions for auction UI
class AuctionUIHelper {
    static formatCountdown(seconds) {
        if (seconds <= 0) return 'Ended';
        
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        if (hours > 0) {
            return `${hours}h ${minutes}m ${secs}s`;
        } else if (minutes > 0) {
            return `${minutes}m ${secs}s`;
        } else {
            return `${secs}s`;
        }
    }
    
    static formatCurrency(amount) {
        return new Intl.NumberFormat('en-IN', {
            style: 'currency',
            currency: 'INR'
        }).format(amount);
    }
    
    static getCountdownColor(seconds) {
        if (seconds <= 60) return 'red';
        if (seconds <= 300) return 'orange';
        return 'green';
    }
    
    static createCountdownElement(auctionId, initialSeconds) {
        const element = document.createElement('div');
        element.className = 'auction-countdown';
        element.id = `countdown-${auctionId}`;
        
        let seconds = initialSeconds;
        
        const updateCountdown = () => {
            const timeStr = AuctionUIHelper.formatCountdown(seconds);
            const color = AuctionUIHelper.getCountdownColor(seconds);
            
            element.innerHTML = `
                <span style="color: ${color}; font-weight: bold;">
                    ${timeStr}
                </span>
            `;
            
            if (seconds > 0) {
                seconds--;
                setTimeout(updateCountdown, 1000);
            }
        };
        
        updateCountdown();
        return element;
    }
    
    static showBidNotification(type, message, options = {}) {
        // Create notification element
        const notification = document.createElement('div');
        notification.className = `bid-notification ${type}`;
        notification.innerHTML = `
            <div class="notification-content">
                <span class="notification-message">${message}</span>
                <button class="notification-close" onclick="this.parentElement.parentElement.remove()">×</button>
            </div>
        `;
        
        // Style the notification
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#4CAF50' : type === 'error' ? '#f44336' : '#ff9800'};
            color: white;
            padding: 15px;
            border-radius: 5px;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            z-index: 10000;
            max-width: 300px;
            animation: slideInRight 0.3s ease-out;
        `;
        
        document.body.appendChild(notification);
        
        // Auto-remove after delay
        const delay = options.delay || 5000;
        setTimeout(() => {
            if (notification.parentElement) {
                notification.style.animation = 'slideOutRight 0.3s ease-in';
                setTimeout(() => {
                    notification.remove();
                }, 300);
            }
        }, delay);
        
        return notification;
    }
}

// Add CSS for notifications
const notificationStyles = `
    @keyframes slideInRight {
        from { transform: translateX(100%); opacity: 0; }
        to { transform: translateX(0); opacity: 1; }
    }
    
    @keyframes slideOutRight {
        from { transform: translateX(0); opacity: 1; }
        to { transform: translateX(100%); opacity: 0; }
    }
    
    .bid-notification {
        font-family: Arial, sans-serif;
        font-size: 14px;
    }
    
    .notification-content {
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .notification-close {
        background: none;
        border: none;
        color: white;
        font-size: 18px;
        font-weight: bold;
        cursor: pointer;
        margin-left: 10px;
    }
    
    .notification-close:hover {
        opacity: 0.8;
    }
    
    .auction-countdown {
        font-family: 'Courier New', monospace;
        font-size: 18px;
        padding: 5px;
        text-align: center;
    }
`;

// Inject styles
if (typeof document !== 'undefined') {
    const styleSheet = document.createElement('style');
    styleSheet.textContent = notificationStyles;
    document.head.appendChild(styleSheet);
}

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { AuctionWebSocketClient, AuctionUIHelper };
} else if (typeof window !== 'undefined') {
    window.AuctionWebSocketClient = AuctionWebSocketClient;
    window.AuctionUIHelper = AuctionUIHelper;
}
