import type { StudioTransport, Workspace } from '../../contracts';
type Props = {
    workspace: Workspace;
    transport: StudioTransport;
    portal: HTMLDivElement;
    locale: string;
};
export declare function MembersPage({ workspace, transport, portal, locale }: Props): import("react/jsx-runtime").JSX.Element;
export {};
