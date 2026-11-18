import {WorkspaceMark} from './WorkspaceEmojiPicker';
import {usePopoverDismiss} from '../../usePopoverDismiss';
import {useState,useCallback,useId} from 'react';
import {Avatar, Button, Dropdown, Label, SearchField, Separator,Tabs} from '@heroui/react';
import {HugeiconsIcon} from '@hugeicons/react';
import {Moon02Icon, Search01Icon, Sun03Icon, ComputerIcon, UserCircleIcon} from '@hugeicons/core-free-icons';
import type {ReactNode} from 'react';
import type {StudioOptions,Workspace} from '../../contracts';
import {Icon, type Messages} from '../../ui';

type Props = {
  workspaces:Workspace[];activeWorkspace:Workspace|undefined;multiWorkspace:boolean;canCreateWorkspace:boolean;onWorkspace:(id:string)=>void;onCreateWorkspace:()=>void;onManageWorkspace:()=>void;onSettings:()=>void;onTrash:()=>void;onLogout:(()=>void)|undefined;
  onAccountSecurity:(()=>void)|undefined;
  picker?: boolean;
  workspaceSelector?: ReactNode;
  navigation: ReactNode;
  searchSurface?: ReactNode;
  breadcrumbs?: ReactNode;
  search: string;
  onSearch: (value: string) => void;
  user: StudioOptions['user'];
  appearance:'light'|'dark'|'system';
  onAppearance:(value:'light'|'dark'|'system')=>void;
  portal: HTMLDivElement;
  t: Messages;
};

export function StudioHeader({workspaces,activeWorkspace,multiWorkspace,canCreateWorkspace,onWorkspace,onCreateWorkspace,onManageWorkspace,onSettings,onTrash,onLogout,onAccountSecurity,navigation, searchSurface, breadcrumbs, search, onSearch, user, appearance, onAppearance, portal, t, picker, workspaceSelector}: Props) {
  const [accountOpen,setAccountOpen]=useState(false);const id=useId(),closeAccount=useCallback(()=>setAccountOpen(false),[]);
  usePopoverDismiss(portal,accountOpen,closeAccount,id);
  const initial = user?.name?.trim().slice(0, 1).toLocaleUpperCase();
  return <header className={`fms-header${picker?' fms-picker-header':''}`}>
    {navigation}
    {picker&&workspaceSelector}
    {(!picker&&breadcrumbs)?<div className="fms-header-trail">{breadcrumbs}</div>:searchSurface??<SearchField className="fms-header-search" variant={picker ? 'secondary' : 'primary'} aria-label={t.searchHint} value={search} onChange={onSearch}>
      <SearchField.Group>
        <SearchField.SearchIcon><HugeiconsIcon icon={Search01Icon} size={20} strokeWidth={2}/></SearchField.SearchIcon>
        <SearchField.Input placeholder={t.search}/>
        <SearchField.ClearButton aria-label={t.clearSearch}/>
      </SearchField.Group>
    </SearchField>}
    {!picker&&<Dropdown isOpen={accountOpen} onOpenChange={setAccountOpen}>
      <Button data-fms-popover={id} isIconOnly variant="ghost" className="fms-account-trigger" aria-label={t.accountMenu}>
        <Avatar size="sm">
          {user?.avatarUrl && <Avatar.Image src={user.avatarUrl} alt=""/>}
          <Avatar.Fallback>{initial ?? 'U'}</Avatar.Fallback>
        </Avatar>
      </Button>
      <Dropdown.Popover isNonModal data-fms-popover={id} className="fms-account-popover" placement="bottom end" UNSTABLE_portalContainer={portal}>
        {(user?.name || user?.email) && <div className="fms-account-identity">
          <Avatar size="sm">{user.avatarUrl&&<Avatar.Image src={user.avatarUrl} alt=""/>}<Avatar.Fallback>{initial??'U'}</Avatar.Fallback></Avatar><div>{user.name&&<strong>{user.name}</strong>}{user.email&&<span>{user.email}</span>}</div>
        </div>}
        <Dropdown.Menu aria-label={t.accountMenu}>
          <Dropdown.Section>{onAccountSecurity&&<Dropdown.Item id="account-security" onAction={onAccountSecurity}><Label>Account security</Label></Dropdown.Item>}<Dropdown.Item id="settings" onAction={onSettings}><Label>{t.settings}</Label></Dropdown.Item><Dropdown.Item id="trash" onAction={onTrash}><Label>{t.trash}</Label></Dropdown.Item>{activeWorkspace?.role==='owner'&&activeWorkspace.permissions?.['workspaces.manage']===true&&<Dropdown.Item id="workspace-settings" onAction={onManageWorkspace}><Label>{t.workspaces} · {t.settings}</Label></Dropdown.Item>}</Dropdown.Section>
          {multiWorkspace&&<><Separator/><Dropdown.Section><Label className="fms-account-section-label">{t.workspaces}</Label>{workspaces.map(w=><Dropdown.Item key={w.id} id={'workspace-'+w.id} aria-label={w.name} textValue={w.name} onAction={()=>onWorkspace(w.id)}><span className="fms-account-workspace-icon"><WorkspaceMark emoji={w.emoji} size={22}/></span><Label>{w.name}</Label>{w.id===activeWorkspace?.id&&<span aria-label="Selected">✓</span>}</Dropdown.Item>)}</Dropdown.Section></>}
          {multiWorkspace&&canCreateWorkspace&&<Dropdown.Item id="create-workspace" onAction={onCreateWorkspace}><Label>{t.newWorkspace}</Label></Dropdown.Item>}
          {onLogout&&<><Separator/><Dropdown.Item id="logout" className="fms-account-logout" onAction={onLogout}><Label>{'Logout'}</Label></Dropdown.Item></>}
        </Dropdown.Menu>
        <div className="fms-account-appearance"><span>Appearance</span><Tabs className="fms-appearance" selectedKey={appearance} onSelectionChange={key=>{if(key==='light'||key==='dark'||key==='system')onAppearance(key);}}><Tabs.ListContainer><Tabs.List aria-label="Appearance">{([['light',t.lightMode,Sun03Icon],['dark',t.darkMode,Moon02Icon],['system','System',ComputerIcon]] as const).map(([key,label,icon])=><Tabs.Tab id={key} key={key} aria-label={label}><Icon icon={icon} size={18}/><Tabs.Indicator/></Tabs.Tab>)}</Tabs.List></Tabs.ListContainer></Tabs></div>
      </Dropdown.Popover>
    </Dropdown>}
  </header>;
}
