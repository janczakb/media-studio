import type {Asset,Preferences} from '../core/types';
import type {Point} from './drag-geometry';
export interface DragRenderer {retain:boolean;open(asset:Asset|Asset[],start:Point,current:Point,preferences:Preferences):void;close():void;}
export type DragWorld=typeof globalThis&{flexCaptureDrag?:DragRenderer};
