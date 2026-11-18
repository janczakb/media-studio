import {Button} from '@heroui/react';
import {StarIcon} from '@hugeicons/core-free-icons';
import type {Entry} from '../../contracts';
import {Icon,type Messages} from '../../ui';

export type ToggleFavorite = (entry:Entry, onChanged?:(starred:boolean)=>void)=>void;

export function FavoriteButton({entry,t,onToggle,pending,className,iconSize=18,iconStrokeWidth=2}:{entry:Entry;t:Messages;onToggle:()=>void;pending?:boolean;className?:string;iconSize?:number;iconStrokeWidth?:number}) {
  const starred = entry.starred===true;
  return <Button
    isIconOnly
    variant="ghost"
    size="sm"
    className={`fms-favorite-button ${className??''}`}
    aria-label={`${starred?t.unfavorite:t.favorite}: ${entry.name}`}
    aria-pressed={starred}
    isDisabled={pending===true}
    onPress={onToggle}
  ><Icon icon={StarIcon} size={iconSize} strokeWidth={iconStrokeWidth}/></Button>;
}
