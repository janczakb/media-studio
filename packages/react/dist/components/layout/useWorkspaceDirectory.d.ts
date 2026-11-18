import type { StudioOptions, Workspace } from '../../contracts';
/** The directory belongs to the instance, not to a page. Navigation never clears it. */
export declare function useWorkspaceDirectory({ transport, workspaceId, workspaceIds }: StudioOptions, requested: string | null, attempt: number): {
    workspaceIdentity: boolean;
    appearance: {
        primaryColor?: string;
    };
    viewer: {
        name?: string | null;
        email?: string | null;
        avatarUrl?: string | null;
    };
    workspaces: Workspace[];
    setWorkspaces: import("react").Dispatch<import("react").SetStateAction<Workspace[]>>;
    multipleEnabled: boolean;
    canCreate: boolean;
    loading: boolean;
    error: string;
};
