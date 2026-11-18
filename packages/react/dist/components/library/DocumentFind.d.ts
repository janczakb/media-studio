import { type Messages } from '../../ui';
import type { OfficeFindState, OfficeFindResult } from '../../office-preview-contract';
export declare function DocumentFind({ state, result, onChange, onClose, t, portal }: {
    state: OfficeFindState;
    result: OfficeFindResult;
    onChange: (state: OfficeFindState) => void;
    onClose: () => void;
    t: Messages;
    portal: HTMLDivElement;
}): import("react/jsx-runtime").JSX.Element;
