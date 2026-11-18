import type {Entry} from '../../contracts';
import type {PhotoPeriod} from './PhotosToolbar';
export type TimelineRow = {key:string;entries:Entry[];label?:string;height:number;start:number};
export function timelineRows(items:Entry[],columns:number,cell:number,period:PhotoPeriod,locale='en'):TimelineRow[] {
  const rows:TimelineRow[]=[];
  let group='',start=0;
  const format = new Intl.DateTimeFormat(locale,period==='years'?{year:'numeric',timeZone:'UTC'}:period==='months'?{year:'numeric',month:'long',timeZone:'UTC'}:{year:'numeric',month:'long',day:'numeric',timeZone:'UTC'});
  const count=Math.max(1,Math.floor(columns));
  for(const entry of items){
    const date=new Date(entry.createdAt??entry.updatedAt);
    const valid=Number.isFinite(date.getTime());
    const key=valid?date.toISOString().slice(0,period==='years'?4:period==='months'?7:10):'unknown';
    if(key!==group){
      group=key;
      rows.push({key:'group-'+key,entries:[],label:valid?format.format(date):'—',height:48,start});start+=48;
    }
    let row=rows.at(-1);
    if(!row||row.label!==undefined||row.entries.length>=count){
      row={key:entry.id,entries:[],height:cell+4,start};rows.push(row);start+=row.height;
    }
    row.entries.push(entry);
  }
  return rows;
}
