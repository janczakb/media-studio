import {Spinner, Switch} from '@heroui/react';
import type {Messages} from '../../ui';
import type {BrowserPushControl} from './useBrowserPush';

export function BrowserPushSwitch({push, t, hideLabel = false}: {push: BrowserPushControl; t: Messages; hideLabel?: boolean}) {
  return <Switch size="sm" isSelected={push.subscribed} isDisabled={push.busy || !push.supported || !push.settings || (!push.settings.available && !push.subscribed)}
    onChange={value => void push.toggle(value)} aria-label={t.desktopNotifications}>
    <Switch.Content>{!hideLabel && <span>{t.desktopNotifications}</span>}<Switch.Control><Switch.Thumb/></Switch.Control>
      {push.busy && <Spinner size="sm" aria-label={t.loading}/>}
    </Switch.Content>
  </Switch>;
}
