/** Font size uses the persisted 1000-unit horizontal coordinate system; strokes use screen pixels. */
export function drawingDefaults(width:number,height:number){
 const w=Math.max(1,width),short=Math.max(1,Math.min(w,height));
 return {strokeWidth:Math.round(Math.max(1.5,Math.min(4.5,short/220))*100)/100,fontSize:Math.round(Math.max(16,Math.min(48,Math.max(14,Math.min(30,short*.035))*1000/w))*100)/100};
}
