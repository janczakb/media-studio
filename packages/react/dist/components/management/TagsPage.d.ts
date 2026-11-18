import type { ManagementTransport, StudioTag } from '../../management-contracts';
import { type ManagementLocale } from './shared';
export declare function TagsPage({ workspace, transport, locale, onOpenTag }: {
    workspace: string;
    transport: ManagementTransport;
    locale?: ManagementLocale;
    onOpenTag: (tag: StudioTag) => void;
}): import("react/jsx-runtime").JSX.Element;
