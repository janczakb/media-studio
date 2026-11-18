import type { WorkspaceMessages } from '../../workspace-i18n';
export declare function memberRoleName(role: string, useManagementMessages: WorkspaceMessages): string;
export declare function MemberRoleSelect({ value, onChange, owner, disabled, portal }: {
    value: string;
    onChange: (role: string) => void;
    owner: boolean;
    disabled: boolean;
    portal: HTMLElement;
    pl?: boolean;
}): import("react/jsx-runtime").JSX.Element;
