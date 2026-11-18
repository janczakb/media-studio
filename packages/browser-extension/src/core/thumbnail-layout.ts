/** Contain the actual image inside a thumbnail cell without distorting its ratio. */
export function thumbnailBounds(width:number,height:number,maxWidth:number,maxHeight:number):{width:number;height:number}{
 const boundWidth=Math.max(1,Number.isFinite(maxWidth)?maxWidth:1);
 const boundHeight=Math.max(1,Number.isFinite(maxHeight)?maxHeight:1);
 if(!Number.isFinite(width)||!Number.isFinite(height)||width<=0||height<=0)return {width:boundWidth,height:boundHeight};
 const scale=Math.min(boundWidth/width,boundHeight/height);
 return {width:width*scale,height:height*scale};
}
