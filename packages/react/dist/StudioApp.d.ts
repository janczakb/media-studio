import type { StudioOptions } from './contracts';
type AppProps = StudioOptions & {
    portalContainer: HTMLDivElement;
    onInitialReady: () => void;
};
export declare function StudioApp(props: AppProps): import("react/jsx-runtime").JSX.Element;
export {};
