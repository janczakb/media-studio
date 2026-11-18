declare const icons: {
    members: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
    invitations: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
    tokens: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
    usage: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
    shares: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
    trash: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
    tags: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
    notifications: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
    files: [string, {
        [key: string]: string | number;
    }][] | readonly (readonly [string, {
        readonly [key: string]: string | number;
    }])[];
};
export type TableEmptyKind = keyof typeof icons;
export declare function TableEmptyState({ kind, label, pending }: {
    kind: TableEmptyKind;
    label: string;
    pending?: boolean;
}): import("react/jsx-runtime").JSX.Element;
export {};
