export interface Point {x:number;y:number;}
export interface Size {width:number;height:number;}
export type Direction='up'|'down'|'right'|'up-right'|'up-left'|'down-right'|'down-left';
export function direction(start:Point,current:Point):Direction {
 const dx=current.x-start.x,dy=current.y-start.y;
 if(Math.abs(dx)<=36)return dy>0?'down':'up';
 if(Math.abs(dy)<=36&&dx>0)return 'right';
 return `${dy>0?'down':'up'}-${dx>0?'right':'left'}`;
}
export function placement(start:Point,current:Point,panel:Size,viewport:Size):Point {
 const orient=direction(start,current);let x=current.x,y=current.y;
 if(orient==='up'||orient==='up-left'){x-=128;y-=panel.height-20;}
 else if(orient==='down'||orient==='down-left'){x-=128;y-=20;}
 else {x-=20;y-=panel.height/2;}
 return {x:Math.max(10,Math.min(Math.max(20,x),viewport.width-panel.width-10)),y:Math.max(10,Math.min(Math.max(20,y),viewport.height-panel.height-20))};
}
export function shouldOpen(events:number,start:Point,current:Point):boolean{return events>10&&Math.hypot(current.x-start.x,current.y-start.y)>30;}
