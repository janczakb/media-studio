import { type ToastQueue } from '@heroui/react';
import type { ManagementTransport } from '../../management-contracts';
import { type ManagementLocale } from './shared';
export declare function StudioToasts({ queue, portal, label }: {
    queue: ToastQueue;
    portal: HTMLDivElement;
    label: string;
}): import("react/jsx-runtime").JSX.Element;
export declare function useInboxToasts(list: ManagementTransport['notifications'], workspace: string | undefined, locale: ManagementLocale, queue: ToastQueue): {
    unread: number;
    setUnread: (count: number) => void;
};
