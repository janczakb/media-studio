export interface StudioAuthOptions {
    screen: string;
    token?: string;
    email?: string;
    prefix: string;
    home: string;
    registration: boolean;
    primaryColor: string;
    stylesheetUrl: string;
}
export declare function mountStudioAuth(host: HTMLElement, options: StudioAuthOptions): {
    destroy(): void;
};
