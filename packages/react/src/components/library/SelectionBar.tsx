import {Button,ButtonGroup,Tooltip} from '@heroui/react';
import type {ComponentProps} from 'react';
import {Icon} from '../../ui';
import type {Folder01Icon} from '@hugeicons/core-free-icons';

export type SelectionAction={id:string;label:string;icon:typeof Folder01Icon;run:()=>void;danger?:boolean;disabled?:boolean};

// Forward HeroUI's group marker through the tooltip wrapper to the actual button.
function Action({action,portal,...groupProps}:{action:SelectionAction;portal:HTMLDivElement;__button_group_child?:boolean}) {
 return <Tooltip delay={300}><Button {...groupProps as ComponentProps<typeof Button>} size="sm" variant={action.danger?'danger-soft':'tertiary'} className="fms-selection-action" aria-label={action.label} isDisabled={action.disabled??false} onPress={action.run}>
  <Icon icon={action.icon} size={18}/><span className="fms-selection-action-label">{action.label}</span>
 </Button><Tooltip.Content UNSTABLE_portalContainer={portal}>{action.label}</Tooltip.Content></Tooltip>;
}

export function SelectionBar({label,count,actions,portal}:{label:string;count:string;actions:SelectionAction[];portal:HTMLDivElement}) {
 return <div className="fms-selection-bar" role="group" aria-label={label}>
  <strong role="status">{count}</strong>
  <ButtonGroup size="sm" variant="tertiary">{actions.map(action=><Action key={action.id} action={action} portal={portal}/>)}</ButtonGroup>
 </div>;
}
