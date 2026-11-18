export type SlackEvent = 'uploaded' | 'guest_uploaded' | 'processed' | 'trashed' | 'restored';
export interface SlackConnection {
  connected: boolean;
  enabled: boolean;
  channel: string;
  teamName: string | null;
  events: SlackEvent[];
  revision: number;
  lastDeliveredAt: string | null;
  status: string;
}
export interface IntegrationOverview {
  available: boolean;
  canManage: boolean;
  oauthConfigured: boolean;
  events: SlackEvent[];
  slack: SlackConnection;
}
export interface SlackSettings {
  revision: number;
  enabled: boolean;
  events: SlackEvent[];
}
export interface IntegrationsTransport {
  approveExtension?(workspace:string,input:{id:string;code:string},signal:AbortSignal):Promise<{approved:boolean}>;
  overview(workspace: string, signal: AbortSignal): Promise<IntegrationOverview>;
  startSlackOAuth(workspace: string, input: {revision: number; returnUrl: string}, signal: AbortSignal): Promise<{authorizationUrl: string}>;
  saveSlack(workspace: string, input: SlackSettings, signal: AbortSignal): Promise<IntegrationOverview>;
  disconnectSlack(workspace: string, revision: number, signal: AbortSignal): Promise<IntegrationOverview>;
}
