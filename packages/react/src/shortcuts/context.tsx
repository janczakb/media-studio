import {createContext,useContext} from 'react';
import type {ShortcutRegistry} from './registry';
export const ShortcutContext=createContext<ShortcutRegistry|null>(null);
export const useShortcutRegistry=()=>useContext(ShortcutContext);
