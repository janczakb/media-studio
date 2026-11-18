import {ext} from './webextension';

const key = 'capture.popupRoute';
const storage = () => ext.storage.session ?? ext.storage.local;

export async function openConnectionPopup(unavailableMessage: string): Promise<void> {
  if (!ext.action.openPopup) throw new Error(unavailableMessage);
  await storage().set({[key]: {view: 'connect', expiresAt: Date.now() + 30_000}});
  try {
    await ext.action.openPopup();
  } catch (error) {
    await storage().remove(key);
    throw error;
  }
}

export async function consumePopupRoute(): Promise<'connect' | null> {
  const value = (await storage().get(key))[key] as {view?: unknown; expiresAt?: unknown} | undefined;
  if (!value) return null;
  await storage().remove(key);
  return value.view === 'connect' && typeof value.expiresAt === 'number' && value.expiresAt > Date.now() ? 'connect' : null;
}
