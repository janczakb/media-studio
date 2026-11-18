import type {DrawingMark, DrawingPoint} from '../../contracts';

export function drawingBounds(mark: DrawingMark) {
  const xs = mark.points.map(point => point[0]);
  const ys = mark.points.map(point => point[1]);
  return {x:Math.min(...xs), y:Math.min(...ys), width:Math.max(...xs)-Math.min(...xs), height:Math.max(...ys)-Math.min(...ys)};
}

/** Translate the whole object; clamping individual vertices would deform it. */
export function moveDrawing(mark: DrawingMark, dx: number, dy: number): DrawingMark {
  const box = drawingBounds(mark);
  dx = Math.max(-box.x,Math.min(1000-box.x-box.width,dx));
  dy = Math.max(-box.y,Math.min(1000-box.y-box.height,dy));
  return {...mark,points:mark.points.map(([x,y])=>[Math.round(x+dx),Math.round(y+dy)] as DrawingPoint)};
}

export function resizeDrawing(mark: DrawingMark, corner: number, point: DrawingPoint): DrawingMark {
  const box = drawingBounds(mark);
  const fixedX = corner === 0 || corner === 3 ? box.x+box.width : box.x;
  const fixedY = corner < 2 ? box.y+box.height : box.y;
  const left = Math.min(point[0],fixedX), top = Math.min(point[1],fixedY);
  const width = Math.max(1,Math.abs(point[0]-fixedX)), height = Math.max(1,Math.abs(point[1]-fixedY));
  return {...mark,points:mark.points.map(([x,y])=>[
    Math.round(Math.min(1000,left+(box.width ? (x-box.x)/box.width : 0)*width)),
    Math.round(Math.min(1000,top+(box.height ? (y-box.y)/box.height : 0)*height)),
  ] as DrawingPoint)};
}
