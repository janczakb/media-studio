/** Small fixed-size signal buffer; paints at 24fps without React state per frame. */
export declare function DictationWaveform({ analyser, label, paused }: {
    analyser: () => AnalyserNode | null;
    label: string;
    paused: boolean;
}): import("react/jsx-runtime").JSX.Element;
