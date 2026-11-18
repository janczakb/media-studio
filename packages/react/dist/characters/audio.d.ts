/** Caller owns the audio context/source. This driver never opens a microphone. */
export declare function createCharacterAudioDriver(analyser: AnalyserNode, { gain }?: {
    gain?: number;
}): {
    level: import("motion-dom").MotionValue<number>;
    destroy(): void;
};
