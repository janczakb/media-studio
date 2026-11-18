import type { DrawingStyle } from '../../contracts';
import { type Messages } from '../../ui';
export declare function DrawingStyles({ tool, style, color, onChange, onColor, t, portal, baseStyle }: {
    baseStyle: {
        strokeWidth: number;
        fontSize: number;
    };
    tool: string;
    style: DrawingStyle;
    color: string;
    onChange: (style: DrawingStyle) => void;
    onColor: (color: string) => void;
    t: Messages;
    portal: HTMLDivElement;
}): import("react/jsx-runtime").JSX.Element;
