import { Folder01Icon } from '@hugeicons/core-free-icons';
import type en from './locales/en';
export type Messages = Record<keyof typeof en, string>;
export declare const Icon: ({ icon, size, strokeWidth, preserveOriginalStroke }: {
    icon: typeof Folder01Icon;
    size?: number;
    strokeWidth?: number;
    preserveOriginalStroke?: boolean;
}) => import("react/jsx-runtime").JSX.Element;
export declare const errorText: (error: unknown) => string;
export { formatFileBytes as formatBytes } from './file-caption';
