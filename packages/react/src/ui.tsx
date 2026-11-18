import {HugeiconsIcon} from '@hugeicons/react';
import {Folder01Icon} from '@hugeicons/core-free-icons';
import type en from './locales/en';
export type Messages = Record<keyof typeof en, string>;
export const Icon = ({icon,size=20,strokeWidth=2,preserveOriginalStroke=false}:{icon:typeof Folder01Icon;size?:number;strokeWidth?:number;preserveOriginalStroke?:boolean})=><HugeiconsIcon icon={icon} size={size} {...(preserveOriginalStroke?{}:{strokeWidth})}/>;
export const errorText=(error:unknown)=>error instanceof Error?error.message:'The request could not be completed.';
export {formatFileBytes as formatBytes} from './file-caption';
