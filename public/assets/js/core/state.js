/**
 * State Manager Module
 * Centralized state management with polling support
 */

export class StateManager {
    constructor(api, options = {}) {
        this.api = api;
        this.state = null;
        this.stateVersion = 0;
        this.listeners = [];
        this.pollInterval = null;
        this.pollDelay = options.pollingInterval || 1500;
        this.autoStart = options.autoStart !== false;
        this.lastSuccessfulFetch = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.isPolling = false;
        
        // Auto start polling if configured
        if (this.autoStart && this.api) {
            this.startPolling();
        }
    }

    /**
     * Get current state
     */
    getState() {
        return this.state;
    }

    /**
     * Get current state version
     */
    getStateVersion() {
        return this.stateVersion;
    }

    /**
     * Fetch fresh state from server
     */
    async refresh() {
        if (!this.api) {
            console.warn('StateManager: No API client configured');
            return null;
        }
        
        try {
            const data = await this.api.getGameState();
            
            if (data && data.success !== false) {
                const oldState = this.state;
                this.state = data;
                
                if (data.stateVersion !== undefined) {
                    this.stateVersion = data.stateVersion;
                }
                
                this.lastSuccessfulFetch = Date.now();
                this.reconnectAttempts = 0;
                
                // Notify listeners
                this.notifyListeners(data, oldState);
                
                return data;
            }
            
            return null;
        } catch (error) {
            console.error('StateManager refresh error:', error);
            this.reconnectAttempts++;
            throw error;
        }
    }

    /**
     * Update state and notify listeners
     */
    setState(newState, version = null) {
        const oldState = this.state;
        this.state = newState;
        
        if (version !== null) {
            this.stateVersion = version;
        }

        this.notifyListeners(newState, oldState);
    }

    /**
     * Notify all listeners of state change
     */
    notifyListeners(newState, oldState) {
        this.listeners.forEach(callback => {
            try {
                callback(newState, oldState);
            } catch (error) {
                console.error('State listener error:', error);
            }
        });
    }

    /**
     * Subscribe to state changes
     * @param {Function} callback - Called with (newState, oldState)
     * @returns {Function} - Unsubscribe function
     */
    subscribe(callback) {
        this.listeners.push(callback);
        
        // Return unsubscribe function
        return () => {
            const index = this.listeners.indexOf(callback);
            if (index > -1) {
                this.listeners.splice(index, 1);
            }
        };
    }

    /**
     * Unsubscribe from state changes
     */
    unsubscribe(callback) {
        const index = this.listeners.indexOf(callback);
        if (index > -1) {
            this.listeners.splice(index, 1);
        }
    }

    /**
     * Start polling for state updates
     */
    startPolling() {
        if (this.isPolling) {
            return;
        }
        
        this.isPolling = true;
        
        const poll = async () => {
            if (!this.isPolling) return;
            
            try {
                const data = await this.api.getGameState();
                
                if (data && data.stateVersion !== undefined) {
                    // Update if version changed OR if this is first fetch (state is null)
                    if (this.state === null || data.stateVersion !== this.stateVersion) {
                        const oldState = this.state;
                        this.state = data;
                        this.stateVersion = data.stateVersion;
                        this.notifyListeners(data, oldState);
                    }
                    this.lastSuccessfulFetch = Date.now();
                    this.reconnectAttempts = 0;
                }
            } catch (error) {
                console.error('Polling error:', error);
                this.reconnectAttempts++;
                
                if (this.reconnectAttempts >= this.maxReconnectAttempts) {
                    console.warn('Max reconnect attempts reached');
                    this.emit('connectionLost');
                }
            }
            
            // Schedule next poll
            if (this.isPolling) {
                this.pollInterval = setTimeout(poll, this.pollDelay);
            }
        };

        // Start polling
        poll();
    }

    /**
     * Stop polling
     */
    stopPolling() {
        this.isPolling = false;
        if (this.pollInterval) {
            clearTimeout(this.pollInterval);
            this.pollInterval = null;
        }
    }

    /**
     * Set polling delay
     */
    setPollDelay(ms) {
        this.pollDelay = ms;
    }

    /**
     * Check if currently polling
     */
    isActive() {
        return this.isPolling;
    }

    /**
     * Get time since last successful fetch
     */
    getTimeSinceLastFetch() {
        if (!this.lastSuccessfulFetch) return null;
        return Date.now() - this.lastSuccessfulFetch;
    }

    /**
     * Emit custom event
     */
    emit(eventName, data = null) {
        const event = new CustomEvent(`stateManager:${eventName}`, { detail: data });
        window.dispatchEvent(event);
    }

    /**
     * Listen for custom events
     */
    on(eventName, callback) {
        const handler = (e) => callback(e.detail);
        window.addEventListener(`stateManager:${eventName}`, handler);
        return () => window.removeEventListener(`stateManager:${eventName}`, handler);
    }
}

// Default export singleton instance (for simple usage)
export const stateManager = new StateManager(null, { autoStart: false });
