export const PHOTO_ZOOM_MIN = 64;
export const PHOTO_ZOOM_MAX = 480;

export type PhotoZoomAnchor = {x:number;y:number};

export function clampPhotoZoom(value:number,min=PHOTO_ZOOM_MIN,max=PHOTO_ZOOM_MAX):number {
  return Math.min(max,Math.max(min,Number.isFinite(value)?value:min));
}

/** Normalize browser wheel units into a bounded logarithmic pinch increment. */
export function photoWheelDelta(delta:number,mode:number):number {
  const pixels=delta*(mode===1?18:mode===2?400:1);
  return Number.isFinite(pixels)?Math.max(-.4,Math.min(.4,-pixels*.012)):0;
}

/** Select a column count without enlarging a target beyond its available width. */
export function photoGridGeometry(width:number,target:number,gap=4):{columns:number;cell:number;row:number} {
  const available=Math.max(1,Number.isFinite(width)?width:1);
  const spacing=Math.max(0,Number.isFinite(gap)?gap:0);
  const columns=Math.max(1,Math.floor((available+spacing)/(clampPhotoZoom(target)+spacing)+1e-9));
  const cell=Math.max(1,(available-spacing*(columns-1))/columns);
  return {columns,cell,row:cell+spacing};
}

export function photoGeometryForColumns(width:number,columns:number,gap=4):{columns:number;cell:number;row:number}{
  const count=Math.max(1,Math.floor(columns));
  const cell=Math.max(1,(Math.max(1,width)-gap*(count-1))/count);
  return {columns:count,cell,row:cell+gap};
}

/** Adjacent density levels are compared by their actual fitted cell sizes. */
export function photoPinchLevel(width:number,columns:number,logScale:number,releasing=false):{columns:number;scale:number}{
  const min=photoGridGeometry(width,PHOTO_ZOOM_MAX).columns,max=photoGridGeometry(width,PHOTO_ZOOM_MIN).columns;
  const here=photoGeometryForColumns(width,columns).cell;
  const inward=columns>min?columns-1:null,outward=columns<max?columns+1:null;
  const upper=inward?Math.log(photoGeometryForColumns(width,inward).cell/here):0;
  const lower=outward?Math.log(photoGeometryForColumns(width,outward).cell/here):0;
  const portion=releasing?.35:1;
  if(inward&&logScale>0&&logScale>=upper*portion)return {columns:inward,scale:1};
  if(outward&&logScale<0&&logScale<=lower*portion)return {columns:outward,scale:1};
  const resisted=logScale>upper?upper+.16*Math.tanh((logScale-upper)/.16):logScale<lower?lower+.16*Math.tanh((logScale-lower)/.16):logScale;
  return {columns,scale:Math.exp(resisted)};
}
