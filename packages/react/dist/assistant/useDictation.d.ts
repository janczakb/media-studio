type State = 'idle' | 'starting' | 'listening' | 'stopping';
/** Browser speech recognition only. No MediaRecorder, audio blobs, files or Studio uploads. */
export declare function useDictation(locale: string, onCommit: (value: string) => void, onError: (message: string) => void): {
    state: State;
    interim: string;
    supported: boolean;
    start: (base: string) => Promise<void>;
    cancel: () => void;
    finish: () => Promise<string>;
    analyser: () => AnalyserNode | null;
};
export {};
