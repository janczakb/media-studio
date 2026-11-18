import {useStudioLocalization} from '../../workspace-i18n';
import {AssistantToggle} from '../../assistant/AssistantBoundary';
import {Button, Tooltip} from '@heroui/react';
import {ArtificialIntelligence03Icon, Plug01Icon, WorkIcon, ShapeCollectionIcon, Notification01Icon, Settings02Icon, AirdropIcon, UserRoundPlusIcon} from '@hugeicons/core-free-icons';
import {HugeiconsIcon} from '@hugeicons/react';
import {memo, type ReactElement} from 'react';
import type {StudioNavigationItem} from './layout-config';

export type NavigationSection = 'library' | 'workspaces';

export const NavRail = memo(function NavRail({unreadCount=0,section, items, onNavigate, view, onView, onSettings, canShare, canNotify, canMembers, canAi, canIntegrations, canSettings = true, logoUrl, logoLabel, labels, portal}: {
  unreadCount?:number;
  items?: StudioNavigationItem[] | undefined;
  section: NavigationSection;
  onNavigate: (section: NavigationSection) => void;
  view: string;
  onView: (view: 'shares' | 'notifications' | 'members' | 'ai' | 'integrations') => void;
  onSettings: () => void;
  canShare: boolean;
  canMembers?: boolean;
  canAi?: boolean;
  canIntegrations?: boolean;
  canSettings?: boolean;
  canNotify: boolean;
  logoUrl: string;
  logoLabel: string;
  labels: {integrations: string; ai: string; primaryNavigation: string; library: string; workspaces: string; shares: string; notifications: string; members: string; settings: string};
  portal: HTMLDivElement;
}) {
  const {locale}=useStudioLocalization();
  const tip = (label: string, control: ReactElement) => <Tooltip delay={300} closeDelay={0}>
    {control}
    <Tooltip.Content placement="right" offset={10} UNSTABLE_portalContainer={portal}>{label}</Tooltip.Content>
  </Tooltip>;

  return <nav className="fms-rail" aria-label={labels.primaryNavigation}>
    <div className="fms-rail-brand">{tip(logoLabel,
      <Button isIconOnly variant="ghost" className="fms-rail-control" aria-label={logoLabel} onPress={() => onNavigate('library')}>
        <img src={logoUrl} alt="" className="fms-rail-logo"/>
      </Button>,
    )}</div>
    <ul className="fms-rail-list">
      {([
        {id: 'library', label: labels.library, icon: ShapeCollectionIcon},
      ] as const).filter(item=>!items||items.includes(item.id)).map(item => <li key={item.id} className={section === item.id && view !== 'shares' && view !== 'notifications' && view !== 'members' && view !== 'ai' && view !== 'integrations' ? 'is-active' : undefined}>
        {tip(item.label, <Button isIconOnly variant="ghost" className="fms-rail-control" aria-label={item.label}
          aria-current={section === item.id && view !== 'shares' && view !== 'notifications' && view !== 'members' && view !== 'ai' && view !== 'integrations' ? 'page' : 'false'} onPress={() => onNavigate(item.id)}>
          <HugeiconsIcon icon={item.icon} size={24} strokeWidth={1.5}/>
        </Button>)}
      </li>)}
      {([{id:'members',label:labels.members,icon:UserRoundPlusIcon,enabled:canMembers},{id:'shares',label:labels.shares,icon:AirdropIcon,enabled:canShare},{id:'ai',label:labels.ai,icon:ArtificialIntelligence03Icon,enabled:canAi},{id:'integrations',label:labels.integrations,icon:Plug01Icon,enabled:canIntegrations}] as const)
        .filter(item=>item.enabled&&(!items||items.includes(item.id))).map(item=><li key={item.id} className={view===item.id?'is-active':undefined}>
          {tip(item.label,<Button isIconOnly variant="ghost" className="fms-rail-control" aria-label={item.label} aria-current={view===item.id?'page':'false'} onPress={()=>onView(item.id)}>
            <HugeiconsIcon icon={item.icon} size={24} strokeWidth={1.5}/>
          </Button>)}
        </li>)}
      <li><AssistantToggle rail locale={locale}/></li>
    </ul>
    <div className="fms-rail-bottom">{canNotify&&(!items||items.includes('notifications'))&&tip(labels.notifications,<Button isIconOnly variant="ghost" className="fms-rail-control" aria-label={labels.notifications} aria-current={view==='notifications'?'page':'false'} onPress={()=>onView('notifications')}><HugeiconsIcon icon={Notification01Icon} size={24} strokeWidth={1.5}/>{unreadCount>0&&<span className="fms-notification-badge" aria-hidden="true">{unreadCount>9?'9+':unreadCount}</span>}</Button>)}{canSettings&&tip(labels.settings,<Button isIconOnly variant="ghost" className="fms-rail-control" aria-label={labels.settings} onPress={onSettings}>
      <HugeiconsIcon icon={Settings02Icon} size={24} strokeWidth={1.5}/>
    </Button>)}</div>
  </nav>;
});
