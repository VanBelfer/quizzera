/**
 * Player Audio Manager Module
 * Handles sound effects for quiz interactions
 */

export class AudioManager {
    constructor() {
        this.sounds = {};
        this.enabled = true;
        this.volume = 0.5;
    }

    /**
     * Initialize audio system
     */
    init() {
        // Preload sounds (using Web Audio API or base64)
        this.preloadSounds();
    }

    /**
     * Preload sound effects
     */
    preloadSounds() {
        try {
            // Create audio context
            const AudioContext = window.AudioContext || window.webkitAudioContext;
            this.audioContext = new AudioContext();

            // Define sounds (you can replace with actual audio files)
            this.sounds = {
                buzz: this.createOscillatorSound(440, 0.1), // Buzz beep
                correct: this.createOscillatorSound(880, 0.15), // Higher pitch for correct
                incorrect: this.createOscillatorSound(220, 0.15), // Lower pitch for incorrect
                tick: this.createOscillatorSound(1000, 0.05) // Quick tick
            };
        } catch (e) {
            console.warn('Audio not supported:', e);
            this.enabled = false;
        }
    }

    /**
     * Create a simple oscillator sound
     */
    createOscillatorSound(frequency, duration) {
        return {
            frequency,
            duration,
            play: () => {
                if (!this.audioContext || !this.enabled) return;

                try {
                    const oscillator = this.audioContext.createOscillator();
                    const gainNode = this.audioContext.createGain();

                    oscillator.connect(gainNode);
                    gainNode.connect(this.audioContext.destination);

                    oscillator.frequency.value = frequency;
                    oscillator.type = 'sine';

                    gainNode.gain.setValueAtTime(this.volume, this.audioContext.currentTime);
                    gainNode.gain.exponentialRampToValueAtTime(
                        0.01,
                        this.audioContext.currentTime + duration
                    );

                    oscillator.start(this.audioContext.currentTime);
                    oscillator.stop(this.audioContext.currentTime + duration);
                } catch (e) {
                    console.warn('Audio play error:', e);
                }
            }
        };
    }

    /**
     * Play a sound by name
     */
    play(soundName) {
        if (!this.enabled) return;

        const sound = this.sounds[soundName];
        if (sound && sound.play) {
            // Resume audio context if suspended (required by browsers)
            if (this.audioContext?.state === 'suspended') {
                this.audioContext.resume();
            }
            sound.play();
        }
    }

    /**
     * Play buzz sound
     */
    playBuzz() {
        this.play('buzz');
    }

    /**
     * Play correct answer sound
     */
    playCorrect() {
        this.play('correct');
    }

    /**
     * Play incorrect answer sound
     */
    playIncorrect() {
        this.play('incorrect');
    }

    /**
     * Enable/disable sounds
     */
    setEnabled(enabled) {
        this.enabled = enabled;
    }

    /**
     * Toggle sounds
     */
    toggle() {
        this.enabled = !this.enabled;
        return this.enabled;
    }

    /**
     * Set volume (0-1)
     */
    setVolume(volume) {
        this.volume = Math.max(0, Math.min(1, volume));
    }

    /**
     * Check if audio is enabled
     */
    isEnabled() {
        return this.enabled;
    }
}

// Factory function
export function initAudioManager() {
    const manager = new AudioManager();
    manager.init();
    return manager;
}

// Default instance
export const audioManager = new AudioManager();
